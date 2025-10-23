-- Test fixture: Multi-level partitioning (partition that is itself partitioned)
-- Expected: measurements (parent) → measurements_2026 (sub-partitioned) → measurements_2026_q1 (leaf partition)

CREATE TABLE measurements (
    id integer NOT NULL,
    logdate date NOT NULL,
    value numeric
) PARTITION BY RANGE (logdate);

CREATE TABLE measurements_2026 PARTITION OF measurements
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')
    PARTITION BY RANGE (id);

CREATE TABLE measurements_2026_q1 PARTITION OF measurements_2026
    FOR VALUES FROM (1) TO (100);
