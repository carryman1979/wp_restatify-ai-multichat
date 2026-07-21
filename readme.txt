=== Restatify Multi Chat Overlay ===
Contributors: restatify
Tags: chat, support, whatsapp, telegram, messenger, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schwebendes Multi-Channel-Chat-Overlay mit integriertem Website-Chat, Support-Posteingang, E-Mail-Benachrichtigungen und optionalen KI-Autoantworten.

== Description ==

Product: Restatify-AI-Multichat  
Current slug: wp_restatify-multi-chat-overlay  
Target slug (2.0.0): wp_restatify-ai-multichat  
Company: https://www.restatify.tech

Restatify Multi Chat Overlay fuegt im Frontend einen schwebenden Chat-Button hinzu und ermoeglicht:

* Beliebte Messaging-Kanaele anzeigen (WhatsApp, Telegram, Messenger, Discord, Signal, Viber, Threema, WeChat).
* Einen nativen Website-Chat direkt im Overlay aktivieren.
* Jede neue Besuchernachricht an eine konfigurierbare Support-E-Mail senden.
* Einen direkten Admin-Link in Support-E-Mails einbetten, um die passende Unterhaltung zu oeffnen.
* Aus dem WordPress-Admin-Support-Posteingang antworten.
* Optional KI-basierte First-Level-Antworten erzeugen.
* Buchungsanfragen aus dem Chat direkt an das Booking-Plugin uebergeben, das Overlay oeffnen und erkannte Felder vorbefuellen.
* Optional das Booking Assistant Overlay aus dem Support-Posteingang ausloesen.
* Buchungs-Lifecycle-Ereignisse (bestaetigt/abgebrochen) in Support-Unterhaltungen anzeigen.

Dieses Plugin ist fuer kleine Support-Teams gedacht, die E-Mails ueberwachen und einen schnellen Uebergang vom Postfach in aktive Chats brauchen.

== Installation ==

1. Plugin-Ordner nach /wp-content/plugins/ hochladen.
2. Restatify Multi Chat Overlay unter Plugins aktivieren.
3. Zu Einstellungen -> Multi Chat Overlay gehen.
4. Overlay aktivieren.
5. Mindestens eine Kanal-URL konfigurieren oder den integrierten Website-Chat aktivieren.
6. Support-E-Mail-Adresse hinterlegen.
7. Aenderungen speichern.

Fuer externe Installationen kann auch ein Release-ZIP verwendet werden.

ZIP-Paket im Plugin-Workspace erstellen mit:

1. pwsh -NoProfile -ExecutionPolicy Bypass -File ./scripts/create-release-zip.ps1

Das ZIP wird unter /release erstellt.

Wenn "Require cookie consent" aktiviert ist und deine Seite ein eigenes Theme oder eigenes CMP nutzt, hinterlege passende Cookie-Regeln in der Plugin-Einstellung "Consent cookie rules".
Format: cookie_name or cookie_name=expected_value.
Example: restatify_cookie_consent=accepted,cookiesDirective,_cky-consent=accept

LightStart-Wartungsmodus:

* Die Einstellung "Bei LightStart-Wartung ausblenden" ist in den Plugin-Einstellungen verfuegbar.
* Standardwert ist aktiviert.
* Die Einstellung wird nur angezeigt, wenn LightStart (`wp-maintenance-mode`) installiert und aktiv ist.
* Ist LightStart nicht installiert/aktiv, wird die Einstellung nicht angezeigt und das Overlay bleibt sichtbar.
* Ist LightStart aktiv und der Wartungsmodus eingeschaltet, wird das Overlay bei aktivierter Option ausgeblendet.

== Frequently Asked Questions ==

= Wie aktiviere ich den integrierten Website-Chat? =

Gehe zu Einstellungen -> Multi Chat Overlay und aktiviere "Enable built-in website chat".

= Wo erscheinen eingehende Chat-Nachrichten? =

Im eigenen Admin-Menuepunkt Support Chat.
Dort kannst du Unterhaltungen oeffnen und Support-Antworten senden.

= Versendet das Plugin E-Mail-Benachrichtigungen? =

Ja. Wenn "Send email on new message" aktiviert ist und eine gueltige Support-E-Mail konfiguriert wurde, versendet das Plugin fuer jede neue Besuchernachricht eine Benachrichtigung.

= Ist KI erforderlich? =

Nein. KI-Autoantwort ist optional und standardmaessig deaktiviert.

= Welche KI-Anbieter werden unterstuetzt? =

Das Plugin unterstuetzt diese Anbieter per Endpunkt-Autoerkennung:

* OpenAI (ChatGPT)
* Google Gemini
* Mistral
* DeepSeek
* Llama (including Ollama-style endpoints)

Der API-Endpunkt ist in den Plugin-Einstellungen konfigurierbar.
Der Anbieter wird ueber die konfigurierte Endpunkt-URL erkannt und Request-/Response-Payloads werden automatisch angepasst.

= Unterstuetzt das Plugin Polylang? =

Ja.

Wenn Polylang aktiv ist, registriert das Plugin konfigurierbare Chat-Texte in der Uebersetzungsgruppe "Restatify Multi Chat Overlay".
Diese Texte koennen unter Languages -> Translations uebersetzt werden.

Uebersetzbare Felder:

* Team title
* Intro message
* Channel heading
* Button accessibility label
* Chat title
* Chat input placeholder
* Send button label
* AI system prompt

= Benoetigt dieses Plugin den Booking Assistant? =

Nein. Die Booking-Assistant-Integration ist optional.

Wenn Booking Assistant installiert ist, kann der Support das Oeffnen des Booking-Overlays beim Besucher triggern und Buchungsstatus-Ereignisse in der Chat-Timeline sehen.
Wenn Booking Assistant nicht installiert ist, funktionieren Chat und Support-Posteingang weiterhin normal.

= Warum wird das Overlay nicht angezeigt, obwohl es aktiviert ist? =

Hauefig ist eine der folgenden Bedingungen nicht erfuellt:

* Das Overlay ist aktiviert, aber es ist keine Kanal-URL hinterlegt und der integrierte Website-Chat ist deaktiviert.
* "Require cookie consent" ist aktiviert, aber die konfigurierten Cookie-Regeln passen nicht zu den tatsaechlichen Consent-Cookies der Seite.

Wenn deine Seite ein eigenes Theme oder Consent-Setup verwendet, aktualisiere "Consent cookie rules" entsprechend.

= Gibt es eine deutsche Setup-Anleitung und ein Support-Playbook? =

Ja. Siehe folgende Dateien im Plugin-Ordner:

* README.de.md
* SUPPORT-PLAYBOOK.md
* RELEASE-CHECKLIST.md

== Privacy ==

Wenn Website-Chat aktiviert ist, werden Besuchernachrichten in WordPress-Optionen gespeichert, um den Unterhaltungsverlauf zu behalten.
Wenn Support-E-Mail-Benachrichtigungen aktiviert sind, wird der Nachrichteninhalt per E-Mail an die konfigurierte Support-Adresse gesendet.
Wenn KI-Autoantwort aktiviert ist, wird der Nachrichteninhalt an den konfigurierten KI-API-Endpunkt gesendet.

Die Datenschutzerklaerung sollte entsprechend angepasst werden.

== Screenshots ==

1. Schwebender Chat-Button und Kanal-Panel im Frontend.
2. Nativer Website-Chat im Overlay.
3. Support-Posteingang im eigenen WordPress-Admin-Menue Support Chat.
4. E-Mail-Benachrichtigung mit direktem Unterhaltungs-Link.
5. KI-Konfigurationseinstellungen.

== Changelog ==

= 2.1.2 =
* Added a private WordPress REST bridge endpoint for the public Support API, so WordPress and Support API can run on separate servers.
* Preserved WordPress-backed support chat, API key, booking trigger and AI reply operations across the new server-to-server boundary.
* Updated release documentation and deployment guidance for split-server Support API production setups.

= 2.1.1 =
* Maintenance release consolidating router/state/runtime updates from current local integration work.
* Explicit EU AI ACT support built in for compliant booking/contact trigger confirmation flow with localized yes/no prompts.
* Synchronized plugin versioning and documentation for coordinated multi-repo release rollout.

= 2.1.0 =
* Added optional WebSocket-first live updates for frontend chat sync with automatic reconnect and polling fallback.
* Added configurable AI legal-notice extension text and Polylang registration for the new translatable option.
* Extended support admin runtime with API key visibility and one-click revoke-all action for incident response.
* Integrated conversation deletion live-update handling for cleaner support and visitor session behavior.

= 2.0.10 =
* Shared resolver and shared-loader ordering finalized for local-root and exact-version fallback paths.
* Router/session hardening continued for language and intent handling in the dual-session flow.
* Runtime/admin mail-context handling and support-inbox guardrails updated for coordinated rollout prep.

= 2.0.9 =
* Mobile overlay behavior revised to full-screen mode on small viewports, with close-only interaction for a more stable chat UX.
* Fixed mobile landscape layout so legal notice, input field and send button no longer overlap or fall outside the visible area.
* Added compact channel-flyout toggle (`>>` / `<<`) for mobile landscape to avoid clipped controls.

= 2.0.5 =
* Renamed the WordPress settings menu label from "Multi Chat Overlay" to "AI Multichat".
* Updated the settings page title to "AI Multichat" for UI consistency.

= 2.0.4 =
* Added self-healing mixed-environment guard: if legacy `wp_restatify-multi-chat-overlay` is still active, AI Multichat now auto-disables the legacy plugin entry and skips bootstrap for the current request.
* Prevents `Cannot redeclare class Restatify_Ai_Multichat_Plugin` fatals during plugin activation in legacy coexistence states.

= 2.0.3 =
* Removed legacy `RESTATIFY_MCO_*` alias constants from AI Multichat bootstrap to prevent cross-plugin constant collisions.
* Fixes mixed-environment activation/runtime fatals where legacy plugin loaders resolved includes to the wrong plugin directory.

= 2.0.2 =
* Fixed legacy constant name collisions that could resolve includes/assets to `wp_restatify-multi-chat-overlay` and cause activation fatals.
* Switched internal path/url resolution to dedicated AI Multichat constants to ensure collision-safe bootstrap/runtime loading.

= 2.0.1 =
* Fixed a plugin activation fatal error when the migration notice helper class file is missing in partial or inconsistent deployments.
* Added a defensive fallback so activation stays functional even if migration helper loading fails.

= 1.4.3 =
* Added LightStart maintenance integration with a configurable setting to hide the overlay while maintenance mode is active.
* Added admin setting "Bei LightStart-Wartung ausblenden" (default enabled), visible only when LightStart is installed and active.
* Added runtime checks for LightStart availability and active maintenance status before rendering overlay output.
* Updated documentation for maintenance-mode behavior in German and WordPress readme docs.

= 1.4.2 =
* Persisted handled booking-open triggers in the browser session to prevent stale support messages from reopening the booking popup after reloads.
* Follow-up maintenance from the analyzer cleanup to keep chat-triggered booking handover stable in real browser sessions.

= 1.4.1 =
* Added configurable public rate limiting for anonymous chat endpoints (send, fetch, booking-event).
* Added settings UI for rate-limit window and per-action request limits.

= 1.4.0 =
* Support-Posteingangs-Aktion "Open Booking Overlay at Client" hinzugefuegt, wenn Booking Assistant verfuegbar ist.
* Buchungsereignis-Nachrichten fuer Besucher-bestaetigte und Besucher-abgebrochene Buchungsablaeufe hinzugefuegt.
* Visuelle Badges und Schnellfilter fuer Buchungs-/Systemereignisse im Support-Posteingang hinzugefuegt.
* Einmalige Behandlung des Booking-Triggers im Frontend hinzugefuegt, um wiederholte Auto-Open-Schleifen zu vermeiden.
* Kompatibilitaets-Guard hinzugefuegt, damit die Booking-Aktion ausgeblendet wird, wenn Booking Assistant nicht aktiv ist.

= 1.3.0 =
* Admin-Settings-UX mit klarerer Grundfuehrung und aufklappbaren Expertensektionen verbessert.
* Verbindliches Verhalten fuer Support-E-Mail-Feld in integrierten Chat-Workflows hinzugefuegt.
* Validierungs-Fallback hinzugefuegt: integrierter Chat faellt bei leerer Support-E-Mail automatisch auf Admin-E-Mail zurueck.
* Validierungs-Fallback hinzugefuegt: KI-Autoantwort wird bei fehlendem API-Key automatisch deaktiviert.
* Legacy-Dead-Code fuer deprecated Chat-Reset-Hour-Option entfernt.

= 1.2.1 =
* Reproduzierbaren Release-ZIP-Packaging-Workflow hinzugefuegt.
* Installationshinweise fuer externe WordPress-Umgebungen hinzugefuegt.

= 1.2.0 =
* Nativen Website-Chat im Overlay hinzugefuegt.
* Support-Posteingang in den Admin-Einstellungen hinzugefuegt.
* Support-E-Mail-Benachrichtigungen mit direktem Unterhaltungs-Link hinzugefuegt.
* Optionale KI-Autoantwort-Konfiguration hinzugefuegt.
* Overlay-Rendering-Logik fuer Chat-Only-Modus verbessert.

= 1.1.0 =
* Multi-Channel-Floating-Overlay mit Auto-Open-Verzoegerung und Dismiss-Speicher hinzugefuegt.

== Upgrade Notice ==

= 2.1.2 =
Adds the private WordPress bridge required when the public Support API and WordPress run on separate servers.

= 2.1.1 =
Maintenance update for current router/runtime consolidation and release metadata sync, including explicit EU AI ACT support for compliant trigger confirmations.

= 2.1.0 =
Adds realtime WebSocket updates and AI legal-notice controls; includes support API key management hardening.

= 2.0.10 =
Maintenance release with shared-loader stabilization and dual-session runtime hardening.

= 2.0.9 =
Improves mobile full-screen chat usability and fixes overlap/clipping issues in landscape mode.

= 2.0.5 =
UI-only update that renames the settings menu/page title to AI Multichat.

= 2.0.4 =
Auto-recovers from legacy coexistence by removing old plugin activation entries and preventing class redeclare fatals.

= 2.0.3 =
Fixes legacy mixed-plugin collisions by removing bootstrap aliases that could poison old loader include paths.

= 2.0.2 =
Fixes activation failures caused by legacy plugin constant collisions in mixed/legacy environments.

= 2.0.1 =
Hotfix release that prevents activation fatals caused by missing migration helper includes in inconsistent deployments.

= 1.4.3 =
Adds LightStart-aware maintenance suppression for the chat overlay with a configurable admin toggle.

= 1.4.2 =
Prevents repeated booking popup auto-opens from already processed support messages in the same browser session.

= 1.4.1 =
Adds configurable abuse protection for public chat AJAX endpoints.

= 1.4.0 =
Enthaelt optionale Booking-Assistant-Handover-Aktionen im Support-Posteingang und Verbesserungen der Sichtbarkeit von Buchungsereignissen.
Keine Aktion erforderlich, wenn Booking Assistant nicht installiert ist.

= 1.3.0 =
Enthaelt Verbesserungen der Admin-UX und strengere Validierungs-Defaults fuer Support-E-Mail- und KI-Einstellungen.

= 1.2.1 =
Enthaelt Packaging-Workflow fuer Releases und verbesserte Installationsanleitung.

= 1.2.0 =
Enthaelt integrierten Website-Chat, Support-Posteingang, E-Mail-Benachrichtigungen und optionale KI-Autoantwort.
Bitte Einstellungen nach dem Upgrade pruefen.



