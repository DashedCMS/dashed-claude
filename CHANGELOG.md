# Changelog

All notable changes to `dashed-claude` will be documented in this file.

## v4.2.0 - 2026-06-24

### Added
- **Prompt caching ondersteuning.** `ClaudeProvider::messages()` en `streamMessages()` accepteren nu `'cache' => true` in de options. Dat zet een `cache_control: {type: ephemeral}` op de system-prompt (een string-prompt wordt automatisch naar het content-block formaat omgezet) en op de laatste tool-definitie. Bedoeld voor herhaalde calls met een stabiele prefix (zoals de livechat-agent-loop); cache-reads kosten ~0,1× t.o.v. de volle inputprijs. Zonder de vlag blijft de payload byte-identiek, dus one-shot calls veranderen niet.

## v4.0.2 - 2026-04-27

### Changed
- `ClaudeProvider::request()` en `vision()` lezen nu `model` en `temperature` uit de options-array door, in plaats van het hardcoded modelconstant en geen temperature.
- `ClaudeProvider::vision()` default `max_tokens` 200 → 1024 zodat rijke image-prompts (60-180 woorden) niet meer afgekapt worden.
