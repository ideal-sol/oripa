# V2 Database Commands

`v2_database.py` is the only approved entry point for V2 development and CI
database initialization.

## Commands

- `init-env`: creates a new non-Production Env File without printing values.
- `validate`: checks Project, Env File, Migration Path, Compose isolation, and
  Resource naming without changing a database.
- `persistent`: starts V2 PostgreSQL／Redis, applies the V2 migration root twice,
  runs the V2 Identity Test, and records non-secret evidence.
- `smoke`: verifies source／restore Ephemeral environments and removes only
  Task-scoped resources after Identity Test and Backup／Restore.

The runner never accepts Production, the V1 migration root, an unqualified
database name, Host Port publication, or shared V1 resource names.

CI Task Projects use the fixed `migNNN-v2-*` namespace. Arbitrary Project names
remain rejected. The runner does not expose arbitrary Laravel commands and
always invokes `apps/api/database/migrations-v2` explicitly.
