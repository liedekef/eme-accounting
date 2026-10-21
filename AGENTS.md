# AGENTS.md — EME Accounting

Single WordPress plugin (no monorepo, no build step). Belgian **single-entry** bookkeeping layered on top of the
**Events Made Easy (EME)** plugin. PHP 8.0+, WP 6.0+. All user-facing strings are **Dutch**, text domain
`eme-accounting`.

## Layout & wiring

- `eme-accounting.php` — bootstrap only: plugin header, `EME_ACCOUNTING_*` constants, autoloader, `plugins_loaded`
  boot, activation/deactivation/uninstall hooks, plugin-row links, vendored updater.
  - The autoloader maps `EMEAccounting\Foo\Bar` → `includes/Foo/Bar.php`. **One class per file, filename ==
    class name.** A new `EMEAccounting\Foo` class must live in `includes/Foo.php`.
  - Exceptions to the namespace: `includes/EMEFileAttachments.php` has **no namespace** (global
    `EMEFileAttachments`) and is `require_once`d manually *before* the autoloader
    (`eme-accounting.php:25`); `includes/class-eme-updater.php` uses namespace `EmeAccounting` (lowercase `e`)
    — deliberately different from `EMEAccounting`. Don't refactor it.
  - `eme-accounting.php:99` instantiates the GitHub updater outside the WP check — keep that `// NOT FOR WP`
    block intact.
- `includes/` — all logic, one class per file: `Accounts` (financial accounts + the denormalized totals table),
  `ChartOfAccounts` (rekeningstelsel), `Journal` (dagboek), `FiscalYear` (boekjaren), `Import` (bank import +
  reconciliation), `Stock`, `Depreciation`, `Budget`, `Patrimony` (Staat van het Vermogen), `Reports`,
  `Capabilities`, `Assets` (enqueue), `EMEBridge` (auto-booking on EME payments), `Installer` (schema),
  `Vat`, `Admin/Menu`.
- `templates/` — plain PHP views included by `Admin\Menu::load_template()` (`templates/<name>.php`). They live in
  the **global namespace** (no `namespace` statement), start with `defined( 'ABSPATH' ) || exit;`, and reference
  plugin classes fully qualified (`\EMEAccounting\...`).
  - `templates/pdf/` holds HTML fragments rendered by dompdf.
- `assets/` — `css/admin.css` (classes prefixed `eme-acc-`), `js/admin.js` (**vanilla JS, no jQuery, no build
  step**), `js/journal.js`, `js/import.js`, `js/fiscalyears.js`, `js/chart.umd.min.js` (vendored Chart.js 4.5.1 —
  do not hand-edit).

## Hard dependencies (not all declared)

EME is required: the bootstrap shows an admin notice and bails if `eme_get_event()` is missing.

- **Nothing is vendored from EME.** `includes/Assets.php:22-30` serves `snapselect`, `sortable`, `ftable` and
  `fdatepicker` JS/CSS straight out of the EME plugin directory (`EME_PLUGIN_URL`).
- **dompdf is not vendored either.** PDF handlers load
  `WP_PLUGIN_DIR . '/events-made-easy/includes/dompdf/vendor/autoload.php'` (`includes/Reports.php:622`,
  `includes/Budget.php:316`, `includes/Patrimony.php:279`). There is no `composer.json`; PDF output fails
  without EME.
- **`\EMEVzw\Config::get()` comes from a *separate* plugin** (`eme-vzw-helper`, sibling repo), not from EME, and
  it is **not** declared in the plugin header. It supplies company identity, `currency`, `vat_liable` and fiscal
  year boundaries. Used in 17 files — treat it as a hard runtime dependency.
- EME helpers used directly: `eme_sanitize_request()` (110×), `eme_translate()`, `eme_localized_date()`,
  `eme_get_event()`, `eme_ui_select()`, `eme_ui_multiselect_key_value()`, `eme_get_ftable_limit()`,
  `eme_get_ftable_orderby()`, plus the constants `EME_PLUGIN_URL` / `EME_PLUGIN_DIR` / `EME_VERSION`.
- **No composer, no npm, no CI, no test suite.** Verification = `php -l` + manual check in a WP install with EME
  (and eme-vzw-helper) active.

## Commands

```bash
# lint (exactly what the release script runs)
find . -name '*.php' -not -path './.git/*' -not -path './dist/*' -not -path './vendor/*' | sort | xargs -n1 php -l

# release: lint -> clean-tree check -> version bump -> zip -> commit/push -> GitHub release
bash script/eme_release.sh 1.0.5 1.0.6
```

`script/eme_release.sh` needs `php`, `zip`, `git` and an authenticated `gh`, and **refuses to run with a dirty
working tree**. It bumps the version with a single `sed -i "s/$old/$new/"` over the whole of
`eme-accounting.php` — which is why the `Version:` header *and* the `EME_ACCOUNTING_VERSION` constant both live
in that file and must stay in sync. It also rewrites `Stable tag:` in `readme.txt` but adds **no changelog
entry** (readme.txt's changelog is stale at 1.0.0). Feature commits do not bump the version; only the release
script does.

The build artifact lands in `dist/` (gitignored) and the zip excludes `dist/`, `script/` and root `*.md`, so
`AGENTS.md` never ships to WordPress users.

## Database

Tables are `{wpdb->prefix}emeacc_*`: `_accounts`, `_account_totals`, `_chart_of_accounts`, `_journal`, `_stock`,
`_stock_movements`, `_depreciation`, `_budget`, `_patrimony`, `_fiscal_years`, `_import_log`, `_import_lines`.
Settings live in the single option `eme_accounting_settings` (array).

- **Schema setup runs from two places, because the activation hook does not fire on every update.** The activation
  hook (`activate_{$plugin}`) is skipped by WordPress for *silent* activations, and core's own update path
  (wp.org / auto-update) silently deactivates before swapping files and reactivates silently
  (`activate_plugin( ..., $silent = true )`). Don't depend on either channel:
  - `Installer::maybe_upgrade()` is hooked on `plugins_loaded` (priority 5, from `eme-accounting.php`) and compares
    the `eme_accounting_db_version` option with `Installer::DB_VERSION`. If they differ it runs `install_site()`:
    `create_tables()` (idempotent `CREATE TABLE IF NOT EXISTS`) → `migrate( $current_version )` → store the new
    version. It also re-runs `seed_data()` (chart of accounts) and `Capabilities::add_caps_to_admin()`, because
    those are needed on new sites too. When versions match, nothing is queried at all.
  - `install_site()` takes a MySQL named lock `emeacc_db_upgrade_{$wpdb->prefix}` (`GET_LOCK(..., 30)` /
    `RELEASE_LOCK`), the same pattern EME uses, so parallel requests can't upgrade concurrently. Lock names are
    per-site (they contain the table prefix).
  - `Installer::migrate( int $current_version )` receives the *stored* version; `0` means "not installed yet" and
    means "nothing to migrate" (`create_tables()` already made the current schema). `DB_VERSION` is `'21'`.
  - When you add a column/table: bump `DB_VERSION`, add a `if ( $current_version < N )` block in `migrate()`.
    The `plugins_loaded` check picks it up on existing installs — no activation needed.
- **Multisite:** network activation loops all sites (`activate( bool $network_wide )` → `switch_to_blog()` per
  site), `wp_initialize_site` (priority 900) installs the schema for newly created sites while the plugin is
  network-active, and `uninstall()` cleans every site (and removes the WP capabilities everywhere) because core's
  `uninstall_{$file}` action passes no arguments. Single-site installs keep working unchanged.
- `Installer::drop_tables()` omits `emeacc_patrimony` — a bug if you enable "delete on uninstall".
- **The balance table is denormalized.** `emeacc_account_totals` holds one `delta` per account, maintained
  incrementally by `Accounts::adjust_totals()` on every journal insert/update/delete — never recomputed from
  the journal. If it drifts, `Accounts::rebuild_totals()` repairs it (weekly cron + a settings button). Any new
  write path that touches the journal **must** call `adjust_totals()` too, or balances silently go wrong.
- `Journal::save()` writes journal rows **and** the totals inside explicit `START TRANSACTION` / `COMMIT`
  blocks. On `adjust_totals()` failure it does `ROLLBACK` then `rebuild_totals()` (a nested transaction would
  deadlock) — keep that order if you touch this code.
- A transfer (`direction = 'transfer'`) creates **two** journal rows with cross-linked `transfer_id` (each points
  at the other). Deleting one leg deletes the pair.
- `Journal::save()` **cannot change the direction** of an existing entry (`no_direction_update` error).
- Tables are dropped on uninstall only when the option `eme_accounting_delete_on_uninstall` is `'yes'`.

## Fiscal years gate all bookings

- A journal entry needs an **existing** fiscal year covering its `entry_date`: `Journal::save()` calls
  `FiscalYear::get_for_date()` and errors with `no_fiscal_year` if none exists — the auto-create call
  (`get_or_create_for_date`) is deliberately commented out at `includes/Journal.php:146`.
- A **closed** fiscal year rejects new bookings (`year_closed`) and deletes are blocked
  (`includes/Journal.php:356`).
- `FiscalYear::close()` currently only flips `status`/`closed_at` — the closing-entry creation block is
  commented out (`includes/FiscalYear.php:270-310`), even though the journal schema still has
  `is_closing_entry` and `FiscalYear::reopen()` can delete closing entries.
- `FiscalYear::get_or_create_result_account()` exists but is currently unused.
- Two crons: `eme_accounting_daily_cron` (scheduled in `Depreciation::init()`; runs automatic depreciation on
  January 2) and `eme_accounting_weekly_rebuild_totals` (scheduled/cleared from the settings toggle via
  `Installer::sync_weekly_rebuild_cron()`, Sunday 03:00). Both registrations must happen on **every** request,
  not only on activation.

## Capabilities

Two levels per page: `emeacc_view_<page>` and `emeacc_manage_<page>` (`includes/Capabilities.php:125-131`).
`manage_options` always grants the settings page.

- **Adding an admin page requires three edits**: `Capabilities::PAGES`, the `PROFILES` maps (`viewer`,
  `bookkeeper`, `manager`), **and** `Admin\Menu::$subpages`.
- `Capabilities::PROFILES` maps a role to a per-page level. `detect_profile()` returns `'custom'` when a role's
  caps match no named profile.

## AJAX & security conventions

- **No REST API and no `wp_ajax_nopriv_*`** — every endpoint is logged-in only.
- All handlers are `wp_ajax_eme_accounting_*` and must call **both**:
  1. `check_ajax_referer( 'eme_accounting_nonce', 'nonce' );`
  2. `Capabilities::require_manage_ajax( '<page>' )` for writes, or `require_view_ajax( '<page>' )` for reads.

  The `<page>` must be a key of `Capabilities::PAGES`.
- The nonce action is always `eme_accounting_nonce` and the POST/GET parameter is always named `nonce`.
  `includes/Assets.php:50-52` localizes it to JS as `emeAccounting.nonce`.
- Sanitize input with EME's `eme_sanitize_request()` — always followed by the trailing
  `//phpcs:ignore WordPress.Security.ValidatedSanitizedInput` comment.
- `templates/settings.php` is the only page that POSTs from a template, and it uses **five separate nonces**
  (`eme_accounting_settings`, `emeacc_access_save`, `emeacc_weekly_rebuild`, `emeacc_rebuild_totals`,
  `emeacc_vat_rates`). Don't reuse the AJAX nonce there.
- List endpoints feeding **ftable** must answer `{Result:'OK', Records:[...], TotalRecordCount:N}` and use
  `eme_get_ftable_limit()` / `eme_get_ftable_orderby()` (they read the `jtStartIndex` / `jtPageSize` /
  `jtSorting` request params). Other endpoints answer `{success, data}` via `wp_send_json_success/error`.
- CSV and PDF endpoints stream directly with `header()` + `exit`; they do **not** use `wp_send_json_*`.

## JavaScript conventions

- **Vanilla JS, no jQuery, no build step, no bundler.** `assets/js/admin.js` defines shared helpers
  (`esc`, `formatCurrency`, `parseBE`, `formToObj`, `toFormData`, `flashButton`, `fadeRemove`, `ajax`,
  `initSnapSelect`, `initSnapSelectRemote`) **outside** its IIFE on purpose — `journal.js`, `import.js` and
  `fiscalyears.js` are separate IIFEs that rely on those globals existing. Adding a helper that later files need
  means putting it *above* the IIFE.
- `admin.js` uses a **single delegated dispatcher** keyed by form id / class name (`submitHandlers`,
  `clickHandlers` maps). A new form goes into that map, not into a new listener.
- Page-scoped scripts are enqueued only on their page (`includes/Assets.php:91-142`): `journal.js` on
  `emeaccounting-journal`, `import.js` on `emeaccounting-import`, `fiscalyears.js` on
  `emeaccounting-fiscal-years`, Chart.js on `emeaccounting-reports`. Each depends on the `emeaccounting` handle.
- JS globals: `emeAccounting` (`{nonce, ajaxUrl, i18n:{...}}`) on `admin.js`; `emeAccountingImport`
  (`{coaSelectIn, coaSelectOut, accountOptions, columnSchema}`) on `import.js`.
- Money in JS is formatted `nl-BE` and parsed with `parseBE()` (`1.234,56`) — do not use `parseFloat` on
  user-entered amounts.
- `initSnapSelect` / `initSnapSelectRemote` must be re-run after ftable rebuilds the tbody
  (`assets/js/import.js:160-172`).

## Templates

- `Admin\Menu::load_template()` passes exactly **one** variable into a template: `$form_disabled`, a *string* of
  HTML attributes (`''` or `'disabled="disabled"'`), emitted as `<fieldset <?php echo $form_disabled; ?>>`.
  The page key is **not** available inside templates — hard-code it.
- `templates/patrimony.php`, `templates/reports.php` and `templates/settings.php` do **not** use
  `$form_disabled` at all. `templates/journal.php:26-43` re-derives it (closed FY / transfer / inactive account).
- Templates render server-side views and let JS do the writes; there is no view layer and no
  `wp_add_inline_script()` anywhere — a new inline script is a raw `<script>` block in the template.
- Most templates early-`return` with an error notice when a prerequisite is missing (no accounts, no fiscal
  year): `templates/import.php:6-9`, `templates/dashboard.php:14-17`, `templates/journal.php:16-19`,
  `templates/reports.php:6-9`, `templates/budget.php:16-20`, `templates/patrimony.php:9-13`.
  `templates/fiscalyears.php` is the exception: it *auto-creates* the current fiscal year via
  `FiscalYear::ensure_current_year_exists()` instead of erroring out.
- `eme_ui_select()` / `eme_ui_multiselect_key_value()` output is echoed raw with a
  `//phpcs:ignore ... OutputNotEscaped` comment.

## Settings

- The single option `eme_accounting_settings` holds the defaults. **Its defaults live in exactly one place**:
  `templates/settings.php:81-90` (`default_income_account`, `delete_on_uninstall`, `default_coa`,
  `eme_auto_booking`, `weekly_rebuild_totals`, `eme_scope`, `eme_cat_ids`, `eme_exclude_cat_ids`). There is no
  `Settings` class.
- `weekly_rebuild_totals` is saved by a **separate** form/handler, so the general settings save explicitly
  re-reads and preserves it (`templates/settings.php:29`). Keep that behaviour if you touch the settings save.
- `eme_accounting_delete_on_uninstall` is a **separate option** mirroring `$settings['delete_on_uninstall']` —
  `Installer::uninstall()` reads the option, not the array.
- VAT rates: official 6/12/21 in `Vat::OFFICIAL_RATES` plus custom rates in the option
  `eme_accounting_vat_rates_custom`.
- `EMEBridge` auto-books an income journal entry on `eme_payment_paid`, but only when both
  `default_income_account` and `default_coa` are configured; it de-duplicates on `eme_payment_id`.

## PDF

- PDF templates are plain PHP emitting HTML + an inline `<style>` block; each gets `$data`, `$config` and `$year`
  (patrimony gets `$p` and `$totals`). The filename must match the report `type` string.
- Allowed export types are `income_expenditure, patrimony, events, financial_year, vat_report, cashflow`
  (`includes/Reports.php:480-483`); `financial_diary` is the fallback default and is *not* in that list but
  does have a PDF template. **`events` and `vat_report` have no `templates/pdf/` file**, so a `format=pdf`
  request for those fails on the include.
- Orientation: A4 portrait, except cashflow and patrimony (landscape).
- **Known bug**: all five PDF templates read `$config->bus`, but the eme-vzw-helper config key is `house_bus`
  (`templates/pdf/*.php:14-15`) — the bus number is always empty.
