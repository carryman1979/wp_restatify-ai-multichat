# Release 2.1.2

Release date: 2026-07-22

## Summary

- Added a private WordPress bridge endpoint for the public Support API.
- Enabled production setups where WordPress and Support API run on two separate servers with their own public IPs and FQDNs.
- Kept booking trigger, support reply, AI mode, delete, API key and conversation token operations on the WordPress source of truth while crossing the new server boundary.

## Versioning

- Plugin version: 2.1.2
- readme.txt stable tag: 2.1.2

## Validation

- PHP lint passes for the plugin bootstrap and chat runtime files.
- Support API bridge regression tests pass on the API side.

## Notes

- The Support API may stay public for apps; the WordPress bridge must remain private for server-to-server traffic.