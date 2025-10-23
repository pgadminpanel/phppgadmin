-- Test fixture: Enum type and composite type depending on it
-- Expected dump order: mood (enum) → person (composite)

CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy');

CREATE TYPE person AS (
    name text,
    current_mood mood
);