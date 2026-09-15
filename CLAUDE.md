# Alumet Payment Control

Internal payment control tower for Alumet Co., Ltd. PHP + SQLite backend
(`db/payment_control.sqlite`), Tailwind (Play CDN) + Alpine.js frontend, no
build step. Bilingual UI (Thai/English) via `config/lang.php` + `t()`.

## State

**Phase 1 (UI/dialog overhaul) — complete. Phase 2 (bilingual strings) — complete.**
Full design rationale, options rejected, and outcome notes for both phases live
in the "Insert/Edit dialogs across the app" plan document (ask the user for the
plan file if picking this up in a new session — it's outside this repo, under
the user's local Claude plans directory).

What's in place:
- **Sidebar navigation** (was a topbar): `layouts/sidebar.php` + `layouts/header.php`,
  inline SVG icons (`stroke="currentColor"`, no icon font).
- **Shared dialog helper**: `dialogOpen()` / `dialogClose()` in
  `config/functions.php` — one chrome, three close paths (Escape, backdrop
  self-click via `@click.self` — never `@click.outside`, header ✕) for every
  Insert/Edit dialog in the app.
- **Insert/Edit dialogs** converted for `settings/users.php`, `cheques/index.php`,
  `payment_batch/index.php`, and `payment_requests/detail.php` (review section
  only). Per-row Edit dialogs are rendered in a second loop placed *after*
  `</table>` (never inside it — a `<div>` isn't valid table content and gets
  hoisted out by the browser), each one statically bound to its own row's data
  — no shared client-side "which record is this" state.
- **Deliberately unconverted**: `payment_requests/create.php` (an invoice-picker
  wizard, not a simple form — stays a full page) and all of `detail.php`'s
  workflow-action forms (submit for review, checker document review, approve,
  reject, return, mark paid — state-machine transitions, not record edits).
- **Form-control base styles**: `assets/css/theme.css` works around Tailwind
  Preflight stripping border/padding/background from any field that doesn't
  carry its own utility classes.
- **CDN scripts pinned to exact versions**, loaded once from `layouts/header.php`
  only: Tailwind 3.4.17, Alpine 3.17.3, DataTables 1.13.8, jQuery 3.7.1,
  Chart.js 4.4.2.
- **CSRF**: every form uses the one canonical `csrfToken()` / `csrfField()` /
  `verifyCsrfToken()` in `config/functions.php` — no more per-page session
  tokens.

Fixed along the way (pre-existing, unrelated to the above): DataTables'
default `errMode` blocked every empty list page behind a native `alert()` —
now `'none'` in `layouts/footer.php`. `dashboard.php` loaded a second, unpinned
copy of Chart.js — removed.

**Known, not fixed:** seed row `payment_requests` id 3 has a `wht_base_amount`
that doesn't logically match `wht_applicable = 0` — an old seed/import
inconsistency, not reproducible via the UI, restored to its original value
rather than "corrected." See the plan doc's Phase 1 outcome for detail before
touching it.

**Housekeeping note:** this repo had an automatic per-turn commit hook during
Phase 1 that swept up pre-existing, unrelated working-tree changes (SAP
importer script, notifications API/config, import module edits) alongside the
UI work. Those files were already modified before Phase 1 started and are not
described above.

**Phase 2 — bilingual strings:** Phase 1 introduced three new `t()` calls with
inline English fallback defaults on `payment_requests/detail.php` (`btn.edit`,
`label.yes`, `label.no`) because those translation keys didn't exist yet. Added
proper `en`/`th` entries to `config/lang.php` and dropped the inline fallbacks.
Scope was deliberately limited to that page: `settings/users.php` (and its new
dialog partials) were never bilingual to begin with — hardcoded English is the
existing convention there, not a regression — so nothing there needed keys.
