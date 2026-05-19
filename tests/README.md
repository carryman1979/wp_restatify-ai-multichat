# Tests für wp_restatify-ai-multichat

Alle Unit- und Integrationstests für das Plugin liegen in diesem Ordner.

## Testausführung

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --testdox
# oder via Composer-Skript:
composer run test:unit:php
```

## Testdateien

| Datei | Beschreibung |
|---|---|
| `AiMultichatRuntimeTest.php` | Smoke-Tests für den Plugin-Bootstrap, Optionen-Sanitisierung und AI-Reply-Logik |
| `DualSessionRouterUnitTest.php` | Unit-Tests für `Restatify_Ai_Dual_Session_Router`: Routing-Entscheidungen, Fast-Track, erzwungene Konvertierung nach 20 Turns, Konversationsformat-Normalisierung |
| `SlotManagerUnitTest.php` | Unit-Tests für `Restatify_Ai_Dual_Session_Slot_Manager`: Slot-Matching (exakt/Alternativen), Anzeigeformatierung |
| `StateAndCooldownManagerTest.php` | Unit-Tests für `Restatify_Ai_Dual_Session_State_Machine` (Standardzustand, Turn-Zähler) und `Restatify_Ai_Dual_Session_Cooldown_Manager` (IP-Cooldown aufzeichnen/löschen) |
| `RouterDebugLoggerUnitTest.php` | Unit-Tests für `Restatify_Ai_Router_Debug_Logger`: Log-Einträge persistieren, nach Typ filtern, Log leeren |

## Integrationstest (kein PHPUnit)

`integration/class-restatify-ai-router-integration-test.php` enthält einen schnellen Smoke-Test, der im laufenden WordPress ausgeführt werden kann (kein CLI-PHPUnit erforderlich).

## Szenarien

`scenarios/class-restatify-ai-router-test-scenarios.php` enthält vollständige Gesprächsszenarien zum manuellen Testen des Routers end-to-end.
