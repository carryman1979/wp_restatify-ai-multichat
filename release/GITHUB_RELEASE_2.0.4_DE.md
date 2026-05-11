# Restatify Multi Chat Overlay 2.0.4

## Was ist neu

- Self-Healing-Guard fuer gemischte Legacy-Umgebungen hinzugefuegt
- Wenn `wp_restatify-multi-chat-overlay` noch aktiv ist, entfernt AI Multichat den Legacy-Aktivierungseintrag automatisch
- AI Multichat ueberspringt den Bootstrap in der aktuellen Anfrage, um Class-Redeclare-Abstuerze zu verhindern

## Warum

Auf einigen Live-Systemen waren alte und neue Plugin-Generation gleichzeitig aktiv, was zu `Cannot redeclare class Restatify_Ai_Multichat_Plugin`-Fatals fuehrte. Dieses Release stellt aus diesem Zustand automatisch wieder her.

## Kompatibilitaet

- Plugin-Version: `2.0.4`
- WordPress getestet bis `6.9`
- Keine manuellen Migrationsschritte fuer Einstellungen erforderlich

## Artefakt

- `wp_restatify-ai-multichat-2.0.4.zip`
