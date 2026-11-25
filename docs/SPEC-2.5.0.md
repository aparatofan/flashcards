# TBT Flashcards 2.5.0 — Card style v2: white table, light blue front, clear Back / Next

**Repo:** `aparatofan/flashcards`, from `main` at **2.4.3**. **Version:** `TBT_FC_VERSION = '2.5.0'` (plugin header too).

**No database, meta, CSV, REST or audio changes.**

**Files touched:**
- `assets/tbt-flashcards.css`
- `assets/tbt-flashcards.js` (only the long-text fit)
- `tbt-flashcards.php` (the nav button markup and the version)
- `CHANGELOG.md`
- `CLAUDE.md` (the "Current release" line)
- this spec as `docs/SPEC-2.5.0.md`

**Approved design:** the claude.ai canvas "TBT card style v2 — white table, light blue front" (Mariusz, 25 Nov 2026). Where this spec and the canvas differ, **this spec wins**.

**Sister spec:** TBT Swipe 1.18.0. The Card style contract v2 below is identical in both. **Deploy and confirm Flashcards 2.5.0 first**, then Swipe.

---

## Why

Three problems with the current style:
- The grey panel stops just short of the card's edges, so in a lesson the player looks like a third-party widget.
- The phone deck page brings in a blue field and a navy back that aren't used anywhere else.
- Learners confuse Flashcards (click through) with Swipe (sort by swiping). The small grey arrow circles don't say "press me to go on".

v2 fixes all three:
- The card sits on plain white.
- The front is a very light blue and the back is TBT blue everywhere.
- Back / Next become labelled pills, with Next in solid TBT blue.

## Locked decisions (Mariusz, 25 Nov 2026)

1. **White** behind the card everywhere: in lessons, on the desktop deck page and on the phone deck page.
2. Front face **really light blue** (`#EEF4FE`); back face **TBT blue** (no navy).
3. **Next** = solid TBT blue; **Back** = light blue. Both are labelled.
4. Phone: card **8% smaller**, **stacked cards shown**, **smaller back text**. Long text must never fill or overflow the card.
5. Swipe gets the same card (sister spec).

---

## Card style contract v2 (identical in the Swipe and Flashcards specs)

v2 changes only colours, the phone size, the phone stack, long-text fitting and the Flashcards buttons. Everything in v1 not mentioned here stays as shipped: the 16:9 desktop card, `cqw` sizing, fonts, tags, logo boxes, the flip, the stack transforms, and the counter living only in the pill.

**Surfaces**

| What | v1 | **v2** |
|---|---|---|
| Table / page behind the card (desktop, lesson, deck page, phone) | `#EEF1F6` desktop; `#0856C9` blue field below 900px | **`#FFFFFF` at every width.** The grey lesson panel and the blue phone field are gone. |
| Front face | `#FFFFFF` | **`#EEF4FE`**, `border: 1px solid #D7E4F8`, `box-shadow: 0 12px 32px rgba(8, 86, 201, 0.14)` |
| Back face | `150deg #0856C9 → #063F94` on the table; navy `160deg #0B2F6E → #061E4A` below 900px | **`linear-gradient(150deg, #0856C9, #063F94)` at every width.** The navy is gone. Shadow `0 12px 32px rgba(8, 86, 201, 0.22)`. |
| Stack backs | as shipped | unchanged, and now **shown on phones too** (see Phone) |

**Front face text** (colours re-tuned so they still pass 4.5:1 on light blue)

| Element | v2 colour |
|---|---|
| Tag (WORD / MEANING) | `#4F6FA6` |
| Lead line (term) | `#0856C9` (unchanged) |
| IPA | `#3E5A85` |
| Hint ("Click or press Space to flip" / "Tap to flip") | `#557099` |
| Audio button (Flashcards) | background `#FFFFFF`, `box-shadow: 0 2px 10px rgba(8, 86, 201, 0.12)`, icon `#0856C9`; hover: `box-shadow: 0 4px 14px rgba(8, 86, 201, 0.22)` and `scale(1.06)` |

The back face's text colours stay as v1 (white; tag, IPA and hint in white at 60–80%).

**Around the card, on white**

| Element | v2 |
|---|---|
| Counter pill | background `#EEF4FE`, text `#3E5A85` (Roboto Mono, as v1) |
| Swipe, phones only: zone labels (KNOW IT / NOT YET), arrows and the "1 / N" progress | `#5C7DB5` at rest; the active zone `#0856C9`; dim stays as an opacity. (Desktop keeps its shipped grey → blue labels and pill.) |
| Focus outlines | `#0856C9` |
| Loading / error message text | `#888780` |
| Set title / small brand text (Flashcards deck page) | `#888780`, as the desktop deck page today |

**Phone (below 900px, full-screen deck pages)**
- The card is **8% smaller** than today in both width and height, centred in the space it has now. (Mariusz asked for 5%, then another 3%: `0.95 × 0.97 ≈ 0.92`.)
- The **stack shows**, with the same transforms as on desktop (`translate(2d px, 3d px) rotate(±0.5d deg)`, max 4). Leave enough room under and to the right of the card for it (≥ 14px).
- **Back face text is smaller**, so a longer expression or example doesn't fill the card:
  - answer line (translation, or the term on a translation-first deck): about **18% smaller** than today;
  - example: about **20% smaller** than today;
  - IPA and tag: unchanged.

  Exact values are in each plugin's tasks.

**Long text never overflows (new, both apps, all widths)**
- After a card renders, on resize, and once web fonts have loaded (`document.fonts.ready`), each face is checked. If its content is taller than the face's content box, the face's font sizes step down together, **5% at a time, to no less than 70%**, until it fits.
- Implementation: a CSS custom property `--fit` (default `1`) on the face. Every font size on a face is multiplied by it: `calc(<v2 size> * var(--fit, 1))`. JS only ever sets `--fit`, never individual sizes.
- Measure the content, not the face: faces centre their content and use `overflow: hidden`, so `scrollHeight` misses what spills over the top. Measure the content's own height, the sum of the children and gaps, against the face's inner height minus the room the tag and hint take.
- Short cards are never enlarged: `--fit` never goes above `1`.
- Reset `--fit` to `1` before measuring a new card.

**Flashcards buttons (Flashcards only; Swipe has no buttons)**

| | Back (previous) | Next |
|---|---|---|
| Shape | pill, height 56px (lesson and desktop deck), 48px (phone deck), radius 999px | same |
| Content | chevron-left icon + **BACK** | **NEXT** + chevron-right icon |
| Type | Roboto 600, 15px (14px phone), `letter-spacing: .06em`, uppercase | same |
| Colours | background `#DCE8FB`, text and icon `#0856C9`, no border, no shadow | background `#0856C9`, text and icon `#FFFFFF`, `box-shadow: 0 6px 16px rgba(8, 86, 201, 0.3)` |
| Hover | background `#CFE0FA` | background `#063F94` |
| Disabled | `opacity: .4`, no hover | same |
| Focus | `outline: 2px solid #0856C9; outline-offset: 3px` | same, but with the outline `#063F94` |

The pill counter stays between them.

---

## Phase 0 — read-only inventory, then STOP and report

0.1 `assets/tbt-flashcards.css`: list every rule that sets one of the following, with line numbers:
- the table/page background: `#eef1f6`, `#0856c9`, `body.tbtfc-deck-page`;
- the front face (`.fc-card-front`) and the back face (`.fc-card-back`, including the navy one in the `max-width: 899px` block);
- the stack (`.fc-stack*`, including `display: none` on phones);
- the nav (`.fc-nav*`, `.fc-progress`);
- the audio button;
- every face font size (`.fc-word`, `.fc-phonetic`, `.fc-translation`, `.fc-example`, `.fc-hint`, tags, finish card), base and phone.

0.2 `tbt-flashcards.php` around line 610: the nav markup. Confirm it is the only place that prints `.fc-nav`, for both the lesson player and the deck page (`templates/single-deck.php`).

0.3 `assets/tbt-flashcards.js`: where a card is rendered (`showCard` / `nextCard` / `prevCard` / `flipCard` / the finish card around line 329) and `renderStack()`. Report:
- the best single place to call a `fitFaces()` after each render;
- whether a resize handler exists already.

0.4 Report whether `renderStack()` runs on phones, or only gets hidden by CSS.

0.5 **Report and STOP.** **Hard stop** in either case:
- anything outside the player (glossary, teacher pages, the editor) uses `.fc-card-face` / `.fc-nav-btn` styles that this spec would change;
- the nav markup is printed in more than one place.

---

## Tasks

### T1 — White table
1. Lesson panel `.fc-table`:
   - `background: transparent`;
   - padding `0 16px 20px 0`, which is room for the stack and nothing more;
   - keep `.fc-table .fc-nav { margin-top: 30px }`.
2. Deck page: `body.tbtfc-deck-page` and `.tbt-flashcard-app.is-deck` get `#FFFFFF` at every width. Remove the `#0856c9` backgrounds from the `max-width: 899px` block.
3. Phone deck page: delete the overrides that only existed for the blue field, so the desktop colours apply:
   - the white set title / brand;
   - the translucent white `.fc-nav-btn` and its hover;
   - the white `.fc-progress`;
   - the white `.fc-deck-bar` / `.fc-deck-bar-fill`.

### T2 — Faces (contract v2)
1. `.fc-card-front`:
   - `background: #EEF4FE`;
   - `border: 1px solid #D7E4F8`;
   - shadow per the contract;
   - text colours per the contract (tag, IPA, hint).
2. `.fc-card-back`: the TBT blue gradient at every width. Delete the navy override and the phone face shadow override, so the contract shadows apply.
3. Audio button: white circle per the contract (it was `rgba(8,86,201,.08)`, which is too faint on light blue).
4. The finish card: report in Phase 0 which face it uses. On the light blue front its blue / grey text needs no change. On any other surface, give it the front's colours.

### T3 — Phone deck page (below 900px)
1. `--deck-card-w: calc(min(84vw, calc((100dvh - 190px) * 3 / 4.1)) * 0.92)`. The aspect ratio `3 / 4.1` is unchanged.
2. Show the stack: delete `.is-deck .fc-stack { display: none; }`. If Phase 0 shows that `renderStack()` is skipped on phones, enable it.
3. Back text, phone only:
   - `.fc-translation` goes from `8.8cqw` to **`7.2cqw`**;
   - `.fc-example` goes from `max(14px, 5.2cqw)` to **`max(12px, 4.2cqw)`**.
4. Nav pills 48px high, 14px text (contract).

### T4 — Long-text fit (contract)
1. CSS: every face font size (base, deck and phone rules; word, phonetic, translation, example, finish message) becomes `calc(<size> * var(--fit, 1))`. For `max(Apx, Bcqw)`, write `calc(max(Apx, Bcqw) * var(--fit, 1))`. Tags and hints don't change.
2. JS `fitFaces(cardEl)`, per face:
   - set `--fit: 1`;
   - while the content is taller than the room and `fit > 0.7`, take 0.05 off `fit`.
3. Call it on both faces after every render (next, previous, start again), on `resize` (debounced, about 100ms), and once on `document.fonts.ready`.
4. Inline lessons and the deck page both use it.

### T5 — Back / Next pills
1. Markup (`tbt-flashcards.php`), keeping the classes, `title` and `disabled`:
   ```html
   <button class="fc-nav-btn fc-prev-btn" title="Previous" disabled>
       <svg …chevron-left…></svg><span class="fc-nav-label"><?php esc_html_e( 'Back', 'tbt-flashcards' ); ?></span>
   </button>
   <div class="fc-progress" aria-live="polite">0 / 0</div>
   <button class="fc-nav-btn fc-next-btn" title="Next">
       <span class="fc-nav-label"><?php esc_html_e( 'Next', 'tbt-flashcards' ); ?></span><svg …chevron-right…></svg>
   </button>
   ```
   Add `aria-hidden="true"` to both SVGs.
2. CSS per the contract's button table. This replaces the 52px circles and the phone overrides. SVG 18px, `fill: currentColor`. Padding `0 28px 0 22px` (Back) and `0 22px 0 30px` (Next). Keep the keyboard shortcuts (← → Space) exactly as they are.
3. Counter pill per the contract, in lessons and on the deck page.

### T6 — Version, docs
1. Bump `TBT_FC_VERSION` and the header to `2.5.0`.
2. Add a CHANGELOG entry: "Card style v2: white table, light blue front, TBT blue back everywhere, phone card 8% smaller with the stack, smaller back text and long-text fit, labelled Back / Next."
3. Update CLAUDE.md "Current release" to `docs/SPEC-2.5.0.md`. Save this spec there.

---

## Out of scope
- Glossary, teacher pages, editor, Paste words, import.
- Audio, TTS, CSV.
- Swipe (sister spec).

## Manual steps for Mariusz after deploy
- Purge LiteSpeed's cache.

## Acceptance checklist (live)
- [ ] **Lesson (desktop):**
  - [ ] no grey panel, just the card and its striped stack on white;
  - [ ] front light blue with a hairline edge, WORD in muted blue, blue term, phonetics, a white audio circle.
- [ ] Flip: TBT blue back with white text, as before.
- [ ] **BACK** (light blue) and **NEXT** (solid blue, shadow) pills with the counter pill between them:
  - [ ] BACK is faded on card 1;
  - [ ] ← → Space still work;
  - [ ] Tab shows a visible focus ring.
- [ ] **Deck page, desktop:** white page, same card, same buttons.
- [ ] **Deck page, phone (or a window under 900px):**
  - [ ] white page, no blue field, no navy back;
  - [ ] the card is visibly smaller with the stack peeking under it;
  - [ ] Back / Next pills.
- [ ] Back with a long translation and a 20-word example, on a phone: the text shrinks to fit, never cut off or touching the edges. A short card looks exactly as designed (no shrinking).
- [ ] The finish card ("Start again") reads well on the light blue front.
- [ ] Side by side with Swipe 1.18.0: the same card front, back and stack.
- [ ] Browser console: nothing new.
