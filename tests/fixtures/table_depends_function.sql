-- Test fixture: Table with DEFAULT using function
-- Expected dump order: gen_timestamp function → orders table
-- DEFAULT should be deferred and applied after function exists

CREATE FUNCTION gen_timestamp() RETURNS timestamp AS $$
    SELECT CURRENT_TIMESTAMP;
$$ LANGUAGE sql STABLE;

CREATE TABLE orders (
    id serial PRIMARY KEY,
    created_at timestamp DEFAULT gen_timestamp(),
    total numeric NOT NULL
);
