/* global phpPgAdminCastFunctions */

(function () {
	function byId(id) {
		return document.getElementById(id);
	}

	function clearSelect(selectEl, keepFirst) {
		var start = keepFirst ? 1 : 0;
		while (selectEl.options.length > start) {
			selectEl.remove(start);
		}
	}

	function setRowVisible(rowEl, visible) {
		if (!rowEl) return;
		rowEl.style.display = visible ? "" : "none";
	}

	function getSelectedRadioValue(name) {
		var radios = document.querySelectorAll('input[name="' + name + '"]');
		for (var i = 0; i < radios.length; i++) {
			if (radios[i].checked) return radios[i].value;
		}
		return "";
	}

	function init() {
		var sourceSel = byId("castsourceoid");
		var targetSel = byId("casttargetoid");
		var functionSel = byId("function_oid");
		var functionRow = byId("cast_function_row");

		if (!sourceSel || !targetSel || !functionSel) return;

		var candidates = Array.isArray(window.phpPgAdminCastFunctions)
			? window.phpPgAdminCastFunctions
			: [];

		function updateFunctionOptions() {
			// Always show the full list of candidate functions
			setRowVisible(functionRow, true);
			functionSel.disabled = false;

			clearSelect(functionSel, true);

			var all = Array.isArray(candidates) ? candidates.slice() : [];

			// Sort by displayed prototype
			all.sort(function (a, b) {
				var aa = String(a.proproto || "");
				var bb = String(b.proproto || "");
				return aa.localeCompare(bb);
			});

			for (var j = 0; j < all.length; j++) {
				var f = all[j];
				var opt = document.createElement("option");
				opt.value = String(f.prooid);
				opt.text = String(f.proproto);
				functionSel.add(opt);
			}

			// Preserve existing selection if still present
			var current = functionSel.getAttribute("data-selected");
			if (current) {
				for (var k = 0; k < functionSel.options.length; k++) {
					if (functionSel.options[k].value === current) {
						functionSel.selectedIndex = k;
						break;
					}
				}
				functionSel.removeAttribute("data-selected");
			}
		}

		// Preserve server-rendered selection after postback
		if (functionSel.value) {
			functionSel.setAttribute("data-selected", functionSel.value);
		}

		sourceSel.addEventListener("change", function () {
			// reset function selection on type change
			functionSel.setAttribute("data-selected", "");
			updateFunctionOptions();
		});
		targetSel.addEventListener("change", function () {
			functionSel.setAttribute("data-selected", "");
			updateFunctionOptions();
		});

		var methodRadios = document.querySelectorAll('input[name="method"]');
		for (var r = 0; r < methodRadios.length; r++) {
			methodRadios[r].addEventListener("change", updateFunctionOptions);
		}

		updateFunctionOptions();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
