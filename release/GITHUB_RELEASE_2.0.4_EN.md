# Restatify Multi Chat Overlay 2.0.4

## What's new

- Added a self-healing mixed-environment guard for legacy coexistence
- If legacy `wp_restatify-multi-chat-overlay` is still active, AI Multichat now auto-removes that activation entry
- AI Multichat skips bootstrap for the current request to avoid class redeclare crashes

## Why

Some live systems still had both plugin generations active simultaneously, causing `Cannot redeclare class Restatify_Ai_Multichat_Plugin` fatals. This release auto-recovers from that state.

## Compatibility

- Plugin version: `2.0.4`
- Tested up to WordPress `6.9`
- No manual settings migration required

## Artifact

- `wp_restatify-ai-multichat-2.0.4.zip`
