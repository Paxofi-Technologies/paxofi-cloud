# Database migrations

PaxofiCloud uses its own forward-only migration runner (PCF has none; SRS
OPS-007).

```bash
bin/paxoficloud migrate          # apply pending migrations in order
bin/paxoficloud migrate:status   # exit 0 = up to date, 2 = pending, 1 = history broken
```

## Writing a migration

1. Add `migrations/NNNN_short_description.sql`, numbered one above the highest
   existing file (`0007_create_invoices.sql`). Lower-case snake case only.
2. Plain SQL, one or more statements separated by `;`. Comments (`--`, `#`,
   `/* */`) are fine. `DELIMITER` and stored routines are not supported.
3. **Prefer one schema change per file.** MySQL commits DDL immediately, so a
   file that fails half-way cannot be rolled back automatically.
4. Money columns are `BIGINT` minor units plus a `CHAR(3)` currency. IDs are
   application-generated (ULID, `CHAR(26)`), never `AUTO_INCREMENT` for
   tenant data. Use `utf8mb4` / `utf8mb4_0900_ai_ci` and `InnoDB`.
5. Every tenant-owned table has an `account_id` column and an index that
   starts with it (tenant isolation, threat model D-13).

## Rules the runner enforces

| Situation | Result |
|---|---|
| An applied file was edited | Refuses to run (`modified`). Write a new migration instead. |
| An applied file was deleted | Refuses to run (`missing`). |
| A new file is numbered below the newest applied one | Refuses to run. Renumber it. |
| Another deploy is already migrating | Refuses to run (MySQL `GET_LOCK`). |
| A statement fails | Stops, reports file and statement number; the file is not recorded as applied. |

Each applied migration is recorded in `schema_migrations` with its SHA-256
checksum, UTC timestamp and duration.

## Fixing a failed migration

Because applied files are immutable, fix forward: correct the failed file
**only if it was never recorded as applied**, otherwise add a new migration
that repairs the schema. Never edit `schema_migrations` by hand in staging or
production.
