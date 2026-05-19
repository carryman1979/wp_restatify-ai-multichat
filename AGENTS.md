# Agent Workflow for wp_restatify-ai-multichat

Shared baseline:
- https://github.com/carryman1979/wp_restatify-shared/blob/main/docs/ai/AGENTS.shared.md

Repo-specific additions:
- Keep AJAX nonce/security/capability checks intact.
- Preserve endpoint sanitization and provider detection safety.
- Do not log or expose secrets in repository files.

## Class Hierarchy (includes/)

```
Restatify_Ai_Multichat_Options_Runtime          (options R/W, sanitization, shared-lib helpers)
  └─ Restatify_Ai_Multichat_Chat_Runtime_Ai_Core_Base  (AI request building, provider detection, retry loop)
       └─ Restatify_Ai_Multichat_Chat_Runtime_Ai_Base  (AI lock/queue helpers)
            └─ Restatify_Ai_Multichat_Chat_Runtime_Transport_Base  (nonce, rate-limiter, WP HTTP)
                 └─ Restatify_Ai_Multichat_Chat_Runtime   (AJAX handlers for chat/send/fetch/booking)
                      └─ Restatify_Ai_Multichat_Admin_Runtime  (admin-page, support-inbox AJAX)
                           └─ Restatify_Ai_Multichat_Plugin  (WP hooks, constants, entry point)
```

## Dual Session Router (includes/)

| Class | Role |
|---|---|
| `Restatify_Ai_Dual_Session_Router` | Routes visitor message to Session 1 (booking) or Session 2 (general AI) |
| `Restatify_Ai_Dual_Session_State_Machine` | Persists session state per visitor via WP transients (TTL 1h) |
| `Restatify_Ai_Dual_Session_Slot_Manager` | Fetches/matches available booking slots from the Booking API |
| `Restatify_Ai_Dual_Session_Prompts` | System-prompt templates for Session 1 and Session 2 |
| `Restatify_Ai_Dual_Session_Cooldown_Manager` | IP-based cooldown (15 min) after Session 1 conversion |
| `Restatify_Ai_Router_Debug_Logger` | Router decision log (WP option, max 500 entries) |
| `Restatify_Ai_Session1_Debug_Logger` | Session 1 activation/extraction log (WP option, max 200 entries) |

## Commands

- `composer run test:unit:php` — run PHPUnit suite (24 tests)
