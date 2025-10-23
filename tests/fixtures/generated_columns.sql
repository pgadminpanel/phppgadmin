-- Test fixture: Generated column with function dependency
-- Expected: calculate_tax function → orders table
-- Generated column should be deferred

CREATE FUNCTION calculate_tax(numeric) RETURNS numeric AS $$
    SELECT $1 * 0.19;  -- 19% tax
$$ LANGUAGE sql IMMUTABLE;

CREATE TABLE invoices (
    id serial PRIMARY KEY,
    subtotal numeric NOT NULL,
    tax numeric GENERATED ALWAYS AS (calculate_tax(subtotal)) STORED
);
