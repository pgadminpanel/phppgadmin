-- Test fixture: Nested view dependency
-- Expected dump order: active_users → premium_users
-- The second view references the first, so it must be created after.

CREATE TABLE users (
    id serial PRIMARY KEY,
    name text,
    active boolean,
    premium boolean
);

CREATE VIEW active_users AS
    SELECT * FROM users WHERE active = true;

CREATE VIEW premium_users AS
    SELECT * FROM active_users WHERE premium = true;