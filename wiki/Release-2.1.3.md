# Release 2.1.3

Release date: 2026-07-22

## Summary

- Added editable Support API endpoint and private bridge key fields to the WordPress admin Support Chat page.
- Removed the need to set the bridge key exclusively through `wp-config.php` or WP-CLI for split-server production setups.

## Versioning

- Plugin version: 2.1.3
- readme.txt stable tag: 2.1.3

## Validation

- PHP lint passes for the admin runtime and chat runtime files.

## Notes

- The API server still needs its `.env.production` values and host resolution configured separately.