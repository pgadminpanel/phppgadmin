-- Test fixture: Partitioned table with a partition whose name sorts first alphabetically
-- Expected dump order: partitioned parent → partition (dependency order, not alphabetical)

CREATE TABLE measurements (
    id integer NOT NULL,
    logdate date NOT NULL,
    value numeric
) PARTITION BY RANGE (logdate);

-- Name deliberately sorts before parent to prove dependency ordering
CREATE TABLE aaa_partition PARTITION OF measurements
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
