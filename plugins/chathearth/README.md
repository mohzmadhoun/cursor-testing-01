# ChatHearth - AI Chatbot

WordPress chatbot plugin that uses **WordPress Connectors** and the core **AI Client** to talk to AI providers. Version 1 targets **OpenAI** via the *AI Provider for OpenAI* plugin.

**Owner:** [PalWP](https://palwp.com/) (`palwp`)  
**Support:** [Mohammed Al-Madhoun](https://momadhoun.com) ([@momadhoun](https://profiles.wordpress.org/momadhoun/))

## Status

**v1.3.0** — activate the plugin after configuring OpenAI under Connectors.

## Requirements (v1)

- WordPress **7.0+** (Connectors + AI Client)
- Plugin **AI Provider for OpenAI** installed and active
- OpenAI API key configured under **Connectors** (this plugin does not store the key)

## Features (v1)

- Site-wide floating chatbot on the front end
- Settings under **Settings → ChatHearth - AI Chatbot** (tabs: Welcome, Protection, Appearance, AI Settings)
- Appearance, welcome greeting, starter phrases, system prompt
- AI provider (OpenAI) and chat model dropdowns
- Protection against abuse and cost spikes (see below)
- Non-streaming replies: typing indicator, then the full answer (Markdown rendered in the popup)
- Conversation history in **`localStorage`** (survives refresh)

## Protection

Configured under **Settings → ChatHearth - AI Chatbot → Protection**:

| Control | Purpose |
|---------|---------|
| **Enable chatbot (kill switch)** | Hides the widget and rejects chat REST requests |
| **Per-IP rate limits** | Max requests per minute / hour per client IP |
| **Global rate limits** | Site-wide caps across all IPs (mitigates multi-IP floods) |
| **Escalate after N global hits** | Counts global-limit blocks in an hourly window |
| **Auto-disable on escalation** | Turns chat off when the threshold is reached |
| **Max message length** | Truncates oversized messages (UTF-8 safe) |
| **Content moderation** | Keyword/disallowed list + optional OpenAI Moderations API before the chat model |
| **Google reCAPTCHA v2** | Optional — enabled when site key **and** secret key are set; “I’m not a robot” checkbox in the chat panel |

When the global-limit incident threshold is reached, the plugin emails the site admin (with a cooldown), shows a dismissible admin notice, and optionally auto-disables the chatbot. Re-enable under Protection when ready.

REST checks run in order: kill switch → reCAPTCHA (if keys set) → global limits → per-IP limits → content moderation (if enabled) → AI call. Counters use atomic object-cache increments when an external cache is present, otherwise a short option lock around transients.

## Setup

1. Install and activate **AI Provider for OpenAI**.
2. Add your OpenAI API key under **Connectors**.
3. Activate **ChatHearth - AI Chatbot**.
4. Open **Settings → ChatHearth - AI Chatbot** and configure appearance, welcome text, starters, AI model, system prompt, and Protection limits.
5. Visit the front end and use the floating launcher.

## Developer notes

### Layout

```
chathearth.php
includes/
  Admin/          Settings page + notices
  Ai/             Ai_Gateway (wp_ai_client_prompt)
  Frontend/       Public assets + widget mount
  Rest/           /wp-json/chathearth/v1/chat
  Security/       Rate_Limiter, Recaptcha, Content_Moderation
assets/           CSS, JS, default launcher SVG
plan/             Product + architecture docs
```

### Extension hooks

- `chathearth_before_generate` (action)
- `chathearth_system_prompt` (filter)
- `chathearth_messages` (filter)
- `chathearth_reply` (filter)
- `chathearth_client_ip` (filter)
- `chathearth_disallowed_phrases` (filter)
- `chathearth_content_allowed` (filter)

See [`plan/architecture.md`](plan/architecture.md) for RAG / N8N / provider extension points and planned **Evaluation and observability** instrumentation.

### REST

`POST /wp-json/chathearth/v1/chat`

Headers: `X-WP-Nonce` with a `wp_rest` nonce (localized on the front end), `Content-Type: application/json`

Body: `{ "message": "...", "history": [ { "role": "user"|"assistant", "content": "..." } ], "recaptcha_token": "..." }`

(`recaptcha_token` required only when reCAPTCHA keys are configured.)

Response: `{ "reply": "..." }`

## Documentation

| File | Purpose |
|------|---------|
| [`plan/functionalities.md`](plan/functionalities.md) | Core + future feature list |
| [`plan/implementation-plan.md`](plan/implementation-plan.md) | Full implementation plan |
| [`plan/architecture.md`](plan/architecture.md) | Extension points, including evaluation and observability |
| [`plan/metrics-and-methods-for-quality.md`](plan/metrics-and-methods-for-quality.md) | Metrics and methods for AI model and RAG quality |
| [`readme.txt`](readme.txt) | WordPress.org-style readme |

## Roadmap (high level)

- Hide/placement controls; shortcode; Gutenberg; Elementor; other page builders
- Real token streaming
- Main + backup AI providers; then third-party providers (DeepSeek, OpenRouter, etc.)
- Custom launcher image/SVG upload
- Server-side conversation history
- **CAPTCHA:** Cloudflare Turnstile, hCaptcha, and Google reCAPTCHA **v3** (v2 is already optional when keys are set)
- **Evaluation and observability:** tokens/cost monitoring, hard cost ceilings with admin escalation, latency SLOs, quality/RAG evaluation, hallucination detection, explainability, transparency, and controllability (see the metrics doc above)
- RAG (documents, site content → markdown, local or remote vector stores)
- Comparison answers grounded in website/store content (products, services, benefits, and more)
- N8N webhook for custom agents / alternate RAG or AI routing

## License

GPL-2.0-or-later
