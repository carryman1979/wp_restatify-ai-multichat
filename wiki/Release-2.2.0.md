# Release 2.2.0

Release date: 2026-09-09

## Summary

- Visitor live updates now use a same-origin WebSocket path instead of exposing the Support API address to browsers.
- AJAX polling remains available when the WebSocket reverse proxy is unavailable.
- Questions about supported assistant languages are classified as general chat, not out of domain.

## Versioning

- Plugin version: 2.2.0
- readme.txt stable tag: 2.2.0

## Deployment note

- Configure the WordPress web server to proxy `/restatify-support/ws/visitor-updates` to the private Support API WebSocket endpoint.