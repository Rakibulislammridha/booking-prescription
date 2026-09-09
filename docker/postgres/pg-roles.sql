-- Booking to Prescription — PostgreSQL roles and databases (BRIEF §3, ARCHITECTURE §3.1; docs/DEPLOYMENT.md §7).
--
-- Three roles, two databases:
--   app_user        owns `booking` (public schema + every tenant_<id> schema; runs pg_dump for tenants:backup)
--   catalog_admin   owns `catalog` — the ONLY role that writes it (catalog:migrate, catalog:import, brand promotion)
--   catalog_ro      SELECT-only on `catalog` — what the application's `catalog` connection uses at runtime
--
-- Idempotent: every CREATE is guarded, every GRANT is re-appliable. Run as a superuser (or a role with
-- CREATEROLE + CREATEDB) with psql variables — never edit the SQL to inline a password:
--
--   psql -v ON_ERROR_STOP=1 \
--        -v booking_db=booking  -v app_user=bp_app        -v app_pass='…' \
--        -v catalog_db=catalog  -v catalog_admin=bp_catalog_admin -v catalog_admin_pass='…' \
--        -v catalog_ro=bp_catalog_ro -v catalog_ro_pass='…' \
--        -h <host> -U postgres -d postgres -f docker/postgres/pg-roles.sql
--
-- The compose stack runs this automatically on the FIRST start of an empty postgres volume
-- (docker/postgres/initdb/01-roles-and-databases.sh); on an external/managed server run it by hand once.
-- Verified on PostgreSQL 16.15: after it, catalog_ro can SELECT, and INSERT / CREATE TABLE are refused.

\set ON_ERROR_STOP on

-- Roles (guarded: CREATE ROLE has no IF NOT EXISTS; \gexec runs the generated statement only when the row exists).
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'catalog_admin', :'catalog_admin_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'catalog_admin') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'catalog_ro', :'catalog_ro_pass')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'catalog_ro') \gexec

-- Passwords are (re)applied on every run so a rotation is a re-run of this file with the new values.
SELECT format('ALTER ROLE %I PASSWORD %L', :'app_user', :'app_pass') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'catalog_admin', :'catalog_admin_pass') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'catalog_ro', :'catalog_ro_pass') \gexec

-- Databases. UTF-8; collation is whatever the cluster was initialised with (the compose postgres image uses
-- en_US.utf8 on glibc — keep restores on the same libc family, collation changes reorder indexes).
SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L', :'booking_db', :'app_user', 'UTF8')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'booking_db') \gexec
SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L', :'catalog_db', :'catalog_admin', 'UTF8')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'catalog_db') \gexec

-- Nobody connects to either database by default; each role is granted what it needs and nothing more.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'booking_db') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'catalog_db') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'catalog_db', :'catalog_ro') \gexec

-- The SELECT-only grant lives inside `catalog`. `\connect` keeps the psql variables.
\connect :"catalog_db"

-- PostgreSQL 15+ already denies CREATE on `public` to everyone but the owner; make it explicit for older clusters.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'catalog_ro') \gexec
SELECT format('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %I', :'catalog_ro') \gexec
SELECT format('GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO %I', :'catalog_ro') \gexec
-- Tables catalog_admin creates LATER (every catalog:migrate) inherit the grant; without this line a new DGDA
-- release's table would be invisible to the app until someone remembered to GRANT it.
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT ON TABLES TO %I', :'catalog_admin', :'catalog_ro') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT ON SEQUENCES TO %I', :'catalog_admin', :'catalog_ro') \gexec
