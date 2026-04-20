Title: Restatify Multi Chat Overlay 1.4.2

## Highlights

- Maintenance release focused on improving developer and IDE compatibility in the plugin codebase
- Shared plugin constants are now referenced explicitly through the main plugin class inside trait files
- This resolves Intelephense/analyzer false positives about undefined class constants in the traits
- Frontend booking-open triggers are now persisted per browser session so old support messages do not reopen the booking popup after reloads

## Compatibility

- Plugin version: `1.4.2`
- No breaking UI or admin workflow changes; only stale booking-trigger reopens are suppressed

## Validation

- The plugin folder was rechecked for editor-reported problems after the constant reference update
- Result: no reported issues in the changed PHP files

## Release Note

- This is a maintenance update with one small frontend stability fix for booking handover flows from the support chat