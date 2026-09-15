# Moving to MySQL / MariaDB

This app runs on SQLite by default and needs no setup. This document covers
switching it to MySQL or MariaDB.

**Scope of what's actually ready:** the schema, migrations runner, `.env`
driver selection, and the one-off copy script are all in place. On top of
that, the application's own SQL has been audited and fixed for portability:
every `datetime('now','localtime')` literal call is now `CURRENT_TIMESTAMP`
(valid on both engines), every `ON CONFLICT` upsert goes through one shared,
driver-aware `upsert()` helper (`config/database.php`) instead of hand-written
dialect-specific SQL or a racy SELECT-then-branch, no SQL text anywhere uses a
double-quoted string literal, and no query repeats a named placeholder (in
fact none of the app's SQL uses named placeholders at all — everything is
positional `?`, so PDO's native prepares with `PDO::ATTR_EMULATE_PREPARES =
false` work unchanged). What's left unfixed is narrower and listed under
[Known incompatibilities](#known-incompatibilities-in-application-code-not-yet-fixed)
below — mainly `COLLATE NOCASE` (harmless, just redundant) and the general
float-vs-string handling of money values outside the DB layer itself
(deliberately out of scope — see that section).

## Prerequisites

- MySQL 8.0+ or MariaDB 10.4+ (both support `utf8mb4` and everything this
  schema uses; nothing here needs a newer version than that).
- PHP's `pdo_mysql` extension enabled (`php -m | grep pdo_mysql`).
- A database and a database user **that you create yourself** — this app
  never runs `CREATE DATABASE` or `CREATE USER` for you, at any point,
  including the schema bootstrap and the copy script. It only ever runs DDL
  (`CREATE TABLE`, …) *inside* a database you've already created.

## 1. Create the database and user

Run this yourself (e.g. via the `mysql` CLI or phpMyAdmin) — adjust the
database name, username, and password:

```sql
CREATE DATABASE alumet_payment_control
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'alumet_app'@'localhost' IDENTIFIED BY 'change-this-password';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
    ON alumet_payment_control.* TO 'alumet_app'@'localhost';

FLUSH PRIVILEGES;
```

The `CREATE`/`ALTER`/`DROP`/`INDEX` grants are needed because the app applies
`database/schema.mysql.sql` and any pending files under
`database/migrations/mysql/` itself the first time it connects to an empty
database — it needs permission to create tables, just not to create the
database. Tables are created with `utf8mb4_unicode_ci` inherited from the
database's collation (no per-table `COLLATE` override in the schema), which
is what makes MySQL's default string comparisons case-insensitive — the same
behavior the app's `COLLATE NOCASE` SQLite queries were opting into explicitly.

## 2. Fill in `.env`

Copy `.env.example` to `.env` if you haven't already, then set:

```
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=alumet_payment_control
DB_USER=alumet_app
DB_PASSWORD=change-this-password
DB_CHARSET=utf8mb4
```

## 3. Cutover steps

1. With `.env` filled in as above, either load any page (the app calls
   `getDB()`, which applies `database/schema.mysql.sql` to the empty database
   automatically) or run `php database/copy_sqlite_to_mysql.php --dry-run`
   (which does the same schema bootstrap before reporting counts).
2. Review the dry-run output — a row count per table, source (SQLite) vs.
   target (MySQL, should all read 0 on a fresh database).
3. Run `php database/copy_sqlite_to_mysql.php --force` to actually copy every
   row, preserving ids, inside one transaction.
4. Smoke-test: log in, open a payment request, create a cheque, save a review
   — anything that writes, since that's what changed most in this phase.
5. Only after that smoke test passes, point production traffic at the MySQL
   database.

## Rollback

Set `DB_DRIVER` back to `sqlite` in `.env`. Nothing in this process ever
modifies or deletes the original SQLite file — it's read-only as far as the
copy script is concerned, and the app itself only ever touches whichever
database `DB_DRIVER` currently points at. Rollback is a one-line `.env` edit.

**One thing rollback does not undo:** the timestamp change below is baked
into application code, not gated by `DB_DRIVER` — rolling back to SQLite still
writes `CURRENT_TIMESTAMP` (UTC), not the old local-time behavior.

## Never edit a baseline schema file to change structure

`database/schema.sqlite.sql` and `database/schema.mysql.sql` describe what an
**empty** database gets, once, on first connection. Once either has shipped,
every future structural change is a new file under
`database/migrations/<driver>/NNN_description.sql` (see the `README.md` in
each migrations folder) — never an edit to the baseline files. That's what
keeps a fresh database and a long-running one identical: both start from the
same baseline and apply the same migrations in the same order.

`database/migrations/*/001_unique_cheque_no.sql` is the first real example:
`cheques.cheque_no` was only ever deduplicated by application code (a
`COLLATE NOCASE` `SELECT` before every insert), never by the database, which
meant `upsert()`'s `ON CONFLICT`/`ON DUPLICATE KEY UPDATE` had no constraint to
detect a conflict against. The migration adds a case-insensitive unique index
on both engines. **Before running it against a database that already has
cheque data**, confirm there are no existing duplicates (the migration file
has the exact query) — it will fail loudly rather than silently corrupt
anything if there are.

## ⚠ Timestamp semantics changed for every table, on both engines

Every `datetime('now','localtime')` in this codebase — both the literal SQL
calls in `INSERT`/`UPDATE` statements and the column `DEFAULT` clauses in the
schema — is now `CURRENT_TIMESTAMP`. This was a deliberate, explicit
requirement for cross-engine portability, but it has a real, visible
consequence: **SQLite's `CURRENT_TIMESTAMP` returns UTC, not local time**
(confirmed empirically — a row written right after this change showed
`17:19` while the local clock read `00:19` the next day, Bangkok is UTC+7).
Every `created_at`/`updated_at`/similar column the app writes from now on is
in UTC, where it used to be Bangkok local time. `fmtDate()`/`fmtDateTime()`
do not convert — a UTC value is displayed as-is, so anything showing
timestamps to a user now effectively displays UTC labeled the same way local
time used to be.

SQLite cannot change a column's stored `DEFAULT` clause in place (no `ALTER
COLUMN`), so the *already-running* production SQLite file's table
definitions still literally say `datetime('now','localtime')` — but every
`INSERT` statement in the app now supplies `created_at`/`imported_at`/etc.
explicitly as `CURRENT_TIMESTAMP`, which takes precedence over the column
default and makes the stored default dead code. This was a deliberate choice
over rebuilding all 21 tables (SQLite's only way to actually rewrite a
column default) — full-table rebuilds on a live financial database carried
materially more risk than adding one more explicit column to each `INSERT`,
for the identical practical outcome. If you `INSERT` into any table from
outside this app's own code (a manual SQL script, a different tool) and omit
the timestamp column, you'll still get the old local-time default on this
particular SQLite file — a new empty database built from `schema.sqlite.sql`
does not have this quirk, since its `CREATE TABLE` statements were written
with `CURRENT_TIMESTAMP` defaults from the start.

## Behavioral differences between the two engines

| Area | SQLite (today) | MySQL / MariaDB | Where it matters |
|---|---|---|---|
| Autoincrement PK | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT UNSIGNED AUTO_INCREMENT` | Schema translation only — `lastInsertId()` works identically on both via PDO. |
| Money columns | `REAL` (float) | `DECIMAL(15,2)` | More correct for a payment ledger, but PDO returns `DECIMAL` values as PHP **strings**, not floats. This is enforced at the schema/driver level only — application code (`fmtMoney()`, calculations, comparisons) still casts money values to `(float)` in ~94 places across ~14 files. That's unchanged and deliberately out of scope here: ordinary arithmetic and display formatting work fine with a float cast either way; only code relying on money being a *native* float type end-to-end (rather than cast on demand) would need a broader bcmath-based rewrite, which was explicitly not requested for this phase. |
| Boolean flags | `INTEGER DEFAULT 0/1` | `TINYINT(1) DEFAULT 0/1` | No behavior change — MySQL's `BOOLEAN` is a literal alias for `TINYINT(1)`. |
| Timestamps | `CURRENT_TIMESTAMP` (UTC) everywhere, both column defaults and literal `INSERT`/`UPDATE` values | `CURRENT_TIMESTAMP` (session time zone, typically UTC unless configured otherwise) | See the warning above — this is a real, already-applied behavior change, not just a porting concern. |
| Date/datetime columns | `TEXT`, so an empty string `''` stores fine | `DATE`/`DATETIME`, and MySQL's default strict mode rejects `''` as an invalid date | The copy script already coerces `''` → `NULL` for columns MySQL considers date-typed (see `dateTypedColumns()` in `copy_sqlite_to_mysql.php`). Any *new* code path that inserts `''` into a date column after cutover will error on MySQL where it silently succeeded on SQLite. |
| Case-insensitive text match | `COLLATE NOCASE` per-query | Column/connection collation (`utf8mb4_unicode_ci` is already case-insensitive) | The remaining `COLLATE NOCASE` call sites (below) are harmless no-ops on MySQL with this schema's collation, not errors — dead weight, not translations. |
| Upsert | One `upsert()` helper (`config/database.php`), driver-aware | Same helper, `ON DUPLICATE KEY UPDATE` branch | Fixed this phase — see `config/notifications.php`, `config/sap_importer.php`, `modules/settings/index.php`, `modules/cheques/index.php`'s finance-sync path. All four require the target table to have a real UNIQUE/PRIMARY KEY constraint on the conflict column(s), which is why `001_unique_cheque_no.sql` exists. |
| String aggregation | `GROUP_CONCAT(DISTINCT col)` | `GROUP_CONCAT(DISTINCT col)` | Portable as-is — MySQL/MariaDB support the same function and syntax. |
| Foreign key enforcement | Off unless `PRAGMA foreign_keys=ON` (the app sets this) | Always enforced for InnoDB | Already-consistent behavior; nothing to change. |
| `AUTO_INCREMENT` after a rollback/delete | SQLite reuses the max existing rowid + 1 (no real gap tracking) | MySQL's counter never goes backward, even after `ROLLBACK` or `DELETE` | Cosmetic only (visible id gaps on MySQL that wouldn't appear on SQLite) — no functional impact anywhere in this app. |
| Multi-statement execution | `PDO::exec()` runs multiple `;`-separated statements | `PDO::exec()` runs exactly one statement | Not an app-code concern — `config/database.php`'s `splitSqlStatements()` already splits schema/migration files into individual statements before executing them on either driver. |
| Named placeholders | N/A — app uses positional `?` everywhere | Native prepares (`EMULATE_PREPARES = false`) reject a named placeholder used more than once per query | Not a real risk here: audited, zero named placeholders exist anywhere in this codebase's SQL. |
| Double-quoted string literals | SQLite tolerates `"value"` as a string in some contexts | Default MySQL mode treats `"value"` as a string too, but `ANSI_QUOTES` mode makes it an identifier — ambiguous either way | Not a real risk here: audited, no SQL text anywhere in the app uses a double-quoted string literal (PHP's own double-quoted string delimiters, which are unrelated, are everywhere and are fine). |

## Known incompatibilities in application code (not yet fixed)

**`COLLATE NOCASE`** — harmless but redundant under this schema's
`utf8mb4_unicode_ci` collation (MySQL already compares case-insensitively);
worth removing for clarity someday, not required for correctness or for a
cutover to work:

- `modules/cheques/index.php:81`
- `modules/cheques/index.php:82`
- `modules/cheques/index.php:166`
- `modules/import/upload_finance.php:144`
- `modules/settings/users.php:49`

**Money values as native floats in application code** — deliberately out of
scope. The schema and PDO driver layer already guarantee `DECIMAL` columns
come back as strings on MySQL; ~94 `(float)` casts across ~14 files
(`fmtMoney()`, totals, comparisons) still convert on demand at the point of
use. That's a much larger, financially-sensitive refactor (bcmath-based
string arithmetic throughout) that was explicitly not requested for this
phase — flagging it here as the natural next step if "money is never a float,
anywhere" becomes a hard requirement rather than a DB-layer guarantee.
