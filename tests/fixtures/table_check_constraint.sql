-- Test fixture: Table with CHECK constraint using function
-- Expected: check_positive function → products table
-- CHECK constraint should be deferred with NOT VALID and validated

CREATE FUNCTION check_positive(numeric) RETURNS boolean AS $$
    SELECT $1 > 0;
$$ LANGUAGE sql IMMUTABLE;

CREATE TABLE products (
    id serial PRIMARY KEY,
    name text NOT NULL,
    price numeric CHECK (check_positive(price))
);
