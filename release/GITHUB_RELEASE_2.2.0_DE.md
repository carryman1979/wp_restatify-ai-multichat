## Restatify AI Multichat 2.2.0

- Besucher-Live-Updates laufen ueber einen Same-Origin-WebSocket-Pfad; interne Support-API-Adressen werden nicht mehr an Browser ausgeliefert.
- AJAX-Polling bleibt als Fallback aktiv, falls der WebSocket-Reverse-Proxy nicht erreichbar ist.
- Fragen zu den unterstuetzten Sprachen der Assistenz werden als General-Chat behandelt.

Hinweis: Fuer Echtzeit-Updates muss der Webserver `/restatify-support/ws/visitor-updates` zur privaten Support-API weiterleiten.