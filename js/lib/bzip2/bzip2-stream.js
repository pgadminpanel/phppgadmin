// bzip2-stream.js
import createModule from "./bzip2.js";

// Singleton for the Emscripten module
let bzip2ModulePromise = null;

/**
 * Loads the Bzip2 WASM module once (Singleton)
 * @returns {Promise<Module>}
 */
export async function getBzip2Module() {
	if (!bzip2ModulePromise) {
		// default export is async function Module(...)
		bzip2ModulePromise = createModule();
	}
	return bzip2ModulePromise;
}
