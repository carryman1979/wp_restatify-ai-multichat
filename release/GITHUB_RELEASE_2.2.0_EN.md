## Restatify AI Multichat 2.2.0

- Visitor live updates use a same-origin WebSocket path, so internal Support API addresses are no longer sent to browsers.
- AJAX polling remains available when the WebSocket reverse proxy is unavailable.
- Questions about supported assistant languages are handled as general chat.

Note: Real-time updates require the web server to proxy `/restatify-support/ws/visitor-updates` to the private Support API.