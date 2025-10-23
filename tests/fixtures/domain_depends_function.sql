-- Test fixture: Domain with CHECK constraint using function
-- Expected dump order: validate_email function → email_address domain
-- CHECK constraint should be deferred and applied after function exists

CREATE FUNCTION validate_email(text) RETURNS boolean AS $$
    SELECT $1 ~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$';
$$ LANGUAGE sql IMMUTABLE;

CREATE DOMAIN email_address AS text
    CHECK (validate_email(VALUE));
