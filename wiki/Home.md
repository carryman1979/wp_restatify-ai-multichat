# Restatify Multi Chat Overlay

Das Restatify Multi Chat Overlay ist ein schwebendes Multi-Channel-Chat-Plugin für WordPress mit integriertem Website-Chat, Support-Posteingang und optionalen KI-Antworten.

Es kombiniert mehrere Kontaktkanäle mit einem nativen Chat-Flow und stellt für interne Teams einen eigenen Support-Eingang im WordPress-Backend bereit.

## Kernfunktionen

- Schwebendes Chat-Overlay im Frontend
- Mehrere externe Kontaktkanäle
- Integrierter Website-Chat
- Support-Posteingang im WordPress-Backend
- Optionale KI-Autoantwort
- Öffnungslogik mit Cookie-Consent-Prüfung
- Öffentliches Rate-Limiting
- Debug-Unterstützung für KI-Anfragen

## Architektur

Das Plugin ist als Hauptklasse mit mehreren Traits aufgebaut. Diese Traits bündeln die Bereiche:

- Optionen
- Chat-Logik
- KI-Logik
- Rendering und Admin-Ausgabe

Diese Struktur hält die Plugin-Klasse kompakt, verlangt aber saubere Referenzen auf gemeinsam genutzte Konstanten und Plugin-Metadaten.

## Ziel der aktuellen Wartung

Die jüngsten Änderungen fokussieren sich nicht auf neue Endnutzer-Funktionen, sondern auf bessere Wartbarkeit und bessere Kompatibilität mit Entwicklungswerkzeugen.

Insbesondere wurde die interne Struktur so angepasst, dass Analyzer und IDEs gemeinsame Plugin-Konstanten in den Traits sauber auflösen können.

## Nutzen für die Entwicklung

Durch die Wartungsanpassungen wird der Code für folgende Szenarien robuster:

- statische Analyse
- Intelephense-Prüfung
- Navigation im Editor
- Fehlersuche in Traits
- langfristige Wartung des Plugins