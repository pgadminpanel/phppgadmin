var predefined_lengths = null;
var sizesLength = false;

function checkLengths(sValue, idx) {
	if (predefined_lengths) {
		if (sizesLength == false) {
			sizesLength = predefined_lengths.length;
		}
		for (var i = 0; i < sizesLength; i++) {
			if (
				sValue.toString().toUpperCase() ==
				predefined_lengths[i].toString().toUpperCase()
			) {
				document.getElementById("lengths" + idx).value = "";
				document.getElementById("lengths" + idx).disabled = "on";
				return;
			}
		}
		document.getElementById("lengths" + idx).disabled = "";
	}
}

function addColumnRow() {
	var table = document.getElementById("columnsTable");
	var numColumnsInput = document.getElementById("num_columns");
	var currentRowCount = parseInt(numColumnsInput.value);
	var newRowIndex = currentRowCount;

	// Clone the last data row
	var lastRow = table.querySelector("tr[data-row-index]:last-of-type");
	if (!lastRow) {
		return; // No rows to clone
	}

	var newRow = lastRow.cloneNode(true);

	// Update row index attribute
	newRow.setAttribute("data-row-index", newRowIndex);

	// Update alternating row class
	if ((newRowIndex & 1) == 0) {
		newRow.className = "data1";
	} else {
		newRow.className = "data2";
	}

	// Update all input elements in the cloned row
	var inputs = newRow.querySelectorAll("input, select");
	for (var i = 0; i < inputs.length; i++) {
		var input = inputs[i];
		var name = input.getAttribute("name");
		var id = input.getAttribute("id");

		// Update array-based names: field[0] -> field[1], etc.
		if (name) {
			var nameMatch = name.match(/^(.+)\[\d+\]$/);
			if (nameMatch) {
				input.setAttribute(
					"name",
					nameMatch[1] + "[" + newRowIndex + "]",
				);
			}
		}

		// Update IDs: types0 -> types1, lengths0 -> lengths1
		if (id) {
			var idMatch = id.match(/^(.+?)(\d+)$/);
			if (idMatch) {
				input.setAttribute("id", idMatch[1] + newRowIndex);
			}
		}

		// Clear values (but preserve hidden fields like 'action', 'stage', etc)
		if (input.type === "hidden" && (!name || !name.match(/\[\d+\]$/))) {
			// Don't clear form-level hidden fields
		} else if (input.type === "checkbox") {
			input.checked = false;
		} else if (input.tagName === "SELECT") {
			input.selectedIndex = 0;
		} else if (input.type !== "hidden") {
			input.value = "";
		}

		// Update onchange for type select
		if (input.tagName === "SELECT" && name && name.match(/^type\[/)) {
			input.setAttribute(
				"onchange",
				"checkLengths(this.value, " + newRowIndex + ");",
			);
		}

		// Update onchange for default preset select
		if (
			input.tagName === "SELECT" &&
			name &&
			name.match(/^default_preset\[/)
		) {
			input.setAttribute(
				"onchange",
				"handleDefaultPresetChange(" + newRowIndex + ");",
			);
		}

		// Update onchange for generated checkbox
		if (
			input.type === "checkbox" &&
			name &&
			name.match(/^is_generated\[/)
		) {
			input.setAttribute(
				"onchange",
				"handleGeneratedChange(" + newRowIndex + ");",
			);
		}
	}

	// Update IDs on div containers (default_container, generated_container)
	var divContainers = newRow.querySelectorAll("div[id]");
	for (var j = 0; j < divContainers.length; j++) {
		var div = divContainers[j];
		var divId = div.getAttribute("id");
		if (divId) {
			var divIdMatch = divId.match(/^(.+?)(\d+)$/);
			if (divIdMatch) {
				div.setAttribute("id", divIdMatch[1] + newRowIndex);
			}
		}
	}

	// Append the new row to the table
	table.appendChild(newRow);

	// Update the hidden num_columns field
	numColumnsInput.value = newRowIndex + 1;

	// Update stage to 2 to stay on current form when submitting with new row
	var stageInput = document.querySelector("input[name='stage']");
	if (stageInput && stageInput.value == "3") {
		stageInput.value = "2";
	}

	// Initialize the new row's length checking
	var typeSelect = newRow.querySelector("select[name^='type']");
	if (typeSelect) {
		checkLengths(typeSelect.value, newRowIndex);
	}

	// Initialize the new row's default preset handling
	handleDefaultPresetChange(newRowIndex, false);

	// Initialize the new row's generated column handling (if PG12+)
	if (typeof hasGeneratedColumns !== "undefined" && hasGeneratedColumns) {
		handleGeneratedChange(newRowIndex, false);
	}
}

function handleDefaultPresetChange(rowIndex, focusCustom) {
	if (typeof rowIndex === "undefined" || rowIndex === null) {
		rowIndex = "";
	}
	if (typeof focusCustom === "undefined") {
		focusCustom = true;
	}

	function getMaybeIndexed(baseId) {
		if (rowIndex === "") {
			return document.getElementById(baseId);
		}
		return (
			document.getElementById(baseId + rowIndex) ||
			document.getElementById(baseId)
		);
	}

	var presetSelect = getMaybeIndexed("default_preset");
	var customInput = getMaybeIndexed("default");
	var notnullCheckbox = getMaybeIndexed("notnull");

	if (!presetSelect || !customInput) {
		return;
	}

	var presetValue = presetSelect.value;

	if (presetValue === "custom") {
		// Show custom input field
		customInput.style.display = "inline";
		if (focusCustom) {
			customInput.focus();
		}
	} else if (presetValue === "") {
		// No default - hide custom input
		customInput.style.display = "none";
		customInput.value = "";
	} else {
		// Preset value selected - hide custom input and uncheck NOT NULL if NULL
		customInput.style.display = "none";
		customInput.value = "";

		if (presetValue === "NULL" && notnullCheckbox) {
			notnullCheckbox.checked = false;
		}
	}
}

/**
 * Handle generated column checkbox change.
 * Toggles visibility between default value fields and generated expression field.
 * Generated columns and default values are mutually exclusive.
 *
 * @param {number|string} rowIndex - The row index (0-based) or empty string for single-column forms
 * @param {boolean} clearFields - Whether to clear the opposite field values (default: true)
 */
function handleGeneratedChange(rowIndex, clearFields) {
	if (typeof rowIndex === "undefined" || rowIndex === null) {
		rowIndex = "";
	}
	if (typeof clearFields === "undefined") {
		clearFields = true;
	}

	function getMaybeIndexed(baseId) {
		if (rowIndex === "") {
			return document.getElementById(baseId);
		}
		return (
			document.getElementById(baseId + rowIndex) ||
			document.getElementById(baseId)
		);
	}

	var generatedCheckbox = getMaybeIndexed("is_generated");
	var defaultContainer = getMaybeIndexed("default_container");
	var generatedContainer = getMaybeIndexed("generated_container");

	if (!generatedCheckbox) {
		return; // PG < 12 or not applicable
	}

	var isGenerated = generatedCheckbox.checked;

	// Toggle visibility
	if (defaultContainer) {
		defaultContainer.style.display = isGenerated ? "none" : "block";
	}
	if (generatedContainer) {
		generatedContainer.style.display = isGenerated ? "block" : "none";
	}

	// Clear opposite field values when toggling (but not during initialization)
	if (clearFields) {
		if (isGenerated) {
			// Clear default fields when enabling generated
			var defaultPreset = getMaybeIndexed("default_preset");
			var defaultInput = getMaybeIndexed("default");
			if (defaultPreset) defaultPreset.value = "";
			if (defaultInput) {
				defaultInput.value = "";
				defaultInput.style.display = "none";
			}
		} else {
			// Clear generated expression when disabling generated
			var generatedExpr = getMaybeIndexed("generated_expr");
			if (generatedExpr) generatedExpr.value = "";
		}
	}
}
