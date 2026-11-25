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

When wpDataTables is deactivated, any `[wpdatatable]` left renders nothing for visitors and a grey note for administrators.
