# TBT Flashcards 2.3.0 — Unified card style (shared with Swipe)

**Repo:** `aparatofan/flashcards` · **Version:** `TBT_FC_VERSION = '2.3.0'` (plugin header too) · **No database or meta changes.** No change to the CSV contract (`word,phonetic,translation,example`), the REST routes, the shortcodes' attributes or the TTS proxy.
**New files:** `tbt-flashcards/assets/img/tbt-logo-colour.png`, `tbt-flashcards/assets/img/tbt-logo-white.png` (copied from Swipe, see T1).
**Approved prototype:** "TBT unified card style — Swipe + Flashcards prototype" (claude.ai artifact, 20 Nov 2026). Where this spec and the prototype differ, **this spec wins**.
**Sister spec:** TBT Swipe 1.17.0 implements the same Card style contract (section below is identical in both specs).

---

## Why

Swipe and TBT Flashcards do almost the same job but look different. Swipe has a 380×500 portrait card on the grey table with a stack of striped backs. Flashcards has an 842×595 card in lessons and an 842:560 card on its deck page, with a striped blue back that is hard to read. Mariusz wants one card: same shape, same front, same back, same stack, same table, in both tools.

## Locked decisions (Mariusz, 20 Nov 2026)

1. **16:9 landscape** card.
2. **Back = solid blue, no stripes.** Stripes stay only on the stack of undealt cards.
3. **Phonetics move to the front**, under the word and above the audio button.
4. **Counter only in the pill under the card.** No counter inside the card.
5. **Both tools get the stack** of card backs under the live card.
6. **Both tools put the card on Swipe's table colour** `#EEF1F6`. In a lesson this is a rounded grey panel.

---

## Card style contract v1 (identical in the Swipe and Flashcards specs)

**Shape and size**

| Where | Size |
|---|---|
| Swipe table (≥ 900px) | fixed **720 × 405** |
| Flashcards deck page (≥ 900px) | width `min(720px, 62vw, calc((100dvh - 220px) * 16 / 9))`, `aspect-ratio: 16 / 9` |
| Flashcards inline (lessons, all widths) | full width of the table panel, `aspect-ratio: 16 / 9` |
| Below 900px on the full-screen deck pages | layout unchanged (phones are not a target); only the face colours below apply |

- Card box: `container-type: inline-size`. Every size on a face is in `cqw` of the card, with a `px` floor where shown.
- Face: `border-radius: 20px`; `box-shadow: 0 14px 34px rgba(4, 30, 74, 0.22)`; `padding: 6cqw 7cqw`; content centred.

**Front (white)**

| Element | Style |
|---|---|
| Face | `#FFFFFF` |
| Tag (top-left) | Roboto Mono 700, `max(10px, 1.5cqw)`, `letter-spacing: .18em`, uppercase, `#8299B8`; `top: 3.4cqw; left: 3.6cqw` |
| Logo (top-right) | `tbt-logo-colour.png`, `top: 3cqw; right: 3cqw`, width `max(26px, 4.6cqw)`, `aspect-ratio: 120 / 104`, opacity `.9` |
| Lead line (term, or translation on a translation-first Swipe deck) | Roboto Slab 700, `6.6cqw`, line-height 1.15, `#0856C9`, `text-wrap: balance` |
| IPA | Roboto Mono 500, `max(12px, 2.3cqw)`, `#4E6689`. Plain text: **no chip background** |
| Audio button (Flashcards only) | circle `max(38px, 6.6cqw)`, `rgba(8, 86, 201, .08)` → hover `.16` and `scale(1.06)`, icon `#0856C9`, `margin-top: 1.4cqw` |
| Gap between lines | `1.6cqw` |
| Hint (bottom) | Roboto, `max(11px, 1.6cqw)`, `#8299B8`, `bottom: 3cqw`: "Click or press Space to flip" |

**Back (solid blue, no stripes)**

| Element | Style |
|---|---|
| Face (table / lesson) | `linear-gradient(150deg, #0856C9, #063F94)`; **no `repeating-linear-gradient`** |
| Face (below 900px on a blue deck page) | `linear-gradient(160deg, #0B2F6E, #061E4A)`; no stripes |
| Tag | as the front, colour `rgba(255, 255, 255, .6)` |
| Logo | `tbt-logo-white.png`, same box as the front, opacity `.95` |
| Answer line (translation, or term on a translation-first Swipe deck) | Roboto Slab 700, `5.6cqw`, line-height 1.2, `#FFFFFF`, `text-wrap: balance` |
| IPA (translation-first Swipe decks only) | as the front IPA, colour `rgba(255, 255, 255, .8)` |
| Rule | width 62%, `border-top: 1px solid rgba(255, 255, 255, .25)`; **only when there is an example** |
| Example | Roboto Slab italic 400, `3.2cqw`, line-height 1.5, `max-width: 86%`, `rgba(255, 255, 255, .95)`, wrapped in curly quotes “ ” |
| Gap | `2.2cqw` |
| Hint | as the front, colour `rgba(255, 255, 255, .6)` |

**Tags:** the face holding the English term says **WORD**; the face holding the translation says **MEANING**.

**Stack (undealt cards under the live card)**
- One back per remaining card, **max 4**. Same box as the card, behind it, `pointer-events: none`, `aria-hidden="true"`.
- Back `i` (depth `d = i + 1`): `transform: translate(${2d}px, ${3d}px) rotate(${(d % 2 ? 1 : -1) * 0.5 * d}deg)`, `opacity: 1 - 0.08d`, `z-index: 4 - d`. (This is Swipe's shipped `renderStack()`.)
- Back face: `border-radius: 20px`; `box-shadow: 0 10px 26px rgba(4, 30, 74, .18)`; background, top to bottom:
  `repeating-linear-gradient(45deg, rgba(255,255,255,.1) 0 8px, rgba(255,255,255,0) 8px 16px)`,
  `url(tbt-logo-white.png) no-repeat center calc(50% - 4px) / 22% auto`,
  `linear-gradient(150deg, #0856C9, #063F94)`.
- On the last card the stack is empty.

**Table and counter**
- Table surface `#EEF1F6`.
- Counter: only in the pill below the card (Roboto Mono 12px, `letter-spacing: .08em`, white 80–100% background, radius 999px). Never inside a face.

**Flip:** `rotateY(180deg)`. Reduced motion: no flip transition, as today.

---

## Phase 0 — read-only inventory, then STOP and report

0.1 In `tbt-flashcards/tbt-flashcards.php`, `tbt_flashcards_render_app()`: confirm the face markup (two `.fc-card-counter`, `.fc-phonetic` on the back, the hint only on the front) and the inline `style="height:…px"` on `.fc-card-scene`.
0.2 In `assets/tbt-flashcards.js`, `initInstance()`: list every reference to `.fc-card-counter`, `.fc-counter-current`, `.fc-counter-total`, `.fc-phonetic`, `.fc-hint`, and confirm `renderCard()` is the single place a card (and the finish card) is drawn.
0.3 In `assets/tbt-flashcards.css`: list every rule for `.fc-card-scene`, `.fc-card-face`, `.fc-card-back`, `.fc-phonetic`, `.fc-card-counter`, `.fc-word`, the `@media (max-width: 480px)` block, the `.is-deck` block and the `@media (max-width: 899px)` block.
0.4 Search the whole plugin (PHP, JS, CSS, admin, tools, glossary) for any other place that renders a card face or relies on `data-height` / the `height` attribute. Expected: the admin preview reuses `tbt_flashcards_render_app()`; the glossary and teacher tools do not render faces.
0.5 Confirm `https://github.com/aparatofan/tbt-swipe` `assets/img/tbt-logo-colour.png` (120×104) and `tbt-logo-white.png` (300×260) can be fetched (`git clone --depth 1`).
0.6 **Report and STOP.** **Hard stop** if another template or script renders the card faces outside `tbt_flashcards_render_app()` / `renderCard()`, or if the logos can't be fetched.

---

## Tasks

### T1 — Logo files
Copy `tbt-logo-colour.png` and `tbt-logo-white.png` byte for byte from `aparatofan/tbt-swipe` `assets/img/` into `tbt-flashcards/assets/img/`. Reference them from CSS with relative URLs (`url(img/…)` from `assets/tbt-flashcards.css`).

### T2 — Markup (`tbt_flashcards_render_app()`)
1. **Front**, in this order: `<span class="fc-face-tag">Word</span>`, `.fc-word`, **`.fc-phonetic` (moved here from the back)**, `.fc-audio-btn`, `.fc-hint`.
2. **Back**, in this order: `<span class="fc-face-tag">Meaning</span>`, `.fc-translation`, `.fc-back-divider`, `.fc-example`, a second `<div class="fc-hint">`.
3. Remove both `.fc-card-counter` blocks.
4. Inside `.fc-card-scene`, **before** `.fc-card`: `<div class="fc-stack" aria-hidden="true"></div>`.
5. **Inline mode only:** wrap `.fc-card-scene` and `.fc-nav` in `<div class="fc-table">`. `.fc-header` (the set title) stays outside, above it. The deck page needs no wrapper: the whole page is the table.
6. Drop the inline `style="height:…"` from `.fc-card-scene`. The `height` shortcode attribute and `data-height` are still accepted, with no effect (documented in the changelog), so old shortcodes don't break.

### T3 — JS (`assets/tbt-flashcards.js`)
1. Add `renderStack()` per the contract: remaining = `cards.length - 1 - currentIndex` (0 on the finish card); clear and redraw `.fc-stack`; max 4 backs.
2. Call it at the end of `renderCard()` (both the normal and the finish branch) and in `restart()`.
3. Hint text, on both faces: "Click or press Space to flip". `hintEl` becomes a `querySelectorAll`; show and hide both together, as today.
4. Phonetic: the show/hide logic stays the same (the element only moved).
5. Finish card: also hide both `.fc-face-tag` elements; show them again when going back.
6. Remove the now-dead counter references (`counterCurrentEls`, `counterTotalEls` and their loops). `.fc-progress` keeps doing the counting.
7. The `.slide-left` / `.slide-right` animations stay on `.fc-card` only. The stack must not slide.

### T4 — CSS (`assets/tbt-flashcards.css`)
1. **Faces:** implement the contract, front and back. Remove both `repeating-linear-gradient` stripes from `.fc-card-back`: the main one, and the one in the ≤899px deck block, which becomes the navy no-stripe gradient. Front `.fc-word` is now `#0856C9`, no longer `#2C2C2A`. Remove the dark chip from `.fc-phonetic`.
2. **Logo:** `::after` on `.fc-card-face`, colour on the front and white on the back, per the contract.
3. **Inline scene:** `aspect-ratio: 16 / 9; height: auto;` (keep `container-type: inline-size` and `perspective`). Delete the `@media (max-width: 480px)` height and counter rules.
4. **Table panel** `.fc-table`: `background: #EEF1F6; border-radius: 18px; padding: 34px 46px 26px 34px;`. The extra right and bottom padding leaves room for the stack offset. `.fc-nav` `margin-top: 30px`; the pill keeps a `#FFFFFF` background on the panel.
5. **Stack** `.fc-stack` and `.fc-stack-card`: per the contract. `.fc-stack` is `position: absolute; inset: 0; z-index: 0`, and `.fc-card` sits above it (`position: relative; z-index: 1`). In deck mode below 900px, `.fc-stack { display: none; }`, as Swipe does on phones.
6. **Deck mode ≥ 900px:** `--deck-card-w` per the contract; `.fc-card-scene` `aspect-ratio: 16 / 9`. Replace the cqw overrides in the `.is-deck` block (`.fc-word`, `.fc-translation`, `.fc-phonetic`, `.fc-example`, `.fc-hint`, `.fc-audio-btn`, face padding) with the contract's values, so deck page and lesson match exactly.
7. **Deck mode below 900px:** keep the layout (3 : 4.1, blue field, white nav). Only the faces change: no counter, the back is the navy gradient without stripes, and the phonetic is on the front in `#4E6689` with no chip.
8. The finish card ("Well done!") stays on the front face as today.

### T5 — Version and changelog
Bump `TBT_FC_VERSION` and the header to `2.3.0`. Add a CHANGELOG entry noting: the unified card style; that `height` is now ignored; that the counter is out of the card.

---

## Out of scope
- Swipe (its own spec, 1.17.0).
- Teacher tools, library, glossary, CSV export, AI generation.
- Any change to how cards are swiped or navigated (Flashcards keeps its arrows and ← → keys).

## Manual steps for Mariusz after deploy
- Purge LiteSpeed's cache.

## Acceptance checklist (live)
- [ ] A lesson built with Lesson Builder: the set title sits above a grey rounded panel; inside, a **16:9** white card with a **stack of striped backs** peeking out bottom-right; the arrows and the "1 / N" pill below.
- [ ] Front: **WORD** tag, logo top-right, blue term, phonetics under it, then the audio button (still plays ElevenLabs), hint at the bottom. **No counter on the card.**
- [ ] Click or Space: the back is **solid blue, no stripes**, with the **MEANING** tag, white logo, white translation, a thin line, the example in quotes, and the hint.
- [ ] A card with no example: no line under the translation. A card with no phonetic: no empty gap.
- [ ] → through the set: the stack thins out and is gone on the last card. The finish card shows "Well done!" with no tags; "Start again" brings the stack back.
- [ ] The set's own page `/flashcards/{slug}/` on desktop: the same 16:9 card, stack and faces on the grey table; the progress bar and nav as before.
- [ ] The same page in a window narrower than 900px: the blue field, the portrait card, and a navy back without stripes.
- [ ] Two flashcard sets in one lesson: keyboard control still follows the set last clicked.
- [ ] An old shortcode with `height="400"`: still renders, at 16:9.
- [ ] PHP error log and browser console: nothing new from `tbt-flashcards`.
