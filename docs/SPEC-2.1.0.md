# TBT Flashcards 2.1.0 — Lesson glossary, and a small API for the Lesson Builder

Two additions, both for TBT Lesson Builder (`aparatofan/tbt-lesson-builder`, next release):

1. **Lesson glossary.** Every Man and Society and Personal Development lesson ends with a glossary of its key language. Today it is a wpDataTables table built by hand from the same words as the flashcards. In 2.1.0 the glossary is a shortcode that reads the lesson's flashcard sets live: expression and Polish translation, with search, sorting and ten rows at a time. Fixing a card fixes the glossary.
2. **PHP API.** Three functions the builder calls: create a set, fill cards with AI, and turn a warm-up answer into an MP3 in a chosen ElevenLabs voice. The ElevenLabs key never leaves this plugin.

The glossary design is `docs/prototypes/glossary.html` (approved 15 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides. The prototype's category switch is prototype-only.

Nothing existing changes: `[tbt_flashcards]`, the teacher tools, the CSV contract, the TTS proxy, its voice and its cache stay exactly as they are.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Glossary columns | Expression (the card's `word`) and Polish translation (`translation`). Nothing else. |
| Glossary order | Lesson order: the sets in the order of `ids`, cards in set order. A word that appears twice (case-insensitive, trimmed) is shown once, at its first place. |
| Search | One box, both columns, as you type. Case-insensitive and accent-insensitive (`ł` matches `l`, `ś` matches `s` …). Matches are highlighted. Escape and the × clear it. |
| Sorting | Click a column header: A–Z, then Z–A, then back to lesson order. English sorts with `localeCompare(…, 'en')`, Polish with `'pl'`, both `sensitivity: 'base'`. |
| Paging | 10 rows per page. **Previous** / **Next 10** (the last step says “Next 9” when 9 are left) and a range “11–20 of 49”. **Show all 49** expands the list; then **Show 10 at a time**. With 10 rows or fewer, no paging controls. Search and sort return to page 1. |
| Colour | Column header text and the rule under it take the lesson's domain colour from `domain` (`gi`, `pd`, `le`; otherwise TBT Blue). Search, sort arrow, paging and focus are TBT Blue. |
| Who sees it | Everyone who can see the lesson. Read-only. |

## Phase 0 — read-only inventory (stop and report)

1. Confirm `TBT_FC_VERSION` is `2.0.2` on main.
2. Confirm `tbt_fc_get_cards( $post_id )` returns the cards of both editor and legacy (CSV) sets, as `{ word, phonetic, translation, example }`, and how it caches.
3. Confirm `tbt_fc_generate( array $items, $context )` returns full cards aligned to the items, its limit (`TBT_FC_MAX_GENERATE_ITEMS`, 40), and its errors.
4. Confirm how `tbt_fc_rest_create_set()` creates a set (post type, status, author, `tbt_fc_write_set_meta()`), so `tbt_fc_create_set()` can reuse exactly that.
5. Confirm how `tbt_fc_tts_proxy()` calls ElevenLabs: option `tbt_fc_elevenlabs_api_key`, `TBT_FC_ELEVENLABS_MODEL_ID`, voice settings, timeout, response handling. The new MP3 function copies the request but takes the voice as an argument and does not touch the proxy, its constants or its cache.
6. Confirm where the plugin's fonts and token copy are registered, so the glossary uses the same handles.

**Hard stop** if anything here contradicts the spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — `[tbt_flashcards_glossary]` (`includes/glossary.php`, `assets/js/glossary.js`, `assets/css/glossary.css`)

`[tbt_flashcards_glossary ids="361,362,363" domain="gi"]`

- `ids`: flashcard set IDs, comma-separated. Unknown, unpublished or non-`tbt_flashcard_set` IDs are skipped. No usable rows: admins see “Glossary: no flashcards found for these sets.”; everyone else nothing.
- The server builds the rows (via `tbt_fc_get_cards()`, de-duplicated as in the decisions) and embeds them as JSON in a `data-` attribute on the root (`esc_attr( wp_json_encode() )`). `glossary.js` renders search, sort and paging from that data. No REST call.
- Markup: a real `<table>` with `<th scope="col">` header buttons and `aria-sort`; the search `<input type="search">` has a visually hidden label; the count has `aria-live="polite"`.
- Text is set with `textContent` (highlighting builds `<mark>` nodes); card text is never inserted as HTML.
- Lesson world, as the prototype: no card around it, Roboto Slab for the rows, Roboto for controls, zebra rows, 44px search field with 12px radius, buttons 12px radius.
- Assets enqueued only where the shortcode renders; versioned with `TBT_FC_VERSION`. Prefix every class `tbt-fc-gl-` (never unprefixed `.tbt-*`).
- Several glossaries on one page work independently.

## Task 2 — PHP API (`includes/api.php`)

```php
/**
 * Create a published flashcard set owned by the current user.
 * @param string  $title   Set title.
 * @param array[] $cards   { word, phonetic, translation, example } — run through tbt_fc_sanitize_cards().
 * @param string  $context Optional AI context, as the teacher tools store it.
 * @return int|WP_Error Set ID.
 */
function tbt_fc_create_set( $title, array $cards, $context = '' );

/**
 * Fill cards with AI. Wraps tbt_fc_generate(); same limits and errors.
 * @param string[] $items   English words or expressions.
 * @param string   $context Optional context (the builder passes the lesson title).
 * @return array[]|WP_Error Cards in the same order as $items.
 */
function tbt_fc_fill_cards( array $items, $context = '' );

/**
 * One ElevenLabs MP3 for a longer text in a given voice. No caching.
 * @param string $text     Up to 2000 characters.
 * @param string $voice_id ElevenLabs voice ID ([A-Za-z0-9]{10,40}).
 * @return string|WP_Error MP3 bytes.
 */
function tbt_fc_tts_mp3( $text, $voice_id );
```

- All three require `current_user_can( 'edit_posts' )`; otherwise `WP_Error` `tbt_fc_forbidden`.
- `tbt_fc_create_set()` reuses exactly what `tbt_fc_rest_create_set()` does (same post type, status, author, meta writer). An empty card list is refused (`tbt_fc_no_cards`).
- `tbt_fc_tts_mp3()` uses the stored key and `TBT_FC_ELEVENLABS_MODEL_ID` with the proxy's voice settings and language code; the voice is the argument. Missing key: `tbt_fc_missing_api_key`. Non-200 or non-audio response: `tbt_fc_tts_failed` with the HTTP status in the data, never the key.
- The functions live in this plugin so the key and models stay here; callers check `function_exists()`.

## Task 3 — Tests

Extend `tests/` (pure PHP, no WordPress) for the glossary row builder, factored as a pure function `tbt_fc_glossary_rows( array $sets_of_cards )`: order kept, duplicates dropped case-insensitively at first place, empty words skipped, empty translation kept as an empty string. `php tbt-flashcards/tests/test-csv.php` must still pass.

## Task 4 — Version

`TBT_FC_VERSION` and the header → `2.1.0`.

## Validation

```bash
for f in tbt-flashcards/tbt-flashcards.php tbt-flashcards/includes/*.php; do php -l "$f"; done
node --check tbt-flashcards/assets/js/glossary.js
php tbt-flashcards/tests/test-csv.php
php tbt-flashcards/tests/test-glossary.php
```

## Acceptance (live)

- [ ] On a draft post, `[tbt_flashcards_glossary ids="<the Stress lesson's 7 sets>" domain="gi"]` shows 10 rows in lesson order, “49 expressions”, and paging 1–10 of 49.
- [ ] **Next 10** steps through to “41–49 of 49”, where the button is disabled; **Show all 49** lists everything; **Show 10 at a time** goes back.
- [ ] Typing “stres” finds both columns' matches and highlights them; Escape clears.
- [ ] Clicking Polish translation sorts A–Z (Polish order), again Z–A, a third time back to lesson order.
- [ ] `domain="pd"` turns the header green.
- [ ] Editing a card in one of the sets changes the glossary after reload.
- [ ] Existing lessons' `[tbt_flashcards url="…"]` and `[tbt_flashcards id="…"]` play exactly as before; card audio still works.
- [ ] Hard refresh shows `?ver=2.1.0`.
