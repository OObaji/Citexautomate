# Citex Tools (Phase 1 — Admin Plugin Shell)

Citex Tools is an internal WordPress admin plugin for managing academic
referencing questions. This is the **Phase 1 MVP**: a clean, working
admin interface and plugin architecture. It does **not** implement
question generation, validation, WordPress population, or record
counting — those are future modules that plug into the hooks already
left in place (see [Architecture notes](#architecture-notes) below).

## 1. Plugin file structure

```text
citex-tools/
│
├── citex-tools.php                  Plugin bootstrap
│
├── includes/
│   ├── class-citex-admin.php        Menu registration, asset loading, shared notices
│   ├── class-citex-dashboard.php    Dashboard page controller
│   ├── class-citex-generator.php    Generate Questions page controller
│   ├── class-citex-questions.php    Question Bank page controller (+ demo data)
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
│   │   └── citex-admin.css          Small custom stylesheet (cards, badges, table)
│   │
│   └── js/
│       └── citex-admin.js           Minimal vanilla JS (select-all checkbox)
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
- **`includes/class-citex-dashboard.php`** — Supplies placeholder
  (`—`) statistics and the placeholder question-bank overview table
  to `admin/views/dashboard.php`. All placeholder values are commented
  `PLACEHOLDER DATA` at the point they're defined.
- **`includes/class-citex-generator.php`** — Supplies the dropdown
  options to `admin/views/generate.php` and handles the form submit:
  verifies the nonce, queues the "Question generation engine has not
  yet been connected." notice, and redirects back. No AI call is made
  and no questions are created.
- **`includes/class-citex-questions.php`** — Reads search/filter
  values from `$_GET` (sanitized), supplies filter dropdown options,
  and supplies **demo** question records from the private
  `get_demo_questions()` method — the only place demo data lives, so
  it can be deleted in one go once real records exist.
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
4. No configuration, database setup, or build step is required.

## 4. Confirmation

No existing WordPress content, questions, options, or database tables
are read, created, or modified by this plugin. Every "connect to a
real engine" action point (generation, validation, population) only
queues an admin notice stating the engine isn't connected yet — it
performs no WordPress writes and calls no external service.

## 5. Assumptions made

- No existing WordPress project files were present in this
  repository, so the plugin was built as a standalone
  `citex-tools/` directory ready to be copied into
  `wp-content/plugins/`, per the task's fallback instruction.
- "A suitable WordPress Dashicon" was interpreted as
  `dashicons-book-alt` for the top-level Citex menu.
- Search/filter fields on the Questions page read from `$_GET` (so the
  form is a plain GET request with a "Filter" submit) but do not yet
  filter the demo table — no real filtering logic was requested for
  this phase, only the interface.
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

## Architecture notes

Each future integration point is isolated behind a single method so the
JS prototypes mentioned in the brief (record counting, validation,
automated WordPress data entry) can be wired in later without
restructuring the plugin:

- **Record counting** → `Citex_Dashboard::render()` (replace the
  `$stats` / `$overview_rows` arrays) and `Citex_Populator::render()`
  (replace the `$status` array).
- **Validation** → `Citex_Validator::maybe_handle_actions()` (replace
  the notice with a real scan) and the `$summary` / demo result
  arrays in `Citex_Validator::render()`.
- **Question generation** → `Citex_Generator::maybe_handle_submit()`.
- **WordPress population** → `Citex_Populator::maybe_handle_submit()`.
- **Question storage** → `Citex_Questions::get_demo_questions()` is the
  single seam to swap for a real query once questions are stored as
  WordPress records.
