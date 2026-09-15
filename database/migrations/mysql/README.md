# MySQL / MariaDB migrations

Empty on purpose — `database/schema.mysql.sql` already describes the full
structure as of the introduction of this migrations system, translated from
the SQLite baseline. There is nothing historical to replay here.

From now on, every structural change to the MySQL schema is a new file here,
never an edit to `schema.mysql.sql`:

```
NNN_short_description.sql
```

- `NNN` is a zero-padded, strictly increasing 3-digit number (`001`, `002`, …)
  — files are applied in filename order.
- Each file is applied at most once per database and recorded by filename in
  the `schema_migrations` table. Never rename or edit a migration file after
  it has been applied anywhere.
- If the same structural change also applies to SQLite, write the matching
  file under `database/migrations/sqlite/` too, ideally with the same `NNN_`
  number and description, translated to SQLite's dialect.
- Statements run inside a transaction per file. Avoid mixing DDL that MySQL
  implicitly commits (most `ALTER TABLE`/`CREATE TABLE` variants) with DML in
  the same migration file if you need the whole file to be atomic on
  rollback — prefer one concern per migration file.
