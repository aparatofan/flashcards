# TBT Flashcards 2.4.0 — lesson glossaries without wpDataTables, plus "+ My Dictionary"

About **318 published lessons** end with a wpDataTables glossary: a two-column table of expression and Polish translation, shown with `[wpdatatable id=N]`. The words live in three places:

| Source | Tables | Notes |
|---|---|---|
| Google Sheets | 209 | These broke once, when Google changed the connection. |
| Excel files in uploads | 98 | 14 of them point to the old host's path `/home/5f2d1904c1ac191bfcc55f78/public_html/…` |
| wpDataTables tables in the database | 6 | Two point to `aoszl_…` tables that no longer exist. |

Lessons made with Lesson Builder already use Flashcards' own glossary (`[tbt_flashcards_glossary ids="…" urls="…"]`), which has search, sorting and paging.

2.4.0 does two things:

1. **Import.** A one-off admin screen reads every old glossary from its source and **stores its rows in the lesson itself** (post meta; Mariusz chose this over making flashcard sets). It then swaps the wpDataTables shortcode for `[tbt_flashcards_glossary source="post"]`. Each row shows a preview before anything is written, and there's an Undo. After this, no lesson glossary depends on Google, an Excel file or wpDataTables.
2. **+ My Dictionary.** Every glossary, old and new, gets a third column for logged-in students. Each row has a **+ My Dictionary** button, which opens a small modal: the student can change the translation, then pick a group or type a new one, and press **Add word**. The row then shows **✓ In my dictionary**. This follows the approved prototype (`docs/prototypes/search-table.html` from tbt-search-table, **Lesson glossary** view).

Repo: `aparatofan/flashcards`, from `main` at **2.3.0**. Prefix `tbt_fc_` / `TBT_FC_`.

## Depends on

| Plugin | Needed? | Used for |
|---|---|---|
| TBT Dictionary **0.3.0** | optional | `POST /lookup`, `POST /words`, `POST /words/{id}/fill`. Without it, there's no Dictionary column and everything else works. |
| TBT Points | via Dictionary | +1 point per new word (Dictionary's own rule; Flashcards doesn't call Points). |
| wpDataTables | only while importing | its tables `wp_wpdatatables`, `wp_wpdatatable_*`, `wp_wpdatatables_cache` are **read**, never written. Its PHP code is never called. |

## Decisions (locked)

| Topic | Decision |
|---|---|
| Storage | Post meta `_tbt_fc_glossary`: JSON list of `[expression, translation]`, in the source's order, duplicates (by trimmed, case-insensitive expression) dropped, empty expressions dropped. `_tbt_fc_glossary_src`: JSON `{table_id, type, source, rows, imported_at}`. New keys only. |
| Shortcode | `[tbt_flashcards_glossary source="post"]` reads the current post's `_tbt_fc_glossary`. An optional `post="123"` reads another post's. `ids` and `urls` work as before and can be combined: sets first, then CSVs, then the stored rows, with duplicates dropped across all of them (`tbt_fc_glossary_rows()` already does this). |
| What gets imported | `post` items only (any status except trash), which are the lessons. Pages and Divi library items are **out of scope**; Mariusz is retiring those pages. Table IDs **117, 185, 277, 278, 280, 281, 282, 289, 291** are never treated as glossaries (catalogues and libraries). This list is a constant. |
| Shared tables | A table used by two lessons is read once and stored in **each** lesson. |
| Columns | The first two columns that have any non-empty cell. Manual tables also skip `wdt_ID`. A header row is dropped when its first cell matches `/^(item|items|word|words|expression|expressions|english|phrase|phrases|term|terms|vocabulary|glossary)\b/i` or its second cell matches `/(translation|polish|polski|tłumaczenie)/i`. The screen shows what was dropped. |
| Domain colour | From the lesson's top-level category: Learn English → `le`, General Interest → `gi`, Personal Development → `pd`, otherwise none. Phase 0 gives the term IDs. |
| Dictionary column | Logged-in users only, and only when Dictionary 0.3.0+ is active (`defined( 'TBTDIC_VERSION' ) && version_compare( TBTDIC_VERSION, '0.3.0', '>=' )`). Logged out: no column, no line. |
| Group picker | Same pattern as the Dictionary's **Add a word** dialog (Dictionary 0.1.0 Task 7.7): a text input with a `<datalist>` of the student's groups. The hint lines are: "Type a group name or pick one from the list." / "N words in this group." / "**New group:** “X” will be created when you add the word." It's prefilled with the last group used on this site (localStorage `tbtfc:{user}:dictGroup`, wrapped in try/catch). |
| Points | Whatever Dictionary's `POST /words` returns. Toast: "+N point(s) · added to {group}", or "Added to {group}" when N = 0. |
| Safety net | When wpDataTables is **not** active, Flashcards registers `wpdatatable` as a shortcode that returns `''` for visitors, and a small grey "Old wpDataTables table (id N) — not shown" note for admins. While wpDataTables is active, its own shortcode wins. |

---

## Phase 0 — read-only inventory (stop and report)

1. **Version.** Confirm `main` is at 2.3.0 and list any open PRs.
2. **Glossary code.** Show `tbt_fc_render_glossary()`, `tbt_fc_glossary_rows()`, `assets/js/glossary.js` (how rows are read from `data-rows` and rendered) and `assets/css/glossary.css` (file and line). Confirm the table currently has exactly two columns, and list every place that assumes two.
3. **SQL for Mariusz** (read-only, phpMyAdmin, prefix `wp_`). Write short queries that report:
   - a. `SHOW CREATE TABLE wp_wpdatatables_cache;` and 3 sample rows (`LEFT(…, 300)` on long columns). Does it hold the last-fetched data per table ID? If it does, it's the fallback for a Google Sheet that can't be fetched.
   - b. `SHOW CREATE TABLE` and the first 5 rows for `wp_wpdatatable_57`, `wp_wpdatatable_58`, `wp_wpdatatable_61`; then `SHOW TABLES LIKE 'aoszl%'` and `SHOW TABLES LIKE 'Grit'`.
   - c. Posts (type `post`, not trash) whose `post_content` contains **more than one** `[wpdatatable`, with all their table IDs.
   - d. Whether any post uses a Divi library item that contains `[wpdatatable` as a **global** module (`post_content LIKE '%global_module="16766"%'`, and the same for 17266, 31954, 36453, 37161, 365625).
   - e. How many `post` items have a `_et_pb_old_content` meta that contains `[wpdatatable`.
   - f. The term IDs of the top-level categories *Learn English*, *General Interest* and *Personal Development* (names as on the site).
4. **Server.** Report whether PHP has `ZipArchive` and `SimpleXML` (needed to read `.xlsx`) and whether `wp_remote_get( 'https://docs.google.com/' )` works from the server. Mariusz can check the first two in **Tools → Site Health → Info → Server / PHP extensions**.
5. **Dictionary.** Confirm Dictionary 0.3.0's `POST /lookup` (its own spec) is merged, or say it isn't yet. Build against the documented shape either way.
6. **Report and STOP.** **Hard stop** if:
   - (d) finds a global module carrying a wpDataTables glossary into posts;
   - `ZipArchive` is missing;
   - (e) shows Divi keeps a second copy of the content that also has to be changed, and it's not clear how.

---

## Files

```
tbt-flashcards.php                 version 2.4.0; Tools menu item
includes/glossary.php              source="post" / post="", Dictionary column data
includes/glossary-import.php       new: scan, readers, import, undo, safety-net shortcode
includes/glossary-import-format.php new: pure helpers (no WordPress), tested
assets/js/glossary.js              Dictionary column, modal, lookup, add
assets/css/glossary.css            third column, + button, ✓ state, modal, toast
assets/js/glossary-import.js       new: import screen
assets/css/glossary-import.css     new: plain wp-admin styling
tests/test-glossary-import.php     new
CHANGELOG.md, README.md, CLAUDE.md
docs/SPEC-2.4.0.md                 this file (Mariusz adds it)
```

---

## Task 1 — Pure helpers (`glossary-import-format.php`)

No WordPress. Tested in Task 8.

- `wdt_ids( string $content ): int[]`: every `[wpdatatable …]` in order. The ID comes from `id=N`, `id="N"`, `id='N'` or `id=&quot;N&quot;`, including curly quotes. Duplicates are removed.
- `replace_wdt( string $content, int $table_id, string $with ): string`: replaces every `[wpdatatable …]` whose ID is `$table_id` (and only those) with `$with`.
- `google_csv_url( string $url ): string`. Returns `''` if the URL isn't a Google Sheet. Two forms:
  - `/spreadsheets/d/{ID}/…#gid=G` or `?gid=G` → `https://docs.google.com/spreadsheets/d/{ID}/export?format=csv&gid=G` (gid 0 if none);
  - `/spreadsheets/d/e/{PUB}/pubhtml?gid=G…` → `https://docs.google.com/spreadsheets/d/e/{PUB}/pub?gid=G&single=true&output=csv`.
- `upload_path( string $content, string $basedir, string $baseurl ): string`. Takes a URL or an absolute path and returns the local file path, or `''`:
  - a URL starting with `$baseurl` → `$basedir` + the rest;
  - any path or URL containing `/wp-content/uploads/` → `$basedir` + the part after it. This fixes the old host's paths.
- `csv_rows( string $text ): array[]`: parsed with `fgetcsv` on `php://temp`, so quoted newlines work. A UTF-8 BOM is stripped.
- `two_columns( array $rows ): array`: returns `{rows, header_dropped, columns_used}`, following the Decisions rules for columns and header.
- `clean_rows( array $rows ): array[]`: trims, collapses whitespace, decodes HTML entities, drops empty expressions, and drops duplicate expressions (case-insensitive, first one wins).

## Task 2 — Readers (`glossary-import.php`)

`tbt_fc_wdt_read( int $table_id ): array`. Returns `{ ok, rows[], type, source, note }`. It never throws: every failure is `ok = false` with a plain `note`.

1. Read the table's row from `wp_wpdatatables` (`id`, `title`, `table_type`, `content`, `mysql_table_name`). Not found → "wpDataTables has no table N".
2. By type:
   - **`manual`:** the table name must match `/^[A-Za-z0-9_]+$/` and exist (`SHOW TABLES LIKE`). Then `SELECT * FROM \`name\`` (at most 2 000 rows), dropping `wdt_ID`. A missing table → "The data table X no longer exists".
   - **`xls`:** `upload_path()`. If the file is missing → "File not found: …/uploads/2020/07/x.xlsx". `.xlsx`: open with `ZipArchive`, read `xl/sharedStrings.xml` and the **first** worksheet (`xl/workbook.xml` + rels give its path) with `SimpleXML`, and handle shared strings, inline strings and numbers. Use `t="s"` and the cell reference to place each value in its column, so empty cells don't shift values. `.csv` files go through `csv_rows()`. Anything else (`.xls`, the old binary format) → "Old .xls format: please re-save as .xlsx".
   - **`google_spreadsheet`:** `google_csv_url()`, then `wp_remote_get( …, timeout 15 s, redirection 5 )`.
     - On HTTP 200 with a body that isn't HTML: `csv_rows()`.
     - Otherwise: try **`wp_wpdatatables_cache`** for this table ID if Phase 0 found usable data there (`source` = "cache").
     - If both fail: "Google Sheet can't be read (not shared publicly?)" with the HTTP code.
   - Empty or unknown type → "Unknown table type".
3. `two_columns()`, then `clean_rows()`. Zero rows → `ok = false`, "The table is empty".

Each result is cached in a transient `tbt_fc_wdt_{id}` for 1 hour, so a rescan doesn't fetch Google again. **Rescan** clears these transients.

## Task 3 — Import screen

**Flashcards → Import glossaries** (`manage_options`), shown only while at least one `post` still contains `[wpdatatable`, or while there is something to undo. Plain wp-admin styling, in English, like Search Table's import.

**Find (on page load, read-only):** every `post` (not trash) whose `post_content` contains `[wpdatatable`, and its glossary IDs (`wdt_ids()` minus the never-list). It shows:
- **N lessons**, **M tables**
- a **Read all** button

Posts whose only wpDataTables are on the never-list don't appear.

**Read all.** The browser calls `POST /tbt-flashcards/v1/glossary-import/read` with **10 table IDs per request**, one request at a time, and shows a progress bar ("Reading 40 of 313…"). Each table is read once, even when two lessons use it. Then it shows the list, one row per lesson:

| ☐ | Lesson (title, status, edit link) | Table (ID, title, type) | Rows | Preview | Status |
|---|---|---|---|---|---|

- **Preview:** the first 3 rows, plus "header dropped: Item / Translation" when a header was dropped, and "columns 1 and 3 used" when the columns weren't the first two.
- **Status:**
  - ✓ Ready (green, ticked)
  - ✗ the reader's note (red, unticked, can't be ticked)
  - **Already imported** (grey), when the lesson has `_tbt_fc_glossary` and no `[wpdatatable` left for that ID
- Filter chips: **All · Ready · Failed**. A count line: "289 ready · 24 failed".
- A lesson with two glossary tables gets **two rows**. Importing both stores the rows of both tables, merged in their order.

**Import selected** (`POST …/glossary-import/run`, 20 lessons per request, one request at a time, with progress). For each ticked lesson:
1. If `_tbt_fc_wdt_backup` doesn't exist yet, store the current `post_content` there. This is the backup, and it's written only once.
2. Merge the ticked tables' rows (`clean_rows()` again across them) into `_tbt_fc_glossary`, and write `_tbt_fc_glossary_src`.
3. `replace_wdt()` for each ticked ID, swapping in `[tbt_flashcards_glossary source="post" domain="xx"]` (or no `domain` when there is none). The **first** ticked ID gets the shortcode. Any second one is removed, so the lesson ends up with one glossary.
4. Save with `$wpdb->update()` on `post_content` (no revision, no `save_post` side effects), then `clean_post_cache()`. If Phase 0 (e) found `_et_pb_old_content` copies, update that meta with the same replace.
5. Never touch the post's status, date, author or any other meta.

The server re-reads the rows itself (from the transient, else the source). It never trusts rows sent by the browser.

**Undo.** One button per imported lesson, plus **Undo all**. Each restores `post_content` from `_tbt_fc_wdt_backup` (and the `_et_pb_old_content` copy, if one was changed), then deletes `_tbt_fc_wdt_backup`, `_tbt_fc_glossary` and `_tbt_fc_glossary_src`. **Undo all** asks first, with a confirm dialog built into the page.

**Failed list.** A **Copy failed list** button copies "Lesson title — edit link — table ID — note", one per line, so Mariusz can fix them by hand: open the sheet, export a CSV, and give the lesson a `urls="…"` glossary, or ask for help.

## Task 4 — `source="post"` in the shortcode (`glossary.php`)

- New attributes: `source` (only `post` is recognised) and `post` (ID).
- With `source="post"`, read `_tbt_fc_glossary` of `post` (or `get_the_ID()` inside the loop, else the queried object), validate it (a list of 2-string lists), and add it as one more "set" to `tbt_fc_glossary_rows()`.
- `tbt_fc_maybe_enqueue_glossary_early()` keeps working, because the shortcode tag is the same.
- When the rows are empty, the existing admin-only notice says "Glossary: no rows stored on this lesson." for `source="post"`.

## Task 5 — Dictionary column (`glossary.php`, `glossary.js`, `glossary.css`)

**Server:** when the column applies (Decisions), add to the root `<div class="tbt-fc-gl">`:
- `data-dict="1"`
- `data-dict-api` = `rest_url( 'tbt-dictionary/v1/' )`
- `data-nonce` = `wp_create_nonce( 'wp_rest' )`
- `data-user` = the user ID, used only for the localStorage key

Also add a third `<th>` holding a visually hidden "My Dictionary". Nothing is added for logged-out users.

**Client:**
1. On init, when `data-dict="1"`, send **one** `POST lookup` with all the glossary's expressions. Store `groups` and `have`.
   - Rows in `have`: the cell shows **✓ In my dictionary** (green, `title` = "In {group}").
   - Other rows: a **+ My Dictionary** pill button (outline, as in the prototype), with `aria-label` "Add {expression} to My Dictionary".
   - If the lookup fails: buttons still show, `groups` is empty, and the modal still works (a new group name is fine).
2. Search, sort, paging and **Show all** keep working with the third column. Sorting ignores it.
3. **Modal** (built into the page, `role="dialog"`, `aria-modal`, focus trapped, Escape and the backdrop close it, focus returns to the button):
   - title **ADD TO MY DICTIONARY** (Roboto Mono)
   - the expression in Roboto Slab
   - **Translation**: an input prefilled with the glossary's translation
   - **Group**: an input with the `<datalist>` and hint lines (Decisions), prefilled from localStorage
   - buttons **CANCEL** (ghost) and **ADD WORD** (blue pill)
   - Enter in the Group field submits. When there's a saved group, ADD WORD gets the focus; otherwise Group does.
4. **Add:** `POST words` with `{group_name, term, translation}`.
   - **200:** the row becomes ✓, and the toast shows "+N point(s) · added to {group}" (Decisions). The group is remembered, and `groups` is updated (or the new group added). Then fire-and-forget `POST words/{id}/fill`; its result isn't shown.
   - **409:** the row becomes ✓ with the group from the message, and the modal closes with the toast "Already in your dictionary ({group})".
   - **400 / 401 / 403 / 5xx:** the server's message in red under the Group field, and the modal stays open. On `rest_cookie_invalid_nonce`, the message is "Your session has expired. Reload the page and try again."
   - The button is disabled while saving.
5. Every value goes into the page as text (`textContent`), never as markup.
6. **Styling:**
   - TBT blue for the button, the modal buttons and focus.
   - Success green `#16803C` for ✓.
   - The toast is a green pill at the bottom centre, gone after 2.4 s, with no motion under `prefers-reduced-motion`.
   - Below 600px the third cell wraps under the translation, left-aligned.
   - Every class is prefixed `tbt-fc-gl-`.

## Task 6 — Safety-net shortcode

On `init` at priority 20: if `! shortcode_exists( 'wpdatatable' )`, register it to return `''`, or the admin-only note (Decisions).

## Task 7 — Version, docs

`TBT_FC_VERSION` and the header → `2.4.0`. CHANGELOG. README: the `source="post"` attribute, the import screen, and the Dictionary column. CLAUDE.md: the new meta keys `_tbt_fc_glossary`, `_tbt_fc_glossary_src` and `_tbt_fc_wdt_backup` as data contracts; current release → `docs/SPEC-2.4.0.md`.

## Task 8 — Tests (`tests/test-glossary-import.php`)

Plain PHP, as `test-glossary.php`.
- `wdt_ids`:
  - `[wpdatatable id=139]`, `id="139"`, `id=&quot;139&quot;`, curly quotes
  - two tables
  - none
- `replace_wdt`: only the given ID changes, and every occurrence of it does.
- `google_csv_url`:
  - `edit#gid=0`, `edit?gid=625851131#gid=625851131`, no gid
  - the `pubhtml?gid=0&single=t` form
  - a non-Google URL → `''`
- `upload_path`:
  - a site URL
  - the old `/home/5f2d1904…/public_html/wp-content/uploads/2020/07/x.xlsx`
  - the current `/home/platne/…/uploads/…`
  - unrelated → `''`
- `csv_rows`: a BOM, quoted commas, quoted newlines.
- `two_columns`:
  - a header dropped (`Item | Translation`)
  - no header
  - an empty first column
  - three columns
- `clean_rows`: entities, duplicates, empty expressions.

## Validation

```bash
for f in tbt-flashcards.php includes/*.php; do php -l "$f"; done
node --check assets/js/glossary.js && node --check assets/js/glossary-import.js
php tests/test-glossary.php && php tests/test-glossary-import.php
```

---

## Manual steps for Mariusz

1. Before CC starts: run the Phase 0 SQL and paste the results.
2. Deploy **Dictionary 0.3.0** first (its own spec), then this.
3. **Flashcards → Import glossaries → Read all.** Check the Failed list.
4. Import a few lessons first and check them live: the glossary looks right, and **+ My Dictionary** works. Then import the rest.
5. Purge LiteSpeed's cache.
6. Fix the failed ones by hand, using the copied list.
7. When no lesson has `[wpdatatable` left (the menu item disappears), and the non-lesson pages are gone or replaced, deactivate and delete **wpDataTables**.

## Acceptance checklist (live)

- [ ] **Read all** finishes with a progress bar. Most tables are **Ready**. Each failure gives a reason (old host path, file not found, sheet not shared, table missing).
- [ ] A Google-Sheet lesson (*Why we sleep*), an Excel lesson (*Make time*), an old-host-path lesson (*How to speak so that people want to listen*) and a database lesson (*How to be happy in English*) all show the right rows in the preview.
- [ ] Import those four. Each lesson's glossary looks like the Lesson Builder glossary (search, sort, 10 at a time, Show all) and shows the same words as before. The lesson's content is otherwise unchanged.
- [ ] **Undo** on one of them brings back the old wpDataTables glossary exactly. Then import it again.
- [ ] Logged in as `pna`: the **+ My Dictionary** column appears. A word already in your dictionary shows **✓ In my dictionary**. Adding a word with a new group name creates the group, the toast shows the point, and the word appears on the Dictionary page with phonetics and an example filled in shortly after. Adding the same word on another lesson shows "Already in your dictionary".
- [ ] Logged out: no third column, and the glossary works.
- [ ] A Lesson Builder lesson's glossary (`ids="…"`) also gets the column.
- [ ] On a phone-width window, the button wraps under the translation and nothing overflows.
- [ ] Deactivate wpDataTables briefly: no lesson shows raw `[wpdatatable id=…]` text. Admins see the grey note on any page still using it. Reactivate it if pages still need it.
- [ ] PHP error log and console: nothing new from `tbt-flashcards`.

## Out of scope

- The non-lesson pages that use wpDataTables (Mariusz is retiring them).
- Making flashcard sets from the old glossaries.
- Editing imported glossary rows on screen. To change one later, give the lesson a CSV or flashcard-set glossary, or ask for an editor in a later release.
- Removing wpDataTables (Mariusz, after the checklist).
