# Release 2.0.0

Version 2.0.0 liefert ein internes Runtime-Refactoring, ein neues Plugin-Slugging und ein aktualisiertes Release-Artefakt.

## Highlights

- Neues WordPress-Slug/Folder naming: `wp_restatify-ai-multichat`
- Plugin-Version auf `2.0.0` angehoben
- Runtime-Monolith in klar getrennte Klassen aufgeteilt:
  - `Options Runtime`
  - `Chat Runtime`
  - `Admin Runtime`
- UI-Teile aus der Hauptklasse in Templates ausgelagert

## Technische Einordnung

Die bisherige Laufzeitklasse wurde in kleinere, wartbare Einheiten getrennt. Dadurch sind künftige Erweiterungen in den Bereichen Optionen, Chat-Transport und Admin-UI sauber separiert.

Zusatznutzen:

- Bessere Lesbarkeit bei Debugging und Reviews
- Klarere Verantwortlichkeiten in der Klassenhierarchie
- Grundlage fuer gezieltere Tests einzelner Teilbereiche

## Dokumentation und Betrieb

- `README.de.md` auf `2.0.0` aktualisiert
- `readme.txt` Stable Tag auf `2.0.0` gesetzt
- Security-Backlog als `wiki/Security-TODO.md` dokumentiert (offen, nicht release-blockierend)

## Kompatibilitaet

- Plugin-Version: `2.0.0`
- Getestet bis WordPress 6.9
- Keine aenderungspflichtigen Einstellungen fuer bestehende Installationen

## Artefakt

- `wp_restatify-ai-multichat-2.0.0.zip`
