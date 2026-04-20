Title: Restatify Multi Chat Overlay 1.4.2

## Highlights

- Maintenance release focused on improving developer and IDE compatibility in the plugin codebase
- Shared plugin constants are now referenced explicitly through the main plugin class inside trait files
- This resolves Intelephense/analyzer false positives about undefined class constants in the traits

## Compatibility

- Plugin version: `1.4.2`
- No intended frontend or support inbox behavior changes

## Validation

- The plugin folder was rechecked for editor-reported problems after the constant reference update
- Result: no reported issues in the changed PHP files

## Release Note

- This is an internal maintenance update with no deliberate end-user facing behavior change