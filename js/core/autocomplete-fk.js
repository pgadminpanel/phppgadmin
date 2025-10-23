/**
 * Foreign Key Autocomplete Module
 * Vanilla JavaScript replacement for jQuery-based autocomplete
 *
 * Usage:
 *   AutocompleteFK.init('insert');  // For insert/edit forms
 *   AutocompleteFK.init('search');  // For search forms
 *
 * Requires global variables from PHP:
 *   - constrs: Object mapping constraint IDs to FK metadata
 *   - attrs: Object mapping field numbers to constraint IDs
 *   - table: Current table name
 *   - server: Current server identifier
 *   - database: Current database name
 */

class AutocompleteFK {
	// Configuration
	static RESULTS_PER_PAGE = 12;
	static FETCH_LIMIT = 13;

	// State per context
	static state = {};

	// Track Popper instances per context
	static popperInstances = {};

	/**
	 * Initialize autocomplete for a specific context (insert, search)
	 * @param {string} context - Context identifier ('insert' or 'search')
	 */
	static init(context = "insert") {
		// Create state container for this context if not exists
		if (!this.state[context]) {
			this.state[context] = {
				selectedIndex: 0,
				offset: 0,
				numRows: 0,
				hasPrev: false,
				hasNext: false,
				currentField: null,
			};
		}

		const state = this.state[context];

		// Cache DOM elements for this context
		const fkbg = document.getElementById(`fkbg-${context}`);
		const fklist = document.getElementById(`fklist-${context}`);
		const form =
			document.getElementById("selectform") ||
			document.querySelector('form[id$="form"]');

		if (!fkbg || !fklist || !form) {
			console.log(
				`AutocompleteFK: Required elements not found for context '${context}'`
			);
			return;
		}

		state.fkbg = fkbg;
		state.fklist = fklist;
		state.form = form;

		// Bind events to FK input fields (per-input listeners can be added multiple times safely)
		const fkInputs = document.querySelectorAll(
			`[data-fk-context="${context}"]`
		);
		fkInputs.forEach((input) => {
			// Key event handlers
			input.addEventListener("keyup", (e) => this.onKeyUp(e, context));
			input.addEventListener("focus", (e) => this.onFocus(e, context));
			input.addEventListener("keydown", (e) =>
				this.onKeyDown(e, context)
			);
			input.classList.add("ac_field");
		});

		// Close dropdown when clicking overlay
		fkbg.addEventListener("click", () => this.hideDropdown(context));

		// Close dropdown on form submit
		form.addEventListener("submit", () => this.hideDropdown(context));

		// Register document-level listeners only once
		if (!this.documentListenersRegistered) {
			this.registerDocumentListeners();
			this.documentListenersRegistered = true;
		}
	}

	/**
	 * Register document-level event listeners (only once for all contexts)
	 */
	static registerDocumentListeners() {
		// Delegated mouseover handler for highlighting rows
		document.addEventListener("mouseover", (e) => {
			const row = e.target.closest("tr.ac_line");
			if (!row) return;

			// Find which context this row belongs to
			const context = this.findContextForElement(row);
			if (!context) return;

			const state = this.state[context];
			if (state && state.fklist && state.fklist.contains(row)) {
				const index = Array.from(
					state.fklist.querySelectorAll("tr")
				).indexOf(row);
				this.selectRow(index, context);
			}
		});

		// Delegated click handler for selecting rows
		document.addEventListener("click", (e) => {
			const row = e.target.closest("tr.ac_line");
			if (!row) return;

			// Find which context this row belongs to
			const context = this.findContextForElement(row);
			if (!context) return;

			const state = this.state[context];
			if (state && state.fklist && state.fklist.contains(row)) {
				this.selectValue(row, context);
			}
		});

		// Delegated click handler for pagination buttons
		document.addEventListener("click", (e) => {
			const buttonId = e.target.id;
			if (
				!buttonId.startsWith("fkprev") &&
				!buttonId.startsWith("fknext")
			)
				return;

			e.preventDefault();
			e.stopPropagation();

			// Find which context's dropdown contains this button
			const context = this.findContextForElement(e.target);
			if (!context) return;

			const state = this.state[context];
			if (!state) return;

			if (buttonId.startsWith("fkprev")) {
				state.offset -= this.RESULTS_PER_PAGE;
			} else if (buttonId.startsWith("fknext")) {
				state.offset += this.RESULTS_PER_PAGE;
			}

			const input = state.currentField;
			if (input) {
				this.fetchAndDisplay(input, context);
			}
		});
	}

	/**
	 * Find the context for a given element by checking which fklist contains it
	 */
	static findContextForElement(element) {
		for (const [context, state] of Object.entries(this.state)) {
			if (state.fklist && state.fklist.contains(element)) {
				return context;
			}
		}
		return null;
	}

	/**
	 * Handle keyup event on FK field
	 */
	static onKeyUp(event, context) {
		const keyCode = event.keyCode;

		// Enter: select current row
		if (keyCode === 13) {
			const state = this.state[context];
			if (
				state.selectedIndex > 0 &&
				state.fklist.style.display === "block"
			) {
				const rows = state.fklist.querySelectorAll("tr");
				if (rows[state.selectedIndex]) {
					rows[state.selectedIndex].click();
				}
			}
			return false;
		}

		// Arrow keys, tab, modifiers: ignore
		if ([38, 40, 9, 37, 39, 16, 17, 18, 20].includes(keyCode)) {
			return;
		}

		// Escape: hide dropdown
		if (keyCode === 27) {
			this.hideDropdown(context);
			return;
		}

		// Other keys: reset offset and fetch
		this.state[context].offset = 0;
		this.fetchAndDisplay(event.target, context);
	}

	/**
	 * Handle focus event on FK field
	 */
	static onFocus(event, context) {
		this.fetchAndDisplay(event.target, context);
	}

	/**
	 * Handle keydown event for arrow navigation
	 */
	static onKeyDown(event, context) {
		const keyCode = event.keyCode;
		const state = this.state[context];
		const rows = state.fklist.querySelectorAll("tr");
		const numRows = rows.length;

		// Prevent form submission on Enter when dropdown is visible
		if (keyCode === 13 && state.fklist.style.display === "block") {
			event.preventDefault();
			return;
		}

		// Down arrow: move down or open list
		if (keyCode === 40) {
			if (state.fklist.style.display === "block") {
				if (state.selectedIndex + 1 < numRows) {
					this.selectRow(state.selectedIndex + 1, context);
				} else if (state.hasNext) {
					state.offset += this.RESULTS_PER_PAGE;
					const input = state.currentField;
					if (input) {
						this.fetchAndDisplay(input, context);
					}
				}
			} else {
				this.fetchAndDisplay(event.target, context);
			}
			return false;
		}

		// Up arrow: move up or wrap to end
		if (keyCode === 38) {
			if (state.selectedIndex - 1 > 0) {
				this.selectRow(state.selectedIndex - 1, context);
			} else if (state.hasPrev && state.selectedIndex === 1) {
				state.offset -= this.RESULTS_PER_PAGE;
				const input = state.currentField;
				if (input) {
					this.fetchAndDisplay(input, context);
				}
			} else if (numRows > 1) {
				this.selectRow(numRows - 1, context);
			}
			return false;
		}
	}

	/**
	 * Fetch FK results from server and display
	 */
	static fetchAndDisplay(input, context) {
		if (!input) return;

		const attnum = input.dataset.attnum;
		if (!attnum || !attrs[`attr_${attnum}`]) {
			return; // Not an FK field
		}

		const conid = attrs[`attr_${attnum}`][0];
		const constr = constrs[`constr_${conid}`];

		// Find position of this field in the constraint
		let fieldPosition = 0;
		for (let i = 0; i < constr.pattnums.length; i++) {
			if (constr.pattnums[i] === parseInt(attnum)) {
				fieldPosition = i;
				break;
			}
		}

		const state = this.state[context];
		state.currentField = input;

		const formData = new URLSearchParams();
		formData.append("fattpos", fieldPosition);
		formData.append("fvalue", input.value);
		formData.append("database", database);
		formData.append("f_table", constr.f_table);
		formData.append("f_schema", constr.f_schema);
		formData.append("offset", state.offset);
		formData.append("context", context);

		// Add arrays
		constr.pattnums.forEach((num, idx) => {
			formData.append("keys[]", num);
			formData.append("keynames[]", constr.pattnames[idx]);
			formData.append("f_keynames[]", constr.fattnames[idx]);
		});

		fetch(`ajax-autocomplete-fk.php?server=${server}`, {
			method: "POST",
			body: formData,
		})
			.then((response) => response.text())
			.then((html) => {
				state.selectedIndex = 0;
				state.fkbg.style.display = "block";
				state.fklist.innerHTML = html;
				state.fklist.style.display = "block";

				// Use Popper.js to position the dropdown
				this.positionWithPopper(input, state, context);

				state.numRows = state.fklist.querySelectorAll("tr").length;

				// Re-bind pagination handlers for new context
				state.fklist
					.querySelectorAll(`[id^="fkprev"], [id^="fknext"]`)
					.forEach((btn) => {
						btn.addEventListener("click", () => input.focus());
					});
			})
			.catch((err) => {
				console.error("FK autocomplete error:", err);
				alert("FK autocomplete error");
				this.hideDropdown(context);
			});
	}

	/**
	 * Position dropdown using Popper.js
	 */
	static positionWithPopper(input, state, context) {
		// Destroy existing Popper instance for this context if any
		if (this.popperInstances[context]) {
			this.popperInstances[context].destroy();
		}

		// Get the scrollable content container
		const contentContainer = document.getElementById("content-container");

		// Create Popper instance with sensible options
		this.popperInstances[context] = Popper.createPopper(
			input,
			state.fklist,
			{
				strategy: "fixed", // Use fixed positioning relative to viewport
				placement: "bottom-start",
				modifiers: [
					{
						name: "offset",
						options: {
							offset: [0, 4],
						},
					},
					{
						name: "preventOverflow",
						options: {
							padding: 8,
							// Use viewport as boundary, not the nearest scrollable ancestor
							boundary: "viewport",
						},
					},
					{
						name: "flip",
						options: {
							padding: 8,
						},
					},
				],
			}
		);

		// Update Popper position on content container scroll
		if (contentContainer && !state.scrollListener) {
			state.scrollListener = () => {
				if (this.popperInstances[context]) {
					this.popperInstances[context].update();
				}
			};
			contentContainer.addEventListener("scroll", state.scrollListener);
		}

		// Constrain the fklist size
		const maxHeight = 300;
		const maxWidth = 600;
		state.fklist.style.maxHeight = maxHeight + "px";
		state.fklist.style.overflowY = "auto";
		state.fklist.style.maxWidth = maxWidth + "px";
		state.fklist.style.overflowX = "auto";
	}

	/**
	 * Highlight a row by index
	 */
	static selectRow(index, context) {
		const state = this.state[context];
		const rows = state.fklist.querySelectorAll("tr");

		// Unhighlight previous
		if (state.selectedIndex > 0 && rows[state.selectedIndex]) {
			rows[state.selectedIndex].querySelectorAll("*").forEach((el) => {
				el.style.backgroundColor = "";
				el.style.color = "";
			});
		}

		// Highlight new
		if (rows[index]) {
			rows[index].querySelectorAll("*").forEach((el) => {
				el.style.backgroundColor = "#3d80df";
				el.style.color = "#fff";
			});
		}

		state.selectedIndex = index;
	}

	/**
	 * Populate values from selected row into form
	 */
	static selectValue(row, context) {
		const links = row.querySelectorAll("td > a.fkval");
		links.forEach((link) => {
			const fieldName = link.name;
			const selector = `input[name="values[${fieldName}]"]`;
			const input = document.querySelector(selector);
			if (input) {
				input.value = link.textContent;
			}
		});
		this.hideDropdown(context);
	}

	/**
	 * Hide dropdown and overlay
	 */
	static hideDropdown(context) {
		const state = this.state[context];
		if (!state) return;
		state.selectedIndex = 0;
		state.offset = 0;
		state.fkbg.style.display = "none";
		state.fklist.style.display = "none";

		// Clean up Popper instance
		if (this.popperInstances[context]) {
			this.popperInstances[context].destroy();
			delete this.popperInstances[context];
		}

		// Remove scroll listener
		if (state.scrollListener) {
			const contentContainer =
				document.getElementById("content-container");
			if (contentContainer) {
				contentContainer.removeEventListener(
					"scroll",
					state.scrollListener
				);
			}
			state.scrollListener = null;
		}
	}

	/**
	 * Enable/disable autocomplete for context
	 */
	static toggleAutocomplete(enabled, context = "insert") {
		const fkInputs = document.querySelectorAll(
			`[data-fk-context="${context}"]`
		);
		fkInputs.forEach((input) => {
			if (enabled) {
				input.addEventListener("keyup.ac_action", (e) =>
					this.onKeyUp(e, context)
				);
				input.addEventListener("focus.ac_action", (e) =>
					this.onFocus(e, context)
				);
				input.addEventListener("keydown.ac_action", (e) =>
					this.onKeyDown(e, context)
				);
				input.classList.add("ac_field");
			} else {
				input.removeEventListener("keyup.ac_action", this.onKeyUp);
				input.removeEventListener("focus.ac_action", this.onFocus);
				input.removeEventListener("keydown.ac_action", this.onKeyDown);
				input.classList.remove("ac_field");
			}
		});
	}
}
