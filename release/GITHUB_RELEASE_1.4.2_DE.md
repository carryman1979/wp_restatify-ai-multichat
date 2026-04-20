Title: Restatify Multi Chat Overlay 1.4.2

## Highlights

- Wartungsrelease zur Verbesserung der Entwickler- und IDE-Kompatibilität im Plugin-Code
- Gemeinsame Plugin-Konstanten werden in den Trait-Dateien jetzt explizit über die Hauptklasse referenziert
- Intelephense-/Analyzer-Fehlalarme zu angeblich undefinierten Klassenkonstanten in den Traits wurden dadurch beseitigt
- Booking-Open-Trigger werden nun pro Browser-Session gemerkt, damit alte Support-Nachrichten das Buchungs-Popup nach Reloads nicht erneut oeffnen

## Kompatibilität

- Plugin-Version: `1.4.2`
- Keine Breaking Changes im Frontend oder Admin-Workflow; lediglich wiederholte Reopens durch alte Trigger werden unterdrueckt

## Validierung

- Der Plugin-Ordner wurde nach der Umstellung erneut gegen die Editorfehler geprüft
- Ergebnis: keine gemeldeten Probleme in den geänderten PHP-Dateien

## Release-Hinweis

- Dieses Wartungsupdate behebt zusaetzlich ein kleines Frontend-Stabilitaetsproblem bei Booking-Handover-Flows aus dem Support-Chat