# TBT Flashcards — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task; do not scan the repository by default.
- Check `git status`, use targeted search, and read only the relevant sections/files.
- The deployable plugin lives under `tbt-flashcards/`; the root also contains an old standalone HTML artifact. Do not treat that HTML file as the plugin source unless the task explicitly concerns it.
- Do not change unrelated card content, shortcode compatibility, audio behavior, styling, or formatting.
- Inspect the final diff before finishing.

## Project basics

- WordPress plugin for The Blue Tree English lessons.
- Main file: `tbt-flashcards/tbt-flashcards.php` (`TBT_FC_VERSION`, `TBT_FC_DIR`, `TBT_FC_URL`).
- Admin/CPT logic: `tbt-flashcards/admin/`; frontend assets: `tbt-flashcards/assets/`.
- 2.0 includes: `includes/access.php` (gate, same rule as Matching Games), `cards.php` (card model, CSV in/out, source rule), `openai.php`, `rest.php` (`tbt-flashcards/v1`), `tools.php`.
- Shortcodes: `[tbt_flashcards]` (player), `[tbt_flashcards_sets generator="…"]` (teacher library), `[tbt_flashcards_generator library="…"]` (teacher workspace). The two teacher shortcodes render nothing for users without `tbt_fc_can_use_tools()`. Without the attributes, each finds the other's page by searching published pages for its shortcode; the library's Create/Edit links must never point back at the library itself.
- Source rule (`tbt_fc_get_cards()`): `_tbt_fc_cards` decoding to a non-empty array = editor set; otherwise a legacy set read from `_tbt_csv_url` / `_tbt_csv_file`. Saving a legacy set writes `_tbt_fc_cards` and never touches the CSV meta.
- A set's own URL uses the bare `templates/single-deck.php` (no `get_header()`/`get_footer()`), a full-screen deck.
- Flashcard sets are WordPress-managed content; preserve existing IDs and saved-set compatibility.
- The plugin registers with TBT Hub but must remain usable when Hub is absent.
- ElevenLabs TTS is proxied server-side and cached as generated audio.

## Audio and API invariants

- Never expose the ElevenLabs API key to the browser or commit a real key.
- Preserve the AJAX nonce and permission/security checks around TTS requests.
- Existing voice/model/language constants are deliberate pronunciation choices; do not change them as part of an unrelated task.
- Cached MP3s are reusable output. Changes to cache keys, paths, purge behavior, or regeneration semantics should be explicit and tested.
- Keep English pronunciation locked as designed unless the task specifically changes language behavior.

## Behavior to preserve

- Preserve `[tbt_flashcards]` output and saved flashcard-set compatibility.
- CSV export must stay byte-identical to the existing files: UTF-8 without BOM, header `word,phonetic,translation,example`, LF, final newline, quote only fields with `,` `"` CR or LF. Never use `fputcsv()`. `php tbt-flashcards/tests/test-csv.php` must pass.
- The PHP CSV parser mirrors `parseCSV()` in `assets/tbt-flashcards.js`; change both together.
- Front-end lists and mutations are owner-scoped (`post_author`) for everyone, admins included; wp-admin stays the cross-user view.
- Keep authoring/admin functionality separate from learner-facing interaction.
- Settings must remain reachable with or without TBT Hub.
- Reuse the existing CPT, shortcode, AJAX, and asset mechanisms rather than creating parallel storage or endpoints.

## Security and WordPress rules

- Preserve nonce/capability checks on writes and TTS requests.
- Sanitize stored/admin values and escape PHP-rendered output for its context.
- Treat card text as untrusted when inserting it into HTML or JavaScript.
- Never commit API keys, FTP credentials, secrets, uploads, or generated private data.

## Coding style

- Follow surrounding WordPress/PHP and vanilla JS/CSS style; do not reformat whole files.
- Prefer small local changes and existing hooks/helpers/selectors.
- Do not add a framework/build system for a focused task.
- Keep comments that explain pronunciation, caching, or compatibility decisions.

## Validation

Run `php -l <changed-file>` for changed PHP files.

Run `node --check <changed-file>` for changed JavaScript files when Node is available.

Run `php tbt-flashcards/tests/test-csv.php` when cards, CSV parsing or export change.

For TTS changes, verify at least: authorization/nonce behavior, cache hit, cache miss/generation, and failure handling. For UI changes, verify a saved set through the real shortcode; audio playback and Divi/theme layout require a live browser check when affected.

## Git and deployment

- `main` is the integration branch; use a focused feature branch.
- A push to `main` deploys `./tbt-flashcards/` to `/tbt-flashcards/` over FTPS.
- Markdown is excluded from the FTP upload, although a push to `main` still starts the workflow.
- Never alter deployment paths or credentials unless the task specifically concerns deployment.

## Context discipline

- Prefer targeted search + narrow reads over broad exploration.
- Do not repeatedly reread the ~18 KB main PHP file; find and read the relevant function area.
- Consult other docs/history only when current code does not explain a decision.
- Do not paste large card sets, audio data, or whole source files into the conversation when an excerpt is enough.
- Finish with a brief summary of changes, checks, and remaining live-site verification.
- For a new unrelated task, prefer a fresh Claude Code session.
