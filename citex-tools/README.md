# Citex Tools

Citex Tools is an internal WordPress admin plugin for managing academic
referencing questions.

**Phase 1** shipped a clean, working admin interface and plugin
architecture with placeholder data throughout.

**Phase 2** (this update) connects the Dashboard and Questions page to
the **real** WordPress question records, read-only, via a browser-side
scanner that reuses the already-proven title-parsing logic supplied for
this phase. Question generation, validation, and WordPress population
are still not implemented — those remain future modules that plug into
the hooks already left in place (see
[Architecture notes](#architecture-notes) below).

## 1. Plugin file structure

```text
citex-tools/
│
├── citex-tools.php                  Plugin bootstrap
│
├── includes/
│   ├── class-citex-admin.php        Menu registration, asset loading, shared notices
│   ├── class-citex-scanner.php      Question-list URL + last-scan storage, scan AJAX endpoints
│   ├── class-citex-dashboard.php    Dashboard page controller (real totals from the last scan)
│   ├── class-citex-generator.php    Generate Questions page controller
│   ├── class-citex-questions.php    Question Bank page controller (real records, search/filter/paginate)
│   ├── class-citex-validator.php    Validation page controller (+ demo data)
│   └── class-citex-populator.php    Populate page controller
│
├── admin/
│   ├── views/
│   │   ├── dashboard.php            Dashboard markup
│   │   ├── generate.php             Generate Questions form markup
│   │   ├── questions.php            Question Bank search/filter/table markup
│   │   ├── validation.php           Validation summary/results markup
│   │   └── populate.php             Populate form markup
│   │
│   ├── css/
│   │   └── citex-admin.css          Small custom stylesheet (cards, badges, table, scan panel)
│   │
│   └── js/
│       ├── citex-scanner.js         Reusable scanner module (fetch/parse/report; no WP writes)
│       └── citex-admin.js           UI wiring: select-all, scan buttons, settings form
│
└── README.md
```

## 2. What each main file does

- **`citex-tools.php`** — Plugin header, defines constants
  (`CITEX_TOOLS_PATH`, `CITEX_TOOLS_URL`, `CITEX_TOOLS_VERSION`),
  requires the `includes/` classes, and boots `Citex_Admin` on
  `plugins_loaded`.
- **`includes/class-citex-admin.php`** — Registers the `Citex`
  top-level menu and its five submenu pages, enqueues
  `citex-admin.css`/`citex-admin.js` only on Citex screens (checked
  against the page hook suffixes returned by `add_menu_page()` /
  `add_submenu_page()`), and provides `Citex_Admin::set_notice()`, a
  shared helper the other controllers use to queue a one-time admin
  notice (via a short-lived transient) before a POST/redirect/GET
  round-trip.
- **`includes/class-citex-scanner.php`** — Stores the administrator-
  configured Question List URL and the most recent scan report as
  WordPress options (`citex_question_list_url`, `citex_last_scan`,
  both `autoload = false`), and exposes the two AJAX endpoints
  (`wp_ajax_citex_save_scanner_settings`, `wp_ajax_citex_save_scan_result`)
  that `admin/js/citex-admin.js` posts to. Every incoming field is
  sanitized (`sanitize_text_field`, `esc_url_raw`, `absint`) before
  being stored. Never reads or writes a WordPress question post —
  purely a cache of what the browser-side scanner found.
- **`admin/js/citex-scanner.js`** — The reusable scanner module. Ported
  from the working Chrome DevTools script supplied for Phase 2 (same
  title-parsing and `countBy` logic), with the page's own
  `window.location.href` replaced by the configurable Question List
  URL, since Citex runs on a different admin screen than the question
  list. Walks every page (`.tablenav-pages .total-pages`), reads
  `#the-list a.row-title` for the title and edit link, extracts the
  WordPress post ID from the edit URL's `post` parameter (never fails
  the scan if one record's ID can't be parsed), and returns a
  structured report with `sources`/`groups`/`categories`/`types`
  breakdowns. Only ever issues `fetch()` `GET` requests — read-only.
- **`includes/class-citex-dashboard.php`** — Total Questions and
  Harvard Questions now come from `Citex_Scanner::get_last_scan()`.
  Valid/Error/Pending counts remain placeholder `—` values (still
  commented `PLACEHOLDER DATA`) until the validation and generation
  modules are connected.
- **`includes/class-citex-generator.php`** — Supplies the dropdown
  options to `admin/views/generate.php` and handles the form submit:
  verifies the nonce, queues the "Question generation engine has not
  yet been connected." notice, and redirects back. No AI call is made
  and no questions are created.
- **`includes/class-citex-questions.php`** — Reads search/filter
  values from `$_GET` (sanitized) and applies them, plus pagination
  (20 per page), to the cached scan's `questions` array from
  `Citex_Scanner::get_last_scan()`. The old demo-data method has been
  removed now that real records are connected; an empty state is shown
  when no scan has run yet.
- **`includes/class-citex-validator.php`** — Supplies placeholder
  summary counts and a **demo** failed-validation record
  (`get_demo_result()`) to `admin/views/validation.php`, and handles
  both "Validate All Questions" / "Validate Selected Questions"
  submits by queuing the "Validation engine has not yet been
  connected." notice. No validation logic runs.
- **`includes/class-citex-populator.php`** — Supplies the source
  dropdown and placeholder readiness counts, and handles the
  "Populate WordPress" submit by queuing the "WordPress population
  engine has not yet been connected." notice. No WordPress content is
  created or modified.
- **`admin/views/*.php`** — Pure display templates; all dynamic output
  is escaped (`esc_html`, `esc_attr`, `esc_url`) and all form fields
  are nonce-protected.
- **`admin/css/citex-admin.css`** — Cards, status badges, filter bar
  and table styling built on top of native WP admin classes
  (`.wrap`, `.button`, `.form-table`, `.wp-list-table`), responsive at
  the standard 782px WP admin breakpoint.
- **`admin/js/citex-admin.js`** — Vanilla JS, no build step; currently
  only wires the Question Bank table's "select all" checkbox.

## 3. Installation instructions

1. Copy the `citex-tools/` directory into your WordPress installation's
   `wp-content/plugins/` directory, so the plugin file is at
   `wp-content/plugins/citex-tools/citex-tools.php`.
2. In the WordPress admin, go to **Plugins** and activate **Citex
   Tools**.
3. A new **Citex** menu (book icon) appears in the admin sidebar with
   Dashboard, Generate Questions, Questions, Validation and Populate
   pages.
4. No database setup or build step is required. One piece of
   configuration is needed before scanning: on the **Dashboard**, open
   **Question List URL settings** and enter the WordPress admin URL of
   your existing question-list screen (e.g.
   `https://yoursite.com/wp-admin/edit.php?post_type=your_question_type`),
   then click **Save**.
5. Click **Scan Question Bank** (Dashboard or Questions page). Citex
   fetches that URL's pages, authenticated as you in the browser, parses
   each title, and stores the result. The Dashboard and Questions page
   then show the real counts, breakdowns, and records.

## 4. Confirmation

No existing WordPress question content is read, created, or modified.
The scanner (`admin/js/citex-scanner.js`) only ever issues `fetch()`
`GET` requests against the question-list URL you configure — it never
submits a form, saves a post, or calls `post.php` with an edit action.
The only writes this plugin makes are to its own plugin options
(`citex_question_list_url`, `citex_last_scan`) so the Dashboard and
Questions page can share the same cached scan. Every "connect to a
real engine" action point that remains (generation, validation,
population) still only queues an admin notice stating the engine isn't
connected yet — no WordPress writes, no external service calls.

## 5. Assumptions made

- No existing WordPress project files were present in this
  repository, so the plugin was built as a standalone
  `citex-tools/` directory ready to be copied into
  `wp-content/plugins/`, per the task's fallback instruction.
- "A suitable WordPress Dashicon" was interpreted as
  `dashicons-book-alt` for the top-level Citex menu.
- Search/filter fields on the Questions page read from `$_GET` (a plain
  GET request with a "Filter" submit) and now filter the real cached
  scan records server-side (search matches title + question ID; the
  Referencing Style/Category/Question Type dropdowns are populated from
  the distinct values actually found in the scan, not a fixed list —
  Phase 2 says to display values exactly as they exist, and real title
  vocabulary may not match the Phase 1 placeholder categories).
- The Questions table is paginated (20 per page) since a real scan can
  return hundreds of records; Phase 1's unpaginated demo table only had
  4 rows and didn't need this.
- Validation Status always shows "Not Validated" for scanned records
  (correct, since no validation has run yet) — the filter still offers
  Valid/Error so the control is ready once validation is connected,
  but selecting either currently yields no matches by design.
- The Questions table's **Edit** button links directly to each
  record's real WordPress edit screen (`editUrl`, opened in a new tab)
  since Phase 2 explicitly asks to preserve that link "so we can later
  edit, validate and update questions." View and Validate remain
  disabled stubs, since only Edit's use (opening WordPress's own,
  unmodified edit screen) was concretely specified.
- The Question List URL and last scan are stored as WordPress options
  (`autoload = false`) rather than transients, since the spec allows
  either and prefers "the simplest reliable" choice — options don't
  expire or get evicted the way transients can.
- The scanner is triggered manually via a button rather than
  automatically on page load, matching "Do not require Chrome DevTools"
  / "Do not require pasting JavaScript" without adding background
  polling that wasn't requested.
- The Generate/Validate/Populate action buttons use a real WordPress
  form submit (nonce + POST/redirect/GET) so the "not yet connected"
  admin notice pattern is a working example the next phase can reuse,
  rather than an inert button.
- Table row action buttons (View/Edit/Validate on Questions,
  Edit/Revalidate on Validation) are rendered `disabled`, since the
  spec says they "do not need real functionality yet" and disabling
  avoids dead links.
- `manage_options` was used as the capability gate for all Citex pages
  (standard WordPress admin capability); no custom roles were added,
  per the constraint against adding roles beyond normal WordPress
  permissions.

## 6. Requirements checklist

| # | Requirement | Status |
|---|---|---|
| 1 | Top-level `Citex` admin menu with Dashicon | ✅ |
| 1 | Submenu: Dashboard / Generate Questions / Questions / Validation / Populate | ✅ |
| 1 | Slugs: `citex`, `citex-generate`, `citex-questions`, `citex-validation`, `citex-populate` | ✅ |
| 2 | Dashboard stat cards (Total / Valid / Errors / Pending) showing `—` | ✅ |
| 2 | Four quick-action buttons linking to the right pages | ✅ |
| 2 | Question Bank Overview placeholder table | ✅ |
| 2 | Placeholder stats clearly marked in code | ✅ (`PLACEHOLDER DATA` comments) |
| 3 | Generate Questions form (all fields, defaults, min/max) | ✅ |
| 3 | Generate button shows "not yet connected" notice, no fake data | ✅ |
| 4 | Questions page: search, filters, Filter button | ✅ |
| 4 | Question table with required columns | ✅ |
| 4 | 3–5 demo records (BK001, BK002, JR001, WB001) | ✅ (4 records) |
| 4 | Demo data isolated for easy removal | ✅ (`get_demo_questions()`) |
| 5 | Validation page: buttons, summary cards, results section | ✅ |
| 5 | Demo failed record (BK002) with demo errors, clearly labelled | ✅ |
| 6 | Populate page: source dropdown, status placeholders, button | ✅ |
| 6 | Populate button shows "not yet connected" notice, no WP writes | ✅ |
| 7 | Clean, responsive, native-feeling styling, small custom CSS only | ✅ |
| 8 | Modular file structure matching the requested layout | ✅ |
| 9 | Direct-access prevention, output escaping, input sanitization, `citex_`/`Citex_` prefixing, scoped asset loading | ✅ |
| 10 | Architecture leaves clear plug-in points for generation/validation/population modules | ✅ (see below) |
| 11 | No AI integration, no external DB, no unrelated frameworks added | ✅ |

## Phase 2 requirements checklist

| # | Requirement | Status |
|---|---|---|
| 1 | Scan all existing question records | ✅ (`admin/js/citex-scanner.js`) |
| 2 | Parse titles using `SOURCE \| GROUP \| CATEGORY \| TYPE \| QUESTION ID` | ✅ (ported verbatim from the supplied script) |
| 3 | Show real total question count | ✅ (Dashboard "Total Questions") |
| 4 | Show Harvard question count | ✅ (Dashboard "Harvard Questions") |
| 5 | Breakdowns by Source / Group / Category / Question Type | ✅ (Dashboard "Question Bank Overview") |
| 6 | Populate the Questions page with real records | ✅ |
| 7 | Search and filter real records | ✅ (server-side, on the cached scan) |
| 8 | Store the most recent scan for Dashboard + Questions to share | ✅ (`citex_last_scan` option) |
| 9 | Preserve links to the original WordPress record | ✅ (`editUrl` / `wpPostId` stored + Edit button linked) |
| — | Configurable Question List URL (not window.location.href) | ✅ (Dashboard settings field) |
| — | No DevTools / no pasted JavaScript required | ✅ (Scan Question Bank button) |
| — | Read-only — no existing question record modified | ✅ (scanner only issues GET requests) |
| — | Refresh / Scan Again | ✅ (same button relabels once a scan exists) |

## Architecture notes

Each future integration point is isolated behind a single method so the
JS prototypes mentioned in the brief (validation, automated WordPress
data entry) can be wired in later without restructuring the plugin.
Record counting (the third prototype) is now connected:

- **Record counting** → done. `Citex_Scanner` + `admin/js/citex-scanner.js`
  scan the real WordPress question list and store the result; the
  `$status` array in `Citex_Populator::render()` is the one remaining
  placeholder (population readiness isn't computable until the
  population module exists).
- **Validation** → `Citex_Validator::maybe_handle_actions()` (replace
  the notice with a real scan) and the `$summary` / demo result
  arrays in `Citex_Validator::render()`. Once connected, each
  question's real validation status can replace the hard-coded
  "Not Validated" badge in `admin/views/questions.php`.
- **Question generation** → `Citex_Generator::maybe_handle_submit()`.
- **WordPress population** → `Citex_Populator::maybe_handle_submit()`.
- **Question storage** → done. `Citex_Questions::render()` now reads
  `Citex_Scanner::get_last_scan()['questions']` instead of demo data.
