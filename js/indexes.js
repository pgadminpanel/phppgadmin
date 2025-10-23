(function () {
	/*
	 * Multiple Selection lists in HTML Document
	 */
	let tableColumnList;
	let indexColumnList;

	/*
	 * Two Array vars
	 */

	let indexColumns, tableColumns, tableName;
	// Remember original left-side positions by column text
	let originalPositions = {};

	window.buttonPressed = function (object) {
		let from, to;
		if (object.name == "add") {
			from = tableColumnList;
			to = indexColumnList;
		} else {
			to = tableColumnList;
			from = indexColumnList;
		}

		let selectedOptions = getSelectedOptions(from);
		for (let i = 0; i < selectedOptions.length; i++) {
			let sel = selectedOptions[i];
			let option = new Option(sel.text, sel.value);

			if (to === tableColumnList) {
				// moving back to left side: restore original position when possible
				let origPos = originalPositions[sel.text];
				let insertIndex = to.options.length;
				if (typeof origPos !== "undefined") {
					for (let j = 0; j < to.options.length; j++) {
						let existing = to.options[j];
						let existingPos = originalPositions[existing.text];
						if (
							typeof existingPos !== "undefined" &&
							existingPos > origPos
						) {
							insertIndex = j;
							break;
						}
					}
				}
				if (insertIndex >= to.options.length) {
					to.options[to.options.length] = option;
				} else {
					// modern DOM: add before reference option
					try {
						to.add(option, to.options[insertIndex]);
					} catch (e) {
						// fallback
						to.add(option, insertIndex);
					}
				}
			} else {
				// moving from left to right: simply append
				to.options[to.options.length] = option;
			}

			// remove from source list (do after inserting to destination)
			from.remove(sel.index);
		}

		// Update index name after any change
		updateIndexName();
	};

	window.doSelectAll = function () {
		for (let x = 0; x < indexColumnList.options.length; x++) {
			indexColumnList.options[x].selected = true;
		}
		updateIndexName();
	};

	function getSelectedOptions(obj) {
		let selectedOptions = new Array();

		for (let i = 0; i < obj.options.length; i++) {
			if (obj.options[i].selected) {
				selectedOptions.push(obj.options[i]);
			}
		}

		return selectedOptions;
	}

	// Build index name like table_col1_col2_idx and set formIndexName value
	function updateIndexName() {
		if (!document.formIndex) return;
		const formIndexName = document.formIndex.formIndexName;
		if (!formIndexName) {
			return;
		}
		const suffix = formIndexName.dataset.suffix || "idx";
		let cols = [];
		for (let i = 0; i < indexColumnList.options.length; i++) {
			cols.push(sanitizeIdentifier(indexColumnList.options[i].text));
		}
		let tbl = sanitizeIdentifier(
			tableName ||
				(document.formIndex[document.formIndex.subject.value] &&
					document.formIndex[document.formIndex.subject.value]
						.value) ||
				suffix,
		);
		let name = tbl;
		if (cols.length > 0) {
			name += "_" + cols.join("_");
		}
		if (name.length > 63 - suffix.length - 1) {
			name = name
				.substring(0, 63 - suffix.length - 1)
				.replace(/_+$/g, "");
		}
		name += "_" + suffix;
		formIndexName.value = name;
	}

	function sanitizeIdentifier(s) {
		// replace non-word characters with underscore
		return String(s).replace(/[^A-Za-z0-9_]/g, "_");
	}

	function init() {
		//console.log("init indexes.js");
		if (!document.formIndex) {
			return;
		}
		tableColumnList = document.formIndex.TableColumnList;
		indexColumnList = document.getElementById("IndexColumnList");
		indexColumns = indexColumnList.options;
		tableColumns = tableColumnList.options;
		tableName = document.formIndex[document.formIndex.subject.value].value;

		// build original positions map
		for (let k = 0; k < tableColumnList.options.length; k++) {
			let opt = tableColumnList.options[k];
			originalPositions[opt.text] = k;
		}

		// ensure index name reflects current selection on load
		updateIndexName();
	}

	document.addEventListener("frameLoaded", init, { once: true });
})();
