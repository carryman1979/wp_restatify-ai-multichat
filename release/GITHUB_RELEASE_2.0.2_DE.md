# Restatify Multi Chat Overlay 2.0.2

## Was ist neu

- Aktivierungs-/Runtime-Fehler durch Legacy-Konstanten-Kollisionen behoben
- Interne Pfad-/URL-Aufloesung im Bootstrap und Runtime auf dedizierte AI-Multichat-Konstanten umgestellt
- Include-/Template-/Asset-Ladevorgaenge sind jetzt von alten Plugin-Slugs isoliert

## Warum

In gemischten oder Legacy-Umgebungen konnten global gleich benannte Konstanten bereits durch alte Plugin-Slugs gesetzt sein und Includes auf den falschen Ordner umbiegen. Dieses Release haertet die Pfadauflosung dagegen ab.

## Kompatibilitaet

- Plugin-Version: `2.0.2`
- WordPress getestet bis `6.9`
- Keine manuellen Migrationsschritte fuer Einstellungen erforderlich

## Artefakt

- `wp_restatify-ai-multichat-2.0.2.zip`
