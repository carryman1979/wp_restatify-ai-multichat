# Migration 2.0.0 (Rename + Shared)

## Zielbild

- Produktname: Restatify-AI-Multichat
- Neuer Plugin-Slug: wp_restatify-ai-multichat
- Alt-Slug: wp_restatify-multi-chat-overlay
- Website: https://www.restatify.tech
- Shared-Package: wp_restatify-shared (public, GPL-2.0-or-later)

## Release-Entscheidungen

- Major-Release: 2.0.0
- Migration nur fuer Einstellungen
- Logs/Historie werden nicht migriert
- Migrationsdialog direkt nach erfolgreicher Migration
- Default-Auswahl im Dialog: Alte Plugins behalten
- Dialog-Sprachen: DE/EN (Default DE)

## Technische Migrationsregeln

1. Beim ersten Start von wp_restatify-ai-multichat pruefen, ob Altplugin-Daten vorhanden sind.
2. Einstellungen aus restatify_multi_chat_overlay_options in neuen Ziel-Key uebernehmen.
3. Backup der Quelldaten vor dem Schreiben anlegen.
4. Migration idempotent gestalten.
5. Migrationsstatus in eigener Option speichern.

## Nach erfolgreicher Migration

Admin-Hinweis mit Aktionen:

- Alte Plugins behalten (Default)
- Alte Plugins deaktivieren und entfernen

Warnhinweis:

- Logs/Historie werden nicht migriert und koennen beim Entfernen verfallen.

## Shared-Package-Regel

- Plugins teilen Shared-Code nur bei exakt gleicher Shared-Version.
- Bei Versionsabweichung laedt jedes Plugin seine eigene kompatible Shared-Version.

## Shared-Aufloesung (Laufzeit)

1. Zuerst pruefen, ob lokales Root-Shared ohne Versionsordner vorhanden ist: `.../wp_restatify-shared/src/php/*`.
2. Wenn vorhanden, dieses Root-Shared als `latest` fuer lokale Entwicklung verwenden (z. B. Laragon).
3. Wenn nicht vorhanden, nur die benoetigte Release-Version unter `wp-content/plugins/wp_restatify-shared/versions/<x.y.z>/` (bzw. `mu-plugins`) laden.
4. Keine Mischung aus Root-Shared und versioniertem Plugin-Shared in derselben Anfrage.

## Shared-Installation und Aufraeumen

1. Bei Installation/Update wird die vom Plugin benoetigte Shared-Version unter `plugins/wp_restatify-shared/versions/<x.y.z>/` abgelegt.
2. Laufzeitreferenzen zeigen nur auf die benoetigte Version.
3. Versionen ohne Referenzen durch Plugins/Themes koennen aufgeraeumt werden.

## Operator-Checkliste

1. Vor Update Datenbank-Backup erstellen.
2. Update auf 2.0.0 einspielen.
3. Overlay, Support-Inbox und Mailversand pruefen.
4. Entscheidung im Migrationsdialog treffen.
