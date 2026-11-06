-- ---------------------------------------------------------------------------
-- Optional manual seeder (for `psql -f database/seed.sql`).
--
-- The canonical seeder is:   php bin/console seed
-- which uses ADMIN_EMAIL (default: tayyebimohammadreza@gmail.com).
--
-- Remember: administrators have NO password and NO username. Signing in always
-- requires a one-time code e-mailed to the address below.
-- ---------------------------------------------------------------------------

INSERT INTO admins (email)
VALUES ('tayyebimohammadreza@gmail.com')
ON CONFLICT (email) DO NOTHING;
