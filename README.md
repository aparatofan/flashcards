# TBT Flashcards

WordPress plugin for The Blue Tree English lessons. The deployable plugin is in `tbt-flashcards/`; see `CHANGELOG.md` for releases and `CLAUDE.md` for contributor rules.

## Lesson glossary

```
[tbt_flashcards_glossary ids="361,362" urls="https://…/uploads/…/words.csv" source="post" domain="gi"]
```

| Attribute | Meaning |
|---|---|
| `ids` | Flashcard sets (published), in order. |
| `urls` | CSV files in this site's uploads (read from disk, never over HTTP). |
| `source="post"` | The rows stored on the lesson (`_tbt_fc_glossary`), written by **Import glossaries**. |
| `post="123"` | With `source="post"`: read another post's stored rows instead of the current one's. |
| `domain` | Header colour: `le`, `gi` or `pd`. |

Rows come from sets first, then CSV files, then stored rows; a repeated expression (case-insensitive) keeps its first place. The glossary has search, sort, 10 rows at a time and **Show all**.

**My Dictionary column.** For logged-in users, when TBT Dictionary 0.3.0 or later is active, each row shows **+ My Dictionary** (or **✓ In my dictionary**). The button opens a small dialog to edit the translation and pick or type a group; the word is added through Dictionary's own REST API, which also awards its point. Logged-out visitors see the two-column glossary.

## Import glossaries (2.4.0, one-off)

**Flashcards → Import glossaries** (administrators; under TBT Hub when it is active). It lists every lesson (`post`) that still uses a wpDataTables glossary.

1. **Read all** reads each table once from its source (Google Sheet, `.xlsx`/`.csv` in uploads, or a wpDataTables table) and shows a preview. Failures say why (sheet not shared, file not found, old `.xls`, table missing, inside a Divi global module…). Reads are cached for an hour; **Rescan** forgets them.
2. **Import selected** stores the rows on each ticked lesson and replaces `[wpdatatable id=N]` with `[tbt_flashcards_glossary source="post" domain="…"]`. Nothing else in the lesson changes. Purge the LiteSpeed cache afterwards.
3. **Undo** (per lesson) or **Undo all** brings back the lesson's content from before its first import and deletes the stored rows. Edits made to the lesson after the import are lost by Undo.
4. **Copy failed list** copies "Lesson — edit link — table ID — note" lines, to fix those lessons by hand (e.g. export the sheet as CSV and use `urls="…"`).

### Paste words (2.4.1)

For lessons whose old glossary can't be read (deleted or private Google Sheets, a missing table), the **Paste words** tab lists every lesson that still shows a wpDataTables glossary, A–Z, with a search box and a count badge. It is built from the posts on every page load (no cache), so it is right even straight after purging LiteSpeed.

- Each row links the lesson's Quizlet sets (**Open set 1**, **Open set 2**…, from `quizlet.com/…` URLs in its content) and Multidecker lists (**Open Multidecker 1**…, from `multidecker.com/list/…`). Only `post_content` is searched, not Divi global modules.
- In Quizlet choose **⋯ → Export**, copy the text and paste it into the lesson's box. One card per line; the separator is a tab when any line has one, otherwise ` - ` / ` – `, `;` or `,` (whichever most lines use, split at the first one). Several exports can go into one box. The line under the box ("34 words read · tab-separated") is a preview; the server parses again.
- **Save** stores the words exactly like an import (`_tbt_fc_glossary`, `_tbt_fc_glossary_src` with `type: "paste"`, `source: "quizlet:{ids}"` or `"paste"` and the replaced `table_id`, backup, shortcode swap). The lesson then shows in the imported list, where **Undo** works as usual. Limits: 2 000 lines per paste; terms are cut at 120 characters, translations at 190.
- **Multi-line Quizlet cards (2.4.2).** When a set's definition field holds a definition and an example on separate lines, Quizlet's export keeps the line break. In tab mode, a line without a tab belongs to the card above it (a stray line before the first card is ignored). `Definition:` / `Definicja:` labels are removed, and `Example:` / `Przykład:` with what follows is split off: it is only context for the AI and never stored. A plain **Save** stores the term and the definition without the example.
- **Translate to Polish (AI) (2.4.2).** A button beside **Save** on every row. It turns whatever is in the box (terms only, terms with English definitions, anything else) into `term⇥Polish translation` lines in the same box, using Flashcards' own OpenAI key and model, 40 cards per request (up to 300). The definition picks the sense (*content* = "zadowolony", not "treść"). Nothing is stored until **Save**; check and edit the lines first. A term the model drops comes back as `term⇥` with an empty translation. When more than half of the definitions look English, the count line says "English definitions found: consider Translate to Polish" and the AI button becomes the primary one.
- REST: `POST /tbt-flashcards/v1/glossary-import/paste {post_id, text}` (administrators, `wp_rest` nonce) → `{rows, imported: true, lesson}`; 409 when the lesson has no old glossary left, 400 when no words were found. `POST …/glossary-import/translate {post_id, text, from}` → `{text, count, missing, total, next}` translates 40 cards from `from`; the screen repeats it until `next` is null. It never stores anything.

When wpDataTables is deactivated, any `[wpdatatable]` left renders nothing for visitors and a grey note for administrators.
