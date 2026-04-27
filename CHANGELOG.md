# Changelog

All notable changes to `dashed-claude` will be documented in this file.

## v4.0.2 - 2026-04-27

### Changed
- `ClaudeProvider::request()` en `vision()` lezen nu `model` en `temperature` uit de options-array door, in plaats van het hardcoded modelconstant en geen temperature.
- `ClaudeProvider::vision()` default `max_tokens` 200 → 1024 zodat rijke image-prompts (60-180 woorden) niet meer afgekapt worden.
