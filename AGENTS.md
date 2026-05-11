# Agent Workflow for wp_restatify-ai-multichat

Shared baseline:
- https://github.com/carryman1979/wp_restatify-shared/blob/main/docs/ai/AGENTS.shared.md

Repo-specific additions:
- Keep AJAX nonce/security/capability checks intact.
- Preserve endpoint sanitization and provider detection safety.
- Do not log or expose secrets in repository files.

Commands:
- composer run test:unit:php
