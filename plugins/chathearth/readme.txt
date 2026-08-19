=== ChatHearth - AI Chatbot ===
Contributors: palwp, momadhoun
Tags: chatbot, ai, openai, connectors, customer-support
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site-wide AI chatbot powered by WordPress Connectors. Version 1 uses OpenAI via the AI Provider for OpenAI plugin.

== Description ==

ChatHearth - AI Chatbot adds a floating chat widget to your site. Visitors get a welcome message and starter phrases, then chat with an AI assistant configured through WordPress Connectors.

Owned by [PalWP](https://palwp.com/). Support by [Mohammed Al-Madhoun](https://profiles.wordpress.org/momadhoun/) (`momadhoun`).

**Version 1 highlights**

* OpenAI via WordPress Connectors / AI Client (provider and chat model selectable in settings)
* Settings page under Settings → ChatHearth - AI Chatbot (Welcome, Protection, Appearance, AI Settings, Knowledge Base)
* Appearance controls (shape, colors, position, popup size)
* Welcome message and clickable starter phrases
* System prompt settings plus always-on website-only grounding
* Knowledge Base (RAG): markdown from selected pages, posts, custom post types, products, and taxonomies; Chroma, Pinecone, or WordPress-database vectors; incremental updates
* Product comparison in chat and add to WooCommerce cart from the widget
* Protection: kill switch, per-IP and site-wide (global) rate limits, escalation email/admin notice, optional auto-disable, max message length, content moderation (keyword list + optional OpenAI Moderations API), optional Google reCAPTCHA v2 checkbox (once per hour after first success; enabled when both keys are set)
* Conversation history in the browser (localStorage)
* Non-streaming replies with a typing indicator and Markdown rendering

Configure your OpenAI API key under **Connectors**. This plugin does not store the API key.

**Planned: Evaluation and observability**

Later releases are expected to add more CAPTCHA providers (Cloudflare Turnstile, hCaptcha, Google reCAPTCHA v3), tokens and cost monitoring, hard cost ceilings with admin escalation, latency and reliability metrics, RAG quality evaluation, hallucination detection, explainability, transparency, and controllability. See the plugin `plan/` docs (especially `metrics-and-methods-for-quality.md` and `functionalities.md`) for details.

== External services ==

This plugin relies on the following third-party services to work. It does not send any data to these services until the site owner configures them and a visitor actively uses the chatbot. No visitor is tracked automatically, and the plugin adds no analytics, advertising, or usage-tracking calls of its own.

= OpenAI (AI chat replies) =

The chatbot generates replies using OpenAI's API, accessed through WordPress Connectors / the core AI Client and the required "AI Provider for OpenAI" plugin. This is the core service that produces the assistant's answers.

* **What is sent:** the text of the message a visitor types, the recent conversation history from that visitor's browser session, the site's configured system prompt, and the selected model name.
* **When it is sent:** only when a visitor actively submits a message in the chat widget. Nothing is sent on page load or in the background.
* **Where the API key lives:** the OpenAI API key is configured under WordPress Connectors (or the `OPENAI_API_KEY` environment variable / constant). This plugin does not store the API key and does not store chat transcripts in the WordPress database.

OpenAI Terms of Use: https://openai.com/policies/terms-of-use/
OpenAI Privacy Policy: https://openai.com/policies/privacy-policy/
OpenAI API data usage policy: https://openai.com/policies/usage-policies/

= OpenAI Moderations API (content moderation) =

When content moderation is enabled under Settings → ChatHearth - AI Chatbot → Protection and the OpenAI Moderations layer is on, visitor message text is checked with OpenAI’s Moderations endpoint before a chat reply is generated. This helps block harmful or disallowed content and ToS-violating usage.

* **What is sent:** the text of the current message and prior user turns from that visitor’s browser session (truncated to a safe length). No system prompt is sent to Moderations.
* **When it is sent:** only when a visitor submits a message, content moderation is enabled, the OpenAI Moderations option is on, and OpenAI is configured under Connectors. If moderation is off or only the keyword list is used, this endpoint is not called.
* **Where the API key lives:** the same OpenAI API key from WordPress Connectors (or `OPENAI_API_KEY`). This plugin does not store a separate moderation key.
* **Endpoint used:** `https://api.openai.com/v1/moderations`

OpenAI Terms of Use: https://openai.com/policies/terms-of-use/
OpenAI Privacy Policy: https://openai.com/policies/privacy-policy/
OpenAI API data usage policy: https://openai.com/policies/usage-policies/

= OpenAI Embeddings API (knowledge base) =

When the Knowledge Base (RAG) is enabled, ChatHearth creates embeddings for selected site content and for visitor questions so matching passages can be retrieved. This uses the same OpenAI API key from Connectors.

* **What is sent:** markdown generated from selected pages, posts, products, taxonomies, and site/store summaries (at index time), and the visitor’s current question (at chat time).
* **When it is sent:** only when RAG is enabled and the site owner runs a sync / content changes, or a visitor sends a chat message while RAG is on.
* **Endpoint used:** `https://api.openai.com/v1/embeddings`

= Chroma (optional self-hosted vector store) =

If the site owner selects Chroma, embeddings and document snippets are sent to the Chroma URL they configure (typically a private server). Chroma is not contacted unless that store is selected.

= Pinecone (optional remote vector store) =

If the site owner selects Pinecone and saves an API key plus index host, embeddings and metadata are sent to Pinecone to store and query vectors. Pinecone is not contacted unless that store is selected.

Pinecone Privacy Policy: https://www.pinecone.io/privacy/

= Google reCAPTCHA v2 (optional bot/abuse protection) =

Google reCAPTCHA v2 ("I'm not a robot" Checkbox) is an optional, human-verification service used only to protect the chat endpoint from automated abuse. It is **disabled by default** and only becomes active when the site owner enters both a reCAPTCHA site key and secret key under Settings → ChatHearth - AI Chatbot → Protection.

* **What is sent:** when enabled, the reCAPTCHA JavaScript is loaded from Google on front-end pages where the chat widget appears, and Google may collect device, browser, and usage data (including the visitor's IP address) to assess whether the visitor is human. When a visitor completes the checkbox and sends a message, the reCAPTCHA response token and the visitor's IP address are sent to Google's verification endpoint to validate the challenge.
* **When it is sent:** only when the site owner has enabled reCAPTCHA (both keys set). If reCAPTCHA is not configured, no request is ever made to Google and Google's script is not loaded.
* **Endpoints used:** `https://www.google.com/recaptcha/api.js` (widget) and `https://www.google.com/recaptcha/api/siteverify` (server-side verification).

Google Terms of Service: https://policies.google.com/terms
Google Privacy Policy: https://policies.google.com/privacy

= Privacy and consent =

This plugin does not track users and does not contact any external server without consent:

* **OpenAI** is only reached after the site owner installs the required provider plugin and configures an API key under Connectors. By installing, configuring, and activating that service, the site owner grants consent for chat messages to be sent to OpenAI (the Software as a Service exception). Data is sent only when a visitor actively submits a message. When content moderation with OpenAI Moderations is enabled, message text may also be sent to OpenAI’s Moderations endpoint before a reply is generated.
* **Google reCAPTCHA** is opt-in and off by default. It loads and contacts Google only after the site owner enters both reCAPTCHA keys. With no keys set, Google is never contacted.
* **No silent tracking:** the plugin includes no analytics, advertising, fingerprinting, or usage-tracking calls. The visitor's IP address is used locally for rate limiting and abuse protection only (short-lived counters), and is not sold, shared, or logged as a chat transcript.

Site owners should review the privacy policies of any service they enable and update their own site's privacy policy accordingly. Suggested privacy-policy text is provided under Settings → Privacy.

== Installation ==

1. Install and activate the **AI Provider for OpenAI** plugin.
2. Add your OpenAI API key under WordPress **Connectors**.
3. Upload the `chathearth` folder to `/wp-content/plugins/`, or install the zip.
4. Activate **ChatHearth - AI Chatbot**.
5. Open **Settings → ChatHearth - AI Chatbot** and save your settings.

== Frequently Asked Questions ==

= Does this plugin store my OpenAI API key? =

No. Use WordPress Connectors (or the OPENAI_API_KEY environment variable / constant).

= Why don't replies stream token by token? =

WordPress AI Client / Connectors do not expose streaming yet. v1 shows a typing indicator, then the full reply. Streaming is planned for a later release.

= Where is chat history stored? =

In the visitor's browser via localStorage. Server-side history is planned for a future release.

= How does abuse / rate-limit protection work? =

Under Settings → ChatHearth - AI Chatbot → Protection you can set per-IP limits, site-wide (global) limits, an escalation threshold, and optional auto-disable. Optionally add Google reCAPTCHA v2 (“I’m not a robot” Checkbox) site and secret keys — CAPTCHA turns on automatically when both are set (green status in settings). Content moderation can check messages with a keyword list and/or OpenAI’s Moderations API before they reach the chat model. Chat requests are checked in order: kill switch, reCAPTCHA (if enabled), then global limits, then per-IP limits, then content moderation (if enabled), then the AI call. When global limits are hit repeatedly within an hour, the plugin emails the admin, shows an admin notice, and can turn the chatbot off automatically.

= Will there be usage, cost, or quality monitoring? =

Yes — planned under Evaluation and observability (tokens/cost, latency, groundedness, hallucination checks, admin dashboards). Request-count protection, admin escalation, and optional reCAPTCHA v2 ship now; hard dollar/token ceilings and additional CAPTCHA providers (Turnstile, hCaptcha, reCAPTCHA v3) are still planned.

== Screenshots ==

1. Floating chat widget on the front end with welcome message and starter phrases.
2. Settings → ChatHearth - AI Chatbot — Welcome tab (greeting and starter phrases).
3. Settings → ChatHearth - AI Chatbot — Protection tab (rate limits, content moderation, and optional reCAPTCHA).
4. Settings → ChatHearth - AI Chatbot — Appearance tab (colors, shape, position, and size).
5. Settings → ChatHearth - AI Chatbot — AI Settings tab (provider, model, and system prompt).
6. Settings → ChatHearth - AI Chatbot — Knowledge Base tab (RAG sources, vector store, sync).

== Changelog ==

= 1.4.0 =
* Knowledge Base (RAG): markdown export of selected site content, admin include/exclude, incremental reindex.
* Vector stores: WordPress database, self-hosted Chroma, or Pinecone.
* Always-on website grounding and off-topic refusal, even when RAG is off.
* Product comparison in chat and add to WooCommerce cart from the widget.

= 1.3.0 =
* Renamed plugin to ChatHearth - AI Chatbot (slug `chathearth`) with matching text domain, namespaces, options, REST routes, and asset handles.

= 1.2.0 =
* Renamed plugin to PalWP – AI Chatbot (slug `palwp-ai-chatbot`) with consistent Option A prefixes.
* Stopped reading Connectors API keys via `get_option()`; readiness uses the WordPress AI Client registry.
* Documented external services (OpenAI, Google reCAPTCHA) in the readme.
* Removed bundled translation files; translations are handled via translate.wordpress.org.

= 1.1.3 =
* Scroll chat to the latest message when opening the panel after a page reload.

= 1.1.2 =
* AI provider failures return a generic client message; details go to `debug.log` only when `WP_DEBUG_LOG` is already enabled.

= 1.1.0 =
* Optional Google reCAPTCHA v2 checkbox on chat (enabled when site + secret keys are set); one solve unlocks chat for about an hour; green status in Protection settings.
* Fix: CAPTCHA no longer resets after every message.

= 1.0.9 =
* Global (site-wide) rate limits with admin escalation email, notice, and optional auto-disable.

= 1.0.8 =
* Markdown list rendering fixes; settings tabs under Settings → ChatHearth - AI Chatbot; provider/model selectors.

= 1.0.0 =
* Initial release: site-wide widget, settings, OpenAI via Connectors, rate limits.

== Upgrade Notice ==

= 1.4.0 =
Adds a Knowledge Base tab (RAG), website-only grounding, and add-to-cart from chat.

= 1.3.0 =
Renames the plugin to ChatHearth - AI Chatbot (slug `chathearth`).

= 1.2.0 =
Renames the plugin to PalWP – AI Chatbot with updated prefixes and slug. Re-activate after replacing the plugin folder if needed.
