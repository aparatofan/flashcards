# TBT Flashcards 2.4.2 — Paste words: multi-line Quizlet cards and AI Polish translations

The first real paste (25 Nov 2026) showed two problems with 2.4.1's **Paste words** tab:

1. **Multi-line cards.** Some Quizlet sets have a definition **and** an example in the definition field, on separate lines. Quizlet's export keeps that line break, so one card looks like this:

   ```
   Coziness	Definition: A feeling of warmth, comfort, and safety, often associated with a pleasant atmosphere.
   Example: The candlelight and soft blankets created a sense of coziness in the room.
   Content	Definition: Feeling satisfied and happy with what you have.
   Example: After dinner with her family, she felt calm and content.
   ```

   2.4.1 reads the `Example:` line as a new word with no translation, so the glossary comes out wrong.
2. **No Polish.** That set has **English definitions, not Polish translations**. A TBT glossary is always *English expression | Polish translation*. Mariusz doesn't want definitions or examples in it, only the translation.

2.4.2 fixes the parser so that a line without a tab belongs to the card above it. It also adds a **Translate to Polish (AI)** button to each paste box. The button turns whatever was pasted (terms only, terms with English definitions, or anything else) into `term ⇥ Polish translation` lines in the same box, for Mariusz to check and edit before he presses **Save**. Saving is unchanged.

Repo: `aparatofan/flashcards`, from `main` at **2.4.1**.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Continuation lines | In **tab** mode, a line with **no tab** that follows a card is part of that card's definition, joined with a space. It is never a new card. A line with no tab **before** the first card is ignored. Other separator modes (` - `, `;`, `,`) are unchanged. |
| Labels | In a definition, the leading labels `Definition:` / `Definicja:` are removed, case-insensitive. Text from `Example:` / `Przykład:` (the label included) to the end of the definition is moved to a separate `example` field, used only as context for the AI and **never stored**. |
| What gets stored | Always and only `[term, translation]`, as in 2.4.0. |
| AI button | **TRANSLATE TO POLISH (AI)**, a ghost button beside **SAVE**, on every paste row. It works on the box's current text. It sends the parsed cards (term + definition + example) to the server. The server returns one Polish translation per term, and the box is **replaced** with `term⇥translation` lines in the same order. The status line reads "45 words translated by AI. Check them, then press Save." Nothing is saved until **Save**. |
| Model and key | Flashcards' own OpenAI settings (`tbt_fc_openai_api_key()`, `tbt_fc_openai_model()`). No new settings. |
| Translation style | As Flashcards' card generator: natural Polish, the item translated as a whole (not word by word), one or two equivalents separated by ", ". The **sense** follows the English definition when one is given (e.g. *content* = "zadowolony", not "treść"). Terms are returned exactly as given. Plain text only. |
| Fail open | No key, a timeout or a bad response → a red message under the box, and the text in the box stays as it was. A term the model drops keeps an empty translation (`term⇥`), so it's visible and can be filled in by hand. |
| Limits | Up to 300 cards per click, sent to the model in batches of 40 (or the batch size `tbt_fc_generate()` already uses, if smaller). |

## Phase 0 — read-only inventory (stop and report)

1. Confirm `main` is at 2.4.1 and list any open PRs.
2. Show `parse_pasted()` and the JS preview parser in `glossary-import.js` (file and line). These are the two places the continuation rule must be added, and both must give the same result.
3. Show `tbt_fc_generate()` and `tbt_fc_openai_developer_prompt()` (file and line). Report:
   - its batch limit;
   - whether its `context` argument can carry per-item definitions, or whether a small translation-only function is cleaner. The model must see each term's definition so it picks the right sense, and only `translation` is needed.

   Propose one of the two.
4. **Report and STOP.** **Hard stop** if a change to `tbt_fc_generate()` would alter the cards Flashcards generates today. Translation-only work must not change the existing generator's output.

## Task 1 — Parser (`glossary-import-format.php` and `glossary-import.js`)

- `parse_pasted()` now returns cards as `{term, definition, example}`, following the continuation and label rules. A thin `pairs()` helper gives `[term, translation-or-definition]` for the existing paths. When a card has a definition and no other Polish text, its "translation" for a **plain Save** is the definition with the example removed. Save still works without AI, as before.
- The JS preview uses the same rules. Its count line also says what it saw: "45 words read · tab-separated · English definitions found: consider Translate to Polish". The last part shows when more than half of the definitions look English, using a simple heuristic: no Polish diacritics (ąćęłńóśźż), and either the word `Definition:` or more than 6 words.

## Task 2 — Translation (`openai.php` or a new `glossary-translate.php`, per the Phase 0 proposal)

`tbt_fc_translate_terms( array $cards, string $lesson_title ): array|WP_Error`
- **Input:** `{term, definition, example}` cards.
- **Output:** one Polish translation per card, in input order. Same-index alignment, with empties for dropped items, as `tbt_fc_align_generated()` does.
- **Prompt:**
  - The lesson title is context.
  - Each item is numbered and sent as `term — definition` (the example only when there's no definition).
  - Rules are as in Decisions → Translation style.
  - Strict JSON schema `{translations: [{index, translation}]}`.
- `reasoning`/`temperature` as the existing generator. Same error handling and messages (`tbt_fc_openai_api_error()`).

REST: `POST /tbt-flashcards/v1/glossary-import/translate` `{post_id, text}`. Uses `manage_options` and the `wp_rest` nonce.
1. Run `parse_pasted()`. Zero cards → 400 "No words found."
2. Run `tbt_fc_translate_terms()`, in batches.
3. Return `{text: "term\ttranslation\n…", count, missing}`.

It **never stores anything**.

## Task 3 — Screen (`glossary-import.js`, `glossary-import.css`)

- The **TRANSLATE TO POLISH (AI)** button sits beside **SAVE**. While it works, it shows "Translating… (up to a minute)" and both buttons are disabled.
- On success, the box gets the returned text, the status line from Decisions shows, the box scrolls to the top, and **Save** becomes the primary action.
- `missing` > 0 → the extra line "N words came back without a translation; they are at the end of their line, after the tab. Fill them in or delete the line."
- Nothing else on the tab changes.

## Task 4 — Version, docs, tests

- `TBT_FC_VERSION` and the header → `2.4.2`. CHANGELOG. README: multi-line Quizlet cards; the AI button.
- `tests/test-glossary-import.php`:
  - The four-line *Coziness / Content* sample from this spec gives **2** cards, with the definitions without `Definition:` and the examples split off.
  - A plain two-column paste is unchanged (2.4.1's tests still pass).
  - A paste that starts with a stray line.
  - `Definicja:` / `Przykład:` labels.
  - The "English definitions found" heuristic: one English sample and one Polish sample.
- The AI call isn't unit-tested. Test only the prompt builder and the alignment of returned translations: dropped items become empty strings.

## Validation

```bash
for f in tbt-flashcards.php includes/*.php; do php -l "$f"; done
node --check assets/js/glossary-import.js
php tests/test-glossary.php && php tests/test-glossary-import.php
```

## Acceptance (live)

- [ ] The lesson pasted wrongly earlier: in the imported list, **Undo** it. It's back in **Paste words**.
- [ ] Paste the same Quizlet export (*Coziness … Adversity*). The count says **45 words read**, with the "English definitions found" hint. No `Example:` lines count as words.
- [ ] **Translate to Polish (AI)**: within about a minute, the box shows 45 lines like `Coziness⇥przytulność`, `Content⇥zadowolony`, `Bask in something⇥rozkoszować się czymś`. Read them, fix any by hand, then **Save** → "✓ Saved · 45 words".
- [ ] The lesson's glossary shows English and Polish only: no definitions, no examples.
- [ ] A plain `term⇥Polish` export (e.g. *Why we sleep*) still saves directly, without AI.
- [ ] Typing a few English words, one per line, then **Translate to Polish (AI)** gives `word⇥tłumaczenie` lines. This is how glossaries can be rebuilt for lessons with no Quizlet set.
- [ ] Flashcards' card generator (Swipe-style teacher tool / Lesson Builder **Fill with AI**) works exactly as before.
- [ ] PHP error log and console: nothing new from `tbt-flashcards`.

## Out of scope

- Storing definitions or examples in glossaries.
- Translating already-imported glossaries.
- Reading Quizlet or Multidecker automatically.

## Phase 0 outcome (decided)

- New `includes/glossary-translate.php` with a translation-only prompt and schema, `tbt_fc_align_translations()` and `tbt_fc_translate_terms()`. `tbt_fc_generate()`'s `context` is one string per batch and its items are echoed back as `word`, so it can't carry per-item definitions cleanly.
- The OpenAI request code moved unchanged into `tbt_fc_openai_json_request()`, called by both. `tbt_fc_generate()`'s request and result stay identical. Neither call sets `reasoning` or `temperature`.
- Batch size 40 (`TBT_FC_MAX_GENERATE_ITEMS`).
- Chunked route instead of one long request (8 × 90 s would exceed PHP/LiteSpeed timeouts): `POST glossary-import/translate {post_id, text, from}` translates 40 cards per call and returns `{text, count, missing, total, next}`. The browser repeats until `next` is null, showing "Translating 40 of 45…", then fills the box. If a chunk fails, it stops, leaves the box as it was and shows the error.
