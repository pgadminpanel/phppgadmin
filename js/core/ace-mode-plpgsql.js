// Standalone mode-plpgsql.js for ACE Editor with proper multiline string support
ace.define(
	"ace/mode/plpgsql",
	[
		"require",
		"exports",
		"module",
		"ace/lib/oop",
		"ace/mode/pgsql",
		"ace/mode/pgsql_highlight_rules",
	],
	function (require, exports, module) {
		"use strict";

		var oop = require("ace/lib/oop");
		var OriginalMode = require("ace/mode/pgsql").Mode;
		var OriginalHighlightRules = require("ace/mode/pgsql_highlight_rules").PgsqlHighlightRules;

		// Create PL/pgSQL highlight rules with proper multiline string support
		var PlpgsqlHighlightRules = function () {
			// Call parent constructor to inherit all SQL keyword highlighting and formatting
			OriginalHighlightRules.call(this);

			// Now override string handling rules to support multiline strings
			var rules = this.$rules;

			// Helper function to insert multiline string rules at the beginning of a state
			var insertMultilineStringRules = function (stateName) {
				if (!Array.isArray(rules[stateName])) {
					return;
				}

				// Remove old single-line string rules that match single quotes
				rules[stateName] = rules[stateName].filter(function (r) {
					return !(
						r.token &&
						typeof r.token === "string" &&
						r.token.indexOf("string") !== -1 &&
						r.regex &&
						typeof r.regex === "string" &&
						r.regex.indexOf("'") !== -1 &&
						!r.next
					);
				});

				// Insert new multiline-aware string rules at the beginning
				var multilineStringRules = [
					// Dollar-quoted strings with proper tag matching
					{
						token: "string.start",
						regex: /(\$[a-zA-Z0-9_]*\$)/,
						onMatch: function (val, state, stack) {
							stack._dollarQuoteTag = val;
							return "string.start";
						},
						next: "dollarQuotedString",
					},

					// Single-quoted strings (multiline support)
					{
						token: "string.start",
						regex: /'/,
						next: "singleQuotedString",
					},
				];

				rules[stateName] = multilineStringRules.concat(rules[stateName]);
			};

			// Apply multiline string rules to all relevant states
			["start", "statement", "dollarSql"].forEach(insertMultilineStringRules);

			// Add multiline string states
			rules.singleQuotedString = [
				// Escaped single quote (two single quotes in Postgres) - must come FIRST
				{
					token: "string.escape",
					regex: /''/,
				},
				// String end - closing single quote
				{
					token: "string.end",
					regex: /'/,
					next: "pop",
				},
				// Regular string content - including newlines for multiline support
				{
					token: "string",
					regex: /[^']+/,
				},
				// Catch-all for any remaining characters
				{
					defaultToken: "string",
				},
			];

			// Dollar-quoted string state with tag validation
			rules.dollarQuotedString = [
				{
					token: "string.end",
					regex: /(\$[a-zA-Z0-9_]*\$)/,
					onMatch: function (val, state, stack) {
						// Check if this closing tag matches the opening tag
						if (stack._dollarQuoteTag === val) {
							delete stack._dollarQuoteTag;
							return "string.end";
						}
						// If tags don't match, treat as regular string content
						return "string";
					},
					next: "pop",
				},
				// All content inside dollar quotes (including newlines) is string
				{
					defaultToken: "string",
				},
			];

			this.normalizeRules();
		};

		oop.inherits(PlpgsqlHighlightRules, OriginalHighlightRules);

		// Create PL/pgSQL mode using the new highlight rules
		var PlpgsqlMode = function () {
			OriginalMode.call(this);
			this.HighlightRules = PlpgsqlHighlightRules;
			this.$id = "ace/mode/plpgsql";
		};

		oop.inherits(PlpgsqlMode, OriginalMode);

		exports.Mode = PlpgsqlMode;
	}
);

// Auto-load when ACE requires this mode
(function () {
	ace.require(["ace/mode/plpgsql"], function (m) {
		if (typeof module == "object" && typeof exports == "object" && module) {
			module.exports = m;
		}
	});
})();
