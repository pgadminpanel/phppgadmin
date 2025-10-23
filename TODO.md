## phpPgAdmin TODO LIST

phpPgAdmin is an open source project. If you see something on this list that you would like to implement, just send us a patch.

Project page: https://github.com/pgadminpanel/phppgadmin

An item is marked 'claimed' when a username is put in the "Claimed by" column. If you want to work on a claimed item, please contact the developers list.

**Status legend:**

| Status | Meaning |
|--------|---------|
| ✅ | Done (already implemented in the current version) |
| 🔄 | Needed in the future (still to do, description updated to current/future versions) |
| ❌ | Obsolete (outdated, no longer needed, or outside the scope of a management panel) |

---

### Cluster

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Database cluster management (roles, tablespaces) | (None) | See `roles.php`, `tablespaces.php`, `RoleActions.php` |
| ✅ | Cluster-level export (pg_dumpall, including roles and tablespaces) | (None) | See `dbexport.php`, `ServerDumper.php` |
| ✅ | Cluster-level import (classify CREATE ROLE/DATABASE/TABLESPACE) | (None) | See `StatementClassifier.php` |
| 🔄 | Default database on login page | (None) | Requested by several users; need to choose a default DB at login |
| 🔄 | Read-only display of `pg_settings` | (None) | Replaces reading postgresql.conf; do not modify the file |
| 🔄 | Read-only display of `pg_hba_file_rules` | (None) | Replaces reading pg_hba.conf; do not modify the file |
| 🔄 | Reload configuration via `pg_reload_conf()` | (None) | Read-only display + trigger reload; do not edit files directly |
| ❌ | Read postgresql.conf and pg_hba.conf | (None) | Instance operations; belongs to `pg_ctl` / `systemd` / cloud platforms |
| ❌ | Support pg_reload_conf(), pg_rotate_logfile() commands | (None) | Operations tasks; the panel should not trigger them directly |

### Export

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | CSV / HTML / JSON / SQL / XML / Tab export | (None) | See the Formatters under `Export/` |
| ✅ | gzip / bzip2 / zip compression | (None) | See `Export/Compression/` |
| ✅ | Dependency-aware export | (None) | See `Dump/DependencyGraph/` |
| 🔄 | Support SQL/XML export | (None) | For data exchange; low priority |
| ❌ | Switch to SPARQL format | (None) | Semantic web demand is very low; not core to a management panel |

### Import

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | CSV / JSON / XML row parsing | (None) | See `Import/Data/` |
| ✅ | COPY streaming import | (None) | See `CopyStreamHandler.php` |

### Users

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | User variables (ALTER USER SET … TO …) | (None) | User-level GUC settings |

### Groups

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Group management | (None) | See `groups.php`, `RoleActions.php` |

### Roles

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Role management (create/alter/drop/inherit/connection limit) | (None) | See `roles.php`, `RoleActions.php`, `RoleDumper.php` |

### Permissions

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Grant on all tables, views, etc. to user, group, public | (None) | Currently only per-object grants are possible |

### Databases

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Alter database variables | (None) | Database-level GUC settings |
| ✅ | Database statistics | (None) | See `statistics.php` |
| ✅ | REASSIGN OWNED & DROP OWNED support | (None) | See `strimportownership`, `strimportrights` |

### Schemas

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Schema management (create/alter/drop/search path) | (None) | See `schemas.php`, `SchemaActions.php` |

### Large Objects

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Large object support | (None) | See Dmitry Koterov's patch |

### Tables

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Replace WITHOUT OIDS with WITH OIDS | (None) | OIDS was removed in PostgreSQL 12 |
| 🔄 | Allow foreign keys during table creation | (None) | Currently FKs can only be added after creation |
| 🔄 | Restrict size on non-size types when adding columns or creating tables | (None) | Non-size types should not show a length input |
| 🔄 | Add WITH storage_parameter option | (None) | fillfactor, autovacuum_* etc. |
| ✅ | Show last vacuum and analyze information | (None) | See `strbrowsestatistics` |
| 🔄 | Restrict operators (to appropriate types) | (None) | e.g. no LIKE for int4 |
| 🔄 | Distinguish implicit casts from those requiring USING when altering columns | (None) | Cast handling must be differentiated |
| ✅ | Restrict ENUM types to enum values | (None) | See `strenum`, `strenumvalues` |

### Columns

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Add column constraints during creation and alteration | (None) | Column-level constraints currently require post-creation ALTER |
| ✅ | Display and edit generated columns | (None) | See `strgeneratedcolumn` |
| 🔄 | Visual JSON/JSONB editing | (None) | Common modern column type |
| 🔄 | Friendly array type editing | (None) | Common PostgreSQL type |

### Views

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Support temporary views | (None) | Object display |
| 🔄 | Support updatable views | (None) | Object editing |
| ✅ | Support materialized views | (None) | See `MaterializedViewDumper.php`, `strmaterializedview` |

### Sequences

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Sequence management (create/alter/restart/reset) | (None) | See `sequences.php`, `SequenceActions.php` |

### Functions

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Clean up JavaScript/HTML spec warnings | (None) | Frontend modernization |
| 🔄 | GUC settings | (None) | Function-level GUC |
| 🔄 | Default parameter values | (None) | Function default parameters |
| ❌ | Remove OUT/INOUT parameter options for older servers | (None) | Older versions are EOL |

### Indexes

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Support Reindex System | (None) | See `strreindex` |
| 🔄 | Expression indexes | (None) | Object editing |
| 🔄 | Create index with ASC/DESC, NULLS FIRST/LAST | (None) | Object editing |

### Types

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Suppress pseudo-type options when creating composite types | (None) | UI optimization |
| ✅ | Enum type management | (None) | See `strenum`, `TypeDumper.php` |

### Operators

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Create | (None) | See `OperatorActions.php`, `OperatorDumper.php` |
| 🔄 | Create/alter/drop operator family | (None) | Object editing |

### Operator Classes

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Create | (None) | See `OperatorClassActions.php`, `opclasses.php` |

### Triggers

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Allow functions from other schemas | (None) | Object editing |
| 🔄 | Support replica trigger modes | (None) | REPLICA / ALWAYS |

### Aggregates

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Support multi-column aggregates | (None) | Object editing |
| 🔄 | Rewrite the aggregate edit page (currently mostly text inputs) | (None) | UI optimization |

### Languages

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Drop | (None) | See `LanguageActions.php` |
| ✅ | Create | (None) | See `LanguageActions.php` |
| 🔄 | Alter owner | (None) | Object editing |
| 🔄 | Alter name | (None) | Object editing |

### Domains

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | ALTER DOMAIN SET SCHEMA support | (None) | Object editing |

### Conversions

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Properties | (None) | See `conversions.php` |
| ✅ | Drop | (None) | See `conversions.php` |
| ✅ | Create | (None) | See `conversions.php` |

### Casts

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Properties | (None) | See `casts.php` |
| ✅ | Drop | (None) | See `CastActions.php` |
| ✅ | Create | (None) | See `CastActions.php` |

### Full Text Search

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Create/alter/drop parser | (None) | See `FtsActions.php` |
| 🔄 | Alter owner | (None) | Object editing |

### Miscellaneous

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Show "what is blocking this query" in the processes view | (None) | Use `pg_blocking_pids()` |
| 🔄 | Show prepared statements in the database view | (None) | Use `pg_prepared_statements` |
| 🔄 | Show cursors in the database view | (None) | Object display |
| 🔄 | Show NOTICES for queries in the SQL window/file | (None) | Object display |
| 🔄 | Printable view | (None) | Object display |
| 🔄 | Show comments for all objects | (None) | Needed for data governance |
| 🔄 | Set/drop comments for all objects | (None) | Object editing |
| 🔄 | Show owner for all objects | (None) | Object display |
| 🔄 | Change owner for objects that support it | (None) | Object editing |
| ✅ | Add CASCADE option to Truncate | (None) | See `strcascade` |
| 🔄 | Add ONLY option to Truncate | (None) | Object editing |
| 🔄 | Support csvlog | (None) | Log display |
| 🔄 | Show executed file scripts in history | (None) | Needed for auditing |
| 🔄 | Audit PHP 8.6-dev compatibility | (None) | Adapt/test against the next development version |
| 🔄 | Support PostgreSQL 19-dev standard string syntax | (None) | Adapt/test against the next development version |
| 🔄 | Support per-database connection limits (`datconnlimit`) | (None) | Uncommon in cloud environments, but needed for local deployments |
| 🔄 | Improve translation sync tools (`sync.php`, `langcheck`) | (None) | Translation workflow |
| 🔄 | Translate the FAQ into all languages | (None) | Documentation translation |
| 🔄 | Pull FAQ/HISTORY/CREDITS from Git for the website | (None) | Website maintenance |
| ❌ | Audit PHP 5.3 compatibility | (None) | PHP 5.3 is EOL |
| ❌ | Support 8.1 standard strings E'' | (None) | Modern PostgreSQL supports them by default |
| ❌ | Add sync tool info for translators | (None) | Not a panel feature |
| ❌ | Pull FAQ/HISTORY/CREDITS from CVS | (None) | CVS has been replaced by Git |

### Exotic

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| 🔄 | Lightweight pivot reports via `plugins/Report` | (None) | Extension of data display |
| 🔄 | Parameterized query templates via `plugins/Report` | (None) | Extension of saved queries |
| 🔄 | Core panel pages conform to WCAG 2.2 AA | (None) | May be required for public-sector use |
| ❌ | Full web accessibility (original wording) | (None) | Very low priority for an internal professional tool |

### Principles

| Status | Item | Claimed by | Notes |
|--------|------|------------|-------|
| ✅ | Maximum error_reporting | (None) | See `errorhandler.inc.php` |
| 🔄 | Use PHP 8.6-dev features | (None) | Adapt/test against the next development version |
| ✅ | No HTML font/color/layout tags | (None) | See `themes/`, `Gui/` |
| 🔄 | Full HTML5 + semantic tags + ARIA | (None) | Frontend modernization |
| ✅ | Proper escaping against SQL injection and XSS | (None) | See `Html/`, `Database/` |
| ✅ | Properly schema qualified | (None) | See `Database/` |
| 🔄 | Support PostgreSQL 19-dev | (None) | Adapt/test against the next development version |
| ✅ | Put functions in the highest class possible | (None) | See `Database/Actions/` |
| ✅ | Follow PSR-12 coding standard | (None) | See `composer.json`, `phpunit.xml` |
| ✅ | Avoid global variables | (None) | See `AppContainer`, `AppContext` |
| ❌ | register_globals off | (None) | Removed in PHP 5.4 |
| ❌ | All XHTML | (None) | XHTML has been superseded by HTML5 |
| ❌ | Use PHP 5.0 features | (None) | PHP 5.0 is EOL |
| ❌ | psql -E for schema queries | (None) | Outdated |
| ❌ | Reference describe.c | (None) | Outdated |

Note: The feature descriptions in this list have only been reformatted into modern Markdown table format. The actual text content is for reference only. If you find any errors, please correct them promptly to avoid misunderstandings.