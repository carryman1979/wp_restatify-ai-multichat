# Security TODO (AJAX Hardening)

Status: Open (reviewed for 2.0.8)
Created: 2026-05-07
Scope: Restatify Multi Chat Overlay

## Goal
Harden public AJAX endpoints against abuse while keeping legitimate website users unaffected.

## TODO
- [ ] Restrict `X-Forwarded-For` trust: only parse forwarded client IP when `REMOTE_ADDR` is in a trusted proxy allowlist.
- [ ] Add helper for trusted proxy matching (single IP + CIDR support for IPv4/IPv6).
- [ ] Keep fallback to `REMOTE_ADDR` when request does not come from a trusted proxy.
- [ ] Make trusted proxy list configurable via WordPress filter (safe default: empty allowlist).
- [ ] Add concise inline comments around IP extraction and trust decision.
- [ ] Add temporary debug log switch for rate-limit fingerprint source (`remote_addr` vs `xff`) for rollout verification.
- [ ] Review and tune rate-limit defaults after rollout using real traffic.
- [ ] Add release note entry documenting header trust behavior and proxy configuration requirements.

## Validation Checklist (when implemented)
- [ ] Requests with spoofed `X-Forwarded-For` from non-trusted source do not change effective client IP.
- [ ] Requests through trusted proxy use forwarded client IP.
- [ ] Public endpoints still return proper JSON errors for invalid nonce.
- [ ] No increase in false-positive 429 responses for legitimate visitors.
