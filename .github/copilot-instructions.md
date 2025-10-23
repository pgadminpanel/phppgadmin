# phpPgAdmin - GitHub Copilot Instructions

## Project Overview

phpPgAdmin is a web-based PostgreSQL administration tool designed to manage databases, schemas, tables, roles, queries, and backups. It's a lightweight PHP application for database administrators, developers, and hosting providers.

**Version**: 8.0.rc1  
**License**: GPL-2.0+  
**PostgreSQL Minimum Version**: 9.0  
**PHP Minimum Version**: 7.4

## Technology Stack

- **Backend**: PHP 7.4+ with PostgreSQL extension (ext-pgsql)
- **Database**: PostgreSQL 9.0+
- **Database Library**: ADOdb (custom-modified version)
- **Frontend**: Vanilla JavaScript (ES5/ES6), jQuery-free
- **CSS**: Custom themes with global.css base
- **JavaScript Libraries**:
    - Ace Editor (SQL editing)
    - Flatpickr (date/time pickers)
    - Highlight.js (syntax highlighting)
    - fflate (compression)
- **Dependency Management**: Composer
- **SQL Parser**: greenlion/php-sql-parser

## Architecture

### PHP Structure

- **Bootstrap**: `libraries/bootstrap.php` - Application initialization and configuration
- **Namespace**: `PhpPgAdmin\` maps to `libraries/PhpPgAdmin/`
- **PSR-4 Autoloading**: Via Composer
- **Core Classes**:
    - `PhpPgAdmin\Core\AppContainer` - Dependency injection container
    - `PhpPgAdmin\Core\AppContext` - Base context class
    - `PhpPgAdmin\Database\*` - Database connection and operations
    - `PhpPgAdmin\Gui\*` - GUI rendering components
    - `PhpPgAdmin\Html\*` - HTML element builders

### Frontend Structure

- **Frameset System**: `js/core/frameset.js` - Single-page navigation, POST caching, history management
- **Utilities**: `js/core/misc.js` - Common functions, SQL parsing, date pickers, editor initialization
- **Page-specific JS**: `js/{database,display,tables,indexes,functions,casts}.js`
- **Import System**: `js/import/*` - File upload and import functionality

### Key Architectural Patterns

1. **Export Architecture** (see `docs/UNIFIED_EXPORT_ARCHITECTURE.md`):
    - Unified formatters accepting ADORecordSet
    - Streaming output support for large exports
    - Multiple formats: SQL, COPY, CSV, Tab, HTML, XML, JSON

2. **Tree Navigation ID System** (see `docs/TREE_README_SYSTEM.md`):
    - Hierarchical database object navigation
    - ID-based navigation for servers → databases → schemas → objects
    - Comprehensive documentation in docs/TREE\_\*.md files

3. **Database Actions**: `libraries/PhpPgAdmin/Database/Actions/*`
    - Action classes for schema, table, database operations
    - Separation of concerns between UI and database operations

## Coding Standards

### PHP Code Style

```php
// Use PSR-4 autoloading and namespaces
namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Connector;

// Extend AppContext for core functionality
class SchemaActions extends AppContext
{
    // Use camelCase for methods
    public function createSchema($schemaname, $authorization)
    {
        // Implementation
    }

    // Use descriptive variable names
    private function validateSchemaName($schemaName): bool
    {
        // Implementation
    }
}
```

### JavaScript Code Style

```javascript
// Use IIFE to avoid global pollution
(function () {
	"use strict";

	// Attach to window only when needed
	window.myFunction = function (param) {
		// Implementation
	};

	// Use addEventListener, not inline handlers
	document.addEventListener("DOMContentLoaded", function () {
		// Initialization
	});

	// Use descriptive function names
	function initializeSqlEditor(element) {
		// Implementation
	}
})();
```

### CSS Code Style

- Use semantic class names
- Follow BEM-like conventions where appropriate
- Import `global.css` for base styles
- Theme-specific files in `themes/` directory
- Use CSS custom properties for theming: `--tooltip-bg`, `--tooltip-color`, etc.

## Important Guidelines

### When Working with Database Operations

1. **Always use prepared statements** - Never concatenate user input into SQL
2. **Use ADOdb methods** for database interaction
3. **Check permissions** before executing operations
4. **Handle transactions** properly with BEGIN/COMMIT/ROLLBACK
5. **Quote identifiers** using `pg_escape_identifier()` or database methods

### When Working with the UI

1. **Use existing renderers**: `TableRenderer`, `FormRenderer`, `RowBrowserRenderer`, etc.
2. **Maintain frameset compatibility** - Use frameset navigation, not direct page loads
3. **Support AJAX operations** - Many operations use AJAX for better UX
4. **Preserve form state** - The frameset system handles form state persistence
5. **Use existing CSS classes** - Don't introduce new styles without checking existing themes

### When Working with JavaScript

1. **No jQuery** - This project is jQuery-free
2. **Use vanilla DOM APIs** - `querySelector`, `addEventListener`, etc.
3. **Respect the frameset** - Use `loadContent()` for navigation
4. **Initialize components** - SQL editors, date pickers, etc. use factory functions
5. **Use existing utilities** - Check `js/core/misc.js` before reinventing

### Export/Import System

1. **Use unified formatters** - All formatters implement `OutputFormatter` interface
2. **Support streaming** - Use `setOutputStream()` for large exports
3. **Handle metadata** - Include column types, constraints in exports
4. **Test with ADORecordSet** - All formatters accept ADORecordSet input
5. **Document format options** - Each format has specific options

### Internationalization (i18n)

1. **Use language files** - All strings in `lang/*.php`
2. **Function**: `$lang['key']` for translations
3. **Never hardcode English strings** - Always use language keys
4. **Update translations** - Add keys to `lang/english.php` first
5. **Check existing keys** - Use `grep` to find similar translations

## File Organization

```
Root PHP files          → Direct page endpoints (tables.php, schemas.php, etc.)
conf/                   → Configuration files
libraries/
  ├── bootstrap.php     → Application startup
  ├── helper.php        → Helper functions
  ├── decorator.php     → Output decoration
  └── PhpPgAdmin/       → PSR-4 namespace root
      ├── Core/         → Core application classes
      ├── Database/     → Database operations
      ├── Gui/          → GUI rendering
      └── Html/         → HTML element builders
js/
  ├── core/             → Core JavaScript (frameset, misc utilities)
  ├── import/           → Import functionality
  └── lib/              → Third-party libraries
lang/                   → Language files
themes/                 → CSS themes
docs/                   → Technical documentation
plugins/                → Plugin system
```

## Testing

- **Selenium Tests**: `tests/selenium/`
- **SimpleTests**: `tests/simpletests/`
- **Test locally** before submitting changes
- **Use test database** - Never test on production data

## Development Workflow

1. **Fork** the repository on GitHub
2. **Create branch** for your feature: `git checkout -b describe_my_fix`
3. **Rebase regularly** from upstream: `git pull upstream master --rebase`
4. **Test thoroughly** using Selenium tests
5. **Submit Pull Request** with clear description
6. **Follow code review** feedback

## Common Patterns

### Database Query Pattern

```php
// Get database connection
$data = $this->getDatabaseAccessor();

// Execute query with parameters
$sql = "SELECT * FROM pg_tables WHERE schemaname = $1";
$result = $data->execute($sql, [$schemaname]);

// Process results
while (!$result->EOF) {
    $row = $result->fields;
    // Process row
    $result->MoveNext();
}
```

### Renderer Pattern

```php
// Use renderers for consistent UI
use PhpPgAdmin\Gui\TableRenderer;

$tableRenderer = new TableRenderer($data, $this->conf);
$tableRenderer->printTable($records, $columns, $actions, $tableAttrs);
```

### JavaScript Initialization Pattern

```javascript
// Initialize components after content load
document.addEventListener("frameLoaded", function (e) {
	const contentFrame = e.detail.frame;
	createSqlEditors(contentFrame);
	createDateAndTimePickers(contentFrame);
	highlightDataFields(contentFrame);
});
```

## Security Considerations

1. **SQL Injection**: Always use parameterized queries
2. **XSS Protection**: Escape output with `htmlspecialchars()`
3. **CSRF Protection**: Use tokens for state-changing operations
4. **Authentication**: Check `$_SESSION['webdbLogin']` before operations
5. **Authorization**: Verify user has permission for requested operation
6. **File Operations**: Validate paths, use `basename()`, check permissions

## Performance Considerations

1. **Large Result Sets**: Use cursors for pagination
2. **Export Operations**: Use streaming output to avoid memory issues
3. **Cache Configuration**: Use `AppContainer` for shared instances
4. **Lazy Loading**: Load JavaScript libraries only when needed
5. **Database Connections**: Reuse connections, close when done

## Plugin System

- Plugins located in `plugins/` directory
- Extend `PhpPgAdmin\Plugin` abstract class
- Register via `PluginManager`
- Examples: `GuiControl`, `Report`

## Documentation

Always refer to comprehensive documentation in `docs/`:

- `TREE_README_SYSTEM.md` - Tree navigation system
- `UNIFIED_EXPORT_ARCHITECTURE.md` - Export system architecture
- `TREE_QUICK_GUIDE.md` - Visual quick start
- Other TREE\_\*.md files for specific topics

## When Suggesting Changes

1. **Check documentation** first - Many systems are well-documented
2. **Maintain backwards compatibility** - phpPgAdmin has many users
3. **Follow existing patterns** - Don't introduce new paradigms without discussion
4. **Test across PostgreSQL versions** - Support 9.0+
5. **Update documentation** - If changing architecture, update docs/
6. **Consider i18n** - New features need translations
7. **Theme compatibility** - Test UI changes across themes

## Common Tasks

### Adding a New Database Feature

1. Create action class in `libraries/PhpPgAdmin/Database/Actions/`
2. Add SQL generation methods
3. Create/update page file in root (e.g., `tables.php`)
4. Add JavaScript if needed in `js/`
5. Add language strings to `lang/english.php`
6. Test with various PostgreSQL versions

### Adding a New Export Format

1. Create formatter in `libraries/PhpPgAdmin/Database/Export/`
2. Extend `OutputFormatter` abstract class
3. Implement `format()`, `getMimeType()`, `getFileExtension()`
4. Support streaming via `setOutputStream()`
5. Register in export system
6. Add tests

### Modifying the UI

1. Check existing renderers in `libraries/PhpPgAdmin/Gui/`
2. Update CSS in appropriate theme file
3. Test frameset compatibility
4. Ensure form state preservation works
5. Test across themes
6. Update helper.css if adding reusable styles

---

**Remember**: phpPgAdmin is a mature, production-used tool. Prioritize stability, backwards compatibility, and security over new features. Always test thoroughly with real PostgreSQL databases.
