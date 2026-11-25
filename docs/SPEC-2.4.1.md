# TBT Flashcards 2.4.1 — paste a glossary from a Quizlet export

**Import glossaries** (2.4.0) moved 113 lessons. The other **214 can't be read automatically**:

- **185** Google Sheets were **deleted**. Google returns HTTP 410, and the sheet page says "the requested file has been deleted". These lessons already show an empty wpDataTables glossary to students.
- **28** sheets are private (HTTP 401).
- **1** wpDataTables data table no longer exists (*Systemic Racism Explained*).

**183** of these lessons embed a **Quizlet** set holding the same words, and Mariusz owns those sets. Quizlet's **⋯ → Export** gives plain text, one card per line, with a **tab** between the term and the translation. For example:

```
motionless	nieruchomy
transition	przejście, przemiana
pull an all-nighter	zarwać nockę
```

2.4.1 adds a **Paste words** tab to the import screen. It lists every lesson still showing a wpDataTables glossary, each with a link to its Quizlet set(s) and a box for the export. On **Save**, the words are stored in the lesson exactly as an automatic import would store them, with the same meta, shortcode swap, backup and Undo.

Repo: `aparatofan/flashcards`, from `main` at **2.4.0**.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Which lessons | `post` items (not trash) whose `post_content` still contains a `[wpdatatable` glossary (2.4.0's never-list excluded). Computed on page load from the posts, so there's **no Read all** and nothing to fetch. Sorted A–Z by title. |
| Quizlet link | Every Quizlet set ID in the post's content, from `quizlet.com/(xx/)?(\d{5,})` (embeds like `/123456789/flashcards/embed`, `/pl/123456789/slug/`, set pages). Each is shown as **Open set 1**, **Open set 2**…, linking to `https://quizlet.com/{id}/` in a new tab. With no set: "No Quizlet set in this lesson". The box still works, so words can come from anywhere. |
| Paste format | One card per line. The separator is a **tab** if any line has one. Otherwise ` - ` / ` – `, then `;`, then `,`, whichever the **most** lines contain, splitting at the **first** occurrence. A line without a separator has an empty translation. Several exports can be pasted one after another into the same box, for example one per video part. |
| Cleaning | 2.4.0's `clean_rows()`: trim, collapse spaces, decode entities, drop empty terms, drop duplicate terms (case-insensitive, first wins). A header line is dropped by 2.4.0's header rule. |
| Storing | Exactly 2.4.0's import step for that lesson:<br>• backup into `_tbt_fc_wdt_backup` (written once);<br>• `_tbt_fc_glossary`;<br>• `_tbt_fc_glossary_src` = `{type: "paste", source: "quizlet:{ids}" or "paste", rows, imported_at}`;<br>• the shortcode swap with `domain`;<br>• `$wpdb->update`, `clean_post_cache()`, and the same `_et_pb_old_content` handling. |
| Undo | The lesson moves to 2.4.0's imported list, and Undo works there as for any imported lesson. |
| Limits | 2 000 lines per paste; 120 characters per term, 190 per translation (cut, not refused). |

## Phase 0 — read-only inventory (stop and report)

1. Confirm `main` is at 2.4.0 and list any open PRs.
2. Show the 2.4.0 import step: the function that stores rows for one lesson (backup, meta, swap, save). It should be reused, not copied. If it isn't one function, propose the extraction.
3. Show how the import screen finds lessons today, and whether that list depends on transients. (Mariusz saw the Failed list empty after purging LiteSpeed's cache. The new tab must not depend on transients.)
4. In the post content of these two lessons, report every Quizlet URL found, as raw strings: *Why we sleep* (ID 9227) and *10 Happy Words Around the World* (ID 31102).
5. **Report and STOP.** **Hard stop** if the store step can't be shared without changing 2.4.0's behaviour.

## Task 1 — Pure helpers (`glossary-import-format.php`)

- `quizlet_ids( string $content ): string[]`: unique set IDs in order.
- `parse_pasted( string $text ): array[]`: follows the Paste format rules. It returns the raw `[term, translation]` pairs; `clean_rows()` runs after it.

## Task 2 — REST

`POST /tbt-flashcards/v1/glossary-import/paste` `{post_id, text}`. Uses `manage_options` and the `wp_rest` nonce.
1. The post must be a `post` that still contains a glossary `[wpdatatable`; otherwise 409 "This lesson has no old glossary left."
2. Run `parse_pasted()` → `clean_rows()`. Zero rows → 400 "No words found. Paste the Quizlet export: one word per line, word and translation separated by a tab."
3. Store (Decisions). Return `{rows, imported: true}`.

## Task 3 — The **Paste words** tab

A new tab on **Flashcards → Import glossaries**, next to the 2.4.0 list. Its title carries a count badge for the lessons left. Plain wp-admin styling.

- Top line: "**214** lessons still use an old wpDataTables glossary. Open the Quizlet set, choose **⋯ → Export**, copy the text, paste it here and press **Save**."
- A search box that filters by title.
- One row per lesson:

| Lesson | Quizlet | Words | |
|---|---|---|---|
| title (status) + **View** / **Edit** links | **Open set 1**, **Open set 2** … or "No Quizlet set in this lesson" | `<textarea rows="4">` with placeholder "Paste the Quizlet export here", plus a live line "34 words read · tab-separated" (it runs the same parse rules in JS, for the preview only) | **SAVE** |

- **Save:** the button is disabled while saving.
  - On success, the row turns green, "✓ Saved · 34 words", then it collapses, and the badge and top count go down by one.
  - On error, the server's message shows in red under the box, and the text stays.
- The server is the authority. The JS count is only a preview.
- When no lessons are left: "Every lesson's glossary is now stored in the lesson. You can remove wpDataTables once the non-lesson pages are gone."

## Task 4 — Version, docs, tests

- `TBT_FC_VERSION` and the header → `2.4.1`. CHANGELOG. README: the Paste words tab.
- `tests/test-glossary-import.php`:
  - `quizlet_ids`: an embed iframe, a `/pl/…/slug/` link, two sets, none.
  - `parse_pasted`: tab lines (the *Why we sleep* sample from this spec's intro); ` - ` lines; `;` lines; mixed blank lines; a line without a separator; two exports pasted together with a repeated word.

## Validation

```bash
for f in tbt-flashcards.php includes/*.php; do php -l "$f"; done
node --check assets/js/glossary-import.js
php tests/test-glossary.php && php tests/test-glossary-import.php
```

## Acceptance (live)

- [ ] **Paste words** shows 214 lessons (fewer if some were fixed since), even straight after purging LiteSpeed.
- [ ] *Why we sleep* shows **Open set 1**, which opens its Quizlet set. Paste its export: "34 words read", then **Save** → "✓ Saved · 34 words".
- [ ] The lesson's glossary now shows those 34 words with search, sorting, 10 at a time, and **+ My Dictionary**.
- [ ] The lesson appears in the imported list, and **Undo** brings back the old (empty) wpDataTables glossary. Paste again afterwards.
- [ ] Pasting nonsense with no lines → the red message, nothing saved.
- [ ] PHP error log and console: nothing new from `tbt-flashcards`.

## Out of scope

- Reading Quizlet automatically: there's no public API and automatic reading is blocked. Mariusz pastes the export himself, or Claude in Chrome does it for him in his logged-in browser.
- The private Google Sheets (401). If Mariusz can open them, they can be exported to CSV and pasted the same way.

## Phase 0 outcome (decided)

- The save step is extracted unchanged into `tbt_fc_wdt_store_rows()`; the paste source also stores `table_id` = the first replaced table, so it joins 2.4.0's imported list.
- *Why we sleep* (9227) has no Quizlet set in `post_content`; it embeds four Multidecker lists. Multidecker lists (`multidecker.com/list/{slug}/…`) are linked too, as **Open Multidecker 1**, **Open Multidecker 2**… (to `https://multidecker.com/list/{slug}/`), after the Quizlet links.
- Acceptance: lesson 31102 (*10 Happy Words Around the World*, Quizlet sets 868199351 and 868199852) for Quizlet; 9227 for Multidecker links + paste.
- `post_content` only: Divi global modules are not read.
