-- Test fixture: Circular dependency between functions
--
-- func_a calls func_b, and func_b calls func_a. This creates a cycle that
-- topological sort cannot resolve; both functions end up in the "unsorted"
-- group (appended alphabetically) and a warning is emitted.
--
-- PostgreSQL validates SQL function bodies at CREATE time, so we cannot
-- create two mutually-referencing SQL functions directly. We create
-- placeholder bodies first, then CREATE OR REPLACE them to introduce the
-- circular references.
--
-- Expected:
--   - graph->hasCircularDependencies() returns true
--   - getCircularNodes() contains func_a and func_b
--   - SchemaDumper emits a WARNING comment listing the cycle

CREATE FUNCTION func_a() RETURNS integer AS $$ SELECT 1 $$ LANGUAGE sql IMMUTABLE;
CREATE FUNCTION func_b() RETURNS integer AS $$ SELECT 2 $$ LANGUAGE sql IMMUTABLE;

CREATE OR REPLACE FUNCTION func_a() RETURNS integer AS $$
    SELECT func_b() + 1;
$$ LANGUAGE sql IMMUTABLE;

CREATE OR REPLACE FUNCTION func_b() RETURNS integer AS $$
    SELECT func_a() - 1;
$$ LANGUAGE sql IMMUTABLE;
