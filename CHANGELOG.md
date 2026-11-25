# Changelog — TBT Flashcards

## 2.4.0 — 25 Nov 2026

Lesson glossaries without wpDataTables, plus "+ My Dictionary". Spec: `docs/SPEC-2.4.0.md`.

- **Import glossaries** (Flashcards → Import glossaries, administrators; shown while a lesson still uses a wpDataTables glossary or an import can be undone). **Read all** reads every old glossary (Google Sheet via its CSV export, `.xlsx`/`.csv` in uploads including the old host's paths, or a wpDataTables manual table), 10 tables per request, with a preview, the dropped header and the columns used. **Import selected** stores the rows on the lesson (`_tbt_fc_glossary`, `_tbt_fc_glossary_src`) and swaps the shortcode for `[tbt_flashcards_glossary source="post" domain="…"]`, saving `post_content` directly (no revision, no `save_post`). The old content is kept once in `_tbt_fc_wdt_backup`; **Undo** / **Undo all** restore it. Divi's `_et_pb_old_content` copy gets the same change (and its own backup). **Copy failed list** for the ones to fix by hand. Tables 117, 185, 277, 278, 280, 281, 282, 289 and 291 are never glossaries. A table inside a Divi global module is not imported.
- **`source="post"`** (and `post="123"`) on `[tbt_flashcards_glossary]`: the stored rows, after sets and CSVs.
- **My Dictionary column** for logged-in students when TBT Dictionary 0.3.0+ is active: one `POST /lookup` on load (split per 300 terms), **✓ In my dictionary** or a **+ My Dictionary** button that opens the Add to My Dictionary modal (translation, group with the student's groups, last group remembered per site). Adds through Dictionary's `POST /words`, then `POST /words/{id}/fill`. Logged out: no column. Below 600px the button wraps under the translation.
- **Safety net:** without wpDataTables, `[wpdatatable]` renders nothing (admins see a grey "Old wpDataTables table (id N) — not shown").
- New files: `includes/glossary-import.php`, `includes/glossary-import-format.php`, `assets/js/glossary-import.js`, `assets/css/glossary-import.css`, `tests/test-glossary-import.php`.

## 2.3.0 — 20 Nov 2026

Unified card style, shared with TBT Swipe 1.17.0 (Card style contract v1).

- **16:9 landscape card** in lessons and on the deck page (≥ 900px: up to 720px wide). Below 900px the deck page keeps its portrait layout on the blue field.
- **Table:** in a lesson, the card and its arrows sit on a rounded grey panel (`#EEF1F6`); the set title stays above it. The deck page is already the table.
- **Stack:** up to four striped card backs peek out under the live card, thinning out as you go; gone on the last card, back after "Start again". Hidden below 900px on the deck page.
- **Front:** WORD tag, colour logo, blue term, phonetics (moved here from the back, plain text, no chip), audio button, "Click or press Space to flip".
- **Back:** solid blue, no stripes (navy below 900px on the deck page). MEANING tag, white logo, white translation, a thin rule only when there is an example, the example in curly quotes, and the hint.
- **Counter is out of the card.** Only the "1 / N" pill below the card counts.
- **The `height` shortcode attribute (and `data-height` / the set's height meta) is now ignored.** It is still accepted, so old shortcodes keep rendering, at 16:9.
- New files: `assets/img/tbt-logo-colour.png`, `assets/img/tbt-logo-white.png` (copied from TBT Swipe).
