-- Test fixture: Child table referencing parent table via foreign key
-- Expected dump order: parent table → child table
-- The referenced table must exist before the FK constraint is created.

CREATE TABLE parent_table (
    id integer PRIMARY KEY,
    name text NOT NULL
);

CREATE TABLE child_table (
    id integer PRIMARY KEY,
    parent_id integer REFERENCES parent_table(id),
    note text
);
