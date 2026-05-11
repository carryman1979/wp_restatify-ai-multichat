# Restatify Multi Chat Overlay 2.0.3

## Was ist neu

- Legacy-`RESTATIFY_MCO_*`-Alias-Konstanten aus dem AI-Multichat-Bootstrap entfernt
- Verhindert plugin-uebergreifendes "Constant Poisoning" in gemischten Legacy-Umgebungen
- Behebt Aktivierungs-/Runtime-Fatals, bei denen Legacy-Loader Includes auf den falschen Plugin-Ordner aufloesten

## Warum

Wenn alte und neue Plugin-Generation parallel vorhanden waren, konnten global wiederverwendete Konstantennamen zwischen Plugins auslaufen und `require_once`-Pfade umbiegen. Dieses Release entfernt diese Bootstrap-Aliase vollstaendig.

## Kompatibilitaet

- Plugin-Version: `2.0.3`
- WordPress getestet bis `6.9`
- Keine manuellen Migrationsschritte fuer Einstellungen erforderlich

## Artefakt

- `wp_restatify-ai-multichat-2.0.3.zip`
