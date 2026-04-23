# Release 1.4.3

Version 1.4.3 erweitert das Multi Chat Overlay um eine direkte LightStart-Wartungsmodus-Integration.

## Highlights

- Neue Option in den Plugin-Einstellungen: `Bei LightStart-Wartung ausblenden`
- Standardwert ist aktiv (AN)
- Option wird nur angezeigt, wenn LightStart (`wp-maintenance-mode`) installiert und aktiv ist
- Overlay wird bei aktivem LightStart-Wartungsmodus und aktivierter Option nicht gerendert

## Technische Einordnung

Die Render-Entscheidung wurde um zwei zentrale Pruefungen erweitert:

- Verfuegbarkeit/Aktivierung von LightStart (Single-Site und Multisite-Netzwerkaktivierung)
- Aktiver Wartungsstatus aus `wpmm_settings.general.status`

Nur wenn beide Bedingungen erfuellt sind und die Option aktiv ist, wird das Overlay unterdrueckt.

## Dokumentation

- Deutsche Benutzerdoku erweitert
- WordPress readme aktualisiert (Stable tag, Changelog, Upgrade Notice)
- Quellcode-Doku fuer die neuen LightStart-Helfermethoden ergaenzt

## Kompatibilitaet

- Plugin-Version: 1.4.3
- Keine Breaking Changes fuer bestehende Chat-/Support-Optionen

## Fazit

Release 1.4.3 sorgt dafuer, dass sich das Overlay im Wartungsbetrieb kontrolliert verhaelt und die Wartungsseite nicht durch Chat-UI ueberlagert wird, sofern dies gewuenscht ist.
