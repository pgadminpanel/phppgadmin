-- Test fixture: Table using a domain in a column
-- Expected: the graph should not break when domains are referenced
-- but not part of the graph (loadDomains is intentionally disabled).

CREATE DOMAIN email_address AS text
    CHECK (VALUE ~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$');

CREATE TABLE users (
    id integer PRIMARY KEY,
    email email_address NOT NULL
);
