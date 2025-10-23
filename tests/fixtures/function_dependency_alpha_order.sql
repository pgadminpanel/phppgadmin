-- Test fixture: Function dependency with alphabetical order conflict
--
-- z_func has no dependencies; a_func calls z_func, so a_func depends on z_func.
-- Alphabetically a_func < z_func, so a naive alphabetical sort would dump
-- a_func first — which would fail because z_func doesn't exist yet.
--
-- Expected dump order: z_func → a_func (dependency order overrides alphabetical)

CREATE FUNCTION z_func() RETURNS integer AS $$ SELECT 1 $$ LANGUAGE sql IMMUTABLE;

CREATE FUNCTION a_func() RETURNS integer AS $$ SELECT z_func() + 1 $$ LANGUAGE sql IMMUTABLE;