// Standalone mode-plpgsql.js for ACE Editor with simple multiline string support
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
		var OriginalHighlightRules =
			require("ace/mode/pgsql_highlight_rules").PgsqlHighlightRules;

		// Create PL/pgSQL highlight rules with simple multiline string support
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
					// Single-quoted strings (multiline support)
					{
						token: "string.start",
						regex: /'/,
						next: "singleQuotedString",
					},
				];

				rules[stateName] = multilineStringRules.concat(
					rules[stateName]
				);
			};

			// Apply multiline string rules to all relevant states
			["start", "statement"].forEach(insertMultilineStringRules);

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

			// Highlight Postgres data types as a distinct token class
			// Use a string alternation so it integrates with existing rule handling
			var PG_TYPES =
				"smallint|integer|bigint|decimal|numeric|real|double\\s+precision|smallserial|serial|bigserial|money|character\\s+varying|varchar|character|char|text|citext|bytea|timestamp\\s+with\\s+time\\s+zone|timestamp\\s+without\\s+time\\s+zone|timestamp|timestamptz|date|time\\s+with\\s+time\\s+zone|time\\s+without\\s+time\\s+zone|time|timetz|interval|cidr|inet|macaddr|macaddr8|json|jsonb|xml|point|line|lseg|box|path|polygon|circle|bit\\s+varying|varbit|bit|uuid|tsvector|tsquery|int4range|int8range|numrange|tsrange|tstzrange|daterange|oid|regproc|regprocedure|regoper|regoperator|regclass|regtype|regrole|regnamespace|regconfig|regdictionary|any|anyelement|anyarray|anynonarray|anyenum|anyrange|cstring|internal|record|void|trigger|event_trigger|fdw_handler|index_am_handler|tsm_handler|opaque|unknown";

			var typeRule = {
				token: "support.type",
				// whole-word match
				regex: "\\b(?:" + PG_TYPES + ")\\b",
			};

			["start", "statement"].forEach(function (stateName) {
				if (Array.isArray(rules[stateName])) {
					rules[stateName].unshift(typeRule);
				}
			});

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
