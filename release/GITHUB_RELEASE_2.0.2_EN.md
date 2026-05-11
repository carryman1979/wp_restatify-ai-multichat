# Restatify Multi Chat Overlay 2.0.2

## What's new

- Fixed activation/runtime failures caused by legacy constant name collisions
- Switched internal bootstrap and runtime path/url resolution to dedicated AI Multichat constants
- Keeps include/template/asset loading isolated from legacy plugin slugs

## Why

In mixed or legacy environments, global constants with reused names could be pre-defined by old plugin slugs and redirect include paths to the wrong directory. This release hardens path resolution to prevent that.

## Compatibility

- Plugin version: `2.0.2`
- Tested up to WordPress `6.9`
- No manual settings migration required

## Artifact

- `wp_restatify-ai-multichat-2.0.2.zip`
