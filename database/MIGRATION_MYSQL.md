# Moving to MySQL / MariaDB

This app runs on SQLite by default and needs no setup. This document covers
switching it to MySQL or MariaDB.

**Scope of what's actually ready:** the schema, migrations runner, `.env`
driver selection, and the one-off copy script are all in place and covered by
real infrastructure. The application's own queries are not — see
[Known incompatibilities in application code](#known-incompatibilities-in-application-code-not-yet-fixed)
below. That part is real, required work before a MySQL cutover can actually
serve traffic, and it is deliberately out of scope of what built this
document.

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
database.

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
4. Fix the application-code incompatibilities listed below — the app will
   throw on its first write attempt otherwise (reads work fine already).
5. Smoke-test: log in, open a payment request, create a cheque, save a review
   — anything that writes.
6. Only after that smoke test passes, point production traffic at the MySQL
   database.

## Rollback

Set `DB_DRIVER` back to `sqlite` in `.env`. Nothing in this process ever
modifies or deletes the original SQLite file — it's read-only as far as the
copy script is concerned, and the app itself only ever touches whichever
database `DB_DRIVER` currently points at. Rollback is a one-line `.env` edit.

## Never edit a baseline schema file to change structure

`database/schema.sqlite.sql` and `database/schema.mysql.sql` describe what an
**empty** database gets, once, on first connection. Once either has shipped,
every future structural change is a new file under
`database/migrations/<driver>/NNN_description.sql` (see the `README.md` in
each migrations folder) — never an edit to the baseline files. That's what
keeps a fresh database and a long-running one identical: both start from the
same baseline and apply the same migrations in the same order.

## Behavioral differences between the two engines

| Area | SQLite (today) | MySQL / MariaDB | Where it matters |
|---|---|---|---|
| Autoincrement PK | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT UNSIGNED AUTO_INCREMENT` | Schema translation only — `lastInsertId()` works identically on both via PDO. |
| Money columns | `REAL` (float) | `DECIMAL(15,2)` | More correct for a payment ledger, but PDO returns `DECIMAL` values as PHP **strings**, not floats. Code that does `is_float($row['amount'])` or relies on the exact float type will behave differently; ordinary arithmetic (`$row['amount'] + 1`) still works because PHP coerces numeric strings automatically. |
| Boolean flags | `INTEGER DEFAULT 0/1` | `TINYINT(1) DEFAULT 0/1` | No behavior change — MySQL's `BOOLEAN` is a literal alias for `TINYINT(1)`. |
| Timestamps | `TEXT DEFAULT (datetime('now','localtime'))` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | Column defaults translate cleanly. **Not** translated: every place the app calls `datetime('now','localtime')` as a literal SQL function inside an `INSERT`/`UPDATE` statement — see below, this is the big one. |
| Date/datetime columns | `TEXT`, so an empty string `''` stores fine | `DATE`/`DATETIME`, and MySQL's default strict mode rejects `''` as an invalid date | The copy script already coerces `''` → `NULL` for columns MySQL considers date-typed (see `dateTypedColumns()` in `copy_sqlite_to_mysql.php`). Any *new* code path that inserts `''` into a date column after cutover will error on MySQL where it silently succeeded on SQLite. |
| Case-insensitive text match | `COLLATE NOCASE` per-query | Column/connection collation (`utf8mb4_unicode_ci` is already case-insensitive) | The 5 `COLLATE NOCASE` call sites (below) are harmless no-ops on MySQL with this schema's collation, not errors — but they're dead weight, not translations. |
| Upsert | `INSERT ... ON CONFLICT(col) DO UPDATE SET ...` | `INSERT ... ON DUPLICATE KEY UPDATE ...` | The 3 `ON CONFLICT` call sites (below) use SQLite-only syntax and will throw a syntax error on MySQL. |
| String aggregation | `GROUP_CONCAT(DISTINCT col)` | `GROUP_CONCAT(DISTINCT col)` | Portable as-is — MySQL/MariaDB support the same function and syntax. |
| Foreign key enforcement | Off unless `PRAGMA foreign_keys=ON` (the app sets this) | Always enforced for InnoDB | Already-consistent behavior; nothing to change. |
| `AUTO_INCREMENT` after a rollback/delete | SQLite reuses the max existing rowid + 1 (no real gap tracking) | MySQL's counter never goes backward, even after `ROLLBACK` or `DELETE` | Cosmetic only (visible id gaps on MySQL that wouldn't appear on SQLite) — no functional impact anywhere in this app. |
| Multi-statement execution | `PDO::exec()` runs multiple `;`-separated statements | `PDO::exec()` runs exactly one statement | Not an app-code concern — `config/database.php`'s `splitSqlStatements()` already splits schema/migration files into individual statements before executing them on either driver. |

## Known incompatibilities in application code (not yet fixed)

These will make the app throw SQL errors on MySQL/MariaDB the first time
each code path runs, even with everything above in place. Fixing them is a
separate, deliberately out-of-scope follow-up.

**`datetime('now','localtime')` used as a literal SQL function call** — not
just a column default, but embedded directly in `INSERT`/`UPDATE` statements.
MySQL has no such function; the fix is `NOW()` (or a driver-aware helper
function in PHP). 57 call sites across 9 files:

- `config/notifications.php` (1)
- `config/sap_importer.php` (2)
- `modules/cheques/index.php` (2)
- `modules/payment_batch/index.php` (2)
- `modules/payment_requests/create.php` (2)
- `modules/payment_requests/detail.php` (13)
- `modules/settings/index.php` (3)
- `modules/settings/users.php` (2)
- (`config/database.php` also has one match, but it's inside the SQLite-only
  branch of the new driver-aware `runPendingMigrations()` — not a bug.)

**`ON CONFLICT` upserts** (SQLite syntax) — needs MySQL's
`ON DUPLICATE KEY UPDATE`:

- `config/notifications.php:170`
- `config/sap_importer.php:384`
- `modules/settings/index.php:43`

**`COLLATE NOCASE`** — harmless but redundant under this schema's
`utf8mb4_unicode_ci` collation; worth removing for clarity, not required for
correctness:

- `modules/cheques/index.php:81`
- `modules/cheques/index.php:82`
- `modules/cheques/index.php:166`
- `modules/import/upload_finance.php:144`
- `modules/settings/users.php:49`
