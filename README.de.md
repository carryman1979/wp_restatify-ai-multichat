# Restatify Multi Chat Overlay - Anleitung (DE)

Diese Anleitung erklaert die Einrichtung des Plugins in WordPress sowie den Support-Ablauf.

## 1) Plugin aktivieren

1. WordPress Admin oeffnen.
2. Zu Plugins gehen.
3. Restatify Multi Chat Overlay aktivieren.
4. Zu Einstellungen > Multi Chat Overlay wechseln.

## 2) Grundkonfiguration

1. Enable overlay aktivieren.
2. Team title und Intro message setzen.
3. Auto-open delay konfigurieren.
4. Mindestens einen Kanal-Link eintragen (WhatsApp, Telegram, usw.) oder den integrierten Chat aktivieren.

Hinweis: Das Overlay wird angezeigt, wenn es aktiviert ist und entweder
- mindestens ein externer Kanal gesetzt ist, oder
- der integrierte Website-Chat aktiv ist.

## 3) Integrierten Website-Chat aktivieren

1. Enable built-in website chat aktivieren.
2. Chat title, Placeholder und Send button label setzen.
3. Refresh interval definieren (z. B. 8 Sekunden).

Der Besucher kann danach direkt im Overlay schreiben.

## 4) Support-Mail einrichten

1. Support email address eintragen.
2. Send email on new message aktivieren.
3. Speichern.

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

## 7) Datenschutz und Betrieb

Wenn der Website-Chat aktiv ist, werden Nachrichten in WordPress gespeichert.
Wenn Mail-Benachrichtigung aktiv ist, werden Inhalte per E-Mail versendet.
Wenn KI aktiv ist, werden Chat-Inhalte an den konfigurierten KI-Endpunkt uebertragen.

Bitte Datenschutzerklaerung entsprechend aktualisieren.

## 8) Polylang (Mehrsprachigkeit)

Wenn Polylang aktiv ist, registriert das Plugin die konfigurierbaren Chat-Texte automatisch in der Gruppe "Restatify Multi Chat Overlay".

Pfad in WordPress:

1. Sprachen > Uebersetzungen
2. Gruppe "Restatify Multi Chat Overlay" filtern
3. Texte je Sprache uebersetzen

Typische Felder:
- Team title
- Intro message
- Chat title
- Placeholder
- Senden-Label
- AI System Prompt

## 9) Typischer Go-Live-Check

1. Test-Nachricht als Besucher senden.
2. Pruefen, ob E-Mail ankommt.
3. Link aus E-Mail oeffnen.
4. Support-Antwort schicken.
5. Frontend aktualisieren und Antwort pruefen.
6. Optional KI-Antwort pruefen.
