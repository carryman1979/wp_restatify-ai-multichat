# Restatify Multi Chat Overlay 2.0.1

## What's new

- Hotfix for an activation fatal in inconsistent deployments
- Added defensive loading guard for `class-restatify-shared-migration-notice-manager.php`
- Activation now remains functional even when the migration helper class file is missing

## Why

In rare live scenarios, partial or inconsistent plugin updates could trigger a fatal error during activation. This release hardens bootstrap loading against that state.

## Compatibility

- Plugin version: `2.0.1`
- Tested up to WordPress `6.9`
- No manual settings migration required

## Artifact

- `wp_restatify-ai-multichat-2.0.1.zip`
