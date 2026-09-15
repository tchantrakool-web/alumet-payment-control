# SQLite migrations

Empty on purpose — `database/schema.sqlite.sql` already describes the full
structure as of the introduction of this migrations system, so there is
nothing historical to replay here.

From now on, every structural change to the SQLite schema is a new file here,
never an edit to `schema.sqlite.sql`:

```
NNN_short_description.sql
```

- `NNN` is a zero-padded, strictly increasing 3-digit number (`001`, `002`, …)
  — files are applied in filename order.
- Each file is applied at most once per database and recorded by filename in
  the `schema_migrations` table. Never rename or edit a migration file after
  it has been applied anywhere (a fresh database and an existing one must end
  up identical, which only holds if migration files never change after the
  fact).
- If the same structural change also applies to MySQL, write the matching
  file under `database/migrations/mysql/` too, ideally with the same `NNN_`
  number and description, translated to MySQL's dialect (see
  `database/MIGRATION_MYSQL.md` for the type-mapping conventions used in the
  baseline schema).
- A migration only needs schema/DDL statements (and seed-data `INSERT`s if the
  change introduces new required rows) — application code changes are a
  separate commit, not part of the `.sql` file.
