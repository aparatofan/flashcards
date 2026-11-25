# Changelog — TBT Flashcards

## 2.4.2 — 25 Nov 2026

Paste words: multi-line Quizlet cards and AI Polish translations. Spec: `docs/SPEC-2.4.2.md`.

- **Multi-line cards.** In tab mode, a line without a tab is part of the card above it (joined with a space), never a new word; before the first card it is ignored. `Definition:` / `Definicja:` labels are removed; `Example:` / `Przykład:` and what follows is split off as context and never stored. Other separator modes are unchanged. `tbt_fc_parse_pasted()` now returns cards `{term, definition, example}`; `tbt_fc_paste_pairs()` gives the `[term, definition]` pairs a plain Save stores, as before.
- **English definitions hint.** The count line adds "English definitions found: consider Translate to Polish" when more than half of the definitions have no Polish diacritics and either a `Definition:` label or more than 6 words (`tbt_fc_paste_english()`, mirrored in JS).
- **Translate to Polish (AI)** beside **Save** on every row. `POST glossary-import/translate {post_id, text, from}` translates 40 cards per call (at most 300), and the screen repeats it ("Translating 40 of 45…") and then replaces the box with `term⇥translation` lines: "45 words translated by AI. Check them, then press Save." Dropped terms come back as `term⇥`, with a note. If a call fails, the box stays as it was and the error shows in red. Nothing is stored until **Save**.
- New `includes/glossary-translate.php`: a translation-only prompt (the lesson title as context; `term — definition`, or the example when there is no definition) and schema `{translations: [{index, translation}]}`, `tbt_fc_align_translations()` and `tbt_fc_translate_terms()`.
- The OpenAI request code moved unchanged from `tbt_fc_generate()` into `tbt_fc_openai_json_request()`, shared with the translation. The card generator's request and result are identical.

## 2.4.1 — 25 Nov 2026

Paste a glossary from a Quizlet export. Spec: `docs/SPEC-2.4.1.md`.

- **Paste words** tab on Flashcards → Import glossaries, with a count badge: every lesson still showing a wpDataTables glossary (A–Z, search by title), computed from the posts on page load, with no transients. Each row links its Quizlet sets (**Open set 1**…) and Multidecker lists (**Open Multidecker 1**…) found in `post_content`, and has a box for the export with a live "N words read · tab-separated" preview. **Save** turns the row green ("✓ Saved · N words"), then collapses it. On an error, the server's message shows in red and the text stays.
- `POST glossary-import/paste {post_id, text}`: parse (tab, else ` - `/` – `, `;` or `,`) → header rule → `clean_rows()` → cut to 120/190 characters; at most 2 000 lines. 409 "This lesson has no old glossary left.", 400 "No words found…". Lessons whose table sits in a Divi global module are refused, as in the import.
- The import's save step is now `tbt_fc_wdt_store_rows()`, shared by the import and Paste words (moved out of `tbt_fc_wdt_import_post()` unchanged). A pasted lesson's `_tbt_fc_glossary_src` is `{table_id, type: "paste", source: "quizlet:{ids}"|"paste", rows, imported_at}`, so it joins the imported list and Undo works as for any import.
- Undo results also carry the lesson's Quizlet/Multidecker links, so an undone lesson reappears in Paste words.
- New pure helpers in `glossary-import-format.php`: `tbt_fc_quizlet_ids()`, `tbt_fc_multidecker_slugs()`, `tbt_fc_parse_pasted()` (with `tbt_fc_paste_lines()`, `tbt_fc_paste_separator()`, `tbt_fc_paste_limit()`), with tests.

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
