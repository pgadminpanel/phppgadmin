-- Test fixture: Aggregate depending on its support function
-- Expected dump order: add_int function → sum_int aggregate
-- The aggregate's SFUNC (state transition function) must exist first.

CREATE FUNCTION add_int(int, int) RETURNS int AS $$
    SELECT $1 + $2;
$$ LANGUAGE sql IMMUTABLE;

CREATE AGGREGATE sum_int(int) (
    SFUNC = add_int,
    STYPE = int,
    INITCOND = '0'
);
