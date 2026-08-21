# ChatHearth - AI Chatbot — Architecture extension points

This document reserves contracts and hooks for future releases. v1 implements the OpenAI path only via `Ai_Gateway`.

## Request flow (v1)

```
Visitor → Frontend widget → REST /chathearth/v1/chat
  → Kill switch → Rate_Limiter (global → per-IP) → Ai_Gateway
  → wp_ai_client_prompt(openai) → reply JSON
```

On repeated **global** rate-limit denials within an hour, `Rate_Limiter` may email the admin, store an abuse alert for an admin notice, and optionally set `chat_enabled` to false.

## Protection (shipped)

| Layer | Behavior |
|-------|----------|
| Kill switch | `chat_enabled` — no front-end assets; REST returns 403 |
| Global limits | Site-wide requests/minute and /hour (all IPs) |
| Per-IP limits | Configurable requests/minute and /hour per client IP |
| Incident escalation | Global denials counted hourly; at threshold → email + notice + optional auto-disable |
| Message length | Max length with `mb_*` UTF-8 truncation |
| REST auth | `wp_rest` nonce (`X-WP-Nonce`) |
| Google reCAPTCHA v2 | Optional — active only when site key **and** secret key are set; checkbox once, then ~1h human-pass cookie; token verified before rate limits / AI |

Counters: `wp_cache_incr` when an external object cache is present; otherwise exclusive `add_option` lock + transients. Override client IP via `chathearth_client_ip`.

### Future: more CAPTCHA / challenge providers

| Provider | Notes |
|----------|--------|
| **Cloudflare Turnstile** | Privacy-oriented challenge |
| **hCaptcha** | Independent CAPTCHA provider |
| **Google reCAPTCHA v3** | Score-based (no checkbox); threshold in settings |

**Google reCAPTCHA v2** (“I’m not a robot” Checkbox) ships when keys are configured in Protection settings.

## Hooks (available now)

| Hook | Type | Purpose |
|------|------|---------|
| `chathearth_before_generate` | action | Runs before the AI call (logging, RAG prep, N8N branch) |
| `chathearth_system_prompt` | filter | Modify system instruction (inject RAG context) |
| `chathearth_messages` | filter | Modify / inject history turns |
| `chathearth_reply` | filter | Post-process assistant text |
| `chathearth_client_ip` | filter | Override IP used for rate limiting |

## Intended future modules

### Providers

- Extend `Ai_Gateway` (or add `Provider_Router`) for main + backup Connectors providers.
- Later: DeepSeek, OpenRouter, and other third-party providers behind the same gateway interface.

Suggested shape (not implemented yet):

```php
interface Text_Generator_Interface {
    /** @return string|\WP_Error */
    public function generate( string $message, array $history, string $system_prompt );
}
```

### RAG

- Toggle in **Settings → Knowledge Base**. Site content is exported to markdown under `uploads/chathearth/kb/`, with a custom table of entries and chunks.
- Retrieval hooks `chathearth_system_prompt` (priority 20). Always-on site grounding runs at priority 5 even when RAG is off.
- **Vector store:** embeddings in `wp_chathearth_kb_chunks` with cosine search in PHP. No extra process. Filter `chathearth_vector_store` for custom PHP implementations.

```php
interface Vector_Store_Interface {
    public function upsert( array $records ): bool;
    public function delete( array $ids ): bool;
    /** @return list<array{id:string,score:float,content:string,meta:array}> */
    public function query( array $embedding, int $limit = 5 ): array;
    public function ping(): bool;
}
```

Incremental updates hook `save_post`, terms, and WooCommerce product changes and re-embed only the affected source id when the markdown hash changes. See [`cursor-milestone-01.md`](cursor-milestone-01.md).

### N8N

- Optional webhook URL in settings.
- May replace Connectors AI generation and/or built-in RAG by short-circuiting inside `Ai_Gateway` or via `chathearth_before_generate`.

### Conversation history

- v1: browser `localStorage` only.
- Future: custom tables + admin UI (schema TBD).

### Placement

- v1: site-wide floating widget.
- Future: hide controls; shortcode; Gutenberg block; Elementor; other builders — keep `Frontend\Assets` logic reusable from a shared renderer.

### Streaming

- v1: full response after typing indicator.
- Future: SSE or streamed fetch when core AI Client supports it, or a dedicated streaming path using the Connectors-stored key.

### Evaluation and observability

Reserve instrumentation around the chat pipeline (especially `chathearth_before_generate` / `chathearth_reply` and future RAG retrieve steps) so later releases can attach:

- **Usage & cost:** token counts (tokenizer and/or provider usage), estimated spend, hard ceilings with admin escalation and kill-switch integration. *(Request-count global limits + admin escalation already ship in v1; see Protection above.)*
- **Latency & reliability:** per-stage timings, error taxonomy, success/failover rates, empty-retrieval rate, index health.
- **Quality & safety:** groundedness/relevance sampling, hallucination gates, user feedback (thumbs/report), offline golden sets.
- **Explainability & control:** store/trace model, prompt version, retrieved chunk IDs/scores for an admin “why this answer” view; grounding-required and policy hooks.

Full metrics catalog and methodologies: [`metrics-and-methods-for-quality.md`](metrics-and-methods-for-quality.md). Future feature list items 15–26 in [`functionalities.md`](functionalities.md).
