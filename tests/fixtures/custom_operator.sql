-- Test fixture: Custom operator with its support function
-- Expected dump order: complex type → complex_add function → + operator

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
