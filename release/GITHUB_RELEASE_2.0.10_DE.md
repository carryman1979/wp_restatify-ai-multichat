# Restatify AI Multichat 2.0.10

## Neu in diesem Release

- Shared-Resolver priorisiert lokales Root-Shared (`wp_restatify-shared/src/*`) fuer lokale Entwicklungsumgebungen.
- Wenn Root-Shared nicht verfuegbar ist, wird exakt die benoetigte Shared-Version aus `wp-content/plugins/wp_restatify-shared/versions/<x.y.z>/` (oder MU-Plugins) geladen.
- Mischbetrieb zwischen Root-Shared und versioniertem Shared in derselben Anfrage wird verhindert.
- Shared-Dateien werden nur geladen, wenn die Zielklasse noch nicht vorhanden ist (Redeclare-Schutz fuer `Restatify\\Shared\\*`).
- Copilot-Release-Notizen und Shared-Loader-Regeln wurden repo-uebergreifend synchronisiert.

## Kompatibilitaet

- Plugin-Version: `2.0.10`
- Getestet bis WordPress `6.9`
- Keine Migration erforderlich.

## Artefakt

- `wp_restatify-ai-multichat-2.0.10.zip`
