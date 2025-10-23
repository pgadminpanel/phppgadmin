// Streaming uploader using new process_chunk endpoint
// Sends chunks and prepends server-provided remainder.
// Supports compressed file decompression and optional chunk compression.

import {
	el,
	formatBytes,
	sniffMagicType,
	getServerCaps,
	fnv1a64,
	appendServerToUrl,
	logImport,
} from "./utils.js";
import { createUniversalDecompressStream, gzipSync } from "./decompressor.js";

function utf8Encode(str) {
	return new TextEncoder().encode(str);
}

function utf8Decode(bytes) {
	return new TextDecoder().decode(bytes);
}

function concatBytes(a, b) {
	const out = new Uint8Array(a.length + b.length);
	out.set(a, 0);
	out.set(b, a.length);
	return out;
}

async function startStreamUpload() {
	const fileInput = el("file");
	if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
		alert("No file selected");
		return;
	}
	let file = fileInput.files[0];
	const hash = fnv1a64(
		utf8Encode(file.name + "|" + file.size + "|" + file.lastModified),
	);

	// Stable per-import session key so multiple uploads can run in parallel.
	const importSessionId = `import-${hash}`;
	console.log("Import session id:", importSessionId);

	// Detect compression format
	const magic = await sniffMagicType(file);
	const isCompressed =
		magic === "gzip" || magic === "bzip2" || magic === "zip";
	console.log(
		`File format: ${magic}${isCompressed ? " (will decompress)" : ""}`,
	);

	// Check if chunk compression is enabled
	const compressChunks = !!document.querySelector(
		"input[name='opt_compress_chunks']",
	)?.checked;

	const scope = el("import_scope")?.value || "database";
	const scopeIdent = el("import_scope_ident")?.value || "";

	// Options
	const importForm = el("importForm");
	const optNames = [
		"opt_roles",
		"opt_tablespaces",
		"opt_databases",
		"opt_schema_create",
		"opt_data",
		"opt_truncate",
		"opt_ownership",
		"opt_rights",
		"opt_defer_self",
		"opt_allow_drops",
		"opt_stop_on_error",
		"opt_ignore_connect",
		"opt_verbose",
		"use_header",
		"include_schema_objects",
		"export_all_objects",
	];
	const opts = {};
	for (const n of optNames) {
		const ck = importForm[n];
		if (ck && ck.checked) opts[n] = 1;
	}

	// Data-import specific options
	if (importForm.dataset.importType === "data") {
		opts.format = importForm.format?.value ?? "csv";
		opts.allowed_nulls = [];
		const nullInputs = importForm.querySelectorAll(
			"input[name^='allowed_nulls']",
		);
		nullInputs.forEach((inp) => {
			if (inp.checked) opts.allowed_nulls.push(inp.value);
		});
		opts.bytea_encoding = importForm.bytea_encoding?.value || "hex";
	}

	console.log("Import options:", opts, `Compress chunks: ${compressChunks}`);

	// UI elements
	const importUI = el("importUI");
	const importProgress = el("importProgress");
	const importStatus = el("importStatus");
	const importLog = el("importLog");
	const importStopBtn = el("importStopBtn");
	const importTitle = el("importTitle");
	if (importTitle) importTitle.textContent = file.name;

	if (importUI) importUI.style.display = "block";
	if (importLog) importLog.textContent = "";

	let stopRequested = false;

	// Wire up stop button
	if (importStopBtn) {
		importStopBtn.style.display = "inline-block";
		importStopBtn.disabled = false;

		importStopBtn.onclick = () => {
			stopRequested = true;
			importStopBtn.disabled = true;
			if (importStatus) {
				importStatus.textContent += " — Stopping...";
			}
		};
	}

	// Chunk size from config attrs
	const chunkAttr = fileInput.dataset
		? fileInput.dataset.importChunkSize
		: null;
	const chunkSize = (chunkAttr && parseInt(chunkAttr, 10)) || 5 * 1024 * 1024;

	let totalRetries = 0;
	let offset = 0;
	let bytesRead = 0; // track compressed input bytes for progress
	let remainder = ""; // server-provided incomplete tail
	//const skipInput = document.querySelector("input[name='skip_statements']");

	let decompressor = createUniversalDecompressStream(magic);
	let decompressionError = null;
	let decompressionFinished = false;

	// Buffering queue for decompressed bytes (list of Uint8Array)
	const chunkQueue = [];
	let queueLen = 0;

	// Push decompressed data into queue
	decompressor.ondata = (chunk, final) => {
		chunkQueue.push(chunk);
		queueLen += chunk.length;
		if (final) decompressionFinished = true;
	};
	decompressor.onerror = (err) => {
		decompressionError = err;
		decompressionFinished = true;
	};

	// Helper: consume N bytes from chunkQueue and return single Uint8Array
	function readFromQueue(n) {
		if (n === 0) return new Uint8Array(0);
		let out = new Uint8Array(n);
		let pos = 0;
		while (pos < n && chunkQueue.length > 0) {
			const head = chunkQueue[0];
			const take = Math.min(n - pos, head.length);
			out.set(head.subarray(0, take), pos);
			pos += take;
			if (take === head.length) {
				chunkQueue.shift();
			} else {
				chunkQueue[0] = head.subarray(take);
			}
		}
		queueLen -= pos;
		return out.subarray(0, pos);
	}

	// Backpressure thresholds
	const highWaterMark = chunkSize * 1;

	// send single payload to server (prepends remainder and handles compression/hash)
	// Includes automatic retry with exponential backoff
	async function sendPayload(payload, isFinal = false) {
		let payloadToSend = payload;
		let chunkCompressed = false;
		if (compressChunks && payload.length > 0) {
			payloadToSend = gzipSync(payload);
			chunkCompressed = true;
		}
		const chunkHash = fnv1a64(payloadToSend);

		const params = new URLSearchParams();
		params.set("offset", String(offset));
		params.set(
			"remainder_len",
			String(remainder ? utf8Encode(remainder).length : 0),
		);
		if (chunkCompressed) params.set("compressed", "1");
		if (isFinal) params.set("eof", "1");
		params.set("import_session_id", importSessionId);
		params.set("chunk_hash", chunkHash);
		for (const [k, v] of Object.entries(opts)) {
			if (Array.isArray(v)) {
				v.forEach((val) => params.append(`${k}[]`, String(val)));
				continue;
			} else {
				params.set(k, String(v));
			}
		}
		if (scope) params.set("scope", scope);
		if (scopeIdent) params.set("scope_ident", scopeIdent);

		const urlBase = importForm.dataset.action;
		const url = appendServerToUrl(
			`${urlBase}?action=process_chunk&${params.toString()}`,
		);

		// Retry loop with exponential backoff
		let retryCount = 0;
		const maxRetryDelay = 30000; // max 30 seconds between retries
		while (true) {
			if (stopRequested) {
				logImport("Upload stopped by user.", "warning");
				throw new Error("Upload stopped by user");
			}

			try {
				const resp = await fetch(url, {
					method: "POST",
					body: payloadToSend,
					headers: { "Content-Type": "application/octet-stream" },
				});
				if (!resp.ok) {
					const text = await resp.text();
					throw new Error(`Server error (${resp.status}): ${text}`);
				}
				const res = await resp.json();

				if (res.error && res.error.includes("checksum")) {
					// Checksum error - retry
					throw new Error(`Checksum mismatch: ${res.error}`);
				}

				// Success - break out of retry loop
				if (retryCount > 0) {
					console.log(
						`Chunk sent successfully after ${retryCount} retries`,
					);
				}
				return res;
			} catch (err) {
				retryCount++;
				totalRetries++;

				// Determine if this is a network error or server error
				const isNetworkError =
					err.name === "TypeError" ||
					err.message.includes("Failed to fetch") ||
					err.message.includes("NetworkError") ||
					err.message.includes("checksum");

				const errorType = isNetworkError
					? "Network/checksum"
					: "Server";

				// Exponential backoff: 1s, 2s, 4s, 8s, 16s, 30s (capped)
				const delay = Math.min(
					1000 * Math.pow(2, Math.min(retryCount - 1, 5)),
					maxRetryDelay,
				);

				const msg = `${errorType} error: ${
					err.message
				}. Retrying in ${Math.round(
					delay / 1000,
				)}s (attempt ${retryCount})...`;
				console.warn(msg);

				logImport(`RETRY: ${msg}`);

				if (importStatus) {
					importStatus.textContent = `${errorType} error - retrying (${retryCount})...`;
				}

				// Wait before retry, but check stopRequested periodically
				for (let i = 0; i < delay; i += 100) {
					if (stopRequested) {
						logImport("Upload stopped by user.", "warning");
						throw new Error("Upload stopped by user during retry");
					}
					await new Promise((r) => setTimeout(r, 100));
				}
				// Loop continues to retry
			}
		}
	}

	// Process server response
	async function processResponse(res, payload) {
		if (typeof res.remainder === "string") {
			remainder = res.remainder;
		} else {
			remainder = "";
		}
		if (typeof res.offset === "number") {
			offset = res.offset;
		} else {
			offset += payload.length; // fallback
		}

		// forward logs to UI
		if (Array.isArray(res.logEntries)) {
			for (const e of res.logEntries) {
				const msg = e.message || e.statement || "";
				if (msg && typeof msg === "string") {
					logImport(msg, e.type || "info", e.time, e);
				}
			}
		}

		// Check if server requests stop (SQL errors with stop_on_error enabled)
		if (res.stop === true) {
			if (importStopBtn) importStopBtn.style.display = "none";
			stopRequested = true;
			logImport(
				"Import halted by server due to SQL errors (stop_on_error enabled) or a fatal error.",
				"error",
			);
			throw new Error(
				"Import stopped due to SQL errors (stop_on_error enabled) or a fatal error.",
			);
		}
	}

	// Process queue: accumulate until chunkSize and send sequentially
	async function processQueue() {
		while (true) {
			// Wait for data unless decompression finished
			while (queueLen < 1 && !decompressionFinished) {
				await new Promise((r) => setTimeout(r, 20));
			}

			// Nothing left to send
			if (decompressionFinished && queueLen === 0) {
				return;
			}

			// Send either a full chunk or the final partial tail
			while (
				queueLen >= chunkSize ||
				(decompressionFinished && queueLen > 0)
			) {
				const toTake = queueLen >= chunkSize ? chunkSize : queueLen;
				const isFinalSend =
					decompressionFinished && queueLen <= chunkSize;
				const chunk = readFromQueue(toTake);
				const remBytes = remainder
					? utf8Encode(remainder)
					: new Uint8Array(0);
				let payload = chunk;
				if (remBytes.length) {
					payload = new Uint8Array(remBytes.length + chunk.length);
					payload.set(remBytes, 0);
					payload.set(chunk, remBytes.length);
				}
				const res = await sendPayload(payload, isFinalSend);
				await processResponse(res, payload);
				if (importProgress)
					importProgress.value = Math.min(
						100,
						Math.floor((bytesRead / file.size) * 100),
					);
				if (importStatus)
					importStatus.textContent = `Processed ${formatBytes(
						bytesRead,
					)} / ${formatBytes(file.size)}`;
			}

			// Backpressure to avoid runaway queue growth during long decompression
			if (!decompressionFinished && queueLen > highWaterMark) {
				while (queueLen > chunkSize) {
					await new Promise((r) => setTimeout(r, 50));
				}
			}

			// Small wait to yield if we have a partial chunk but not done decompressing
			if (
				!decompressionFinished &&
				queueLen > 0 &&
				queueLen < chunkSize
			) {
				await new Promise((r) => setTimeout(r, 20));
			}
		}
	}

	try {
		// Start reader + processing
		const reader = file.stream().getReader();
		let readerErr = null;
		const queueProcessor = processQueue();
		try {
			while (true) {
				const { value, done } = await reader.read();
				if (stopRequested) {
					logImport("Upload stopped by user.", "warning");
					reader.cancel();
					return;
				}
				if (value) bytesRead += value.length;
				await decompressor.push(value || new Uint8Array(), done);
				while (queueLen > highWaterMark) {
					await new Promise((r) => setTimeout(r, 50));
				}
				if (done) break;
			}
		} catch (err) {
			readerErr = err;
			decompressionError = err;
		} finally {
			await queueProcessor;
			if (readerErr) throw readerErr;
			if (decompressionError)
				throw new Error(
					`Decompression failed: ${decompressionError.message}`,
				);
		}

		// Upload completed successfully
		let msg = "Upload completed successfully.";
		if (totalRetries > 0) {
			msg += ` (${totalRetries} total retries were needed)`;
		}
		logImport(msg, "success");
		console.log(msg);
	} catch (err) {
		console.error("Upload failed:", err);
		logImport(`Upload failed: ${err.message}`, "error");
		if (importStatus)
			importStatus.textContent = `Upload failed: ${err.message}`;
	} finally {
		if (importStopBtn) importStopBtn.style.display = "none";
	}
}

document.addEventListener("frameLoaded", () => {
	// Wire up start button
	const btn = el("importStart");
	if (!btn) return;
	btn.addEventListener("click", (ev) => {
		ev.preventDefault();
		startStreamUpload();
	});
});
