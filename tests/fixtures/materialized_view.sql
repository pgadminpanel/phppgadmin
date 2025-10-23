-- Test fixture: Materialized view
-- Expected: CREATE MATERIALIZED VIEW ... WITH NO DATA
--           followed by REFRESH MATERIALIZED VIEW (deferred)

CREATE TABLE sales (
    id serial PRIMARY KEY,
    amount numeric
);

CREATE MATERIALIZED VIEW sales_summary AS
    SELECT count(*) AS cnt, sum(amount) AS total FROM sales;