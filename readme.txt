=== Restatify Multi Chat Overlay ===
Contributors: restatify
Tags: chat, support, whatsapp, telegram, messenger, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Floating multi-channel chat overlay with integrated website chat, support inbox, email notifications, and optional AI auto replies.

== Description ==

Restatify Multi Chat Overlay adds a floating chat button to the frontend and allows you to:

* Show popular messaging channels (WhatsApp, Telegram, Messenger, Discord, Signal, Viber, Threema, WeChat).
* Enable a native website chat directly inside the overlay.
* Send each new visitor message to a configurable support email address.
* Include a direct admin link in support emails to open the exact conversation.
* Reply from the WordPress admin support inbox.
* Optionally generate AI-based first-level responses.

This plugin is designed for small support teams that monitor email and need a quick handoff from mailbox to active chat.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate Restatify Multi Chat Overlay in Plugins.
3. Go to Settings -> Multi Chat Overlay.
4. Enable the overlay.
5. Configure at least one channel URL or enable the built-in website chat.
6. Add your support email address.
7. Save changes.

If "Require cookie consent" is enabled and your site uses a custom theme or custom CMP, add matching cookie rules in the plugin setting "Consent cookie rules".
Format: cookie_name or cookie_name=expected_value.
Example: restatify_cookie_consent=accepted,cookiesDirective,_cky-consent=accept

== Frequently Asked Questions ==

= How do I enable the built-in website chat? =

Go to Settings -> Multi Chat Overlay and enable "Enable built-in website chat".

= Where do incoming chat messages appear? =

In the dedicated admin menu item Support Chat.
You can open a conversation and send support replies.

= Does the plugin send email notifications? =

Yes. If "Send email on new message" is enabled and a valid support email is configured, the plugin sends a notification for each new visitor message.

= Is AI required? =

No. AI auto reply is optional and disabled by default.

= Which AI provider is supported? =

The plugin supports these providers via endpoint auto-detection:

* OpenAI (ChatGPT)
* Google Gemini
* Mistral
* DeepSeek
* Llama (including Ollama-style endpoints)

The API endpoint is configurable in plugin settings.
Provider is detected from the configured endpoint URL and request/response payloads are adapted automatically.

= Does the plugin support Polylang? =

Yes.

When Polylang is active, the plugin registers configurable chat texts in the translation group "Restatify Multi Chat Overlay".
You can translate these texts in Languages -> Translations.

Translated fields include:

* Team title
* Intro message
* Channel heading
* Button accessibility label
* Chat title
* Chat input placeholder
* Send button label
* AI system prompt

= Why is the overlay not showing although it is enabled? =

Most commonly one of these conditions is not met:

* The overlay is enabled, but no channel URL is configured and built-in website chat is disabled.
* "Require cookie consent" is enabled, but the configured cookie rules do not match the site's actual consent cookie.

If your site uses a custom theme or consent solution, update "Consent cookie rules" accordingly.

= Is there a German setup guide and a support playbook? =

Yes. See the files in the plugin folder:

* README.de.md
* SUPPORT-PLAYBOOK.md
* RELEASE-CHECKLIST.md

== Privacy ==

When website chat is enabled, visitor messages are stored in WordPress options to keep conversation history.
If support email notifications are enabled, message content is sent by email to the configured support address.
If AI auto reply is enabled, message content is sent to the configured AI API endpoint.

You should update your privacy policy accordingly.

== Screenshots ==

1. Floating chat button and channel panel on frontend.
2. Native website chat inside the overlay.
3. Support inbox in dedicated WordPress admin menu Support Chat.
4. Email notification with direct conversation link.
5. AI configuration settings.

== Changelog ==

= 1.2.0 =
* Added native website chat in overlay.
* Added support inbox in admin settings.
* Added support email notifications with direct conversation link.
* Added optional AI auto reply configuration.
* Improved overlay rendering logic for chat-only mode.

= 1.1.0 =
* Added multi-channel floating overlay with auto-open delay and dismiss memory.

== Upgrade Notice ==

= 1.2.0 =
Includes integrated website chat, support inbox, email notifications, and optional AI auto reply.
Review settings after upgrade.
