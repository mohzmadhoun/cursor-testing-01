# ChatHearth - AI Chatbot

WordPress chatbot plugin that uses **WordPress Connectors** and the core **AI Client** to talk to AI providers. Version 1 targets **OpenAI** via the *AI Provider for OpenAI* plugin.

**Owner:** [PalWP](https://palwp.com/) (`palwp`)  
**Support:** [Mohammed Al-Madhoun](https://momadhoun.com) ([@momadhoun](https://profiles.wordpress.org/momadhoun/))

## Status

**v1.4.5** — RAG knowledge base (WordPress database only), always-on website grounding, product comparison, add-to-cart from chat, and Google reCAPTCHA v3.

## Requirements (v1)

- WordPress **7.0+** (Connectors + AI Client)
- Plugin **AI Provider for OpenAI** installed and active
- OpenAI API key configured under **Connectors** (this plugin does not store the key)

## Features (v1)

- Site-wide floating chatbot on the front end
- Settings under **Settings → ChatHearth - AI Chatbot** (tabs: Welcome, Protection, Appearance, AI Settings, Knowledge Base)
- Appearance, welcome greeting, starter phrases, system prompt
- AI provider (OpenAI) and chat model dropdowns
- Protection against abuse and cost spikes (see below)
- Non-streaming replies: typing indicator, then the full answer (Markdown rendered in the popup, including tables)
- Conversation history in **`localStorage`** (survives refresh)
- Always-on **website grounding**: answers stay on this site even when RAG is off
- **Knowledge Base (RAG):** export pages, posts, custom post types, products, and taxonomies to markdown; store embeddings in the WordPress database; incremental reindex
- **Store chat:** compare products from catalog facts; add purchasable products to the WooCommerce cart from the widget

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
| **Google reCAPTCHA v3** | Optional — enabled when site key **and** secret key are set (settings or `CHATHEARTH_RECAPTCHA_*` environment variables). A white blurred overlay covers the chat until Google verifies the visitor. |

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
  Ai/             Ai_Gateway (wp_ai_client_prompt), OpenAI credentials helper
  Commerce/       WooCommerce cart + product cards
  Frontend/       Public assets + widget mount
  Rag/            Markdown export, indexer, vector stores, retriever, grounding
  Rest/           /wp-json/chathearth/v1/chat, /cart, /kb/*
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

(`recaptcha_token` required only when reCAPTCHA keys are configured and the visitor has not already passed the overlay check.)

`POST /wp-json/chathearth/v1/recaptcha` — `{ "recaptcha_token": "..." }` (same nonce). Unlocks the chat overlay.

Response: `{ "reply": "...", "sources": [ { "title", "url", "type" } ], "products": [ { "id", "name", "url", "price", "purchasable" } ], "commerce": { "enabled", "cart_url", "checkout_url" } }`

`POST /wp-json/chathearth/v1/cart` — `{ "product_id", "quantity?", "variation_id?" }` (same nonce). WooCommerce cart only.

Knowledge Base admin REST (`manage_options`): `/kb/sync`, `/kb/status`, `/kb/entries`, `/kb/ping`.

### Knowledge base storage

RAG embeddings live in this site's WordPress database (`wp_chathearth_kb_chunks`). The plugin zip is enough: no Python, Chroma process, Pinecone account, or other server software. The `chathearth_vector_store` filter remains for developers who implement a custom PHP store.

## Documentation

| File | Purpose |
|------|---------|
| [`plan/functionalities.md`](plan/functionalities.md) | Core + future feature list |
| [`plan/cursor-milestone-01.md`](plan/cursor-milestone-01.md) | RAG / grounding / chat-commerce milestone |
| [`plan/architecture.md`](plan/architecture.md) | Extension points, including evaluation and observability |
| [`plan/metrics-and-methods-for-quality.md`](plan/metrics-and-methods-for-quality.md) | Metrics and methods for AI model and RAG quality |
| [`readme.txt`](readme.txt) | WordPress.org-style readme |

## Roadmap (high level)

- Hide/placement controls; shortcode; Gutenberg; Elementor; other page builders
- Real token streaming
- Main + backup AI providers; then third-party providers (DeepSeek, OpenRouter, etc.)
- Custom launcher image/SVG upload
- Server-side conversation history
- **CAPTCHA:** Cloudflare Turnstile and hCaptcha later; Google reCAPTCHA **v3** ships now
- **Evaluation and observability:** tokens/cost monitoring, hard cost ceilings with admin escalation, latency SLOs, quality/RAG evaluation, hallucination detection, explainability, transparency, and controllability (see the metrics doc above)
- **RAG:** enable retrieval; markdown from selected site content; WordPress-database vectors; comparison answers; add to cart from chat
- N8N webhook for custom agents / alternate RAG or AI routing

## License

GPL-2.0-or-later
