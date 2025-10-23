-- Test fixture: Function depending on another function
-- Expected dump order: base_func → derived_func

CREATE FUNCTION base_func() RETURNS integer AS $$
    SELECT 42;
$$ LANGUAGE sql IMMUTABLE;

CREATE FUNCTION derived_func() RETURNS integer AS $$
    SELECT base_func() + 1;
$$ LANGUAGE sql IMMUTABLE;
