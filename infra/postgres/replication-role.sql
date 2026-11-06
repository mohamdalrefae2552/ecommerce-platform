-- Replication role on db-app (run by the one-shot db-app-roles service on
-- every `up`, so it exists for old and new volumes and follows the password
-- in .env). Variables: -v user=... -v password=...
SELECT format('CREATE ROLE %I WITH LOGIN REPLICATION', :'user')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'user') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN REPLICATION PASSWORD %L', :'user', :'password') \gexec
