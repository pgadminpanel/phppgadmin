//#region IndexedDB Manager for POST result caching

class PostCacheDB {
	constructor() {
		this.dbName = "phpPgAdminCache";
		this.storeName = "postResults";
		this.dbVersion = 1;
		this.db = null;
		this.initPromise = this.init();
	}

	async init() {
		return new Promise((resolve, reject) => {
			const request = indexedDB.open(this.dbName, this.dbVersion);

			request.onerror = () => reject(request.error);
			request.onsuccess = () => {
				this.db = request.result;
				resolve(this.db);
			};

			request.onupgradeneeded = (event) => {
				const db = event.target.result;
				if (!db.objectStoreNames.contains(this.storeName)) {
					const store = db.createObjectStore(this.storeName, {
						keyPath: "key",
					});
					// Index by sessionId for efficient cleanup
					store.createIndex("sessionId", "sessionId", {
						unique: false,
					});
					// Index by timestamp for age-based cleanup
					store.createIndex("timestamp", "timestamp", {
						unique: false,
					});
				}
			};
		});
	}

	getCookie(name) {
		const match = document.cookie.match(
			new RegExp("(^| )" + name + "=([^;]+)"),
		);
		return match ? match[2] : null;
	}

	getSessionId() {
		return this.getCookie("PPA_ID") || "unknown";
	}

	async set(key, value) {
		await this.initPromise;
		return new Promise((resolve, reject) => {
			const transaction = this.db.transaction(
				[this.storeName],
				"readwrite",
			);
			const store = transaction.objectStore(this.storeName);
			const data = {
				key: key,
				value: value,
				sessionId: this.getSessionId(),
				timestamp: Date.now(),
			};
			const request = store.put(data);

			request.onerror = () => reject(request.error);
			request.onsuccess = () => resolve();
		});
	}

	async get(key) {
		await this.initPromise;
		return new Promise((resolve, reject) => {
			const transaction = this.db.transaction(
				[this.storeName],
				"readonly",
			);
			const store = transaction.objectStore(this.storeName);
			const request = store.get(key);

			request.onerror = () => reject(request.error);
			request.onsuccess = () => {
				const result = request.result;
				if (result) {
					// Verify the entry belongs to current session
					if (result.sessionId === this.getSessionId()) {
						resolve(result.value);
					} else {
						// Entry from different session - ignore it
						resolve(null);
					}
				} else {
					resolve(null);
				}
			};
		});
	}

	async delete(key) {
		await this.initPromise;
		return new Promise((resolve, reject) => {
			const transaction = this.db.transaction(
				[this.storeName],
				"readwrite",
			);
			const store = transaction.objectStore(this.storeName);
			const request = store.delete(key);

			request.onerror = () => reject(request.error);
			request.onsuccess = () => resolve();
		});
	}

	async cleanupOldEntries(maxAgeMs = 24 * 60 * 60 * 1000) {
		// Default: 24 hours
		await this.initPromise;
		return new Promise((resolve, reject) => {
			const transaction = this.db.transaction(
				[this.storeName],
				"readwrite",
			);
			const store = transaction.objectStore(this.storeName);
			const index = store.index("timestamp");
			const cutoffTime = Date.now() - maxAgeMs;

			// Get all entries older than cutoff
			const range = IDBKeyRange.upperBound(cutoffTime);
			const request = index.openCursor(range);
			let deletedCount = 0;

			request.onerror = () => reject(request.error);
			request.onsuccess = (event) => {
				const cursor = event.target.result;
				if (cursor) {
					cursor.delete();
					deletedCount++;
					cursor.continue();
				} else {
					console.log(
						`Cleaned up ${deletedCount} old entries from IndexedDB`,
					);
					resolve(deletedCount);
				}
			};
		});
	}

	async cleanupOtherSessions() {
		await this.initPromise;
		const currentSessionId = this.getSessionId();
		return new Promise((resolve, reject) => {
			const transaction = this.db.transaction(
				[this.storeName],
				"readwrite",
			);
			const store = transaction.objectStore(this.storeName);
			const request = store.openCursor();
			let deletedCount = 0;

			request.onerror = () => reject(request.error);
			request.onsuccess = (event) => {
				const cursor = event.target.result;
				if (cursor) {
					if (cursor.value.sessionId !== currentSessionId) {
						cursor.delete();
						deletedCount++;
					}
					cursor.continue();
				} else {
					console.log(
						`Cleaned up ${deletedCount} entries from other sessions`,
					);
					resolve(deletedCount);
				}
			};
		});
	}

	async cleanup() {
		// Clean up both old entries and entries from other sessions
		try {
			await this.cleanupOldEntries(365 * 24 * 60 * 60 * 1000); // 1 year
			await this.cleanupOtherSessions();
		} catch (error) {
			console.error("Error during IndexedDB cleanup:", error);
		}
	}
}

//#endregion

//#region Frameset handler simulating frameset behavior

/** @var {PostCacheDB} postCacheDB */
function frameSetHandler(postCacheDB) {
	const isRtl = document.documentElement.getAttribute("dir") === "rtl";
	const tree = document.getElementById("tree");
	const content = document.getElementById("content");
	const contentContainer = document.getElementById("content-container");

	// Helper function to escape HTML special characters
	const escapeHtml = (text) => {
		const map = {
			"&": "&amp;",
			"<": "&lt;",
			">": "&gt;",
			'"': "&quot;",
			"'": "&#039;",
		};
		return text.replace(/[&<>"']/g, (m) => map[m]);
	};

	// Frameset simulation
	const resizer = document.getElementById("resizer");
	if (!resizer) {
		console.warn("No resizer element found!");
		return false;
	}

	let isResizing = false;

	resizer.addEventListener("mousedown", (e) => {
		e.preventDefault();
		isResizing = true;
		document.body.style.cursor = "col-resize";
	});

	document.addEventListener("mousemove", (e) => {
		if (isResizing) {
			const newWidth =
				(isRtl ? window.innerWidth - e.clientX : e.clientX) -
				resizer.offsetWidth;
			tree.style.width = newWidth + "px";
			positionLoadingIndicator();
		}
	});

	document.addEventListener("mouseup", (e) => {
		if (isResizing) {
			isResizing = false;
			document.body.style.cursor = "default";
		}
	});

	// Loading indicator

	const loadingIndicator = document.getElementById("loading-indicator");

	function positionLoadingIndicator() {
		const rect = tree.getBoundingClientRect();
		loadingIndicator.style.position = "fixed";
		loadingIndicator.style.width = rect.width + "px";
		//loadingIndicator.style.left = rect.left + "px";
	}

	positionLoadingIndicator();

	window.addEventListener("scroll", positionLoadingIndicator);
	window.addEventListener("resize", positionLoadingIndicator);

	// Link and Form interception

	async function setContent(html, opts = {}) {
		const { restoreFormStates = false, formStates = null } = opts;
		content.innerHTML = html;

		setStickyHeader();

		// Restore form state BEFORE running scripts
		// Only do this when explicitly requested (e.g. history back/forward).
		if (restoreFormStates) {
			const statesToRestore =
				formStates ?? window.history.state?.formStates ?? null;
			if (statesToRestore) {
				restoreAllFormStates(statesToRestore);
			}
		}

		// Now bring scripts to life...

		// Separate external scripts from inline scripts
		const externalScripts = [];
		const inlineScripts = [];

		content.querySelectorAll("script").forEach((oldScript) => {
			if (oldScript.src) {
				externalScripts.push(oldScript);
			} else {
				inlineScripts.push(oldScript);
			}
			oldScript.remove();
		});

		// Load all external scripts first and wait for them
		const scriptPromises = externalScripts.map((oldScript) => {
			return new Promise((resolve, reject) => {
				const newScript = document.createElement("script");
				newScript.src = oldScript.src;
				newScript.type = oldScript.type || "text/javascript";
				newScript.onload = resolve;
				newScript.onerror = reject;
				content.appendChild(newScript);
			});
		});

		// Wait for all external scripts to load, then execute inline scripts
		try {
			await Promise.all(scriptPromises);
			// Now execute inline scripts in order
			inlineScripts.forEach((oldScript) => {
				const newScript = document.createElement("script");
				newScript.textContent = oldScript.textContent;
				content.appendChild(newScript);
			});
		} catch (err) {
			console.error("Failed to load external script:", err);
			// Execute inline scripts anyway to avoid breaking the page
			inlineScripts.forEach((oldScript) => {
				const newScript = document.createElement("script");
				newScript.textContent = oldScript.textContent;
				content.appendChild(newScript);
			});
		}
	}

	/**
	 * Capture all form values from all forms in content
	 * @return {Object} Object with form indices as keys, containing all form field states
	 */
	function captureAllFormStates() {
		const allFormStates = {};
		content.querySelectorAll("form").forEach((form, index) => {
			allFormStates[index] = captureFormState(form);
		});
		return allFormStates;
	}

	/**
	 * Restore all form values from saved states
	 * @param {Object} allFormStates - The saved form states by index
	 */
	function restoreAllFormStates(allFormStates) {
		if (!allFormStates || typeof allFormStates !== "object") return;
		content.querySelectorAll("form").forEach((form, index) => {
			if (allFormStates[index]) {
				restoreFormState(form, allFormStates[index]);
			}
		});
	}

	function persistCurrentFormStatesToHistory() {
		if (!content?.querySelector("form")) return;
		const currentState =
			window.history.state && typeof window.history.state === "object"
				? { ...window.history.state }
				: {};
		currentState.formStates = captureAllFormStates();
		try {
			history.replaceState(currentState, "", window.location.href);
		} catch (e) {
			console.warn("Failed to persist form state to history", e);
		}
	}

	/**
	 * Capture all form values from a form element
	 * @param {HTMLFormElement} form - The form to capture
	 * @return {Object} Object containing all form field names and values
	 */
	function captureFormState(form) {
		const formState = {};
		if (!form) return formState;

		const formData = new FormData(form);
		for (const [key, value] of formData.entries()) {
			if (!formState[key]) {
				formState[key] = [];
			}
			formState[key].push(value);
		}

		// Also capture checkbox and radio states explicitly
		form.querySelectorAll(
			"input[type=checkbox], input[type=radio]",
		).forEach((input) => {
			if (!formState[input.name]) {
				formState[input.name] = [];
			}
			if (input.checked && !formState[input.name].includes(input.value)) {
				formState[input.name].push(input.value);
			}
		});

		// Capture select element values
		form.querySelectorAll("select").forEach((select) => {
			formState[select.name] = select.value;
		});

		// Capture textarea values
		form.querySelectorAll("textarea").forEach((textarea) => {
			formState[textarea.name] = textarea.value;
		});

		return formState;
	}

	/**
	 * Restore form values from saved state
	 * @param {HTMLFormElement} form - The form to restore
	 * @param {Object} formState - The saved form state
	 */
	function restoreFormState(form, formState) {
		if (!form || !formState) return;

		console.log("Restoring form state:", formState);

		// Restore text inputs, textareas, and selects
		form.querySelectorAll(
			"input[type=text], input[type=hidden], textarea, select",
		).forEach((field) => {
			if (formState[field.name] !== undefined) {
				if (field.tagName === "SELECT") {
					field.value = formState[field.name];
				} else if (field.tagName === "TEXTAREA") {
					field.value = formState[field.name];
				} else {
					field.value = Array.isArray(formState[field.name])
						? formState[field.name][0]
						: formState[field.name];
				}
			}
		});

		// Restore checkboxes
		form.querySelectorAll("input[type=checkbox]").forEach((checkbox) => {
			const savedValues = formState[checkbox.name];
			if (Array.isArray(savedValues)) {
				checkbox.checked = savedValues.includes(checkbox.value);
			} else if (savedValues !== undefined) {
				checkbox.checked = savedValues === checkbox.value;
			}
		});

		// Restore radio buttons
		form.querySelectorAll("input[type=radio]").forEach((radio) => {
			const savedValue = formState[radio.name];
			if (Array.isArray(savedValue)) {
				radio.checked = savedValue.includes(radio.value);
			} else if (savedValue !== undefined) {
				radio.checked = savedValue === radio.value;
			}
		});
	}

	function setStickyHeader() {
		// Check if sticky header already exists
		let stickyHeader = content.querySelector("#sticky-header");
		if (stickyHeader) {
			return;
		}
		stickyHeader = document.createElement("div");
		stickyHeader.id = "sticky-header";
		content.insertBefore(stickyHeader, content.firstChild);

		const stickyHeaderBg = document.createElement("div");
		stickyHeaderBg.id = "sticky-header-bg";
		stickyHeader.appendChild(stickyHeaderBg);

		const stickyHeaderContent = document.createElement("div");
		stickyHeaderContent.id = "sticky-header-content";
		stickyHeader.appendChild(stickyHeaderContent);

		// Collect elements to be sticky
		const stickyElements = [
			content.querySelector(".topbar"),
			content.querySelector(".trail"),
			content.querySelector(".tabs"),
		].filter(Boolean);

		// Move elements into sticky container
		stickyElements.forEach((el) => stickyHeaderContent.appendChild(el));

		const updateHeaderBgWidth = () => {
			stickyHeaderBg.style.width = "100%";
			stickyHeaderBg.style.width = content.scrollWidth - 3 + "px";
			window.setTimeout(() => {});
		};

		window.addEventListener("resize", updateHeaderBgWidth);
		document.addEventListener(
			"beforeFrameUnload",
			() => {
				window.removeEventListener("resize", updateHeaderBgWidth);
			},
			{ once: true },
		);
		updateHeaderBgWidth();

		// Get height of sticky header
		const headerHeight = stickyHeader.getBoundingClientRect().height;

		// Get sticky table header if exists
		const stickyTableHeads = content.querySelectorAll("thead.sticky-thead");
		if (stickyTableHeads.length > 0) {
			// Set top dynamically (px)
			stickyTableHeads.forEach((thead) => {
				thead.querySelectorAll("th").forEach((th) => {
					th.style.position = "sticky";
					th.style.top = headerHeight + "px";
				});
			});
		}
	}

	async function loadContent(url, options = {}, addToHistory = true) {
		if (addToHistory) {
			// Persist the current page form state so Back/Forward restores it.
			persistCurrentFormStatesToHistory();
		}

		url = url.replace(/[&?]$/, "");
		url += (url.includes("?") ? "&" : "?") + "target=content";
		console.log("Fetching:", url, options);

		// Check if this is a download request (any output starting with 'download')
		const urlObj = new URL(url, window.location.href);
		if (urlObj.searchParams.get("output")?.startsWith("download")) {
			// For actual file downloads, open in new window and let browser handle it
			console.log("Opening download in new window:", url);
			window.open(url, "_blank");
			return;
		}

		let finalUrl = null;
		let indicatorTimeout = window.setTimeout(() => {
			loadingIndicator.classList.add("show");
		}, 200);

		try {
			document.body.style.cursor = "wait";
			const res = await fetch(url, options);

			if (!res.ok) {
				// noinspection ExceptionCaughtLocallyJS
				throw new Error(`HTTP error ${res.status}`);
			}

			const contentType = res.headers.get("content-type") || "";
			const responseText = await res.text();

			// Calculate final URL from response
			const urlObj = new URL(res.url || url, window.location.href);
			urlObj.searchParams.delete("target");
			finalUrl = urlObj.toString();

			const unloadEvent = new CustomEvent("beforeFrameUnload", {
				target: content,
			});
			document.dispatchEvent(unloadEvent);

			// Render content
			if (contentType.includes("text/plain")) {
				// For plain text (dumps/exports), wrap in <pre> tag
				await setContent(`<pre>${escapeHtml(responseText)}</pre>`, {
					restoreFormStates: !addToHistory,
				});
			} else {
				// For HTML, parse as normal
				await setContent(responseText, {
					restoreFormStates: !addToHistory,
				});
			}

			if (addToHistory) {
				// Build history state
				const state = {};

				// For POST requests, cache HTML in IndexedDB
				if (/post/i.test(options.method ?? "")) {
					const compressed = LZString.compressToUTF16(responseText);
					const storageKey =
						"post_" +
						Date.now() +
						"_" +
						Math.random().toString(36).substring(2, 11);
					try {
						await postCacheDB.set(storageKey, compressed);
						state.storageKey = storageKey;
					} catch (e) {
						// Fallback if IndexedDB fails
						console.warn(
							"IndexedDB storage failed, storing in history state",
							e,
						);
						state.htmlLz = compressed;
					}
				}

				// Capture form states from the newly loaded content
				if (content.querySelector("form")) {
					state.formStates = captureAllFormStates();
				}

				history.pushState(state, "", finalUrl);

				// Scroll back to the top
				content.scrollTo(0, 0);
			}

			const loadedEvent = new CustomEvent("frameLoaded", {
				detail: { url: finalUrl },
				target: content,
			});
			document.dispatchEvent(loadedEvent);
		} catch (err) {
			console.error("Error:", err);
			window.alert(err);
		} finally {
			document.body.style.cursor = "default";
			loadingIndicator.classList.remove("show");
			window.clearTimeout(indicatorTimeout);
		}
	}

	let lastSubmitButton = null;

	document.addEventListener("click", (e) => {
		if (e.target.matches("input[type=submit], button[type=submit]")) {
			lastSubmitButton = e.target;
			return;
		}
		lastSubmitButton = null;

		const target = e.target.closest("a");
		if (!target) {
			return;
		}

		const url = new URL(target.href, window.location.origin);
		if (target.target || url.host !== window.location.host) {
			// Ignore external links
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		if (target.href === window.location.href + "#") {
			// Emulate scroll top
			if (target.classList.contains("bottom_link")) {
				content.scrollTo({
					top: 0,
					left: 0,
					behavior: "smooth",
				});
			}
			return;
		}

		if (target.href.startsWith("javascript")) {
			return;
		}

		console.log("Intercepted link:", target.href);
		return loadContent(target.href);
	});

	document.addEventListener("submit", (e) => {
		//console.log("Check:", e);

		const form = e.target;
		if (!form.matches("form")) return;

		e.preventDefault();
		console.log("Intercepted form:", form);

		const action = form.getAttribute("action");
		const method = form.getAttribute("method") || "GET";
		const post = /post/i.test(method);

		const formData = new FormData(form);

		const submitter = e.submitter || lastSubmitButton;
		if (submitter && submitter.name) {
			formData.append(submitter.name, submitter.value);
		}
		lastSubmitButton = null;

		const url = new URL(action, window.location.href);
		const params = new URLSearchParams(url.search);

		if (post) {
			// add hidden input fields and important form fields to search query
			const hiddenInputs = form.querySelectorAll("input[type=hidden]");
			hiddenInputs.forEach((input) => {
				if (input.name) {
					if (!/^(loginServer|action)$/.test(input.name)) {
						if (!input.dataset.hideFromUrl) {
							params.append(input.name, input.value);
						}
					}
					//formData.delete(input.name);
				}
			});
		} else {
			// add complete form to search query
			for (const [key, value] of formData.entries()) {
				params.append(key, value);
			}
		}

		url.search = params.toString();

		if (post) {
			return loadContent(url.toString(), {
				method: method,
				body: formData,
			});
		} else {
			return loadContent(url.toString());
		}
	});

	window.addEventListener("popstate", async (e) => {
		const url = window.location.href;

		let htmlLz = null;
		if (e.state?.storageKey) {
			// Cached content found in IndexedDB
			htmlLz = await postCacheDB.get(e.state.storageKey);
		} else if (e.state && e.state.htmlLz) {
			// Fallback: direct HTML in state (for storage failure case)
			htmlLz = e.state.htmlLz;
		}

		if (htmlLz) {
			const cachedHtml = LZString.decompressFromUTF16(htmlLz);
			await setContent(cachedHtml, {
				restoreFormStates: true,
				formStates: e.state?.formStates,
			});
			// Scroll back to the top
			content.scrollTo(0, 0);
			// Dispatch frame loaded event
			const event = new CustomEvent("frameLoaded", {
				detail: { url: url },
				target: content,
			});
			document.dispatchEvent(event);
			return;
		}

		// No cached content, fetch fresh (for GET requests)
		await loadContent(url, {}, false);
	});

	window.addEventListener("message", (event) => {
		console.log("Received message:", event.data);

		if (event.origin !== window.location.origin) {
			console.warn(
				"Origin mismatch:",
				event.origin,
				window.location.origin,
			);
			return;
		}

		const { type, payload } = event.data;

		if (type === "formSubmission") {
			//loadContent(payload.url);
			if (payload.post) {
				const formData = new FormData();
				for (const [key, value] of Object.entries(payload.data)) {
					formData.append(key, value);
				}
				return loadContent(payload.url, {
					method: payload.method,
					body: formData,
				});
			} else {
				return loadContent(payload.url);
			}
		} else if (type === "linkNavigation") {
			return loadContent(payload.url);
		}
	});

	setStickyHeader();

	return true;
}

//#endregion

//#region Popup handler intecepting form submissions and links

function popupHandler() {
	document.addEventListener("submit", (e) => {
		const form = e.target;
		if (!form.matches("form")) return;

		// We lost the popup reference, lets create a new one again
		if (!window.opener) return;

		if (form.getAttribute("target") != "detail") {
			// Intercept only frameset forms
			return;
		}

		e.preventDefault();
		console.log("Intercepted form:", form);

		const action = form.getAttribute("action");
		let method = form.getAttribute("method") || "GET";
		const post = /post/i.test(method);

		const formData = new FormData(form);

		const url = new URL(action, window.location.href);
		const params = new URLSearchParams(url.search);

		if (post) {
			// add hidden input fields to search query
			const inputs = form.querySelectorAll("input,textarea,select");
			inputs.forEach((input) => {
				if (!input.name) return;
				if (input.type == "hidden" && !/^(action)$/.test(input.name)) {
					if (!input.dataset.hideFromUrl) {
						params.append(input.name, input.value);
					}
				} else if (input.dataset.useInUrl) {
					params.append(input.name, input.value);
				}
			});
		} else {
			// add complete form to search query
			for (const [key, value] of formData.entries()) {
				params.append(key, value);
			}
		}

		url.search = params.toString();

		window.opener.postMessage(
			{
				type: "formSubmission",
				payload: {
					method: method,
					post: post,
					url: url.toString(),
					data: Object.fromEntries(formData.entries()),
				},
			},
			window.opener.location.origin,
		);
	});

	document.addEventListener("click", (e) => {
		const target = e.target.closest("a");
		if (!target) {
			return;
		}

		const url = new URL(target.href, window.location.origin);
		if (url.host !== window.location.host) {
			// Ignore external links
			return;
		}

		if (target.href === window.location.href + "#") {
			// Emulate scroll top
			if (target.classList.contains("bottom_link")) {
				document.getElementById("content").scrollTo({
					top: 0,
					left: 0,
					behavior: "smooth",
				});
			}
			return;
		}

		if (target.href.startsWith("javascript")) {
			return;
		}

		if (target.target != "detail") {
			// Intercept only frameset links
			return;
		}

		// We lost the popup reference, lets create a new one again
		if (!window.opener) return;

		e.preventDefault();
		e.stopPropagation();

		console.log("Intercepted link:", target.href);

		window.opener.postMessage(
			{
				type: "linkNavigation",
				payload: {
					url: target.href,
				},
			},
			window.opener.location.origin,
		);
	});

	return true;
}

//#endregion

// Initialize frameset or popup handler

(function () {
	// Initialize the cache DB
	const postCacheDB = new PostCacheDB();

	// Try to initialize frameset handler, if not possible, fallback to popup handler
	frameSetHandler(postCacheDB) || popupHandler();

	const content = document.getElementById("content");

	document.addEventListener("DOMContentLoaded", (e) => {
		// dispatch virtual frame event
		const event = new CustomEvent("frameLoaded", {
			detail: { url: window.location.href },
			target: content,
		});
		document.dispatchEvent(event);
	});

	// Run cleanup on page load to remove old entries and entries from other sessions
	postCacheDB
		.cleanup()
		.catch((err) =>
			console.error("Failed to cleanup IndexedDB cache:", err),
		);
})();
