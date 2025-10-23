(function () {
	//#region FK Popup Management

	const FKPopupManager = {
		maxPopups: 5,
		openPopups: [], // array of {element, triggerLink, constraintClass}
		popperInstances: new Map(), // Map<element, Popper.Instance>
		fkHandler: null,
		closeHandler: null,
		container: null,

		/**
		 * Initialize the FK popup system
		 */
		init() {
			// Ensure #fk_container and structure exist
			const dataTable = document.querySelector("table#data");
			if (!dataTable) return;

			this.container = document.createElement("div");
			this.container.id = "fk_container";
			//this.container.className = "fk";
			dataTable.parentElement.insertBefore(this.container, dataTable);
			this.container.appendChild(dataTable);

			/*
			const rootDiv = document.createElement("div");
			rootDiv.id = "fk_root";
			wrapper.appendChild(rootDiv);
			*/

			// Attach click handler to all FK links using event delegation
			this.fkHandler = (e) => this.handleFKClick(e);
			document.addEventListener("click", this.fkHandler);

			// Attach close button handler using event delegation
			this.closeHandler = (e) => this.handleCloseClick(e);
			document.addEventListener("click", this.closeHandler);
		},

		/**
		 * Cleanup on unload
		 */
		unload() {
			// Remove event listeners
			if (this.fkHandler) {
				document.removeEventListener("click", this.fkHandler);
				this.fkHandler = null;
			}
			if (this.closeHandler) {
				document.removeEventListener("click", this.closeHandler);
				this.closeHandler = null;
			}

			// Close all open popups
			this.openPopups.forEach((popup) => {
				this.closePopup(popup.element);
			});
			this.openPopups = [];
		},

		/**
		 * Handle FK link click
		 */
		handleFKClick(event) {
			const fkLink = event.target.closest("a.fk");
			if (!fkLink) return;

			console.log("FKPopupManager.handleFKClick");

			event.preventDefault();
			event.stopPropagation();

			document.body.style.cursor = "wait";

			const constraintClass = fkLink.className.split(" ")[1]; // e.g., 'fk_12345'

			// Fetch FK data via AJAX
			const url = new URL(fkLink.dataset.href, window.location.href);
			url.searchParams.set("action", "dobrowsefk");
			console.log("Fetching FK data from:", url.toString());

			fetch(url.toString())
				.then((response) => {
					if (!response.ok)
						throw new Error(
							`HTTP error! status: ${response.status}`,
						);
					return response.text();
				})
				.then((htmlContent) => {
					this.displayPopup(fkLink, constraintClass, htmlContent);
				})
				.catch((error) => {
					const errorMsg = document.createElement("p");
					errorMsg.className = "errmsg";
					errorMsg.textContent =
						Display.errmsg || "Error loading foreign key data";
					this.container.appendChild(errorMsg);
				})
				.finally(() => {
					document.body.style.cursor = "auto";
				});
		},

		/**
		 * Display the FK popup with Popper.js positioning
		 */
		displayPopup(triggerLink, constraintClass, htmlContent) {
			// Check popup cap and close oldest if necessary
			if (this.openPopups.length >= this.maxPopups) {
				const oldest = this.openPopups.shift();
				this.closePopup(oldest.element);
			}

			// Create popup container
			const popupDiv = document.createElement("div");
			popupDiv.className = `fk ${constraintClass}`;
			popupDiv.innerHTML = htmlContent;
			popupDiv.style.position = "absolute";
			popupDiv.style.zIndex = 1000 + this.openPopups.length;

			// Store reference to trigger link on popup element
			popupDiv._triggerLink = triggerLink;
			popupDiv._constraintClass = constraintClass;

			// Append to document body for Popper.js to position
			this.container.appendChild(popupDiv);

			// Initialize Popper.js for positioning
			const popperInstance = Popper.createPopper(triggerLink, popupDiv, {
				placement: "bottom-start",
				modifiers: [
					{
						name: "offset",
						options: {
							offset: [0, 8],
						},
					},
					{
						name: "flip",
						options: {
							padding: 8,
						},
					},
					{
						name: "preventOverflow",
						options: {
							padding: 8,
						},
					},
				],
			});

			this.popperInstances.set(popupDiv, popperInstance);

			// Track open popup
			this.openPopups.push({
				element: popupDiv,
				triggerLink,
				constraintClass,
			});

			// Setup hover highlight effect
			this.setupHighlightEffect(popupDiv, triggerLink, constraintClass);

			// Re-attach close handlers to new close button
			this.attachCloseHandlers(popupDiv);
		},

		/**
		 * Setup highlight effect on hover
		 */
		setupHighlightEffect(popupDiv, triggerLink, constraintClass) {
			popupDiv.addEventListener("mouseenter", () => {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.add("highlight");
					}
				}
			});

			popupDiv.addEventListener("mouseleave", () => {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.remove("highlight");
					}
				}
			});
		},

		/**
		 * Attach close button handlers to popup
		 */
		attachCloseHandlers(popupDiv) {
			const closeBtn = popupDiv.querySelector("a.fk_close");
			if (closeBtn) {
				closeBtn.addEventListener("click", (e) => {
					e.preventDefault();
					e.stopPropagation();
					this.closePopup(popupDiv);
				});
			}
		},

		/**
		 * Handle close button click
		 */
		handleCloseClick(event) {
			const closeBtn = event.target.closest("a.fk_close");
			if (!closeBtn) return;

			event.preventDefault();
			event.stopPropagation();

			const popupDiv = closeBtn.closest("div.fk");
			if (popupDiv) {
				this.closePopup(popupDiv);
			}
		},

		/**
		 * Close a popup
		 */
		closePopup(popupDiv) {
			// Remove from tracking array
			const index = this.openPopups.findIndex(
				(p) => p.element === popupDiv,
			);
			if (index !== -1) {
				this.openPopups.splice(index, 1);
			}

			// Destroy Popper instance
			const popperInstance = this.popperInstances.get(popupDiv);
			if (popperInstance) {
				popperInstance.destroy();
				this.popperInstances.delete(popupDiv);
			}

			// Remove highlight from referencing field
			const triggerLink = popupDiv._triggerLink;
			const constraintClass = popupDiv._constraintClass;
			if (triggerLink) {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.remove("highlight");
					}
				}
			}

			// Remove from DOM
			popupDiv.remove();
		},
	};

	// Initialize FK popup system
	FKPopupManager.init();

	// Virtual Frame Unload Event
	document.addEventListener(
		"beforeFrameUnload",
		() => {
			FKPopupManager.unload();
		},
		{ once: true },
	);

	//#endregion End FK Popup Management

	//#region Column Sorting Management

	const reverseSortDir = {
		asc: "desc",
		desc: "asc",
	};

	let tooltipTimout = 0;

	// Adjust orderby fields in links before sending them out
	document.querySelectorAll("a.orderby").forEach((a) => {
		a.addEventListener("click", (e) => {
			//e.preventDefault();
			//e.stopPropagation();
			const col = a.dataset.col;
			const url = new URL(a.href, window.location.origin);
			const params = new URLSearchParams(url.search);
			const initialDir = /date|timestamp/.test(a.dataset.type)
				? "desc"
				: "asc";

			let orderby = {};
			for (const [key, val] of params.entries()) {
				const match = key.match(/^orderby\[(.+)]$/);
				if (match) orderby[match[1]] = val;
			}

			if (!orderby[col]) {
				// set reversed here, because it get reversed later again
				orderby[col] = reverseSortDir[initialDir];
			}

			//console.log(orderby);

			if (e.ctrlKey) {
				delete orderby[col];
			} else if (e.shiftKey) {
				orderby[col] = reverseSortDir[orderby[col]];
			} else {
				const direction = reverseSortDir[orderby[col]];
				orderby = {};
				orderby[col] = direction;
			}

			//console.log(orderby);

			[...params.keys()].forEach((k) => {
				if (k.startsWith("orderby[")) params.delete(k);
			});
			params.delete("orderby_clear");
			for (const [c, dir] of Object.entries(orderby)) {
				params.set(`orderby[${c}]`, dir);
			}
			if (Object.keys(orderby).length === 0) {
				params.set("orderby_clear", "1");
			}

			url.search = params.toString();
			a.href = url.toString();

			//console.log(url.toString());
		});

		a.addEventListener("mouseenter", () => {
			tooltipTimout = window.setTimeout(() => {
				window.showTooltip(a, a.closest("tr").dataset.orderbyDesc);
			}, 500);
		});

		a.addEventListener("mouseleave", () => {
			window.clearTimeout(tooltipTimout);
			window.hideTooltip();
		});
	});

	// Virtual Frame Unload Event
	document.addEventListener(
		"beforeFrameUnload",
		() => {
			window.clearTimeout(tooltipTimout);
			window.hideTooltip();
		},
		{ once: true },
	);

	//#endregion End Column Sorting

	//#region Popup Field Editor

	const FieldPopupEditor = {
		currentPopup: null,
		popperInstance: null,
		currentCell: null,
		_boundOutsideClick: null,
		_boundKeydown: null,

		init() {
			this.unload();

			const dataTable = document.querySelector("table#data");
			if (!dataTable) return;

			this._boundOutsideClick = this.handleOutsideClick.bind(this);
			this._boundKeydown = this.handleKeydown.bind(this);

			let lastTap = 0;

			dataTable.addEventListener("click", (e) => {
				const now = Date.now();
				const delta = now - lastTap;

				if (delta < 300 && delta > 0) {
					this.handleDoubleClick(e);
				}

				lastTap = now;
			});

			// Attach click-outside handler
			document.addEventListener("click", this._boundOutsideClick);
			// Attach keydown handler for Escape
			document.addEventListener("keydown", this._boundKeydown);

			// Restore scroll position if available
			let scrollTop = sessionStorage.getItem("contentScrollTop");
			if (scrollTop !== null) {
				const contentDiv = document.getElementById("content");
				if (contentDiv) {
					contentDiv.scrollTop = parseInt(scrollTop, 10);
				}
				sessionStorage.removeItem("contentScrollTop");
			}
		},

		handleDoubleClick(e) {
			const cell = e.target.closest("td.editable");
			if (!cell) return;

			e.preventDefault();
			e.stopPropagation();

			this.openEditor(cell);
		},

		handleOutsideClick(e) {
			if (!this.currentPopup) return;

			// Check if click is inside flatpickr calendar
			const flatpickrCalendar = e.target.closest(".flatpickr-calendar");
			if (flatpickrCalendar) {
				return; // Ignore clicks inside flatpickr
			}

			// Check if click is outside popup
			if (!this.currentPopup.contains(e.target)) {
				this.saveAndClose();
			}
		},

		handleKeydown(e) {
			if (!this.currentPopup) return;

			if (e.key === "Escape") {
				e.preventDefault();
				this.close();
			} else if (e.key === "Enter" && !e.shiftKey) {
				const target = e.target;
				// Allow Enter in textareas
				if (target.tagName === "TEXTAREA") return;
				e.preventDefault();
				this.saveAndClose();
			}
		},

		async openEditor(cell) {
			// Close existing popup
			if (this.currentPopup) {
				this.close();
			}

			const fieldName = cell.dataset.name;
			const fieldType = cell.dataset.type;
			const row = cell.closest("tr");
			const table = cell.closest("table#data");

			if (!fieldName || !row || !table) return;

			const schema = table.dataset.schema;
			const tableName = table.dataset.table;

			if (!schema || !tableName) {
				console.error("Missing schema or table information");
				return;
			}

			// Get row keys
			const keysJson = row.dataset.keys;
			if (!keysJson) {
				console.error("Missing row keys");
				return;
			}

			this.currentCell = cell;

			// Build URL
			const urlParams = new URLSearchParams(window.location.search);
			const params = new URLSearchParams({
				action: "popupedit",
				server: urlParams.get("server"),
				database: urlParams.get("database"),
				schema: schema,
				table: tableName,
				field: fieldName,
				keys: keysJson,
			});

			// Fetch editor HTML
			try {
				const response = await fetch(
					"display.php?" + params.toString(),
				);
				if (!response.ok) {
					throw new Error("Failed to load editor");
				}
				const html = await response.text();
				this.showPopup(
					cell,
					html,
					row,
					schema,
					tableName,
					fieldName,
					fieldType,
				);
			} catch (err) {
				console.error("Error loading editor:", err);
			}
		},

		showPopup(cell, html, row, schema, tableName, fieldName, fieldType) {
			// Create popup element
			const popup = document.createElement("div");
			popup.className = "field-popup-container";
			popup.innerHTML = html;
			document.body.appendChild(popup);
			const maximizeBtn = document.getElementById("popup-maximize-btn");
			if (maximizeBtn) {
				maximizeBtn.addEventListener("click", (e) => {
					e.preventDefault();
					this.toggleMaximizePopup();
				});
			}

			this.currentPopup = popup;
			cell.classList.add("active");

			// Create Popper instance
			this.popperInstance = window.Popper.createPopper(cell, popup, {
				placement: "bottom-start",
				modifiers: [
					{
						name: "offset",
						options: {
							offset: [
								-(cell.offsetWidth / 2),
								-(cell.offsetHeight / 2),
							],
						},
					},
					{
						name: "preventOverflow",
						options: {
							padding: 0,
							tether: false,
						},
					},
					{
						name: "flip",
						options: {
							fallbackPlacements: ["top-start"],
						},
					},
				],
			});

			// Focus input
			const inputs = popup.querySelectorAll(
				"input[name=value], textarea[name=value], select[name=value]",
			);
			// Get radio separated
			const checkedRadio = this.currentPopup.querySelector(
				"input[name=value][type=radio]:checked",
			);
			const nullCb = popup.querySelector("#popup-null-cb");
			inputs.forEach((input) => {
				setTimeout(() => {
					input.focus();
					if (input.select) input.select();
				}, 50);
				input.onchange = () => {
					if (nullCb) nullCb.checked = false;
				};
				popup.dataset.value = input.value;
			});
			if (checkedRadio) {
				popup.dataset.value = checkedRadio.value;
			}
			popup.dataset.isNull = nullCb?.checked ?? false;

			// Store row data for SQL generation
			popup.dataset.schema = schema;
			popup.dataset.table = tableName;
			popup.dataset.field = fieldName;
			popup.dataset.type = fieldType;
			popup.dataset.keys = row.dataset.keys;

			createSqlEditors(popup, { selected: true });
			createDateAndTimePickers(popup);
		},

		toggleMaximizePopup() {
			const popup = this.currentPopup;
			if (!popup) return;

			// Maximize
			if (!popup.dataset.maximized) {
				const rect = popup.getBoundingClientRect();

				popup.classList.add("field-popup-maximized");
				popup.dataset.maximized = "true";

				// Pause Popper
				if (this.popperInstance) {
					this.popperInstance.disable();
				}
				return;
			}

			// Restore
			popup.classList.remove("field-popup-maximized");

			popup.dataset.maximized = "";

			// Reactivate Popper
			if (this.popperInstance) {
				this.popperInstance.enable();
				this.popperInstance.update();
			}
		},

		saveAndClose() {
			if (!this.currentPopup) return;

			/**
			 * Formats a value or expression for SQL purposes.
			 * @param {string} type      The type of the field
			 * @param {string|null} func A SQL function template containing the word "value"
			 * @param {boolean} isExpr     Treat value as raw SQL expression
			 * @param {boolean} isNull   Whether the value is null
			 * @param {string|null} value The actual value entered in the field (may be null)
			 * @returns {string}
			 */
			function formatValue(type, func, isExpr, isNull, value) {
				if (isNull) {
					return "NULL";
				}

				// Normalize null/undefined
				if (value === null || value === undefined) {
					value = "";
				}

				switch (type) {
					case "bool":
					case "boolean":
						if (value === "t") return "TRUE";
						if (value === "f") return "FALSE";
						if (value === "") return "NULL";
						return value;
				}

				// SQL function case: e.g. "ENCODE(value,'base64')"
				if (func) {
					let v = value;
					if (!isExpr) {
						v = pgQuoteLiteral(value);
					}
					return func.replace(/\bvalue\b/g, v);
				}

				// Raw SQL expression
				if (isExpr) {
					return value;
				}

				// Date/time types
				const isDateOrTime =
					type.length >= 4 &&
					(type.startsWith("time") || type.startsWith("date"));

				if (isDateOrTime) {
					if (value === "") return "''";

					const upper = value.toUpperCase();
					const keywords = [
						"CURRENT_TIMESTAMP",
						"CURRENT_TIME",
						"CURRENT_DATE",
						"LOCALTIME",
						"LOCALTIMESTAMP",
					];

					if (keywords.includes(upper)) {
						return value;
					}
				}

				// Default: quote and escape
				return pgQuoteLiteral(value);
			}

			const schema = this.currentPopup.dataset.schema;
			const table = this.currentPopup.dataset.table;
			const field = this.currentPopup.dataset.field;
			const keys = JSON.parse(this.currentPopup.dataset.keys);

			// Get input value
			const inputField = this.currentPopup.querySelector(
				"input[name=value], textarea[name=value], select[name=value]",
			);
			// Get radio separated
			const checkedRadio = this.currentPopup.querySelector(
				"input[name=value][type=radio]:checked",
			);
			const nullCheckbox =
				this.currentPopup.querySelector("#popup-null-cb");
			const exprCheckbox =
				this.currentPopup.querySelector("#popup-expr-cb");
			const functionSelect = this.currentPopup.querySelector(
				"#popup-function-sel",
			);

			if (!inputField) {
				this.close();
				return;
			}

			const newValue = checkedRadio
				? checkedRadio.value
				: inputField.value;
			const isNull = nullCheckbox?.checked ?? false;
			const isExpr = exprCheckbox?.checked ?? false;
			const functionValue = functionSelect && functionSelect.value;
			console.log(
				"New Value:",
				newValue,
				"isNull:",
				isNull,
				"isExpr:",
				isExpr,
			);

			if (
				newValue === this.currentPopup.dataset.value &&
				isNull.toString() === this.currentPopup.dataset.isNull &&
				!functionValue &&
				!isExpr
			) {
				// No changes made
				this.close();
				return;
			}

			// Generate UPDATE SQL
			let sql =
				"UPDATE " +
				pgQuoteIdent(schema) +
				"." +
				pgQuoteIdent(table) +
				" SET ";

			// SET clause
			sql += pgQuoteIdent(field) + " = ";
			sql += formatValue(
				this.currentPopup.dataset.type,
				functionValue,
				isExpr,
				isNull,
				newValue,
			);

			// WHERE clause
			const whereParts = [];
			for (const [keyName, keyValue] of Object.entries(keys)) {
				if (keyValue === null) {
					whereParts.push(pgQuoteIdent(keyName) + " IS NULL");
				} else {
					whereParts.push(
						pgQuoteIdent(keyName) +
							" = " +
							pgQuoteLiteral(keyValue),
					);
				}
			}

			if (whereParts.length == 0) {
				console.error("No keys available for WHERE clause!");
				this.close();
				return;
			}
			sql += " WHERE " + whereParts.join(" AND ") + ";";

			console.log("Generated SQL:", sql);

			const editor = document.getElementById("query-editor");
			if (!editor) {
				console.error("Query editor not found!");
				this.close();
				return;
			}
			if (editor.beginEdit) {
				editor.beginEdit(sql + "\n");
			} else {
				editor.value = sql;
			}

			const form = document.getElementById("query-form");
			if (form) {
				const submit = form.querySelector('[type="submit"]');
				if (submit) {
					// Save scroll position
					sessionStorage.setItem(
						"contentScrollTop",
						document.getElementById("content").scrollTop,
					);
					submit.click();
				}
			}

			this.close();
		},

		close() {
			if (this.popperInstance) {
				this.popperInstance.destroy();
				this.popperInstance = null;
			}

			if (this.currentPopup) {
				this.currentPopup.remove();
				this.currentPopup = null;
			}

			if (this.currentCell) {
				this.currentCell.classList.remove("active");
				this.currentCell = null;
			}
		},

		unload() {
			if (this._boundOutsideClick) {
				document.removeEventListener("click", this._boundOutsideClick);
			}
			if (this._boundKeydown) {
				document.removeEventListener("keydown", this._boundKeydown);
			}
			this.close();
		},
	};

	// Initialize on load
	document.addEventListener(
		"frameLoaded",
		() => {
			FieldPopupEditor.init();
		},
		{ once: true },
	);

	// Cleanup on unload
	document.addEventListener(
		"beforeFrameUnload",
		() => {
			FieldPopupEditor.unload();
		},
		{ once: true },
	);

	//#endregion End Popup Field Editor

	//#region Query EXPLAIN Helper

	let explainAnalyzeCheckbox = null;
	let explainBuffersCheckbox = null;
	let explainVerboseCheckbox = null;
	let explainFormatSelect = null;
	let explainQueryCheckbox = null;
	let queryEditorElement = null;
	let expandStringsElement = null;

	function addExplainPrefix(ifExists = false) {
		let query = queryEditorElement.value.trimLeft();

		const found = /^\s*EXPLAIN/i.test(query);

		// Remove existing EXPLAIN
		query = query
			.replace(/^\s*EXPLAIN(\s*\(.*?\))?(\s+ANALY[ZS]E|VERBOSE)*/i, "")
			.trimLeft();

		let newQuery = "";

		if (!ifExists && found) {
			// Toggle EXPLAIN on/off
			newQuery = query;
			explainQueryCheckbox.checked = false;
			if (explainAnalyzeCheckbox) explainAnalyzeCheckbox.checked = false;
			if (explainVerboseCheckbox) explainVerboseCheckbox.checked = false;
			if (explainBuffersCheckbox) explainBuffersCheckbox.checked = false;
			if (explainFormatSelect) explainFormatSelect.value = "";
		} else {
			// Get options
			let options = [];
			if (explainAnalyzeCheckbox && explainAnalyzeCheckbox.checked) {
				options.push("ANALYZE");
			}
			if (explainVerboseCheckbox && explainVerboseCheckbox.checked) {
				options.push("VERBOSE");
			}
			if (explainBuffersCheckbox && explainBuffersCheckbox.checked) {
				options.push("BUFFERS");
			}
			if (explainFormatSelect && explainFormatSelect.value) {
				options.push("FORMAT " + explainFormatSelect.value);
			}

			let prefix = "EXPLAIN";
			if (options.length > 0) {
				prefix += " (" + options.join(", ") + ")";
			}

			// Prepend prefix
			newQuery = prefix + "\n" + query;
			explainQueryCheckbox.checked = true;
			if (expandStringsElement) {
				expandStringsElement.checked = true;
			}
		}

		// Set back to editor
		if (queryEditorElement.beginEdit) {
			queryEditorElement.beginEdit(newQuery);
		} else {
			queryEditorElement.value = newQuery;
		}
	}

	function parseExplainOptions() {
		const matches =
			/^\s*EXPLAIN(\s*\(.*?\))?(\s+ANALY[ZS]E|VERBOSE)*/i.exec(
				queryEditorElement.value,
			);
		if (matches) {
			const optionsStr = matches[0] || "";
			if (/ANALY[SZ]E/i.test(optionsStr)) {
				if (explainAnalyzeCheckbox)
					explainAnalyzeCheckbox.checked = true;
			}
			if (/BUFFERS/i.test(optionsStr)) {
				if (explainBuffersCheckbox)
					explainBuffersCheckbox.checked = true;
			}
			if (/VERBOSE/i.test(optionsStr)) {
				if (explainVerboseCheckbox)
					explainVerboseCheckbox.checked = true;
			}
			const formatMatch = /FORMAT\s+(\w+)/i.exec(optionsStr);
			if (formatMatch && explainFormatSelect) {
				const formatValue = formatMatch[1].toUpperCase();
				for (const option of explainFormatSelect.options) {
					if (option.value.toUpperCase() === formatValue) {
						explainFormatSelect.value = option.value;
						break;
					}
				}
			}
			explainQueryCheckbox.checked = true;
		}
	}

	document.addEventListener(
		"frameLoaded",
		() => {
			explainQueryCheckbox = document.getElementById("explain-query");
			explainAnalyzeCheckbox = document.getElementById("explain-analyze");
			explainBuffersCheckbox = document.getElementById("explain-buffers");
			explainVerboseCheckbox = document.getElementById("explain-verbose");
			explainFormatSelect = document.getElementById("explain-format");
			queryEditorElement = document.getElementById("query-editor");
			expandStringsElement = document.getElementById("expand-strings");

			if (!queryEditorElement || !explainQueryCheckbox) {
				return;
			}

			parseExplainOptions();

			explainQueryCheckbox.onchange = () => addExplainPrefix(false);

			if (explainBuffersCheckbox) {
				explainBuffersCheckbox.onchange = () => addExplainPrefix(true);
			}

			if (explainAnalyzeCheckbox) {
				explainAnalyzeCheckbox.onchange = () => addExplainPrefix(true);
			}

			if (explainVerboseCheckbox) {
				explainVerboseCheckbox.onchange = () => addExplainPrefix(true);
			}

			if (explainFormatSelect) {
				explainFormatSelect.onchange = () => addExplainPrefix(true);
			}
		},
		{ once: true },
	);

	//#endregion End Query EXPLAIN Helper
})();
