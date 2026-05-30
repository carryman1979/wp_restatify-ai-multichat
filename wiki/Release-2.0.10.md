# Release 2.0.10

Version 2.0.10 stabilisiert die Shared-Aufloesung und behebt einen Klassenkonflikt bei parallelen Shared-Pfaden.

## Highlights

- Shared-Resolver priorisiert lokales Root-Shared (`wp_restatify-shared/src/*`) fuer lokale Entwicklungsumgebungen.
- Fallback laedt exakt die angeforderte Shared-Version aus `wp-content/plugins/wp_restatify-shared/versions/<x.y.z>/` (oder MU-Plugins).
- Mischbetrieb aus Root-Shared und versioniertem Shared innerhalb derselben Anfrage wird vermieden.
- Shared-Dateien werden symbolsicher geladen, sodass bereits vorhandene Klassen nicht erneut inkludiert werden (Redeclare-Schutz).
- Copilot-/Teamrichtlinien zur Shared-Loader-Reihenfolge klar dokumentiert.

## Release-prep refresh (2026-05-30)

- Kein Versionssprung: Release-Prep auf Basis `2.0.10`.
- Dual-Session-Router, Sprachsteuerung und Intent-Firewall fuer laufende Hotfix-Arbeiten nachgezogen.
- Mail-/Admin-Kontext in Runtime und Support-Inbox fuer stabile Handovers aktualisiert.

## Kompatibilitaet

- Plugin-Version: `2.0.10`
- Getestet bis WordPress `6.9`
- Keine Migration erforderlich

## Artefakt

- `wp_restatify-ai-multichat-2.0.10.zip`
