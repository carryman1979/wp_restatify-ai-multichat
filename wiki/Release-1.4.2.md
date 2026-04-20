# Release 1.4.2

Version 1.4.2 ist ein technisches Wartungsrelease ohne beabsichtigte Änderungen am Frontend oder am Support-Posteingang.

## Highlights

- Wartungsrelease zur Verbesserung der Entwickler- und IDE-Kompatibilität im Plugin-Code
- Gemeinsame Plugin-Konstanten werden in den Trait-Dateien jetzt explizit über die Hauptklasse referenziert
- Intelephense- und Analyzer-Fehlalarme zu angeblich undefinierten Klassenkonstanten in den Traits wurden dadurch beseitigt

## Technische Einordnung

Zuvor wurden gemeinsame Konstanten in mehreren Trait-Dateien implizit referenziert. Das funktionierte zur Laufzeit, führte aber in der Editor-Analyse zu einer großen Zahl von Fehlalarmen.

Die Wartungsanpassung stellt die Referenzen auf eine analyzer-freundliche Form um und reduziert dadurch Rauschen in der statischen Codeanalyse erheblich.

## Kompatibilität

- Plugin-Version: 1.4.2
- Keine beabsichtigten funktionalen Änderungen am Frontend oder Support-Posteingang

## Validierung

- Der Plugin-Ordner wurde nach der Umstellung erneut gegen Editorfehler geprüft
- Ergebnis: keine gemeldeten Probleme in den geänderten PHP-Dateien

## Fazit

Release 1.4.2 ist ein internes Wartungsupdate. Für Endnutzer soll sich das Verhalten nicht ändern, für die Entwicklung wird das Plugin aber deutlich sauberer analysierbar.