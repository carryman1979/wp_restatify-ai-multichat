# Release-Checkliste (DE)

Diese Checkliste vor jedem Produktiv-Deployment durchgehen.

## 1) Paket und Version

1. Plugin-Header-Version in restatify-multi-chat-overlay.php pruefen.
2. Stable tag in readme.txt mit der Plugin-Version abgleichen.
3. Sicherstellen, dass kein lokaler Debug-/Testcode enthalten ist.
4. Sicherstellen, dass nur notwendige Dateien im Release-Paket enthalten sind.

## 2) Funktionale Smoke-Tests

1. Frontend-Overlay oeffnet und schliesst korrekt (X, Klick ausserhalb, FAB-Toggle).
2. Cookie-Consent-Gating funktioniert mit den konfigurierten Cookie-Regeln.
3. Integrierter Chat funktioniert fuer Senden/Empfangen (Besucher und Support).
4. Lange Konversationen halten Eingabefeld sichtbar und bedienbar.
5. Reset-Intervall verhaelt sich nach Inaktivitaet korrekt.

## 3) Support-Inbox

1. Menuepunkt Support Chat ist fuer vorgesehene Rollen/Capabilities sichtbar.
2. Konversation aus der Liste laesst sich oeffnen.
3. Delete in der Liste entfernt den Eintrag direkt.
4. Delete this conversation in der Detailansicht funktioniert.
5. KI-Modus pro Konversation wird gespeichert und angewendet.

## 4) Benachrichtigungen und Links

1. Support-E-Mail wird bei neuer Besucher-Nachricht versendet.
2. Deep-Link in der E-Mail oeffnet die korrekte Konversation im Admin.
3. Link-Verhalten ist robust, auch wenn die Konversation bereits abgelaufen/geloescht ist.

## 5) KI-Integration

1. Endpoint und Modell sind konfiguriert.
2. KI-Antwortmodus funktioniert erwartungsgemaess (off, visitor, support, both).
3. Wenn KI-Debug aktiv ist, sind Logs vorhanden und enthalten keine Geheimnisse.

## 6) Mehrsprachigkeit und Inhalte

1. Polylang-Strings sind registriert und bei Bedarf uebersetzt.
2. UI-Labels (Weiter/Weniger, Chat-Labels, Prompts) stimmen pro Sprache.
3. Dokumentationsdateien sind auf dem aktuellen Funktionsstand.

## 7) Theme-Unabhaengigkeit

1. Kanal-Icons rendern mit Theme-Fonts, falls verfuegbar.
2. Fallback-Icon-Buchstaben rendern korrekt, wenn Icon-Fonts fehlen.

## 8) Finaler Rollout

1. Plugin-Paket auf Zielserver deployen.
2. Plugin aktivieren/aktualisieren und Einstellungen einmal speichern.
3. Kurzen Smoke-Test auf Desktop und Mobile durchfuehren.
4. Support-Inbox und Error-Logs in den ersten 24h beobachten.
