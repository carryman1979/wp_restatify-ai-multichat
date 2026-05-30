# Restatify AI Multichat 2.0.10

## What's new

- Shared resolver now prioritizes local root shared (`wp_restatify-shared/src/*`) for local development setups.
- If root shared is not available, it loads only the exact required shared version from `wp-content/plugins/wp_restatify-shared/versions/<x.y.z>/` (or MU-plugins).
- Mixed loading between root shared and versioned shared in the same request is prevented.
- Shared files are now symbol-guarded and skipped when target classes already exist (prevents `Restatify\\Shared\\*` redeclare fatals).
- Copilot/release guidance for shared loader order was aligned across repositories.

## Release-prep refresh (2026-05-30)

- No version bump: release prep remains on `2.0.10`.
- Ongoing router/session and language-flow hardening aligned for the coordinated rollout.
- Mail-context/runtime guardrails and support-inbox runtime paths were refreshed.

## Compatibility

- Plugin version: `2.0.10`
- Tested up to WordPress `6.9`
- No migration required.

## Artifact

- `wp_restatify-ai-multichat-2.0.10.zip`
