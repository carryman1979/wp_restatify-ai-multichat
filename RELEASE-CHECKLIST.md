# Release Checklist

Use this checklist before deploying to production.

German version available: RELEASE-CHECKLIST.de.md

## 1) Package and Version

1. Verify plugin header version in `restatify-multi-chat-overlay.php`.
2. Verify `Stable tag` in `readme.txt` matches plugin version.
3. Confirm no local debug/test code remains.
4. Ensure only required files are included in release package.

## 2) Functional Smoke Tests

1. Frontend overlay opens and closes (`X`, outside click, FAB toggle).
2. Cookie-consent gating works for your configured cookie rules.
3. Native chat send/receive works (visitor and support).
4. Long conversation keeps input visible and usable.
5. Reset interval behavior works after inactivity.

## 3) Support Inbox

1. `Support Chat` admin menu is visible for intended roles/capabilities.
2. Open conversation from list works.
3. `Delete` in list removes row immediately.
4. `Delete this conversation` in detail works.
5. AI mode per conversation saves and applies.

## 4) Notifications and Links

1. Support email notification is sent for new visitor messages.
2. Conversation deep-link in email opens correct chat in admin.
3. Link still works when conversation already expired/deleted.

## 5) AI Integration

1. Endpoint and model are configured.
2. AI reply mode behaves as expected (`off`, `visitor`, `support`, `both`).
3. If AI debug is enabled, logs are present and no secrets are exposed.

## 6) Multilingual and Content

1. Polylang strings are registered and translated where needed.
2. UI labels (`Weiter/Weniger`, chat labels, prompts) are correct per language.
3. Documentation files are updated for current behavior.

## 7) Theme Independence

1. Channel icons render with theme fonts where available.
2. Fallback icon letters render correctly when icon fonts are missing.

## 8) Final Rollout

1. Deploy plugin package to target server.
2. Activate/update plugin and save settings once.
3. Run quick smoke test on desktop and mobile.
4. Monitor support inbox and error logs for first 24h.
