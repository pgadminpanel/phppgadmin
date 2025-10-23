// Universal streaming decompressor for ZIP, Gzip, Bzip2, and raw files
// Provides unified push-based API for all formats

import {
	Unzip,
	Gunzip,
	gzipSync,
	UnzipInflate,
} from "../lib/fflate/esm/browser.js";
import { getBzip2Module } from "../lib/bzip2/bzip2-stream.js";
import { logImport, detectZipSignature } from "./utils.js";

/**
 * Detect compression format from magic bytes
 * @param {Uint8Array} header - First bytes of file
 * @returns {string} - 'zip', 'gzip', 'bzip2', or 'raw'
 */
export function detectFormat(header) {
	if (header.length < 2) return null;

	// ZIP: 50 4B 03 04 (PK..)
	if (detectZipSignature(header)) {
		return "zip";
	}

	// GZIP: 1F 8B
	if (header[0] === 0x1f && header[1] === 0x8b) {
		return "gzip";
	}

	// BZIP2: 42 5A 68 ("BZh")
	if (header[0] === 0x42 && header[1] === 0x5a && header[2] === 0x68) {
		return "bzip2";
	}

	// Otherwise: uncompressed
	return "raw";
}

/**
 * Base decoder class - all decoders implement this interface
 */
class BaseDecoder {
	constructor() {
		this.ondata = null; // (chunk: Uint8Array, final: boolean) => void
		this.onerror = null; // (error: Error) => void
	}

	push(_chunk, _final) {
		throw new Error("Not implemented");
	}

	_emit(chunk, final) {
		if (this.ondata) this.ondata(chunk, final);
	}

	_error(err) {
		if (this.onerror) this.onerror(err);
	}
}

/**
 * ZIP decoder using fflate.Unzip
 * Extracts all files in the archive and emits their data sequentially
 */
class ZipDecoder extends BaseDecoder {
	constructor() {
		super();
		this.unzip = new Unzip();
		this.activeFiles = 0; // Track number of files being processed

		// Register decoders for compressed files within the ZIP
		// UnzipInflate handles deflate-compressed files (compression type 8)
		this.unzip.register(UnzipInflate);

		// Set up onfile callback
		this.unzip.onfile = (file) => {
			this.activeFiles++; // Increment for each file encountered

			logImport(`ZIP: Extracting file: ${file.name}`);

			// Set up the data handler for this file
			file.ondata = (err, data, final) => {
				if (err) {
					console.error(`ZIP decompression error:`, err);
					this._error(new Error(`ZIP decompression error: ${err}`));
					this.activeFiles--; // Decrement on error to avoid hanging
					if (this.activeFiles === 0) {
						this._emit(new Uint8Array(0), true); // Emit final only if no more active files
					}
					return;
				}
				if (data && data.length > 0) {
					console.log(
						`ZIP file chunk: ${data.length} bytes, final=${final}`,
					);
					// Emit data immediately, but always with final=false for chunks
					this._emit(data, false);
				}

				// When this file is final, decrement active count
				if (final) {
					this.activeFiles--;
					// Only emit global final=true when ALL files are done
					if (this.activeFiles === 0) {
						logImport("ZIP archive extraction complete");
						this._emit(new Uint8Array(0), true);
					}
				}
			};

			// Start processing the file
			file.start();
		};
	}

	push(chunk, final) {
		try {
			const u8 =
				chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk);
			this.unzip.push(u8, final);
		} catch (err) {
			this._error(err);
		}
	}
}

/**
 * GZIP decoder using fflate.Gunzip
 */
class GzipDecoder extends BaseDecoder {
	constructor() {
		super();
		this.gunzip = new Gunzip();

		this.gunzip.ondata = (data, final) => {
			this._emit(data, final);
		};
	}

	push(chunk, final) {
		try {
			const u8 =
				chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk);
			this.gunzip.push(u8, final);
		} catch (err) {
			this._error(err);
		}
	}
}

/**
 * Raw decoder - passthrough for uncompressed files
 */
class RawDecoder extends BaseDecoder {
	push(chunk, final) {
		const u8 = chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk);
		this._emit(u8, final);
	}
}

// Streaming Bzip2 decoder using BZ2_bzDecompress
class Bzip2Decoder extends BaseDecoder {
	constructor() {
		super();
		this.modulePromise = getBzip2Module();
		this.ready = this._init();
		this._queue = Promise.resolve();
		this.strmPtr = 0;
		this.outPtr = 0;
		this.outSize = 1024 * 1024; // 1 MB output buffer
		this.finished = false;
		this.error = null;
	}

	async _init() {
		const m = await this.modulePromise;

		// bz_stream struct = 48 bytes (12 x 4-byte fields)
		const strmPtr = m._malloc(48);
		m.HEAPU8.fill(0, strmPtr, strmPtr + 48);

		// Allocate output buffer once
		const outPtr = m._malloc(this.outSize);

		const BZ_OK = 0;
		const ret = m._BZ2_bzDecompressInit(strmPtr, 0, 0);
		if (ret !== BZ_OK) {
			throw new Error("BZ2_bzDecompressInit failed with code " + ret);
		}

		this.strmPtr = strmPtr;
		this.outPtr = outPtr;
		this.module = m;
	}

	push(chunk, final) {
		// Ensure sequential access to the underlying bz_stream.
		this._queue = this._queue.then(() => this._pushInternal(chunk, final));
		// Keep the chain alive even if a chunk errors.
		this._queue = this._queue.catch(() => undefined);
		return this._queue;
	}

	async _pushInternal(chunk, final) {
		if (this.finished || this.error) return;

		await this.ready;

		const m = this.module;
		const u8 = chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk);

		// Allocate input buffer in WASM
		const inPtr = m._malloc(u8.length);
		m.HEAPU8.set(u8, inPtr);

		try {
			const strm = this.strmPtr;
			const HEAPU32 = m.HEAPU32;

			const BZ_OK = 0;
			const BZ_STREAM_END = 4;

			// Set input pointers
			HEAPU32[(strm + 0) >> 2] = inPtr; // next_in
			HEAPU32[(strm + 4) >> 2] = u8.length; // avail_in

			while (true) {
				// Prepare output buffer
				HEAPU32[(strm + 16) >> 2] = this.outPtr; // next_out
				HEAPU32[(strm + 20) >> 2] = this.outSize; // avail_out

				const status = m._BZ2_bzDecompress(strm);

				const availOut = HEAPU32[(strm + 20) >> 2];
				const produced = this.outSize - availOut;

				if (produced > 0) {
					const outChunk = m.HEAPU8.slice(
						this.outPtr,
						this.outPtr + produced,
					);
					this._emit(outChunk, false);
				}

				if (status === BZ_STREAM_END) {
					this.finished = true;
					this._endStream();
					this._emit(new Uint8Array(0), true);
					break;
				}

				if (status !== BZ_OK) {
					this.error = new Error("Bzip2 stream error " + status);
					this._error(this.error);
					this._endStream();
					break;
				}

				const availIn = HEAPU32[(strm + 4) >> 2];
				if (availIn === 0) {
					// No more input → wait for next chunk
					break;
				}
			}
		} finally {
			// Always free input buffer
			this.module._free(inPtr);
		}

		if (final && !this.finished && !this.error) {
			this.error = new Error("Unexpected end of Bzip2 stream");
			this._error(this.error);
			this._endStream();
		}
	}

	_endStream() {
		if (!this.module || !this.strmPtr) return;

		try {
			this.module._BZ2_bzDecompressEnd(this.strmPtr);
		} catch (_) {}
		try {
			this.module._free(this.strmPtr);
		} catch (_) {}
		try {
			this.module._free(this.outPtr);
		} catch (_) {}

		this.strmPtr = 0;
		this.outPtr = 0;
	}
}

/**
 * Create appropriate decoder for format
 */
function createDecoderForFormat(format) {
	switch (format) {
		case "zip":
			return new ZipDecoder();
		case "gzip":
			return new GzipDecoder();
		case "bzip2":
			return new Bzip2Decoder();
		case "raw":
		default:
			return new RawDecoder();
	}
}

/**
 * Create universal decompression stream with auto-detection
 * Returns object with push(chunk, final) method and ondata/onerror callbacks
 */
export function createUniversalDecompressStream(format) {
	let finished = false;
	const decoder = createDecoderForFormat(format);

	// Wire up callbacks
	decoder.ondata = (data, isFinal) => {
		console.log(
			`Decompressed chunk: ${data.length} bytes, final=${isFinal}`,
		);
		if (stream.ondata) stream.ondata(data, isFinal);
		if (isFinal) finished = true;
	};

	decoder.onerror = (err) => {
		if (stream.onerror) stream.onerror(err);
		finished = true;
	};

	const stream = {
		ondata: null, // (chunk: Uint8Array, final: boolean) => void
		onerror: null, // (error: Error) => void

		async push(chunk, final) {
			if (finished) return;

			const u8 =
				chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk);

			// Push entire chunk to decoder (including header portion)
			if (decoder) {
				await decoder.push(u8, final);
			}
		},
	};

	return stream;
}

// Re-export gzipSync for chunk compression feature
export { gzipSync };
