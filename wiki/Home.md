# Restatify Multi Chat Overlay - Wiki (DE)

Produktname (ab 2.0.0): Restatify-AI-Multichat  
WordPress-Slug (ab 2.0.0): wp_restatify-ai-multichat  
Website: https://www.restatify.tech

Stand: Version 1.4.x, getestet bis WordPress 6.9.

Diese Seite ist als zentrale One-Page-Dokumentation für Einrichtung, Betrieb, Support und Release gedacht.

## Inhaltsverzeichnis

- Zweck und Funktionsumfang
- Schnellstart Installation
- Basis-Konfiguration
- Integrierter Website-Chat
- Support-Inbox
- KI-Auto-Reply
- Polylang und Mehrsprachigkeit
- Troubleshooting
- Datenschutz und Sicherheit
- Release-Workflow
- Verlinkte Projektdokumente

---

## Zweck und Funktionsumfang

Restatify Multi Chat Overlay stellt ein schwebendes Chat-Overlay bereit und unterstützt:

- Mehrere externe Chat-Kanäle wie WhatsApp, Telegram, Messenger oder Discord
- Optionalen nativen Website-Chat
- Support-Inbox im WordPress-Admin
- E-Mail-Benachrichtigung bei neuen Besucher-Nachrichten
- Optionale KI-First-Level-Antworten
- Polylang-Integration für übersetzbare Texte
- Consent-abhängige Anzeige und Auto-Open-Logik
- Wartbare interne Struktur für Support und Weiterentwicklung

---

## Schnellstart Installation

### Option A: Standardinstallation in WordPress

1. Plugin-ZIP in WordPress hochladen.
2. Plugin aktivieren.
3. Zu Einstellungen > Multi Chat Overlay wechseln.
4. Overlay aktivieren.
5. Mindestens einen Kanal setzen oder integrierten Chat aktivieren.
6. Speichern.

### Option B: Release-ZIP lokal erzeugen

1. PowerShell im Plugin-Ordner öffnen.
2. Skript ausführen:

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -File ./scripts/create-release-zip.ps1
```

3. Das Ergebnis liegt im Ordner `release`.
4. ZIP in externer WordPress-Instanz hochladen.

---

## Basis-Konfiguration

Empfohlene Minimal-Konfiguration:

1. Enable overlay aktivieren.
2. Team title und Intro message setzen.
3. Auto-open delay sinnvoll wählen.
4. Mindestens einen Kanal-Link pflegen.
5. Optional Consent-Regeln setzen, falls Cookie-Consent erzwungen wird.

### Consent cookie rules

Format:

- `cookie_name`
- `cookie_name=expected_value`

Beispiel:

- `restatify_cookie_consent=accepted`
- `cookiesDirective`
- `_cky-consent=accept`

---

## Integrierter Website-Chat

1. Enable built-in website chat aktivieren.
2. Chat title, Placeholder und Send label definieren.
3. Refresh interval festlegen, zum Beispiel 8 Sekunden.
4. Speichern und Frontend testen.

Der integrierte Chat eignet sich für Erstkontakt, Lead-Erfassung und einfache Support-Anfragen direkt auf der Website.

---

## Support-Inbox

Ablauf für Support-Mitarbeiter:

1. Menü Support Chat öffnen.
2. Konversation auswählen.
3. Verlauf prüfen.
4. Antwort senden.
5. Ergebnis im Frontend validieren.

Empfohlene Reaktionszeiten:

- Erste Antwort innerhalb von 10 Minuten während der Support-Zeit
- Follow-up innerhalb von 30 Minuten
- Eskalation innerhalb von 20 Minuten nach Erkennung kritischer Fälle

---

## KI-Auto-Reply

1. Enable AI auto reply aktivieren.
2. API key setzen.
3. API endpoint konfigurieren.
4. Modell konfigurieren.
5. System prompt an Marke und Sprache anpassen.

Unterstützte Provider via Endpoint-Erkennung:

- OpenAI
- Gemini
- Mistral
- DeepSeek
- Llama- oder Ollama-kompatible Endpunkte

Empfehlung:

- KI als First-Level nutzen
- Kritische Antworten menschlich freigeben
- Keine sensiblen Daten in Prompts oder Antworten hinterlegen

---

## Polylang und Mehrsprachigkeit

Bei aktivem Polylang registriert das Plugin Übersetzungsstrings in der Gruppe Restatify Multi Chat Overlay.

Typische Felder:

- Team title
- Intro message
- Channel heading
- Button accessibility label
- Chat title
- Chat placeholder
- Send button label
- AI system prompt

---

## Troubleshooting

### Overlay erscheint nicht

Prüfen:

- Overlay ist aktiviert
- Mindestens ein Kanal gesetzt oder integrierter Chat aktiv
- Bei aktivem Consent matchen die Cookie-Regeln korrekt

### Keine Support-E-Mail

Prüfen:

- Support email address ist gesetzt
- Send email on new message ist aktiviert
- Mailversand der WordPress-Instanz funktioniert

### KI antwortet nicht

Prüfen:

- API key vorhanden
- Endpoint korrekt
- Modell korrekt
- KI-Modus nicht ausgeschaltet

### Deep-Link aus E-Mail funktioniert nicht

Prüfen:

- Konversation existiert noch
- Admin ist eingeloggt
- URL führt auf dieselbe Instanz oder Domain

---

## Datenschutz und Sicherheit

- Chat-Nachrichten werden bei aktivem Website-Chat gespeichert
- Bei Mail-Benachrichtigung werden Inhalte per E-Mail versendet
- Bei KI-Nutzung werden Inhalte an den konfigurierten Endpoint übertragen
- Die aktuelle Plugin-Wartung verbessert zusätzlich die IDE- und Analyzer-Kompatibilität der internen Trait-Struktur

To-do für Betreiber:

- Datenschutzerklärung entsprechend aktualisieren
- Zugriff auf Support-Inbox rollenbasiert absichern
- Keine Secrets in Logs, Screenshots oder Support-Antworten veröffentlichen

---

## Release-Workflow

1. Versionsstand im Plugin-Header prüfen.
2. Stable tag in Readme prüfen.
3. Release-Checkliste durchgehen.
4. Release-ZIP erzeugen.
5. Smoke-Test auf externer Testinstanz durchführen.
6. Commit, Tag und Push ausführen.
7. GitHub Release Notes pflegen.

---

## Verlinkte Projektdokumente

- EN Readme
- DE Readme
- Support Playbook (DE)
- Release Checkliste (DE)
- Release Checklist (EN)