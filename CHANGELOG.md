# Changelog — TBT Flashcards

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
