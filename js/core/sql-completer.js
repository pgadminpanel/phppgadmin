// sqlCompleter.js
const SQLCompleter = (function () {
	// **Schema Cache**
	let schema = { tableList: [], tables: {}, fks: [] };

	// **Utility: fetch schema JSON from server**
	async function loadSchemaOnce(url) {
		try {
			const res = await fetch(url, { credentials: "same-origin" });
			if (!res.ok) throw new Error("Schema load failed");
			const json = await res.json();
			schema.tableList = json.tableList || [];
			schema.tables = json.tables || {};
			schema.fks = json.fks || [];
		} catch (e) {
			console.error("SQLCompleter schema load error", e);
		}
	}

	function loadSchemaFromWindow() {
		if (window.autocompleteSchema) {
			schema.tableList = window.autocompleteSchema.tableList || [];
			schema.tables = window.autocompleteSchema.tables || {};
			schema.fks = window.autocompleteSchema.fks || [];
		}
	}

	// **Position helpers**
	function posToIndex(session, pos) {
		return session.doc.positionToIndex(pos);
	}

	// **Context detection**
	function detectContext(session, pos) {
		const row = pos.row;
		const col = pos.column;
		const tokens = session.getTokens(row);
		// find token containing cursor or last token before cursor
		let tokenBefore = null;
		for (let i = 0; i < tokens.length; i++) {
			const t = tokens[i];
			const start = t.start;
			const end = start + t.value.length;
			if (start <= col && col <= end) {
				tokenBefore = t;
				break;
			}
			if (end < col) tokenBefore = t;
		}

		// scan backwards across lines up to a limit to find last keyword
		const KEYWORDS = new Set([
			"SELECT",
			"FROM",
			"JOIN",
			"ON",
			"WHERE",
			"GROUP",
			"BY",
			"HAVING",
			"ORDER",
			"INSERT",
			"INTO",
			"VALUES",
			"UPDATE",
			"SET",
			"DELETE",
		]);
		const maxLines = 20;
		let keyword = null;
		for (let r = row; r >= Math.max(0, row - maxLines); r--) {
			const lineTokens = session.getTokens(r);
			let endIndex = lineTokens.length - 1;
			// On the current row, ignore tokens that start after the cursor.
			if (r === row) {
				while (endIndex >= 0 && lineTokens[endIndex].start >= col) {
					endIndex--;
				}
			}
			for (let i = endIndex; i >= 0; i--) {
				const v = (lineTokens[i].value || "").toUpperCase();
				if (!KEYWORDS.has(v)) continue;

				// Treat BY as part of ORDER BY / GROUP BY for column suggestions.
				if (v === "BY") {
					let resolved = null;
					for (let j = i - 1; j >= 0; j--) {
						const prev = (lineTokens[j].value || "").toUpperCase();
						if (prev === "ORDER" || prev === "GROUP") {
							resolved = prev;
							break;
						}
						// stop if we hit another clause keyword first
						if (prev !== "BY" && KEYWORDS.has(prev)) break;
					}
					keyword = resolved || v;
					r = -1;
					break;
				}

				keyword = v;
				r = -1;
				break;
			}
		}

		return { tokenBefore, keyword };
	}

	// **FROM scanner to extract alias -> table mapping**
	function extractTables(session, pos) {
		const text = session.getValue().slice(0, posToIndex(session, pos));
		const regex =
			/\b(FROM|JOIN|INTO|UPDATE)\s+([a-zA-Z0-9_\."]+)(?:\s+AS\s+|\s+)?([a-zA-Z0-9_"]+)?/gi;
		const map = {};
		let m;
		while ((m = regex.exec(text))) {
			let table = m[2].replace(/"/g, "");
			let alias = (m[3] || table).replace(/"/g, "");
			// if table is schema.table, keep only table part for lookup
			if (table.includes(".")) table = table.split(".").pop();
			map[alias] = table;
		}
		return map;
	}

	// **Resolve columns for alias or table**
	function resolveColumns(tablesMap, aliasOrTable) {
		const table = tablesMap[aliasOrTable] || aliasOrTable;
		return schema.tables[table] || [];
	}

	// **Build completions for tables**
	function tableCompletions() {
		return schema.tableList.map((t) => ({
			caption: t,
			value: t,
			meta: "table",
		}));
	}

	// **Build completions for columns**
	function columnCompletions(cols) {
		return cols.map((c) => ({ caption: c, value: c, meta: "column" }));
	}

	// **Optional: FK based join suggestions**
	function fkJoinCompletions(tablesMap) {
		// return suggestions like "JOIN orders o ON o.user_id = u.id"
		const suggestions = [];
		schema.fks.forEach((fk) => {
			// fk: { source_table, source_column, target_table, target_column }
			if (
				schema.tableList.includes(fk.source_table) &&
				schema.tableList.includes(fk.target_table)
			) {
				const text = `JOIN ${fk.target_table} ON ${fk.source_table}.${fk.source_column} = ${fk.target_table}.${fk.target_column}`;
				suggestions.push({
					caption: `join ${fk.target_table}`,
					value: text,
					meta: "join",
				});
			}
		});
		return suggestions;
	}

	// **Main completer**
	const completer = {
		getCompletions: function (editor, session, pos, prefix, callback) {
			const ctx = detectContext(session, pos);
			const tablesMap = extractTables(session, pos);

			// alias.<cursor>
			if (
				ctx.tokenBefore &&
				ctx.tokenBefore.value &&
				ctx.tokenBefore.value.endsWith(".")
			) {
				const alias = ctx.tokenBefore.value
					.slice(0, -1)
					.replace(/"/g, "");
				const cols = resolveColumns(tablesMap, alias);
				return callback(null, columnCompletions(cols));
			}

			// Keywords that expect table names
			if (
				ctx.keyword === "FROM" ||
				ctx.keyword === "JOIN" ||
				ctx.keyword === "INTO" ||
				ctx.keyword === "UPDATE"
			) {
				// include FK join suggestions when in JOIN context
				const tables = tableCompletions();
				if (ctx.keyword === "JOIN") {
					const fk = fkJoinCompletions(tablesMap);
					return callback(null, tables.concat(fk));
				}
				return callback(null, tables);
			}

			// Contexts that expect columns
			if (
				[
					"SELECT",
					"WHERE",
					"ORDER",
					"GROUP",
					"HAVING",
					"ON",
					"SET",
				].includes(ctx.keyword)
			) {
				// collect columns from all tables in FROM
				const cols = Object.values(tablesMap).flatMap(
					(t) => schema.tables[t] || []
				);
				// deduplicate
				const uniq = Array.from(new Set(cols));
				return callback(null, columnCompletions(uniq));
			}

			// Fallback: suggest tables and top-level SQL keywords
			const keywords = [
				"SELECT",
				"FROM",
				"WHERE",
				"JOIN",
				"ON",
				"GROUP BY",
				"ORDER BY",
				"INSERT",
				"UPDATE",
				"DELETE",
			];
			const kw = keywords.map((k) => ({
				caption: k,
				value: k + " ",
				meta: "keyword",
			}));
			return callback(null, tableCompletions().concat(kw));
		},
	};

	// register completer using global ace ext
	if (window.ace && ace.ext && ace.ext.language_tools) {
		ace.ext.language_tools.addCompleter(completer);
	} else if (window.ace && ace.require) {
		const lt = ace.require("ace/ext/language_tools");
		lt.addCompleter(completer);
	} else {
		console.warn(
			"Ace language_tools not found. Make sure ext-language_tools.js is loaded."
		);
	}

	// load schema from window global if available
	loadSchemaFromWindow();

	// **Expose API**
	return {
		reload: loadSchemaFromWindow,
		loadSchema: async function (url) {
			await loadSchemaOnce(url);
		},
		getSchema: function () {
			return schema;
		},
	};
})();
