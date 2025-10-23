# Unified Export Architecture

## Overview

The export system now uses a unified architecture where all formatters accept ADORecordSet input, enabling a single code path for both database structure exports (via dumpers) and query result exports.

**Key Feature:** Output formatters support **streaming output** via output streams for memory efficiency on large exports, or collect output as strings for flexibility.

## Architecture Components

### 1. OutputFormatter Interface & Implementations

All formatters implement a consistent interface with streaming support:

```php
abstract class OutputFormatter
{
    // Set output stream for memory-efficient streaming
    public function setOutputStream($stream);  // Accepts resource|null

    // Format data (writes to stream if set, returns string otherwise)
    public function format(mixed $recordset, array $metadata = []): string;

    public function getMimeType(): string;
    public function getFileExtension(): string;
}
```

**Supported Formatters:**

- `SqlFormatter` - SQL INSERT statements (single, multi-row) or COPY format
- `CopyFormatter` - PostgreSQL COPY FROM stdin format
- `CsvFormatter` - RFC 4180 CSV format
- `TabFormatter` - Tab-delimited format
- `HtmlFormatter` - XHTML table output
- `XmlFormatter` - XML structure with metadata
- `JsonFormatter` - JSON with column metadata

### 2. DumperInterface

Dumpers provide three export patterns:

```php
interface DumperInterface
{
    // Traditional full export: outputs complete SQL
    public function dump($subject, array $params, array $options = []);

    // Split export: returns [structure, recordset, columns, metadata]
    public function getDump($subject, array $params, array $options = []);

    // Data-only export: returns ADORecordSet
    public function getTableData(array $params);
}
```

**Supported Dumpers:**

- `TableDumper` - Tables (with structure + data)
- `ViewDumper` - Views (read-only data export)
- `DatabaseDumper` - Full database dumps
- `SchemaDumper` - Schema exports
- And 12 other specialized dumpers

### 3. FormatterFactory

Centralizes formatter creation:

```php
$formatter = FormatterFactory::create('csv');
$output = $formatter->format($recordset, $metadata);
```

## Usage Patterns

### Pattern 1a: Query Export (String Collection - Default)

For exporting query results and collecting output as string:

```php
// Execute query
$recordset = $pg->conn->Execute($sql);

// Create formatter
$formatter = FormatterFactory::create('csv');

// Format data (collects in memory)
$metadata = ['table' => 'query_result', 'insert_format' => 'copy'];
$output = $formatter->format($recordset, $metadata);

// Send to client
header('Content-Type: text/csv');
echo $output;
```

### Pattern 1b: Query Export (Streaming - Memory Efficient)

For large exports, use output streams to avoid collecting entire output in memory:

```php
// Execute query
$recordset = $pg->conn->Execute($sql);

// Create formatter
$formatter = FormatterFactory::create('csv');

// Set output stream (streams directly to STDOUT)
$formatter->setOutputStream(STDOUT);

// Format data (writes to stream, does not return)
$metadata = ['table' => 'query_result'];
$formatter->format($recordset, $metadata);

// Output automatically sent to client
```

**Location:** `dataexport.php` uses streaming for non-gzipped output

### Pattern 1c: Query Export (Gzipped - Buffered)

For gzipped output, buffer to memory first, compress, then send:

```php
// Need to collect output first to compress
$recordset = $pg->conn->Execute($sql);
$formatter = FormatterFactory::create('csv');

// Use string collection mode
$formatter->setOutputStream(null);
$output = $formatter->format($recordset, $metadata);

// Compress and send
$output = gzencode($output, 9);
header('Content-Type: application/gzip');
echo $output;
```

### Pattern 2: Table Export (Structure + Data)

For exporting table structure with data:

```php
// Get dumper
$dumper = new TableDumper($connection);

// Get split dump
$params = ['table' => 'users', 'schema' => 'public'];
$dump = $dumper->getDump('table', $params, ['clean' => true]);

// Format data with chosen formatter
$formatter = FormatterFactory::create('json');
$dataOutput = $formatter->format($dump['recordset'], $dump['metadata']);

// Combine structure + formatted data
$output = $dump['structure'] . "\n\n" . $dataOutput;
```

**Location:** `dbexport.php` (can use either `dump()` or `getDump()`)

### Pattern 3: Full Database Export

For traditional SQL exports:

```php
// Get dumper for entire database
$dumper = new DatabaseDumper($connection);

// Direct dump to stdout
ob_start();
$dumper->dump('database', $params, $options);
$output = ob_get_clean();

// Send to client as SQL
header('Content-Type: application/sql');
echo $output;
```

**Location:** `dbexport.php` (traditional path)

## Data Flow

### Query Export Flow

```
SQL Query
  ↓
$recordset = Execute()
  ↓
$formatter = FormatterFactory::create('csv')
  ↓
$output = $formatter->format($recordset)
  ↓
Send to client
```

### Table Export Flow

```
Table Name + Schema
  ↓
$dumper = new TableDumper($connection)
  ↓
$dump = $dumper->getDump('table', $params)
  ↓
// $dump['structure'] contains CREATE TABLE SQL
// $dump['recordset'] contains table data
  ↓
$formatter = FormatterFactory::create('json')
  ↓
$output = $formatter->format($dump['recordset'])
  ↓
Send structure + formatted data
```

## Output Stream Support

### Two Output Modes

OutputFormatters support two output modes for flexibility:

#### 1. String Collection Mode (Default)

Without setting an output stream, formatters collect and return output as string:

```php
$formatter = FormatterFactory::create('csv');
$output = $formatter->format($recordset, $metadata);
// $output contains formatted string, can be manipulated, compressed, etc.
```

**Advantages:**

- Output can be modified after generation (e.g., compress, add headers)
- Works with buffering and capture mechanisms
- Easy to test

**Disadvantages:**

- Entire output held in memory
- Slower for very large datasets

#### 2. Streaming Mode (Memory Efficient)

Set an output stream to write directly without collecting:

```php
$formatter = FormatterFactory::create('csv');
$formatter->setOutputStream(STDOUT);  // Or any resource
$formatter->format($recordset, $metadata);
// Output written directly to stream
```

**Advantages:**

- Memory efficient - no string collection
- Faster for large datasets
- Natural streaming support

**Disadvantages:**

- Cannot modify output after generation
- Not suitable for compression (unless buffered first)

### Practical Example: dataexport.php

```php
// Direct streaming for uncompressed exports
if ($output !== 'gzipped') {
    $formatter->setOutputStream(STDOUT);
    $formatter->format($rs, $metadata);
} else {
    // Buffer for gzip compression
    $formatter->setOutputStream(null);  // String mode
    $output = $formatter->format($rs, $metadata);
    $output = gzencode($output, 9);
    echo $output;
}
```

## Implementation Details

### Formatter Input: ADORecordSet

All formatters expect an ADODB RecordSet as input:

```php
class CsvFormatter implements OutputFormatterInterface
{
    public function format(mixed $recordset, array $metadata = []): string
    {
        $output = '';

        // Process headers
        if ($recordset->RecordCount() > 0) {
            // ... process column names
        }

        // Process rows
        $recordset->moveFirst();
        while (!$recordset->EOF) {
            foreach ($recordset->fields as $field) {
                // ... format field value
            }
            $recordset->moveNext();
        }

        return $output;
    }
}
```

### Dumper Integration

Dumpers can provide data as ADORecordSet via `getTableData()`:

```php
class TableDumper extends AbstractDumper
{
    public function getTableData($params)
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->dumpRelation($table, false);

        return $recordset;
    }
}
```

## Metadata Object

The `$metadata` parameter allows formatters to access context:

```php
$metadata = [
    'table' => 'users',                    // Table/result name
    'insert_format' => 'copy',             // For SQL: 'copy', 'single', 'multi'
    'schema' => 'public',                  // Schema name
    'columns' => [...],                    // Column definitions (optional)
    'exported_at' => date('Y-m-d H:i:s')   // Timestamp
];
```

Different formatters use different metadata fields:

- **SqlFormatter** - uses `insert_format` and `table`
- **HtmlFormatter** - uses `table` for header
- **JsonFormatter** - uses `table`, `columns` for metadata section
- **CsvFormatter** - ignores metadata

## Benefits

1. **Single Code Path**: One formatter implementation handles both queries and dumper results
2. **Streaming Capable**: Row-by-row processing never loads full dataset
3. **Flexible**: Mix structure and data exports easily
4. **Consistent**: All formats work with all data sources
5. **Extensible**: Add new formatters without changing consumer code
6. **Memory Efficient**: ADORecordSet supports lazy loading

## Migration Guide

### Old Pattern (dataexport.php - before)

```php
// ~150 lines of inline CSV/JSON/XML/HTML formatting code
if ($format === 'csv') {
    // ... build CSV manually
} elseif ($format === 'json') {
    // ... build JSON manually
} // ... repeat for each format
```

### New Pattern (dataexport.php - after)

```php
// 3 lines of unified formatter code
$formatter = FormatterFactory::create($output_format);
$output_buffer = $formatter->format($rs, $metadata);
echo $output_buffer;
```

**Result:** Reduced from ~150 lines to ~3 lines, with better separation of concerns.

## Files Modified

### Core Infrastructure

- `libraries/PhpPgAdmin/Database/Dump/DumperInterface.php` - Added getDump() and getTableData()
- `libraries/PhpPgAdmin/Database/Dump/AbstractDumper.php` - Implemented getDump() and getTableData()

### Formatters (All Refactored)

- `libraries/PhpPgAdmin/Database/Export/OutputFormatter.php` - Changed signature to accept ADORecordSet
- `libraries/PhpPgAdmin/Database/Export/SqlFormatter.php` - Generates INSERT/COPY from recordset
- `libraries/PhpPgAdmin/Database/Export/CopyFormatter.php` - Generates COPY format from recordset
- `libraries/PhpPgAdmin/Database/Export/CsvFormatter.php` - Processes recordset directly
- `libraries/PhpPgAdmin/Database/Export/TabFormatter.php` - Tab-delimited from recordset
- `libraries/PhpPgAdmin/Database/Export/HtmlFormatter.php` - XHTML from recordset
- `libraries/PhpPgAdmin/Database/Export/XmlFormatter.php` - XML from recordset
- `libraries/PhpPgAdmin/Database/Export/JsonFormatter.php` - JSON from recordset

### Dumpers (Data Export Support)

- `libraries/PhpPgAdmin/Database/Dump/TableDumper.php` - Added getTableData()
- `libraries/PhpPgAdmin/Database/Dump/ViewDumper.php` - Added getTableData()

### Consumer Pages

- `dataexport.php` - Simplified from ~150 lines of inline formatting to 3 lines using formatters

## Testing

To test the unified export system:

```php
// Test 1: Query export as CSV
$_REQUEST['action'] = 'export';
$_REQUEST['output_format'] = 'csv';
$_REQUEST['query'] = 'SELECT * FROM users LIMIT 10';
// ... dataexport.php handles it

// Test 2: Table export as JSON
$dumper = new TableDumper($connection);
$dump = $dumper->getDump('table',
    ['table' => 'users', 'schema' => 'public'],
    []
);
$formatter = FormatterFactory::create('json');
$output = $formatter->format($dump['recordset']);

// Test 3: Full database dump as SQL
$dumper = new DatabaseDumper($connection);
$dumper->dump('database', [], ['clean' => true]);
```

## Future Enhancements

1. **Custom Formatters**: Easy plugin system for new export formats
2. **Compression**: Built-in gzip/bzip2 support
3. **Incremental Exports**: Support for exporting only recent changes
4. **Format-Specific Options**: Column selection, filtering, sorting directly in formatters

---

## Deferred Statements Architecture

### Overview

The dump system uses a **deferred statements architecture** to resolve circular dependencies between database objects. This ensures that objects are created in the correct order and that all dependencies are satisfied during restore.

### The Circular Dependency Problem

PostgreSQL database objects have complex interdependencies:

- **Triggers** reference functions that may reference tables
- **Functions** may reference tables that haven't been created yet
- **Views** may reference other views (nested views)
- **Check constraints** may call functions
- **Generated columns** use expressions that may call functions

**Without deferral, traditional dump order causes failures:**

```sql
-- Traditional order (BROKEN):
CREATE TABLE users (...);
CREATE TRIGGER audit_trigger ON users EXECUTE FUNCTION audit_log();  -- ❌ Function doesn't exist!
CREATE FUNCTION audit_log() RETURNS trigger AS $$ ... $$;
```

### Solution: Functions Before Tables + Deferred Triggers

**Key Insight:** PostgreSQL's `SET check_function_bodies = false` allows functions to reference tables that don't exist yet. This enables a clean dump order:

1. Functions are created first (with body validation disabled)
2. Tables are created (functions already exist for generated columns, defaults, checks)
3. Triggers and rules are deferred until after all structure is created
4. Views are topologically sorted to handle nested dependencies
5. Materialized views use `WITH NO DATA` and refresh later
6. Domain CHECK constraints referencing functions are deferred until after functions are created

### New Dump Order

```
1. Domains and Types (topologically sorted)
2. Sequences (without OWNED BY statements)
3. Functions ← Moved BEFORE tables
4. Aggregates
5. Tables (structure with defaults and check constraints)
   - Defaults inline (functions exist)
   - Check constraints inline (functions exist)
   - Generated columns inline (functions exist)
   - NO triggers (deferred)
   - NO rules (deferred)
6. Operators ← Moved AFTER functions
   (operators reference functions: FUNCTION = schema.func)
7. Views (topologically sorted)
8. Materialized Views (WITH NO DATA)
9. Data import (COPY statements)
10. DEFERRED OBJECTS APPLIED:
    a. Refresh materialized views
    b. Create triggers (functions and tables exist)
    c. Create rules (functions and tables exist)
    d. Set sequence ownerships (tables exist)
    e. Domain CHECK constraints (functions exist)
```

### Deferred Collections in SchemaDumper

The `SchemaDumper` class maintains collections of deferred statements:

```php
private $deferredTriggers = [];              // Trigger definitions
private $deferredRules = [];                 // Rule definitions
private $deferredSequenceOwnerships = [];    // ALTER SEQUENCE OWNED BY
private $deferredMaterializedViewRefreshes = []; // REFRESH MATERIALIZED VIEW
private $deferredDomainConstraints = [];     // ALTER DOMAIN ... ADD CONSTRAINT
private $dumpedTables = [];                  // Validation: track dumped tables
```

### Sub-Dumper Integration

Sub-dumpers (TableDumper, ViewDumper, SequenceDumper) defer statements via parent reference:

```php
// In TableDumper:
if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredTrigger')) {
    $this->parentDumper->addDeferredTrigger($schema, $table, $triggerDefinition);
}
```

### Domain Constraint Deferral

Domain CHECK constraints that reference user-defined functions must be deferred
until after all functions are created. Otherwise the generated SQL fails on
import with "function does not exist".

**Why it's needed:** Domain dumping happens before function dumping (step 1 vs
step 3 in the dump order). A domain like:

```sql
CREATE DOMAIN email_address AS text
    CHECK (validate_email(VALUE));
```

references `validate_email()`, which may not exist yet when the domain is dumped.

**How it works:**

1. `DomainDumper` analyzes each constraint. If it references a user function,
   the constraint is **not** emitted inline with `CREATE DOMAIN`. Instead it is
   stored in `$deferredConstraints` and exposed via `getDeferredConstraints()`.
2. `SchemaDumper::dumpDomainsAndCompositeTypes()` collects these after each
   domain is dumped.
3. `SchemaDumper::applyDeferredDomainConstraints()` (step 10e) emits them after
   all functions have been created:

```sql
ALTER DOMAIN "public"."email_address"
    ADD CONSTRAINT "email_address_check"
    CHECK (public.validate_email(VALUE)) NOT VALID;
ALTER DOMAIN "public"."email_address"
    VALIDATE CONSTRAINT "email_address_check";
```

`NOT VALID` is used so the constraint can be added without scanning existing
rows; `VALIDATE CONSTRAINT` then checks them.

**Note:** `DomainDumper::applyDeferredConstraints()` is kept for reference but
is no longer called from `dump()`. The deferred constraints are now emitted by
`SchemaDumper` at the correct point in the dump sequence.

### Operator Ordering

Operators are dumped **after** functions, because an operator definition
references its support function:

```sql
CREATE OPERATOR "public".+ (
    FUNCTION = "public"."complex_add",   -- references function
    LEFTARG = complex,
    RIGHTARG = complex
);
```

If the operator were dumped before the function, `CREATE OPERATOR` would fail
on import with "function does not exist".

**Why it was wrong (8.0.4 fix):** In earlier versions, `SchemaDumper::dump()`
emitted operators **before** `dumpObjectsTopologically()` (which dumps
functions). This caused the operator's `FUNCTION = ...` reference to point to
a function that had not yet been created.

**Fix:** Operators are now emitted **after** `dumpObjectsTopologically()`.

**Related issue:** The function name in `FUNCTION = ...` was previously
schema-qualified **twice** (e.g.
`"deps_test"."deps_test.complex_add"`), because `OperatorActions::getOperator()`
used `oprcode::regproc` which returns a schema-qualified name when the
function's schema is not in `search_path`, and `OperatorDumper` added the
schema again. Fixed by using `p.proname` (bare function name) in the SQL query.

### Topological Sorting

**Two object types use topological sorting:**

#### 1. Types and Domains

Sorts based on `pg_depend` entries with `deptype IN ('n','i')` using Kahn's algorithm.

#### 2. Views

Views are topologically sorted to handle nested view dependencies:

```php
protected function sortViewsTopologically(array $views, array $deps)
{
    // Build dependency graph from pg_depend
    // Apply Kahn's algorithm (topological sort)
    // Detect circular dependencies
    // Return sorted OIDs or handle cycles gracefully
}
```

**Cycle Detection:**

If circular view dependencies are detected (shouldn't happen in valid PostgreSQL databases), the system:

1. Writes a warning comment: `-- Warning: Circular view dependencies detected`
2. Lists affected views in comments
3. Dumps remaining views in alphabetical order
4. Continues with dump (doesn't fail)

### Materialized Views

Materialized views are exported using a two-phase approach:

1. **Structure Phase:** `CREATE MATERIALIZED VIEW ... WITH NO DATA`
2. **Refresh Phase (Deferred):** `REFRESH MATERIALIZED VIEW ...`

This approach:

- Avoids computing view data before dependent objects exist
- Allows indexes to be created before data is populated
- Simplifies dependency resolution (no topological sort needed)

### Generated Columns (PostgreSQL 12+)

Generated columns are fully supported:

```php
// In TableActions.php getTableAttributes():
if ($this->connection->major_version >= 12) {
    $attgeneratedField = "a.attgenerated,";  // Add to query
}

// In TableDumper.php:
if (isset($atts->fields['attgenerated']) && $atts->fields['attgenerated'] === 's') {
    $this->write(" GENERATED ALWAYS AS ({$atts->fields['adsrc']}) STORED");
}
```

Generated columns:

- Are dumped inline with table structure (functions exist first)
- Support expressions that reference functions
- Are automatically excluded from COPY data (computed during restore)

### Sequence Ownership Validation

Sequence ownership statements are deferred and validated:

```php
// Deferred in SequenceDumper:
$this->parentDumper->addDeferredSequenceOwnership($schema, $sequence, $table, $column);

// Applied in SchemaDumper after checking table was dumped:
if (!$this->isTableDumped($schema, $table)) {
    $this->write("-- Skipping ownership: table $schema.$table not found\n");
    continue;
}
```

This prevents ownership errors when:

- Tables are filtered from export
- Sequences reference dropped columns
- Selective object export is used

### Comment Format Standards

Deferred sections use informational comments:

```sql
--
-- Refresh Materialized Views
--

-- Refreshing materialized view public.sales_summary
REFRESH MATERIALIZED VIEW public.sales_summary;

--
-- Deferred Triggers
--

-- Trigger on public.users
CREATE TRIGGER audit_trigger ...;

--
-- Deferred Domain Constraints (applied after all functions are created)
--

ALTER DOMAIN "public"."email_address"
    ADD CONSTRAINT "email_address_check"
    CHECK (public.validate_email(VALUE)) NOT VALID;

--
-- Sequence Ownerships
--

-- Skipping ownership: table public.old_table not found
ALTER SEQUENCE public.user_id_seq OWNED BY public.users.id;
```

### Benefits of Deferred Architecture

1. **Correctness:** Guarantees successful restore by resolving dependencies
2. **Flexibility:** Supports partial exports (filtered objects)
3. **Maintainability:** Clear separation between structure and deferred objects
4. **Performance:** Allows optimal import order (constraints after data)
5. **Robustness:** Handles edge cases (missing tables, circular dependencies)
6. **Standards Compliance:** Uses `SET check_function_bodies = false` (same as pg_dump)
7. **Complete Coverage:** Handles domain CHECK constraints, generated columns,
   triggers, rules, and sequence ownership uniformly
8. **Operator Correctness:** Operators are dumped after their support
   functions, preventing "function does not exist" errors on import

### Chunked Import Compatibility

The dump architecture is compatible with chunked SQL imports:

- **No transactions used:** Each statement is independent
- **Per-chunk SET commands:** Settings like `check_function_bodies` apply to each chunk
- **Idempotent operations:** Deferred statements can be rerun safely
- **Clear section markers:** Comments help identify where chunks were split

### Implementation Files

| Component                      | File                                        | Purpose                                                |
| ------------------------------ | ------------------------------------------- | ------------------------------------------------------ |
| Main orchestration             | `SchemaDumper.php`                          | Dump ordering and deferred collections                 |
| Trigger deferral               | `TableDumper.php`, `ViewDumper.php`         | Collect triggers instead of writing                    |
| Rule deferral                  | `TableDumper.php`, `ViewDumper.php`         | Collect rules instead of writing                       |
| Sequence ownership             | `SequenceDumper.php`                        | Defer ownership statements                             |
| Domain constraint deferral     | `DomainDumper.php`, `SchemaDumper.php`      | Defer CHECK constraints that reference functions       |
| Operator ordering              | `SchemaDumper.php`                          | Dump operators after functions                         |
| Operator function names        | `OperatorActions.php`                       | Use `p.proname` (bare name), not `regproc`             |
| Generated columns              | `TableActions.php`, `TableDumper.php`       | Query and dump generated columns                       |
| View sorting                   | `SchemaDumper.php`                          | Topological sort with cycle detection                  |
| Parent reference               | `ExportDumper.php`                          | Enable sub-dumper access to parent                     |

### Testing the Deferred System

Test cases to verify the architecture:

```sql
-- Test 1: Trigger referencing function referencing table
CREATE FUNCTION get_user_count() RETURNS int AS $$
    SELECT COUNT(*) FROM users;  -- References table
$$ LANGUAGE sql;

CREATE TABLE users (id serial, name text);

CREATE TRIGGER count_trigger AFTER INSERT ON users
    EXECUTE FUNCTION log_user_count();  -- References function

-- Expected: Function created first, trigger deferred

-- Test 2: Nested views
CREATE VIEW active_users AS SELECT * FROM users WHERE active = true;
CREATE VIEW premium_users AS SELECT * FROM active_users WHERE premium = true;

-- Expected: Topologically sorted (active_users before premium_users)

-- Test 3: Generated column with function
CREATE FUNCTION calculate_discount(price numeric) RETURNS numeric AS $$
    SELECT price * 0.9;
$$ LANGUAGE sql;

CREATE TABLE products (
    price numeric,
    discount_price numeric GENERATED ALWAYS AS (calculate_discount(price)) STORED
);

-- Expected: Function before table, generated column inline

-- Test 4: Sequence ownership with filtered export
-- Export only 'users' table, not 'orders' table
-- Sequence 'order_id_seq' owned by 'orders.id'

-- Expected: Ownership skipped with comment

-- Test 5: Domain with CHECK constraint referencing a function
CREATE FUNCTION validate_email(text) RETURNS boolean AS $$
    SELECT $1 ~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$';
$$ LANGUAGE sql IMMUTABLE;

CREATE DOMAIN email_address AS text
    CHECK (validate_email(VALUE));

-- Expected: Function created first (step 3), domain created next (step 1)
--           without inline CHECK, then deferred constraint applied in step 10e

-- Test 6: Custom operator with support function
CREATE TYPE complex AS (
    r double precision,
    i double precision
);

CREATE FUNCTION complex_add(complex, complex) RETURNS complex AS $$
    SELECT ROW($1.r + $2.r, $1.i + $2.i)::complex;
$$ LANGUAGE sql IMMUTABLE;

CREATE OPERATOR + (
    LEFTARG = complex,
    RIGHTARG = complex,
    FUNCTION = complex_add
);

-- Expected:
--   1. Type complex (step 1)
--   2. Function complex_add (step 3)
--   3. Operator + (step 6)
-- AND the operator's FUNCTION = "public"."complex_add"
-- (NOT "public"."public.complex_add")
```

---
