// Lightweight DOM utilities

// Shortcut for getElementById
export const el = (id) => document.getElementById(id);

// Query selector (single element)
export const qs = (sel, root = document) => root.querySelector(sel);

// Query selector (multiple elements, returned as array)
export const qsa = (sel, root = document) =>
	Array.from(root.querySelectorAll(sel));

// Event helper
export const on = (target, event, handler, opts) =>
	target.addEventListener(event, handler, opts);

// Value helper (safe input value getter)
export const val = (id) => el(id)?.value ?? "";

// Class helpers
export const addClass = (el, cls) => el.classList.add(cls);
export const removeClass = (el, cls) => el.classList.remove(cls);
export const toggleClass = (el, cls) => el.classList.toggle(cls);

// Visibility helpers
export const show = (el) => (el.style.display = "");
export const hide = (el) => (el.style.display = "none");

// Utility helpers for import module
const FNV_OFFSET_BASIS = BigInt("0xcbf29ce484222325");
const FNV_PRIME = BigInt("0x100000001b3");
const FNV_MASK = BigInt("0xffffffffffffffff");
const FNV_TABLE = Array.from({ length: 256 }, (_, i) => BigInt(i));

export const fnv1a64 = (buf) => {
	let hash = FNV_OFFSET_BASIS;
	for (let i = 0; i < buf.length; i++) {
		hash ^= FNV_TABLE[buf[i]];
		hash = (hash * FNV_PRIME) & FNV_MASK;
	}
	return hash.toString(16).padStart(16, "0");
};

export const formatBytes = (bytes) => {
	if (bytes === 0) return "0 B";
	const k = 1024;
	const sizes = ["B", "KB", "MB", "GB", "TB"];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + " " + sizes[i];
};

export const detectZipSignature = (bytes) => {
	return (
		bytes.length >= 4 &&
		bytes[0] === 0x50 &&
		bytes[1] === 0x4b &&
		((bytes[2] === 0x03 && bytes[3] === 0x04) ||
			(bytes[2] === 0x05 && bytes[3] === 0x06) ||
			(bytes[2] === 0x07 && bytes[3] === 0x08))
	);
};

export const sniffMagicType = async (file) => {
	try {
		const blob = file.slice(0, 8);
		let buf;
		if (blob.arrayBuffer) {
			buf = await blob.arrayBuffer();
		} else {
			buf = await new Promise((resolve, reject) => {
				const reader = new FileReader();
				reader.onload = () => resolve(reader.result);
				reader.onerror = () => reject(reader.error);
				reader.readAsArrayBuffer(blob);
			});
		}
		const bytes = new Uint8Array(buf || []);
		if (bytes.length >= 2 && bytes[0] === 0x1f && bytes[1] === 0x8b)
			return "gzip";
		if (
			bytes.length >= 3 &&
			bytes[0] === 0x42 &&
			bytes[1] === 0x5a &&
			bytes[2] === 0x68
		)
			return "bzip2";
		if (detectZipSignature(bytes)) return "zip";
		return bytes.length > 0 ? "plain" : "unknown";
	} catch (e) {
		return "unknown";
	}
};

export const getServerCaps = (fileInput) => {
	const ds = fileInput && fileInput.dataset ? fileInput.dataset : {};
	return {
		gzip: ds.capGzip === "1",
		zip: ds.capZip === "1",
		bzip2: ds.capBzip2 === "1",
	};
};

/*
export const logImport = (msg, type, time) => {
	const importLog = el("importLog");
	if (!importLog) return;
	let timeMs;
	if (typeof time === "number") {
		timeMs = time > 1e11 ? time : time * 1000;
	} else {
		timeMs = Date.now();
	}
	if (!type) type = "info";
	const line = `[${new Date(
		timeMs
	).toISOString()}] ${type.toLowerCase()}: ${msg}`;
	const span = document.createElement("span");
	span.textContent = line;
	span.className = `log-${type.toLowerCase()}`;
	importLog.appendChild(span);
	const br = document.createElement("br");
	importLog.appendChild(br);
	//importLog.textContent += line + "\n";
	importLog.scrollTop = importLog.scrollHeight;
	console.log(msg);
};
*/

export const logImport = (msg, type = "info", time, entry) => {
	const importLog = el("importLog");
	if (!importLog) return;

	if (type == "streaming_summary") {
		console.log(entry);
		return;
	}

	let timeMs =
		typeof time === "number"
			? time > 1e11
				? time
				: time * 1000
			: Date.now();

	//const ts = new Date(timeMs).toISOString();

	const wrapper = document.createElement("div");
	wrapper.className = `log-line log-${type}`;

	// Timestamp
	const tsSpan = document.createElement("span");
	tsSpan.className = "log-ts";
	//tsSpan.textContent = `[${ts}] `;

	const dt = new Date(timeMs);

	const dateStr = dt.toLocaleDateString("sv-SE");
	const timeStr = dt.toLocaleTimeString("sv-SE", { hour12: false });

	const offsetMin = dt.getTimezoneOffset();
	const sign = offsetMin <= 0 ? "+" : "-";
	const hh = String(Math.floor(Math.abs(offsetMin) / 60)).padStart(2, "0");
	const offset = `${sign}${hh}`;

	tsSpan.innerHTML =
		`[<span class="log-date">${dateStr}</span>` +
		` <span class="log-time">${timeStr}</span>` +
		`<span class="log-tz">${offset}</span>] `;

	wrapper.appendChild(tsSpan);

	// Content
	const contentSpan = document.createElement("span");
	contentSpan.className = `log-content log-${type}`;

	// Level
	const levelSpan = document.createElement("span");
	levelSpan.className = `log-level log-${type}`;
	levelSpan.textContent = `${type}: `;
	contentSpan.appendChild(levelSpan);

	// Message
	const msgSpan = document.createElement("span");
	msgSpan.className = "log-msg";
	msgSpan.textContent = msg;
	contentSpan.appendChild(msgSpan);

	wrapper.appendChild(contentSpan);

	importLog.appendChild(wrapper);
	importLog.scrollTop = importLog.scrollHeight;
};

export function appendServerToUrl(url) {
	const SERVER = el("importForm").server.value;
	const DATABASE = el("importForm").database?.value;
	const SCHEMA = el("importForm").schema?.value;
	url += url.indexOf("?") === -1 ? "?" : "&";
	url += "server=" + encodeURIComponent(SERVER);
	if (DATABASE) url += "&database=" + encodeURIComponent(DATABASE);
	if (SCHEMA) url += "&schema=" + encodeURIComponent(SCHEMA);
	return url;
}
