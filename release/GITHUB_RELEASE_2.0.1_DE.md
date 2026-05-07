# Restatify Multi Chat Overlay 2.0.1

## Was ist neu

- Hotfix fuer einen Aktivierungs-Fatal bei inkonsistenten Deployments
- Defensive Absicherung beim Laden von `class-restatify-shared-migration-notice-manager.php`
- Aktivierung bleibt funktionsfaehig, auch wenn die Migrations-Hilfsklasse fehlt

## Warum

Auf Live-Systemen konnte in seltenen Faellen ein unvollstaendiges/inkonsistentes Plugin-Update zu einem Fatal Error bei der Aktivierung fuehren. Dieses Release macht den Bootstrap robust gegen genau diesen Zustand.

## Kompatibilitaet

- Plugin-Version: `2.0.1`
- WordPress getestet bis `6.9`
- Keine manuellen Migrationsschritte fuer Einstellungen erforderlich

## Artefakt

- `wp_restatify-ai-multichat-2.0.1.zip`
