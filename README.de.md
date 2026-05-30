# Restatify Multi Chat Overlay - Anleitung (DE)

Stand: Version 2.0.11, getestet bis WordPress 6.9.

Diese Anleitung erklaert die Einrichtung des Plugins in WordPress sowie den Support-Ablauf.

## Release-Prep Update (2026-05-30)

- Dokumentationsabgleich fuer den aktuellen 2.0.10-Hotfix-Stand ohne Versionssprung.
- Router-/Session-Logik fuer Intent, Sprachsteuerung und Session-1-Fallbacks weiter stabilisiert.
- Mail-/Admin-Kontext und Firewall-nahe Guardrails in Runtime und Support-Inbox nachgezogen.
- Zusatztests fuer Router-/Runtime-/Admin-Asset-Pfade erweitert.

## 1) Plugin aktivieren

1. WordPress Admin oeffnen.
2. Zu Plugins gehen.
3. Restatify Multi Chat Overlay aktivieren.
4. Zu Einstellungen > Multi Chat Overlay wechseln.

Fuer externe Installationen kann das Plugin auch als Release-ZIP installiert werden.

Release-ZIP erzeugen:

1. pwsh -NoProfile -ExecutionPolicy Bypass -File ./scripts/create-release-zip.ps1

Das ZIP liegt danach unter /release.

## 2) Grundkonfiguration

1. Enable overlay aktivieren.
2. Team title und Intro message setzen.
3. Auto-open delay konfigurieren.
4. Mindestens einen Kanal-Link eintragen (WhatsApp, Telegram, usw.) oder den integrierten Chat aktivieren.
5. Falls eure Website ein eigenes Cookie-Consent-System oder ein anderes Theme nutzt, im Feld Consent cookie rules die passenden Cookie-Regeln hinterlegen.

Hinweis zur neuen Einstellungsstruktur:

- Wichtige Basiswerte stehen direkt sichtbar oben.
- Optionale Bereiche sind als Expert-Settings einklappbar organisiert.

Das Overlay wird angezeigt, wenn es aktiviert ist und entweder:

- mindestens ein externer Kanal gesetzt ist, oder
- der integrierte Website-Chat aktiv ist.

### LightStart-Wartungsmodus

- In den Plugin-Einstellungen gibt es die Option `Bei LightStart-Wartung ausblenden`.
- Standardwert ist `AN`.
- Die Option wird nur angezeigt, wenn LightStart (`wp-maintenance-mode`) installiert und aktiviert ist.
- Ist LightStart nicht installiert oder nicht aktiv, erscheint die Option nicht und das Overlay bleibt sichtbar.
- Ist LightStart aktiv und Wartungsmodus eingeschaltet, wird das Overlay bei aktivierter Option automatisch nicht gerendert.

Hinweis zu Cookie-Consent:

- Wenn Require cookie consent aktiviert ist, rendert das Overlay erst nach erkannter Einwilligung.
- Bei fremden Themes oder eigener Consent-Logik kann es noetig sein, Consent cookie rules manuell zu pflegen.
- Format: cookie_name oder cookie_name=expected_value
- Beispiel: restatify_cookie_consent=accepted,cookiesDirective,_cky-consent=accept

## 3) Integrierten Website-Chat aktivieren

1. Enable built-in website chat aktivieren.
2. Chat title, Placeholder und Send button label setzen.
3. Refresh interval definieren (z. B. 8 Sekunden).

Der Besucher kann danach direkt im Overlay schreiben.

## 4) Support-Mail einrichten

1. Support email address eintragen.
2. Send email on new message aktivieren.
3. Speichern.

Wenn der integrierte Chat aktiv ist und keine Support-Mail gesetzt wird, setzt das Plugin die Admin-E-Mail als sicheren Fallback.

Bei jeder neuen Besucher-Nachricht wird eine Mail versendet mit:

- Conversation ID
- Source URL
- letzter Nachricht
- Direktlink zur Konversation in der Admin-Inbox

## 5) Support Inbox verwenden

1. Im WordPress-Admin den Menuepunkt Support Chat oeffnen.
2. Konversation in der Tabelle anklicken.
3. Verlauf lesen.
4. Support reply schreiben und senden.

Die Antwort wird im Frontend-Chat des Besuchers sichtbar.

Optional mit Booking Assistant:

- Wenn `WP Restatify Booking Assistant` aktiv ist, erscheint in der Konversation der Button `Open Booking Overlay at Client`.
- Support kann damit den Buchungsdialog beim Besucher oeffnen.
- Bestaetigung/Abbruch der Buchung werden als System-Ereignis im Verlauf quittiert.

## 6) Optionale KI-Antwort

1. Enable AI auto reply aktivieren.
2. API key eintragen.
3. API endpoint setzen (Standard: https://api.openai.com/v1/chat/completions).
4. Modell setzen (Standard: gpt-4o-mini).
5. System prompt auf eure Marke/Sprache anpassen.

Unterstuetzte Anbieter (automatisch per API-URL erkannt):

- ChatGPT/OpenAI
- Gemini
- Mistral
- DeepSeek
- Llama (inkl. Ollama-Endpoints)

Empfehlung:

- KI nur als First-Level einsetzen.
- Keine sensiblen Daten in Prompts oder Antworten schicken.
- Regelmaessig Antwortqualitaet pruefen.

## 6a) Dual-Session-KI-Router (ab 2.0.7)

Der integrierte Dual-Session-Router ermoeglicht automatische Termin-Intent-Erkennung im Chat:

**Session 1 – Terminbuchung:**
- Erkennt Terminbuchungsabsicht anhand von Konfidenzwerten (0.0–1.0).
- Sammelt strukturiert Pflichtfelder: Name, E-Mail, Kontaktkanal, Thema, Zeitslot.
- Holt verfuegbare Termine live aus dem Booking-Assistant-Plugin.
- Praesentsiert maximal 3 Slots; bietet Alternativen bei keinem exakten Treffer.
- Sperrt Sitzung nach Buchung (IP-Cooldown ~15 Minuten).

**Session 2 – Allgemeiner Chat:**
- Beantwortet allgemeine Fragen per konfiguriertem AI-Anbieter.
- Uebergibt nahtlos an Session 1, sobald Terminabsicht erkannt wird.

**Automatisches Routing:**
- Fast-Track bei eindeutigem Intent (Konfidenz ≥ 0.98).
- Rueckfragen bei mittlerem Intent (0.30–0.97), max. 2 Versuche.
- Erzwungene Konvertierung zu Session 1 nach 20 Besucher-Turns.
- Unterdrückung von Neu-Routing nach expliziter Ablehnung.

**Booking-Overlay mit Prefill:**
- Am Ende von Session 1 oeffnet das Plugin das Booking-Overlay des Booking Assistants.
- Erfasste Daten werden als Prefill an das Booking-Plugin uebergeben.
- Das Formular ist dann bereits mit Name, E-Mail, Kontaktkanal, Kontaktdaten sowie Zeit-/Slot-Hinweisen vorbelegt.
- Der Besucher muss nur noch pruefen, bei Bedarf ergaenzen und absenden.

**Voraussetzung:** WP Restatify Booking Assistant muss aktiv und mit der Booking-API verbunden sein.

**Live-Debug-Overlay (Admin):**
- In den Plugin-Einstellungen `Live Debug` aktivieren.
- Im Chat erscheint ein Debug-Panel mit aktuellem Session-Status, Konfidenzlevel und Log-Eintraegen.
- Optional fuer alle Benutzer freischaltbar (nicht fuer Produktion empfohlen).

## 7) FAQ

### Wo erscheinen eingehende Nachrichten?

Im WordPress-Menuepunkt Support Chat. Dort koennt ihr Konversationen oeffnen und direkt antworten.

### Ist KI zwingend notwendig?

Nein. KI-Antworten sind optional und standardmaessig deaktiviert.

### Warum wird das Overlay trotz Aktivierung nicht angezeigt?

Haeufige Ursachen:

- Kein Kanal-Link gesetzt und integrierter Chat deaktiviert.
- Require cookie consent ist aktiv, aber Consent cookie rules passen nicht zu eurem Consent-Cookie.

### Unterstuetzt das Plugin Polylang?

Ja. Konfigurierbare Chat-Texte werden in der Gruppe Restatify Multi Chat Overlay registriert.

## 8) Polylang (Mehrsprachigkeit)

Wenn Polylang aktiv ist, registriert das Plugin die konfigurierbaren Chat-Texte automatisch in der Gruppe Restatify Multi Chat Overlay.

Pfad in WordPress:

1. Sprachen > Uebersetzungen
2. Gruppe Restatify Multi Chat Overlay filtern
3. Texte je Sprache uebersetzen

Typische Felder:

- Team title
- Intro message
- Channel heading
- Button accessibility label
- Chat title
- Placeholder
- Send button label
- AI system prompt

## 9) Datenschutz und Betrieb

Wenn der Website-Chat aktiv ist, werden Nachrichten in WordPress gespeichert.
Wenn Mail-Benachrichtigung aktiv ist, werden Inhalte per E-Mail versendet.
Wenn KI aktiv ist, werden Chat-Inhalte an den konfigurierten KI-Endpunkt uebertragen.

Bitte Datenschutzerklaerung entsprechend aktualisieren.

## 10) Typischer Go-Live-Check

1. Test-Nachricht als Besucher senden.
2. Pruefen, ob E-Mail ankommt.
3. Link aus E-Mail oeffnen.
4. Support-Antwort schicken.
5. Frontend aktualisieren und Antwort pruefen.
6. Optional KI-Antwort pruefen.

## 11) Weitere Dokumente im Plugin-Ordner

- readme.txt (englische Standard-Readme)
- SUPPORT-PLAYBOOK.md
- RELEASE-CHECKLIST.de.md (deutsche Release-Checkliste)
- RELEASE-CHECKLIST.md


