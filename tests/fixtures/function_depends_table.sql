-- Test fixture: Function with return type based on table (composite type)
-- Expected dump order: customers table → rewards_report function

CREATE TABLE customers (
    id integer PRIMARY KEY,
    name text NOT NULL,
    email text
);

CREATE FUNCTION rewards_report(customer_id integer) 
RETURNS SETOF customers AS $$
    SELECT * FROM customers WHERE id = customer_id;
$$ LANGUAGE sql STABLE;
