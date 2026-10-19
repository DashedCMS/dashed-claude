# Changelog

All notable changes to `dashed-claude` will be documented in this file.

## v4.5.0 - 2026-10-19

### Added
- **Prompt caching voor het hele gesprek.** `'cache' => true` zet naast de breakpoints op system-prompt en laatste tool nu ook een top-level `cache_control` (automatische caching): het breakpoint schuift mee met het gesprek, zodat elke volgende beurt van een agent-loop ook de eerdere berichten en tool-resultaten uit de cache leest.
- **1 uur-TTL.** `'cache' => '1h'` zet alle breakpoints op de TTL van een uur (schrijven kost 2× in plaats van 1,25×; alleen zinvol als er vaak meer dan 5 minuten tussen twee calls met dezelfde prefix zit).
- **Cache-tellers in het verbruik.** `streamMessages()` geeft `cache_read_input_tokens` en `cache_creation_input_tokens` terug in `usage`, en de dag-/maandtellers (`ai_usage_claude_*`) houden beide bij. `input_tokens` telt alleen de niet-gecachete tokens.

### Fixed
- `messages()` telde zijn verbruik niet mee in de dag-/maandtellers; alleen `streamMessages()` deed dat.

### Changed
- **Elke aanroep in het AI-logboek.** `text()`, `json()`, `vision()`, `messages()` en `streamMessages()` loggen via `AiLogger` (dashed-ai) met model, tokens (ook cache), prompt en antwoord; een mislukte aanroep komt er als fout in. De dag- en maandtellers in `ai_usage_claude_*` blijven bestaan.


## v4.2.0 - 2026-06-24

### Added
- **Prompt caching ondersteuning.** `ClaudeProvider::messages()` en `streamMessages()` accepteren nu `'cache' => true` in de options. Dat zet een `cache_control: {type: ephemeral}` op de system-prompt (een string-prompt wordt automatisch naar het content-block formaat omgezet) en op de laatste tool-definitie. Bedoeld voor herhaalde calls met een stabiele prefix (zoals de livechat-agent-loop); cache-reads kosten ~0,1× t.o.v. de volle inputprijs. Zonder de vlag blijft de payload byte-identiek, dus one-shot calls veranderen niet.

## v4.0.2 - 2026-04-27

### Changed
- `ClaudeProvider::request()` en `vision()` lezen nu `model` en `temperature` uit de options-array door, in plaats van het hardcoded modelconstant en geen temperature.
- `ClaudeProvider::vision()` default `max_tokens` 200 → 1024 zodat rijke image-prompts (60-180 woorden) niet meer afgekapt worden.
