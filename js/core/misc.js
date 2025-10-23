(function () {
	//#region Miscellaneous Functions

	// Multi-form toggle
	window.toggleAllMf = function (bool) {
		var inputs = document
			.getElementById("multi_form")
			.getElementsByTagName("input");

		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].type == "checkbox") inputs[i].checked = bool;
		}
		return false;
	};

	/* SQL Quoting Helpers */
	window.pgQuoteIdent = function (ident) {
		return '"' + String(ident).replace(/"/g, '""') + '"';
	};

	window.pgQuoteLiteral = function (value) {
		if (value === null || value === undefined) {
			return "NULL";
		}
		return "'" + String(value).replace(/'/g, "''") + "'";
	};

	/**
	 * SQL query extractor with multibyte-safe iteration and dollar-quoted strings
	 *
	 * @param {string} sql
	 * @returns {string[]}
	 */
	window.extractSqlQueries = function (sql) {
		const queries = [];
		const len = sql.length;
		let current = "";
		let i = 0;

		let inSingle = false; // '
		let inDouble = false; // "
		let inDollar = false; // $tag$ ... $tag$
		let dollarTag = ""; // full tag like $tag$
		let inLineComment = false; // --
		let blockDepth = 0; // nested /* ... */

		while (i < len) {
			const ch = sql[i];
			const next = i + 1 < len ? sql[i + 1] : null;

			// ------------------------------
			// Line comment --
			// ------------------------------
			if (inLineComment) {
				current += ch;
				if (ch === "\n" || ch === "\r") {
					inLineComment = false;
				}
				i++;
				continue;
			}

			// ------------------------------
			// Block comment /* ... */
			// ------------------------------
			if (blockDepth > 0) {
				if (ch === "/" && next === "*") {
					blockDepth++;
					current += "/*";
					i += 2;
					continue;
				}
				if (ch === "*" && next === "/") {
					blockDepth--;
					current += "*/";
					i += 2;
					continue;
				}
				current += ch;
				i++;
				continue;
			}

			// ------------------------------
			// Dollar-quoted string
			// ------------------------------
			if (inDollar) {
				const tagLen = dollarTag.length;
				const substr = sql.substring(i, i + tagLen);
				if (substr === dollarTag) {
					current += dollarTag;
					i += tagLen;
					inDollar = false;
					dollarTag = "";
					continue;
				}
				current += ch;
				i++;
				continue;
			}

			// ------------------------------
			// Single-quoted string
			// ------------------------------
			if (inSingle) {
				if (ch === "'" && next === "'") {
					current += "''";
					i += 2;
					continue;
				}
				if (ch === "'") {
					inSingle = false;
					current += ch;
					i++;
					continue;
				}
				current += ch;
				i++;
				continue;
			}

			// ------------------------------
			// Double-quoted identifier
			// ------------------------------
			if (inDouble) {
				if (ch === '"' && next === '"') {
					current += '""';
					i += 2;
					continue;
				}
				if (ch === '"') {
					inDouble = false;
					current += ch;
					i++;
					continue;
				}
				current += ch;
				i++;
				continue;
			}

			// ------------------------------
			// Detect starts (not inside string/comment/dollar)
			// ------------------------------

			// line comment --
			if (ch === "-" && next === "-") {
				inLineComment = true;
				current += "--";
				i += 2;
				continue;
			}

			// block comment /*
			if (ch === "/" && next === "*") {
				blockDepth = 1;
				current += "/*";
				i += 2;
				continue;
			}

			// dollar-quote start: $tag$
			if (ch === "$") {
				const rest = sql.substring(i);
				const m = rest.match(/^\$[A-Za-z0-9_]*\$/);
				if (m) {
					dollarTag = m[0];
					inDollar = true;
					current += dollarTag;
					i += dollarTag.length;
					continue;
				}
			}

			// single quote start
			if (ch === "'") {
				inSingle = true;
				current += ch;
				i++;
				continue;
			}

			// double quote start
			if (ch === '"') {
				inDouble = true;
				current += ch;
				i++;
				continue;
			}

			// semicolon ends statement
			if (ch === ";") {
				const trimmed = current.trim();
				if (trimmed !== "") {
					queries.push(trimmed);
				}
				current = "";
				i++;
				continue;
			}

			// normal char
			current += ch;
			i++;
		}

		const trimmed = current.trim();
		if (trimmed !== "") {
			queries.push(trimmed);
		}

		return queries;
	};

	/**
	 * Return true if the given single SQL statement is read-only
	 * (no DB modifications / side effects).
	 * Conservative: returns false when unsure.
	 *
	 * @param {string} sql
	 * @returns {boolean}
	 */
	window.isReadOnlyQuery = function (sql) {
		if (!sql || typeof sql !== "string") return false;

		// trim and remove leading comments (single-line and block)
		let s = sql.trim();
		if (s === "") return false;
		s = s.replace(/^\s*(--[^\n]*\n|\/\*[\s\S]*?\*\/\s*)+/s, "").trim();
		if (s === "") return false;

		const firstToken = (str) => {
			const m = str.trim().match(/^([A-Za-z_]+)/);
			return m ? m[1].toUpperCase() : "";
		};

		// helper: determine if a statement is SELECT-like
		// (SELECT, VALUES, TABLE or WITH whose main is SELECT-like)
		const isSelectLike = (stmt) => {
			if (!stmt || typeof stmt !== "string") return false;
			const t = stmt.trim();
			if (/^\s*(SELECT|VALUES|TABLE)\b/i.test(t)) return true;

			// handle WITH ... find main token after CTE list
			// (simple but robust)
			if (/^\s*WITH\b/i.test(t)) {
				// try to find the main token after the CTE list by
				// scanning parentheses and quotes/dollar
				const len = t.length;
				let i = 0;
				const mWith = t.match(/^\s*WITH\b/i);
				if (mWith) i = mWith[0].length;
				let depth = 0;
				let inSingle = false,
					inDouble = false,
					inDollar = false;
				let dollarTag = "";
				for (; i < len; i++) {
					const ch = t[i];
					// dollar-quote handling
					if (!inSingle && !inDouble && ch === "$") {
						const rest = t.slice(i);
						const m = rest.match(/^\$[A-Za-z0-9_]*\$/);
						if (m) {
							const tag = m[0];
							if (!inDollar) {
								inDollar = true;
								dollarTag = tag;
								i += tag.length - 1;
								continue;
							} else if (tag === dollarTag) {
								inDollar = false;
								dollarTag = "";
								i += tag.length - 1;
								continue;
							}
						}
					}
					if (inDollar) continue;
					if (ch === "'" && !inDouble) {
						inSingle = !inSingle;
						continue;
					}
					if (ch === '"' && !inSingle) {
						inDouble = !inDouble;
						continue;
					}
					if (inSingle || inDouble) continue;
					if (ch === "(") {
						depth++;
						continue;
					}
					if (ch === ")") {
						if (depth > 0) depth--;
						continue;
					}
					if (ch === ";" && depth === 0) break;
					if (
						depth === 0 &&
						ch !== " " &&
						ch !== "\t" &&
						ch !== "\n" &&
						ch !== ","
					) {
						const rest = t.slice(i);
						const m2 = rest.match(
							/^\s*(SELECT|VALUES|TABLE|INSERT|UPDATE|DELETE|MERGE|SHOW|EXPLAIN|COPY|FETCH|MOVE)\b/i,
						);
						if (m2) {
							const main = m2[1].toUpperCase();
							return /^(SELECT|VALUES|TABLE)$/i.test(main);
						}
					}
				}
				// fallback: if SELECT appears anywhere, assume select-like
				if (/\bSELECT\b/i.test(t)) return true;
				return false;
			}
			return false;
		};

		// EXPLAIN handling:
		// - EXPLAIN (with or without options) is read-only
		// - EXPLAIN ANALYZE executes the inner statement; treat as
		// read-only only if inner is SELECT-like
		if (/^\s*EXPLAIN\b/i.test(s)) {
			// detect ANALYZE presence (either as keyword or inside
			// parentheses options)
			const hasAnalyze =
				/\bANALYZE\b/i.test(s) ||
				/^\s*EXPLAIN\s*\([^\)]*\banalyze\b/i.test(s);
			if (!hasAnalyze) return true;

			// has ANALYZE -> extract inner statement after EXPLAIN and its options
			// remove leading EXPLAIN and any parenthesized options or keyword options
			// pattern: EXPLAIN ( ... ) <rest>  OR EXPLAIN <word> <word> ... <rest>
			let rest = s.replace(/^\s*EXPLAIN\b/i, "").trim();

			// if starts with '(' then strip the parenthesized options block
			if (rest.startsWith("(")) {
				// find matching closing parenthesis (no full parser, but handle nested and quotes/dollar)
				let idx = 0,
					depth = 0;
				let inS = false,
					inD = false,
					inDol = false;
				let dolTag = "";
				for (let j = 0; j < rest.length; j++) {
					const ch = rest[j];
					if (!inS && !inD && ch === "$") {
						const sub = rest.slice(j);
						const mm = sub.match(/^\$[A-Za-z0-9_]*\$/);
						if (mm) {
							const tag = mm[0];
							if (!inDol) {
								inDol = true;
								dolTag = tag;
								j += tag.length - 1;
								continue;
							} else if (tag === dolTag) {
								inDol = false;
								dolTag = "";
								j += tag.length - 1;
								continue;
							}
						}
					}
					if (inDol) continue;
					if (ch === "'" && !inD) {
						inS = !inS;
						continue;
					}
					if (ch === '"' && !inS) {
						inD = !inD;
						continue;
					}
					if (inS || inD) continue;
					if (ch === "(") {
						depth++;
					} else if (ch === ")") {
						depth--;
						if (depth === 0) {
							idx = j + 1;
							break;
						}
					}
				}
				rest = rest.slice(idx).trim();
			} else {
				// strip leading option keywords like ANALYZE, VERBOSE, COSTS, BUFFERS, TIMING, FORMAT, SUMMARY etc.
				// stop at first token that looks like a statement starter
				const tokens = rest.split(/\s+/);
				let k = 0;
				for (; k < tokens.length; k++) {
					const tk = tokens[k].toUpperCase();
					if (
						/^(ANALYZE|VERBOSE|COSTS|BUFFERS|TIMING|SUMMARY|FORMAT|SETTINGS|SILENT|COSTS|BUFFERS)$/i.test(
							tk,
						)
					)
						continue;
					break;
				}
				rest = tokens.slice(k).join(" ").trim();
			}

			// now rest should start with the inner statement; check if it's SELECT-like
			if (isSelectLike(rest)) return true;
			return false;
		}

		// Always read-only starters
		if (/^\s*(SELECT|VALUES|TABLE|SHOW|FETCH|MOVE)\b/i.test(s)) return true;

		// COPY TO is read-only (reads DB), COPY FROM is not
		if (/^\s*COPY\b[\s\S]*\bTO\b/i.test(s)) return true;
		if (/^\s*COPY\b[\s\S]*\bFROM\b/i.test(s)) return false;

		// DECLARE CURSOR ... READ ONLY
		if (/^\s*DECLARE\b/i.test(s)) {
			if (/\bCURSOR\b/i.test(s) && /\bREAD\s+ONLY\b/i.test(s))
				return true;
			return false;
		}

		// Top-level DML that modifies data -> not read-only
		if (/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE)\b/i.test(s))
			return false;

		// DDL / utility / session commands -> not read-only
		if (
			/^\s*(CREATE|ALTER|DROP|SET|RESET|VACUUM|ANALYZE|DO|CALL|LOCK|GRANT|REVOKE|CHECKPOINT)\b/i.test(
				s,
			)
		) {
			return false;
		}

		// If statement contains RETURNING it's modifying -> not read-only
		if (/\bRETURNING\b/i.test(s)) return false;

		// WITH ... need to ensure CTE bodies and main query are read-only
		if (/^\s*WITH\b/i.test(s)) {
			// simple scan: if any CTE body or main token is a write operation, return false
			// reuse isSelectLike for main detection; but also check CTE bodies for write tokens
			// crude extraction of CTE bodies: find all "AS ( ... )" occurrences at top-level
			const bodies = [];
			let i = 0,
				len = s.length;
			const mWith = s.match(/^\s*WITH\b/i);
			if (mWith) i = mWith[0].length;
			let depth = 0,
				inSingle = false,
				inDouble = false,
				inDollar = false,
				dollarTag = "";
			while (i < len) {
				const ch = s[i];
				// dollar handling
				if (!inSingle && !inDouble && ch === "$") {
					const rest = s.slice(i);
					const m = rest.match(/^\$[A-Za-z0-9_]*\$/);
					if (m) {
						inDollar = !inDollar;
						dollarTag = inDollar ? m[0] : "";
						i += m[0].length;
						continue;
					}
				}
				if (inDollar) {
					i++;
					continue;
				}
				if (ch === "'" && !inDouble) {
					inSingle = !inSingle;
					i++;
					continue;
				}
				if (ch === '"' && !inSingle) {
					inDouble = !inDouble;
					i++;
					continue;
				}
				if (inSingle || inDouble) {
					i++;
					continue;
				}
				// detect AS (
				if (s.slice(i).match(/^\s*AS\s*\(/i)) {
					// move to '('
					const asMatch = s.slice(i).match(/^\s*AS\s*\(/i);
					i += asMatch[0].length - 1; // position at '('
					// find matching ')'
					let j = i,
						pDepth = 0,
						inS = false,
						inD = false,
						inDol = false,
						dolTag = "";
					for (; j < len; j++) {
						const ch2 = s[j];
						if (!inS && !inD && ch2 === "$") {
							const rest2 = s.slice(j);
							const mm = rest2.match(/^\$[A-Za-z0-9_]*\$/);
							if (mm) {
								inDol = !inDol;
								dolTag = inDol ? mm[0] : "";
								j += mm[0].length - 1;
								continue;
							}
						}
						if (inDol) continue;
						if (ch2 === "'" && !inD) {
							inS = !inS;
							continue;
						}
						if (ch2 === '"' && !inS) {
							inD = !inD;
							continue;
						}
						if (inS || inD) continue;
						if (ch2 === "(") {
							pDepth++;
							continue;
						}
						if (ch2 === ")") {
							if (pDepth === 0) {
								// body between i+1 and j-1
								const body = s.slice(i + 1, j);
								bodies.push(body);
								i = j + 1;
								break;
							} else pDepth--;
						}
					}
					continue;
				}
				// if we reach a token that likely starts main query, break
				if (!/\s/.test(ch) && ch !== ",") break;
				i++;
			}

			// check CTE bodies for writes
			for (const b of bodies) {
				if (/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE)\b/i.test(b))
					return false;
				if (/^\s*COPY\b/i.test(b) && /\bFROM\b/i.test(b)) return false;
				if (/^\s*EXPLAIN\b/i.test(b) && /\bANALYZE\b/i.test(b))
					return false;
				if (/\bRETURNING\b/i.test(b)) return false;
			}

			// check main query: if select-like -> read-only, else not
			// find main token after last CTE closing ) by searching for first statement token
			const afterCTEs = s.slice(i).trim();
			if (isSelectLike(afterCTEs)) return true;
			return false;
		}

		// default: not read-only
		return false;
	};

	//#endregion

	//#region Date/Time Picker Functions

	/**
	 * @param {HTMLElement} element
	 * @param {Object} options
	 */
	function createDateTimePickerInternal(element, options) {
		// Check if flatpickr is already initialized
		if (element._flatpickr) {
			return;
		}

		const originalValue = element.value;
		element.value = "";

		const sharedOptions = {
			clickOpens: false,
			allowInput: true,
			disableMobile: true,
			defaultDate: originalValue || null,

			onChange: (selectedDates, dateStr, instance) => {
				const cbExpr = document.getElementById(
					"cb_expr_" + element.dataset.field,
				);
				if (cbExpr) cbExpr.checked = false;
				const cbNull = document.getElementById(
					"cb_null_" + element.dataset.field,
				);
				if (cbNull) cbNull.checked = false;
				const selFnc = document.getElementById(
					"sel_fnc_" + element.dataset.field,
				);
				if (selFnc) selFnc.value = "";
			},

			onReady: (selectedDates, dateStr, instance) => {
				element.value = originalValue;
			},
		};

		options = { ...options, ...sharedOptions };

		const fp = flatpickr(element, options);

		// Create wrapper container
		let container = document.createElement("div");
		container.classList.add("date-picker-input-container");

		// Create button
		let button = document.createElement("div");
		button.className = "date-picker-button mx-1";
		button.innerHTML = "📅";

		element.parentNode.insertBefore(container, element);

		// Move input into container
		container.appendChild(element);
		container.appendChild(button);

		button.addEventListener("click", () => {
			// Make input readonly while picker is open
			element.readOnly = true;
			fp.open();
			fp.config.onClose.push(() => {
				element.readOnly = false;
			});
		});

		element.addEventListener("click", () => fp.close());
	}

	/**
	 * Format: [+-]0001-12-11[ BC]
	 * @param {HTMLElement} element
	 */
	window.createDatePicker = function (element) {
		const options = {
			dateFormat: "Y-m-d",

			parseDate: (datestr, format) => {
				element.dataset.date = datestr;
				const clean = datestr
					.replace(/^[-+]\d{4}/, (match) => match.slice(1)) // strip sign from year
					.replace(/\s?(BC|AD)$/i, ""); // strip era
				return flatpickr.parseDate(clean, format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				let datestr = flatpickr.formatDate(date, format, locale);

				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				const match = prevDateStr.match(/\s?(BC|AD)$/i);
				if (match) {
					datestr += match[0];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	};

	/**
	 * Format: [+-]0001-12-11 19:35:00[.123456][+02][ BC]
	 * @param {HTMLElement} element
	 */
	window.createDateTimePicker = function (element) {
		const options = {
			enableTime: true,
			enableSeconds: true,
			time_24hr: true,
			dateFormat: "Y-m-d H:i:S",
			minuteIncrement: 1,
			defaultHour: 0,

			parseDate: (datestr, format) => {
				//console.log(datestr);
				// Save original string for later reconstruction
				element.dataset.date = datestr;

				// Strip sign from year, microseconds, timezone, and BC/AD suffix
				const clean = datestr
					.replace(/^([-+])(\d{4})/, "$2") // remove leading +/-
					.replace(/\.\d+/, "") // remove microseconds
					.replace(/([+-]\d{2}:?\d{2}|Z)?\s?(BC|AD)?$/i, ""); // remove tz + era

				return flatpickr.parseDate(clean.trim(), format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				//console.log(prevDateStr);
				//console.log(new Error());
				let datestr = flatpickr.formatDate(date, format, locale);

				// Reattach sign if original year had one
				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				// Reattach microseconds if present
				const microsMatch = prevDateStr.match(/\.\d+/);
				if (microsMatch) {
					datestr += microsMatch[0];
				}

				// Reattach timezone and/or BC/AD suffix if present
				const match = prevDateStr.match(
					/([+-]\d{2}(:?\d{2})?|Z)?(\s?(BC|AD))?$/,
				);
				if (match && match[1]) {
					datestr += match[1];
				}
				if (match && match[3]) {
					datestr += match[3];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	};

	/**
	 * Format: 19:35:00[.123456][+02[:00]]
	 * @param {HTMLElement} element
	 */
	window.createTimePicker = function (element) {
		const options = {
			enableTime: true,
			enableSeconds: true,
			noCalendar: true,
			time_24hr: true,
			dateFormat: "H:i:S",
			minuteIncrement: 1,
			defaultHour: 0,

			parseDate: (datestr, format) => {
				// Save original string (for offset + microseconds)
				element.dataset.time = datestr;

				// Extract time part (without offset)
				// Examples:
				// 19:35:00
				// 19:35:00.123456
				const timeOnly = datestr.match(/^\d{2}:\d{2}:\d{2}(?:\.\d+)?/);
				const clean = timeOnly ? timeOnly[0] : "00:00:00";

				return flatpickr.parseDate(clean, format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prev = element.dataset.time ?? "";
				let out = flatpickr.formatDate(date, format, locale);

				// Reattach microseconds
				const micros = prev.match(/\.\d+/);
				if (micros) {
					out += micros[0];
				}

				// Reattach offset
				const offset = prev.match(/([+-]\d{2}(?::?\d{2})?)$/);
				if (offset) {
					out += offset[1];
				}

				return out;
			},
		};

		createDateTimePickerInternal(element, options);
	};

	//#endregion

	//#region SQL Editor / Viewer Functions

	/**
	 * @param {HTMLElement} element
	 */
	function createSqlEditor(element, options = {}) {
		if (element.classList.contains("ace_editor")) {
			// Editor already created
			return;
		}
		const editorDiv = document.createElement("div");
		editorDiv.className = element.className;
		//editorDiv.style.width = textarea.style.width || "100%";
		//editorDiv.style.height = textarea.style.height || "100px";

		const hidden = document.createElement("input");
		hidden.type = "hidden";
		hidden.name = element.name;
		hidden.onchange = element.onchange;
		hidden.dataset.hideFromUrl = true;

		// copy data- attributes
		for (const [key, value] of Object.entries(element.dataset)) {
			hidden.dataset[key] = value;
		}

		element.insertAdjacentElement("afterend", editorDiv);
		editorDiv.insertAdjacentElement("afterend", hidden);
		element.remove();

		const editor = ace.edit(editorDiv);
		editor.setShowPrintMargin(false);
		editor.session.setUseWrapMode(true);

		// Set mode
		const mode = element.dataset.mode || "pgsql";
		if (mode === "tsv" || mode === "tab") {
			editor.session.setMode({
				path: "ace/mode/csv",
				splitter: "\t",
			});
		} else {
			editor.session.setMode("ace/mode/" + mode);
		}

		//editor.setTheme("ace/theme/tomorrow");
		editor.setHighlightActiveLine(false);
		editor.renderer.$cursorLayer.element.style.display = "none";
		editor.setValue(element.value || "", -1);
		editor.setReadOnly(element.hasAttribute("readonly"));
		editor.setOptions({
			enableBasicAutocompletion: true,
			enableSnippets: true,
			enableLiveAutocompletion: true,
		});

		editor.session.on("change", function () {
			hidden.value = editor.getValue();
			hidden.dispatchEvent(new Event("change"));
		});

		editor.on("blur", () => {
			editor.setHighlightActiveLine(false);
			editor.renderer.$cursorLayer.element.style.display = "none";
			hidden.dispatchEvent(new Event("blur"));
		});

		editor.on("focus", () => {
			editor.setHighlightActiveLine(true);
			editor.renderer.$cursorLayer.element.style.display = "";
			hidden.dispatchEvent(new Event("focus"));
		});

		hidden.id = element.id;
		hidden.value = editor.getValue();
		hidden.editor = editor;
		hidden.beginEdit = (content) => {
			editor.setValue(content, -1);
			editor.focus();
		};

		if (options.selected) {
			editor.selectAll();
			editor.focus();
		}

		if (element.classList.contains("auto-expand")) {
			// We resize the editor height according to content but not below
			// the height that is defined in CSS
			const lineHeight = editor.renderer.lineHeight;
			const lineCount = editor.session.getLength();
			const cssHeight = parseInt(
				getComputedStyle(editor.container).height,
				10,
			);
			const padding = 4;
			const newHeight = Math.max(
				cssHeight,
				lineCount * lineHeight + padding,
			);
			editor.container.style.height = newHeight + "px";
			editor.resize();
		}
	}

	/**
	 * @param {HTMLElement} element
	 */
	function createSqlViewer(element) {
		if (element.dataset.hljsInitialized) {
			return;
		}
		element.dataset.hljsInitialized = "1";

		const language = element.dataset.language || "pgsql";
		if (language === "plpgsql") language = "pgsql";
		element.classList.add(`language-${language}`);
		console.log("SQL Viewer language:", language);

		// Apply syntax highlighting

		hljs.highlightElement(element);

		if (language === "pgsql") {
			// Quoted identifiers
			element.innerHTML = element.innerHTML.replace(
				/"([\w.]+)"/g,
				'<span class="hljs-quoted-identifier">"$1"</span>',
			);
		}
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createSqlEditors(rootElement, options = {}) {
		rootElement.querySelectorAll(".sql-editor").forEach((element) => {
			//console.log(element);
			createSqlEditor(element, options);
		});

		const elements = Array.from(
			rootElement.querySelectorAll(".sql-viewer"),
		);
		processInIdle(elements, createSqlViewer);
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createDateAndTimePickers(rootElement) {
		rootElement
			.querySelectorAll("input[data-type^=timestamp]")
			.forEach((element) => {
				//console.log(element);
				if (!element.dataset.type.endsWith("[]")) {
					createDateTimePicker(element);
				}
			});
		rootElement
			.querySelectorAll("input[data-type^=date]")
			.forEach((element) => {
				//console.log(element);
				if (!element.dataset.type.endsWith("[]")) {
					createDatePicker(element);
				}
			});
		rootElement
			.querySelectorAll("input[data-type^=time]")
			.forEach((element) => {
				//console.log(element);
				if (!element.dataset.type.endsWith("[]")) {
					createTimePicker(element);
				}
			});
	}

	function highlightDataFields(rootElement) {
		const rePgDateTime =
			/^(?=(?:\d{4}-\d{2}-\d{2})|(?:\d{2}:\d{2}:\d{2}))(?:(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}))?(?:\s*(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.(?<ms>\d+))?(?<tz>[+-]\d{2})?)?$/;

		const elements = Array.from(
			rootElement.querySelectorAll(".field.highlight-datetime"),
		);
		processInIdle(elements, (element) => {
			const text = element.textContent.trim();
			console.log("Checking datetime field:", text);

			let m = text.match(rePgDateTime);
			if (m) {
				const groups = m.groups;
				console.log("Matched datetime groups:", groups);
				let html = "";
				if (groups.year) {
					html += '<span class="dt-date">';
					html += `<span class="dt-year">${groups.year}</span>-`;
					html += `<span class="dt-month">${groups.month}</span>-`;
					html += `<span class="dt-day">${groups.day}</span>`;
					html += "</span>";
					if (groups.hour) {
						html += " ";
					}
				}
				if (groups.hour) {
					html += '<span class="dt-time">';
					html += `<span class="dt-hour">${groups.hour}</span>:`;
					html += `<span class="dt-minute">${groups.minute}</span>:`;
					html += `<span class="dt-second">${groups.second}</span>`;
					if (groups.ms) {
						html += `.<span class="dt-ms">${groups.ms}</span>`;
					}
					if (groups.tz) {
						html += `<span class="dt-tz">${groups.tz}</span>`;
					}
					html += "</span>";
				}
				element.innerHTML = html;
			}
		});
	}

	//#endregion

	//#region Tooltip Functions

	const tooltip = document.getElementById("tooltip");
	const tooltipContent = document.getElementById("tooltip-content");
	let popperInstance = null;

	window.showTooltip = function (referenceEl, text) {
		console.log("show tooltip", referenceEl);
		text = text || referenceEl.dataset.desc || "Description missing!";
		if (!/<\w+/.test(text)) {
			// plain text, convert line endings into html breaks
			text = text.replace(/\n/g, "<br>\n");
		}
		tooltipContent.innerHTML = text;
		tooltip.style.display = "block";

		if (popperInstance) {
			popperInstance.destroy();
		}

		popperInstance = Popper.createPopper(referenceEl, tooltip, {
			placement: "top",
		});
	};

	window.hideTooltip = function () {
		tooltip.style.display = "none";
		if (popperInstance) {
			popperInstance.destroy();
			popperInstance = null;
		}
	};

	//#endregion

	// Helper to process elements in idle time

	function processInIdle(chunks, fn) {
		const scheduleIdleWork = (cb) => {
			return setTimeout(() => {
				const start = performance.now();
				cb({
					didTimeout: false,
					timeRemaining: () =>
						Math.max(0, 10 - (performance.now() - start)),
				});
			}, 0);
		};

		const run = (deadline) => {
			while (deadline.timeRemaining() > 0 && chunks.length > 0) {
				fn(chunks.shift());
			}
			if (chunks.length > 0) {
				scheduleIdleWork(run);
			}
		};
		scheduleIdleWork(run);
	}

	//#region Initialization

	document.addEventListener("frameLoaded", function (e) {
		console.log("Frame loaded:", e.detail.url);
		createSqlEditors(e.target);
		createDateAndTimePickers(e.target);
		highlightDataFields(e.target);
	});

	flatpickr.localize(flatpickr.l10ns.default);
	window.createSqlEditor = createSqlEditor;
	window.createSqlViewer = createSqlViewer;
	window.createSqlEditors = createSqlEditors;
	window.createSqlViewers = createSqlEditors;
	window.createDateAndTimePickers = createDateAndTimePickers;

	hljs.registerLanguage("pgsql", function (hljs) {
		const base = hljs.getLanguage("pgsql");

		// Kopie der bestehenden Grammatik
		const newGrammar = Object.assign({}, base);

		// Neue Funktionserkennungs-Regel hinzufügen
		newGrammar.contains = [
			{
				className: "function",
				begin: /\b[a-zA-Z_][a-zA-Z0-9_]*\s*(?=\()/, // identifier followed by "("
			},
			{
				className: "string",
				begin: /\$\$/,
			},
			{
				className: "symbol",
				begin: /\$\d+/,
			},
			...base.contains,
		];

		return newGrammar;
	});

	//#endregion
})();
