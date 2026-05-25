# Copilot Instructions for wp_restatify-ai-multichat

Shared baseline:
- https://github.com/carryman1979/wp_restatify-shared/blob/main/docs/ai/copilot-instructions.shared.md

Repo-specific requirements:
- Preserve nonce and permission checks for all AJAX handlers.
- Keep AI endpoint sanitization and provider detection behavior stable.
- Keep support inbox capability boundaries intact.
- Keep shared loader order stable: local root shared first for dev, otherwise exact versioned shared under plugins/mu-plugins, never mixed in one request.

Required checks:
- composer run test:unit:php
- If changing chat payload logic, verify provider request/response mapping behavior.
