# phpPgAdmin Changelog

::: tip Note
### All significant updates to this project will be recorded in this changelog.
:::

## phpPgAdmin 8.0.4 Release Notes

**Release date**: September 23, 2026

This update consists of two parts: **Dump dependency ordering fixes** and **language file expansion and corrections**.

---

## 1. Dump Dependency Ordering Fixes

### Fixed

- **Fixed incorrect dependency ordering in dumps**: When a table's DEFAULT expression, CHECK constraint, or generated column references a function, the function is now correctly dumped before the table. Previously, the generated SQL failed on re-import with "function does not exist".

- **Fixed incorrect ordering for aggregates**: Aggregates are now dumped after their support functions (SFUNC, etc.).

- **Fixed missing function-to-function dependency ordering**: Functions were previously dumped in alphabetical order only, so a function calling another could be dumped before its dependency, causing import failures. Function bodies are now parsed to determine the real dependency order.

- **Fixed incorrect ordering for domain constraints**: If a domain's CHECK constraint references a user-defined function, the constraint is now deferred until after all functions are dumped, avoiding "function does not exist" errors during import.

- **Fixed schema list leaking across instances**: When dumping multiple schemas in the same process, the second and subsequent calls could use the wrong schema list.

### Tests

- Added 14 integration tests covering functions, tables, partitions, aggregates, domains, foreign keys, circular dependencies, and other scenarios.
- Added 6 test fixtures.

---

## 2. Language File Expansion and Corrections

### Translation and Terminology Fixes

- **Added translations for new features**: generated columns, import/export forms, statistics dashboard, materialized views, partitioned tables, type management (base types, range types, enum values), CAST management, SQL editor, and query history.
- **Unified terminology**: terms such as "Owner", "Name", "Alter", "Enable/Disable" are now consistent with their actual UI meaning.
- **Fixed older mistranslations**: e.g. "Crash" corrected to "Collapse", "Cluster" corrected to "Reindex", "Fixed (Tabbed)" corrected to "Tab-separated".

### New Languages (27)

This update adds 27 language files, registered in both `$appLangFiles` and `$availableLanguages`:

| Language file | Language code | Language name |
|---|---|---|
| `albanian` | `sq` | Shqip |
| `amharic` | `am` | አማርኛ |
| `armenian` | `hy` | Հայերեն |
| `azerbaijani` | `az` | Azərbaycan |
| `bengali` | `bn` | বাংলা |
| `bosnian` | `bs` | Bosanski |
| `bulgarian` | `bg` | Български |
| `croatian` | `hr` | Hrvatski |
| `estonian` | `et` | Eesti |
| `finnish` | `fi` | Suomi |
| `georgian` | `ka` | ქართული |
| `hausa` | `ha` | Hausa |
| `hindi` | `hi` | हिन्दी |
| `indonesian` | `id` | Bahasa Indonesia |
| `korean` | `ko` | 한국어 |
| `kurdish` | `ku` | Kurdî |
| `lao` | `lo` | ລາວ |
| `malay` | `ms` | Bahasa Melayu |
| `norwegian` | `no` | Norsk |
| `persian` | `fa` | فارسی |
| `punjabi` | `pa` | ਪੰਜਾਬੀ |
| `serbian` | `sr` | Српски |
| `swahili` | `sw` | Kiswahili |
| `tamil` | `ta` | தமிழ் |
| `thai` | `th` | ไทย |
| `urdu` | `ur` | اردو |
| `vietnamese` | `vi` | Tiếng Việt |

### Missing Language Registration

- **`portuguese-pt`**: The file already existed but was not registered; now added:
  - `$appLangFiles`: `'portuguese-pt' => 'Português'`
  - `$availableLanguages`: `'pt' => 'portuguese-pt'`
- `hebrew.php`, `mongol.php`, and `lithuanian.php` were already registered correctly; no changes needed.

### Language Mapping Fixes

- **Incorrect `pt` mapping**: `pt` (European Portuguese) was incorrectly mapped to Brazilian Portuguese `portuguese-br`. Now corrected to:
  - `'pt' => 'portuguese-pt'` (European Portuguese)
  - `'pt-br' => 'portuguese-br'` (Brazilian Portuguese)

### Language Display Name Fixes

- **`chinese-zh-CN`**: corrected the display name shown in the language selector to the proper Simplified Chinese label
- **`chinese-zh-TW`**: corrected the display name shown in the language selector to the proper Traditional Chinese label

### Language Coverage

The application now supports 56 languages, covering all major language regions worldwide:

- **Europe**: English, German, French, Spanish, Italian, Portuguese, Russian, Ukrainian, etc.
- **Asia**: Chinese, Japanese, Korean, Hindi, Bengali, Tamil, Thai, Vietnamese, Indonesian, etc.
- **Middle East**: Arabic, Hebrew, Persian, Turkish, Kurdish, etc.
- **Africa**: Swahili, Hausa, Amharic, Afrikaans, etc.

### Translation Improvements and Feedback

This update adds 27 new languages and supplements/corrects existing translations. If you find translation errors while using the application, please report them via:

- **Discussions**: [pgadminpanel discussions](https://github.com/orgs/pgadminpanel/discussions)
- **Issues**: [pgadminpanel issues](https://github.com/pgadminpanel/phppgadmin/issues)

Corrections to translations are welcome and help us continuously improve translation quality across all languages.

---

## phpPgAdmin 8.0.3 Release Notes

The following changelog was compiled from the commit history of [osiramen/phppgadmin](https://github.com/osiramen/phppgadmin/commits).

This update includes PHP 8 compatibility work, architectural refactoring, UI and interaction improvements, query and statistics features, import/export enhancements, database object management, security and authentication, plugins and languages, tests and dependencies, and bug fixes. Major changes:

---

## 1. PHP 8 Compatibility

- Completed PHP 8 compatibility work; minimum supported version is now PHP 7.4, with PHP 8 support (2025-10-23)
- Removed PHP 8-deprecated `each()`, replaced with `foreach` (2025-10-23)
- Converted old-style constructors to `__construct()` (2025-10-23)
- Removed trailing `?>` from pure PHP files (2025-12-02)
- Imported source from `EdvaldoAFilho/pgAdmin` (PHP 8+ adaptation branch) (2025-12-01)
- Upgraded ADOdb to v5.22.11 (2025-12-01)
- Updated PHP version requirement in `composer.json` (2025-10-23)

## 2. Architectural Refactoring

- Split the large database class into multiple independent classes by responsibility (2025-12-13)
- Further split the `Misc` class into several single-responsibility classes (2025-12-13)
- Migrated all classes to the new `PhpPgAdmin\...` namespace (2025-12-13)
- Replaced global variables with the `AppContainer` container (2025-12-15 – 2025-12-16)
- Introduced a new `Postgres` class; removed all legacy PostgreSQL version classes (2025-12-13)
- Raised the minimum supported PostgreSQL version to 9.0 (2025-12-13)
- Renamed `lib.inc.php` to `bootstrap.php` (2025-12-14)
- Standardized database action class names (`SchemaActions`, `RoleActions`, `TableActions`, `SequenceActions`, etc.) (2025-12-14 – 2026-01-03)
- Eliminated global variable dependencies in plugins (2025-12-14)

## 3. UI and Interaction

- Removed the old `frameset` layout (2025-12-05)
- Fully redesigned the interface style (2025-12-05)
- Added icons to action buttons (2025-12-05)
- Increased Bootstrap theme font size (2025-12-01)
- Tree browser now supports scrolling; improved state persistence (2025-12-16, 2025-12-18)
- Sticky table headers (2026-01-01, 2026-01-24)
- Added `pga-symbols` font (2026-01-14)
- Introduced Ace SQL editor with PostgreSQL syntax highlighting (2025-12-07)
- Introduced highlight.js for SQL/PL-pgSQL, JSON, and XML highlighting (2026-01-04, 2026-01-13)
- SQL editor and popup editor now support maximizing and auto-expanding height (2025-12-21, 2026-01-28)
- Introduced flatpickr date picker with microsecond and time selection (2025-12-12, 2026-01-14, 2026-01-22)
- Implemented inline row editing (Popup Field Editor) (2026-01-21)
- Support for `bytea` field upload and download (including large-file chunking) (2026-01-14, 2026-01-15)
- Disallowed sorting on JSON/XML columns and editing of `tsvector` columns (2026-01-14, 2026-02-06)
- Row browser supports foreign key links (2026-01-21)

## 4. Queries and Statistics

- Added query statistics (2025-12-07)
- Added database statistics dashboard with real-time charts for sessions, transactions, tuples in/out, and block I/O (2026-02-07)
- Introduced PHPSQLParser for parsing SQL input (2025-12-12)
- Refactored the SQL browser (2025-12-12)
- Added Query EXPLAIN feature (2026-02-04)
- SQL parser enhancements: dollar-quoted strings, structured COPY statement return, improved comment and result-set detection (2026-02-01, 2026-02-03)
- Query history deduplication (2025-12-18)
- IndexedDB caching of POST results (2026-01-21)

## 5. Export / Import / Dump

### Export

- Unified export form supporting databases, schemas, tables, views, and SQL queries (2025-12-26)
- Support for internal dumper and external `pg_dump` (2025-12-23 – 2025-12-25)
- Added streaming export to improve memory efficiency for large result sets (2025-12-26, 2026-01-18)
- Added `CursorReader` for streaming reads and `RowSizeEstimator` for row-size estimation (2026-01-17)
- Support for gzip, bzip2, and zip compressed exports (2025-12-29)
- Support for `bytea` encoding selection (hex / base64 / octal) (2026-01-12)
- Export form grouped by object type (2026-01-16)

### Import

- Added import feature with gzip, bzip2, and zip compression support (2025-12-27)
- Chunked upload and resumable transfers (2025-12-27 – 2025-12-30)
- Job management interface (view, start, cancel, resume, delete) (2025-12-28)
- Import options: roles, tablespaces, error handling, ownership management (2025-12-27, 2026-01-16)
- Support for `COPY` format conversion (2026-01-11)
- Added `StatementExecutor` and `StatementClassifier` (2026-01-09)
- Added `ColumnHeaderBuilder` and CSV/JSON/XML row parsers (2026-01-11, 2026-01-12)

### Internal Dump

- Added internal dump mechanism, no longer fully reliant on `pg_dump` (2025-12-23)
- Added various dumpers: database, schema, table, sequence, type, view, role, tablespace, domain, aggregate, etc. (2025-12-23 – 2026-01-17)
- Support for inter-table foreign key dependency analysis (2026-01-28, 2026-04-24)
- Support for deferred processing of generated columns and default expressions (2026-01-28)
- Support for `IF NOT EXISTS` / `OR REPLACE` syntax (2026-01-05)
- Support for data-only / structure-only exports (2026-05-02)
- Skip ownership statements for data-only exports (2026-04-24)

## 6. Database Object Management

- Support for materialized views (create, refresh, UI) (2026-01-27)
- Support for partitioned tables: create partitioned tables (PG 10+), convert regular tables to partitioned, display partition information (2026-01-30)
- Support for type management: type comments, base type creation, type editing (2025-12-15, 2026-01-06)
- Support for CAST creation and management (2026-01-04)
- Support for operator creation and operator class management (2026-01-02, 2026-01-03)
- Added `CastActions`, `DomainActions`, `OperatorClassActions`, etc. (2026-01-03 – 2026-01-04)
- Enhanced role management: name validation, comments, transaction handling (2026-02-05)
- Display system roles for database objects (2026-04-24)
- Show owner and size in table, view, sequence, and type management pages (2026-02-04)
- Added row count and totals to table footers (2026-02-04)
- Refactored sequence management using `SequenceActions` (2025-12-29)
- Enhanced sequence and table handling with unique selection and ownership information (2026-05-02)

## 7. Security and Authentication

- Introduced sodium encryption extension requirement (2026-01-23)
- Credential encryption and key version validation; session invalidated automatically on key change (2026-01-23)
- Support for form authentication, HTTP Basic authentication, and config-based encrypted credential authentication (2026-01-23)
- Session path configuration validation and automatic directory creation (2025-12-24)
- Replaced `htmlspecialchars_nc` with `html_esc` to strengthen XSS protection (2025-12-31)

## 8. Plugins and Languages

- Enhanced plugin architecture with hooks for tree / navlink / actionbuttons (2025-12-14)
- Eliminated global variable dependencies in plugins (2025-12-14)
- Updated German translations (2026-02-05, 2026-02-07)
- Added language synchronization tool `sync.php` (2026-02-02)
- Replaced HTML entities with UTF-8 characters in `translations.php` (2026-02-02)
- Added language strings for statistics, import, export, EXPLAIN, etc. (2025-12-27, 2026-02-04, 2026-02-07)

## 9. Tests and Dependencies

- Added `DependencyGraph` unit tests (2026-01-28)
- Added Deflate / Gzip / Zlib tests (2025-12-31)
- Added SQL parser tests (2026-02-01, 2026-02-03)
- Updated `sebastian/version` package (2026-02-01)
- Added `theseer/tokenizer` library (2026-02-01)
- Removed Symfony Mbstring polyfill (2025-12-29)
- Updated `.editorconfig` and `.gitignore` (2025-12-18, 2026-02-01)

## 10. Bug Fixes

- Fixed `each()` deprecation warning (2025-10-23)
- Fixed by-reference warnings on constraint, index, and view pages (2025-12-19 – 2025-12-22)
- Fixed long-query download issue (2026-01-24)
- Fixed login failure message (2026-01-17)
- Fixed domain and type comment handling (2026-01-05, 2026-01-06)
- Fixed domain default value check (2025-12-24)
- Fixed secure access to role attributes (2025-12-24)
- Fixed logo path and badge colors in README (2026-01-27, 2026-02-04)
- Fixed incorrect sequence handling in data/structure exports (2026-05-02)
- Fixed ownership statements still emitted in data-only exports (2026-04-24)
- Fixed CASCADE option support for empty-table operations (2026-04-24)
- Fixed inter-table foreign key dependency ordering (2026-04-24)

## 11. New Features

- **Catalogs navigation**: System catalog schemas (`pg_catalog`, `information_schema`) now appear in a separate "Catalogs" section in the tree navigation, following the convention established by pgAdmin III/4 since 2011, clearly separating system metadata from user schemas.
- Added support for PostgreSQL 15–18.

---

## Version 7.14.6-hestiacp

**Release date**: September 18, 2023

Changes from the commit history of [hestiacp/phppgadmin](https://github.com/hestiacp/phppgadmin).

### Fixed

- **Security fix**: Added a `safeUnserialize()` wrapper and replaced all `unserialize()` calls to prevent PHP object injection attacks (function taken from phpMyAdmin 5.2.1)
- **Strengthened session security check**: `extra_session_security` is now evaluated as `($conf['extra_session_security'] ?? true) === true` to avoid misjudgment when unset
- **Improved security warning text**: The session security warning now reads "You are running phpPgAdmin with session security disabled. This is a potential security risk!"
- **Updated session security FAQ link**: Changed from `phppgadmin.sourceforge.net` to the `php.net` session.cookie-samesite documentation
- Fixed the `extra_session_security` evaluation logic in `Misc.php`

### Code Quality

- Unified indentation (tabs/spaces)
- Removed trailing `?>` from files
- Unified brace style in control structures
- Updated `composer.lock`'s `plugin-api-version` to 2.6.0

---

## Version 7.13.0

**Released**: November 7th, 2020

### Features

- Add support for Postgres 13
- Add provisional support for Postgres 14
- Upgrade jQuery library to 3.4.1 (Nirgal)
- Allow users to see group owned databases when using "owned only"

### Bugs

- Fix bug where sorting on selects dumped you to the table screen (MichaMEG)

### Incompatibilities

- This release drops support for PHP 7.1
- This will be the last release to support PHP 7.2

---

## Version 7.12.1

**Released**: December 10th, 2019

### Features

- Add support for granting USAGE on sequences
- Update French translation

### Bugs

- Fix issues with OID removal in Postgres 12+
- Remove broken tree branch from table/view browse option
- Properly escape identifiers when browsing tables/views/schemas
- Fix truncation of long multibyte strings
- Clean up a number of misspellings and typos from codespell report

### Incompatibilities

- Require mbstring module support in PHP

---

## Version 7.12.0

**Released**: September 28, 2019

### Features

- Add Support for PHP 7.x
- Add Support for Postgres 12
- Update Bootstrap to version 3.3.7 (wisekeep)

### Bugs

- Fix several issues with CSS files (wisekeep)
- Clean up file permissions (nirgal)
- Fixed Reflected XSS vulnerability (om3rcitak)
- Fixes with sequence visibility and permission handling

### Incompatibilities

- We no longer support PHP 5
- Change in version numbering system

---

## Version 5.6

**Released**: November 12th, 2018

### Features

- Add support for PostgreSQL 9.3, 9.4, 9.5, 9.6, 10, 11
- Development support for PostgreSQL 12
- Add support for browse/select navigation tabs (firzen)
- Add new theme, "bootstrap" (amenadiel)
- Improved support for json/jsonb

### Bugs

- Fix bug in Turkish translation which caused failed ajax responses
- Account for Blocked field in admin processes Selenium test
- Properly handle column comments
- Fix background css issue
- Additional language updates

### Incompatibilities

- Dropped testing of pre-9.3 versions of Postgres, which are now EOL

---

## Version 5.1

**Released**: April 14th, 2013

### Features

- Full support for PostgreSQL 9.1 and 9.2
- New plugin architecture, including addition of several new hooks (asleonardo, ioguix)
- Support nested groups of servers (Julien Rouhaud & ioguix)
- Expanded test coverage in Selenium test suite
- Highlight referencing fields on hovering Foreign Key values when browsing tables (asleonardo)
- Simplified translation system implementation (ioguix)
- Don't show cancel/kill options in process page to non-superusers
- Add download ability from the History window (ioguix)
- User queries now paginate by default

### Bugs

- Fix several bugs with bytea support, including possible data corruption bugs when updating rows that have bytea fields
- Numerous fixes for running under PHP Strict Standards
- Fix an issue with autocompletion of text based Foreign Keys
- Fix a bug when browsing tables with no unique key

### Translations

- Lithuanian (artvras)

### Incompatibilities

- We have stopped testing against Postgres versions < 8.4, which are EOL
- phpPgAdmin core is now UTF-8 only

---

## Version 5.0

**Released**: November 29th, 2010

### Features

- Support for PostgreSQL 8.4 and 9.0
- Support for database level collation for 8.4+
- Support for schema level export
- Add ability to alter schema ownership
- Clean up domain support and improve interface
- Add support for commenting on functions
- Allow user to rename role/users and set new passwords at the same time
- Greatly enhanced Full-Text-Search capabilities (ioguix, Loomis_K)
- Overhauled Selenium Test suite to support multiple database versions
- Optimized application graphics (Limo Driver)
- Support for Column Level Privileges
- Allow users to specify a template database at database creation time
- Support killing processes
- Add ability to create indexes concurrently
- Much better support of autovacuum configuration
- Add an admin page for table level
- Refactored autocompletion:
    - Fix support for cross-schema objects
    - Support multi-field FK
    - Support for pagination of values in the auto-complete list
- Allow user to logically group their server under custom named node in the browser tree
- New themes (Cappuccino and Gotar) and a theme switcher on the introduction page
- Auto refresh Locks page
- Auto refresh Processes page
- Link in the bottom of the page to go to top of page
- Browsing on Foreign Keys (When browsing a table, clicking on a FK value, jump to the PK row)

### Bugs

- Fix problems with query tracking on overly long queries
- Ensure pg_dump paths are valid
- Fix multiple bugs about quoting and escaping database objects names with special chars
- Fix multiple bugs in the browser tree
- Fix multiple bugs on the SQL and script file import form
- Three security fix about code injection
- Don't allow inserting on a table without fields
- Some fix about commenting databases
- Removed deprecated functions from PHP 5.3
- Lot of code cleanup
- Many other small minor bugs found on our way
- Fix the operator property page

### Translations

- Czech (Marek Cernocky)
- Greek (Adamantios Diamantidis)
- Brazilian Portuguese (Fernando Wendt)
- Galician (Adrián Chaves Fernández)

### Incompatibilities

- No longer support PHP < 5.0
- No longer support Postgres < 7.4

---

## Version 4.2

### Features

- Add Analyze to Table Level Actions (ioguix)
- Add support for multiple actions on main pages (ioguix, Robert Treat)
- Added favicon for Mozilla and a backwards compatible version for IE
- Allow browsers to save different usernames and passwords for different servers
- Pagination selection available for reports
- You can configure reports db, schema and table names
- Add support for creating a table using an existing one (ioguix)
- Auto-expand a node in the tree browser if there are no other nodes (Tomasz Pala)
- Add column about fields constraints type + links in table properties page (ioguix)
- Support for built-in Full Text Search (Ivan Zolotukhin)
- Add alter name, owner & comment on views (ioguix)
- Add column about called procedure + links to their definition in the triggers properties page (ioguix)
- Add Support for Enum type creation (ioguix,xzilla)
- Add alter name, owner, comment and properties for sequences (ioguix)
- Add function costing options (xzilla)
- Add alter owner & schema on function (xzilla)
- Add a popup window for the session requests history (karl, ioguix)
- Add alter table, view, sequence schema (ioguix)

### Bugs

- Fix inability to assign a field type/domain of a different schema
- Can't edit a report and set its comment to empty
- Fix PHP5 Strict mode complaints
- Fix IN/NOT IN to accept text input lists 'a','b'
- Fix bytea doesn't display as NULL when NULL
- Schema qualifying every object to avoid non wanted behaviour about users' rights and schema_path
- Remove shared credentials when logging out of single server, to prevent automatic re-login
- Improved SSL connection handling, fix problems with connections from older php builds
- Fix bug with long role name truncation
- Fix bug with DELETE FROM when dropping a row (spq)
- Fix problems when deleting PUBLIC schema
- Fix several bugs in aggregate support
- Improve autocompletion support
- Tighten up use of global scope variables

### Translations

- UTF Traditional Chinese (Kuo Chaoyi)
- UTF Simplified Chinese (Kuo Chaoyi)
- Italian (Nicola Soranzo)
- Catalan (Bernat Pegueroles)
- French (ioguix)
- German (Albe Laurenz, spq)
- Japanese (Tadashi Jokagi)
- Hungarian (Sulyok Peti)

---

_For versions 4.1.3 and earlier, see the original HISTORY file_