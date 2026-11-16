# TBT Flashcards 2.2.0 — Glossary from Media files, left-aligned

Two small changes to the glossary (`[tbt_flashcards_glossary]`, added in 2.1.0). The rest of the plugin stays exactly as in 2.1.0.

1. **Left column alignment.** In the first live lesson built with the Lesson Builder, the Expression column is centred while the Polish column is left-aligned. Both columns must be left-aligned. `glossary.css` sets no `text-align` on `td`, so a theme or Divi rule is centring the first cell.
2. **Glossary from CSV files.** Lesson Builder 0.3.0 lets a part's flashcards come from a CSV in the Media library (`[tbt_flashcards url="…"]`) instead of a flashcard set. The glossary must be able to read those files too. It gains a `urls` attribute next to `ids`.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Alignment | Every glossary cell (`td`, including `td:first-child`) is `text-align: left`. The header cells already are. The no-match row keeps `center`. |
| New attribute | `[tbt_flashcards_glossary ids="12,13" urls="https://…/a.csv,https://…/b.csv" domain="pd"]`. Both are optional; either or both may be given. |
| Order | Sets from `ids` first (in the given order), then files from `urls` (in the given order). De-duplication works as today across all sources (first occurrence wins, case-insensitive). |
| Which URLs | Only files inside this site's uploads (the same scheme-less `baseurl` comparison `tbt_fc_read_csv_text()` already makes). Any other URL is skipped silently. The glossary never fetches over HTTP. |
| Reading | `tbt_fc_read_csv_text()` then `tbt_fc_parse_csv()`, the same reader and parser the `url` flashcards use on the server, so the glossary shows exactly the cards the lesson's flashcards show. A file that cannot be read is skipped. |
| Nothing found | As today: admins see “Glossary: no flashcards found for these sets.”; visitors see nothing. |
| Caching | None. The files are small and read from disk. |

## Files

```
tbt-flashcards/tbt-flashcards.php      version 2.2.0 (header + TBT_FC_VERSION)
tbt-flashcards/includes/glossary.php   urls attribute; a local-only read
tbt-flashcards/assets/css/glossary.css td text-align
tbt-flashcards/tests/test-glossary.php new cases
docs/SPEC-2.2.0.md                     this file (Mariusz adds it)
```

## Phase 0 — read-only inventory (stop and report)

1. Confirm `main` is at 2.1.0 and list any open PRs.
2. In `glossary.css` and `glossary.js`: confirm nothing sets the first column's alignment. Say which selector specificity the fix needs so it beats a typical theme rule like `.entry-content td:first-child` or Divi's `.et_pb_text td`.
3. `tbt_fc_read_csv_text()`: confirm it reads uploads from disk. Propose how the glossary gets a **disk-only** read (no `wp_remote_get` fallback). Either add a `$local_only` parameter defaulting to `false`, or add a small wrapper that checks the uploads prefix first. Pick the one that changes existing behaviour least.
4. `tbt_fc_parse_csv()`: confirm it returns `{word, phonetic, translation, example}` rows that `tbt_fc_glossary_rows()` accepts unchanged.

**Hard stop** if anything contradicts this spec. Otherwise report and wait for Mariusz to say go.

## Task 1 — Version

`Version: 2.2.0`, `TBT_FC_VERSION` `2.2.0`.

## Task 2 — Alignment (`glossary.css`)

Add `text-align: left` to the glossary's `td` rule and to its `td:first-child` rule, at the specificity agreed in Phase 0 (no `!important` unless Phase 0 shows it is needed). Keep the no-match row centred.

## Task 3 — `urls` (`glossary.php`)

- `shortcode_atts` gains `'urls' => ''`.
- `tbt_fc_glossary_parse_urls( $value )`: split on commas, trim, `esc_url_raw`, drop empties and duplicates, keep order.
- After the sets from `ids`, each URL is read locally only (Phase 0 item 3), parsed with `tbt_fc_parse_csv()` and appended to `$sets`. Skip on any error.
- Tests: `tbt_fc_glossary_parse_urls()` (commas, spaces, duplicates, empty). A URL outside uploads is skipped. A set and a file holding the same word give one row (the set's).

## Validation

```bash
for f in tbt-flashcards/tbt-flashcards.php tbt-flashcards/includes/*.php; do php -l "$f"; done
php tbt-flashcards/tests/test-glossary.php
```

## Acceptance checklist (live site)

- [ ] The glossary of the lesson built on 16 Nov 2026 shows both columns left-aligned.
- [ ] `[tbt_flashcards_glossary urls="<a CSV from Media>" domain="pd"]` on a draft lists that file's words and translations.
- [ ] `ids` and `urls` together list the set's words first, then the file's, with no duplicates.
- [ ] A URL to another site or a missing file is skipped without an error for visitors.
- [ ] Existing glossaries (`ids` only) look and work as before, apart from the alignment.
