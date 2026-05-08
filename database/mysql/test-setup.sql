-- Test database + user setup. Sourced by the mysql:8.0 entrypoint AFTER
-- init.sql (alphabetical order; this file is mounted as zz-test-setup.sql).
--
-- Mirrors .github/workflows/tests.yml so `composer test` works the same
-- locally as in CI:
--   * nws_cad_test database with the same 15-table schema as nws_cad
--   * test_user / test_pass with grants scoped to nws_cad_test only
--
-- Only effective on a fresh data volume (initdb only runs when the volume
-- is empty). If the volume exists, apply manually:
--   docker compose exec mysql sh -c \
--     'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/zz-test-setup.sql'

CREATE DATABASE IF NOT EXISTS nws_cad_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'test_user'@'%' IDENTIFIED BY 'test_pass';
GRANT ALL PRIVILEGES ON nws_cad_test.* TO 'test_user'@'%';
FLUSH PRIVILEGES;

-- Replicate the production schema into the test database. SOURCE is a
-- client-side directive interpreted by the mysql CLI (which is what the
-- docker entrypoint uses), so re-sourcing init.sql here under the
-- nws_cad_test database creates the same tables there. CREATE TABLE
-- IF NOT EXISTS makes this idempotent.
USE nws_cad_test;
SOURCE /docker-entrypoint-initdb.d/init.sql;
