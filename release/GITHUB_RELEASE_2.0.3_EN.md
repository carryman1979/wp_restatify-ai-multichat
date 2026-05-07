# Restatify Multi Chat Overlay 2.0.3

## What's new

- Removed legacy `RESTATIFY_MCO_*` alias constants from AI Multichat bootstrap
- Prevents cross-plugin constant poisoning in mixed legacy environments
- Fixes activation/runtime fatals where legacy loader includes were resolved to the wrong plugin directory

## Why

When old and new plugin generations coexisted, globally reused constant names could leak between plugins and redirect `require_once` paths. This release removes those bootstrap aliases entirely.

## Compatibility

- Plugin version: `2.0.3`
- Tested up to WordPress `6.9`
- No manual settings migration required

## Artifact

- `wp_restatify-ai-multichat-2.0.3.zip`
