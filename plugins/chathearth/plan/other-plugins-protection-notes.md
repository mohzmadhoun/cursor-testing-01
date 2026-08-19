# Abuse-Protection Notes from Other Chatbot Plugins

Review of how the five plugins in `wp-content/other-plugins/` protect the chat endpoint against abusive / excessive usage. Grouped per plugin, followed by common patterns worth adopting.

---

## 1. AskAny (`askany/`)

- **Dedicated rate limiter class** (`includes/class-rate-limiter.php`) with per-role limits (administrator, editor, author, contributor, subscriber, customer, shop_manager) plus a `visitor` (logged-out) bucket.
- **Configurable timeframes**: per hour / day / week / month; counters stored in **WordPress transients** with matching TTL, so they auto-expire.
- **Per-IP tracking for visitors**: transient key is `askany_rl_ip_<md5(ip)>_<timeframe>`; logged-in users keyed by user id + role instead.
- **Real client IP resolution** with `HTTP_X_FORWARDED_FOR` / `HTTP_CLIENT_IP` / `REMOTE_ADDR` fallback and `FILTER_VALIDATE_IP` validation.
- **Limit `0` = unlimited**; a valid set of allowed limit values is enforced on save (`1,3,5,10,15,20,50,100,0`).
- Rate limiting is gated as a **Pro feature** (bypassed if Pro not active) and behind an on/off option.
- **Nonce protection** on every AJAX/REST action (`check_ajax_referer('askany_chat_nonce')` / `wp_verify_nonce`) and a REST endpoint that hands out fresh nonces (cache-safe).
- **Capability checks** (`current_user_can('manage_options')`) on all admin actions.
- **Input sanitization** everywhere (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_email`).
- Optional **fallback message** instead of a hard block when the limit is hit.

## 2. MXChat Basic (`mxchat-basic/`)

- **Per-role rate limits** (`check_rate_limit()` in `class-mxchat-integrator.php`) with hourly/daily/weekly/monthly timeframes; logged-out users tracked **by IP**, logged-in by role.
- **Whole-chatbot global cap** evaluated first as a hard ceiling across all users/roles (independent of the per-role limit); default `unlimited`.
- **Per-bot limits**: counters include the `bot_id` so each bot has its own quota.
- Counters stored as options with `{count, timestamp}` and reset by comparing elapsed time to the timeframe; a **cron job** (`mxchat_reset_rate_limits`, hourly) resets counts, with a **fallback cleanup** path if cron fails.
- Customizable limit message with placeholders (`{limit}`, `{count}`, `{remaining}`, `{timeframe}`).
- **Moderation / ban system** (`MX_Chat_Moderation` + `MX_Chat_Ban_Handler`): blocks requests from **banned IPs and banned emails** before processing.
- **Server-side max input length** guard (`max_input_length`) backing the bypassable client-side `maxlength` on the textarea; measured with `mb_strlen` on the raw POST.
- **Cache-safe per-request nonce flow**: dedicated REST endpoint issues a fresh `mxchat_chat_send` nonce (so cached HTML never carries a stale nonce), and that endpoint is itself **rate-limited to 1 call/IP/sec** to prevent flooding the nonce issuer. Accepts both new and legacy nonce actions for compatibility.
- **Auto-retry only on transient provider errors** (timeouts, 429/502/503/504) — deliberately does not retry on hard client errors.

## 3. AI Chat Search / Listeo (`ai-chat-search/`)

- **Tiered per-IP rate limiting**, enforced **server-side** (`check_ip_rate_limit()` in `includes/class-chat-api.php`), independent of the frontend:
  - Tier 1: short window (1 minute)
  - Tier 2: medium window (15 minutes)
  - Tier 3: long window (24 hours)
- Limits are **role/tier aware** (logged-out / logged-in / premium) and configurable in admin (`listeo_ai_chat_rate_limit_tier1..3`).
- Uses a **sliding window of timestamps** stored in a transient keyed by `md5(ip)`; old timestamps are filtered out each request — more accurate than a single counter.
- An **internal multiplier** hides the true limit from the displayed number (makes limits harder to probe).
- **Global hourly API cap** (`listeo_ai_rate_limit_<Y-m-d-H>`) protects the upstream API key/budget on top of per-IP limits.
- **Query length cap** (`max_query_length = 1000`, truncated) and **conversation history trimming** (`max_messages`) to bound token/cost usage.
- **Secure IP resolution** helper and **nonce verification** (`check_ajax_referer('listeo_ai_search_nonce')`) on AJAX handlers.
- **Server-side enforcement of the system-prompt length** on save (prevents dev-tools bypass of the `maxlength` attribute).
- Admin tools to **clear IP rate-limit transients** and global limits.

## 4. Chatbot / WPBot by QuantumCloud (`chatbot/`)

- **Opt-in rate limiting** (`is_rate_limiting_enabled` option) fired via a shared `do_action('rate_limit_checker')` before each AI call across all providers (OpenAI, Gemini, Grok, OpenRouter).
- **Per-role limits** for logged-in users, stored in **user meta** (`qcld_openai_user_rate_limit`) and compared against `rate_limit_<role>`.
- **Guest limits tracked via PHP `$_SESSION`** (guest id derived from IP + time), with a configurable timeframe.
- **Configurable timeframe per role** (`rate_limit_timeframe_<role>`, in hours) and separate guest timeframe.
- Counts **reset by WP-Cron** (`reset_rate_limit_used_counts`) on a per-role schedule.
- **Nonce checks** (`wp_verify_nonce`, e.g. `wp_chatbot`, `str-nonce`) and `current_user_can('manage_options')` on admin actions.
- Custom, translatable "rate limit exceeded" message; handles both streaming and non-streaming responses.
- *Weaknesses to avoid:* guest limiting relies on server sessions (cleared/rotated easily) and IP+time hashing; less robust than transient/IP approaches above.

## 5. Ask My Content (`ask-my-content/`)

- **Abuse protection largely delegated to the SaaS backend**: the plugin proxies queries to a remote API (`askmyco_send_content_to_backend`) that enforces quotas.
- **Free-tier / billing gate** (`askmyco_get_free_tier_limit_state`): when the usage limit is reached, the request is **blocked before hitting the backend** and returns HTTP `402` with a clear message (usage still logged to history).
- **Nonce validation** (`check_ajax_referer(..., 'security')`) on both public and admin query endpoints, plus **capability check** for admin-only actions.
- **Cache-safe nonce refresh** endpoints for public and admin nonces.
- **Input sanitization** and an **empty-question guard** (`400`) and **indexing-not-ready guard** (`409`).
- Response snippets truncated (`mb_substr`) to bound log/response size.

---

## Common Patterns / Takeaways

1. **Rate limit by identity + timeframe**: per-role for logged-in users, **per-IP for guests**, with configurable windows (hour/day/week/month).
2. **Storage**: transients (auto-expiring, preferred) or options + timestamp + cron reset; sliding-window timestamp arrays are the most accurate.
3. **Layered ceilings**: combine a per-user/role limit with a **global chatbot/API cap** to protect cost and upstream keys even under distributed abuse.
4. **Server-side enforcement is mandatory**: never trust client-side `maxlength` — re-check **input length** and limits on the server (dev-tools bypass).
5. **Nonces on every request**, with **cache-safe nonce refresh endpoints** (important behind page caches like WP Rocket / LiteSpeed / Cloudflare) — and rate-limit the nonce issuer itself.
6. **Capability checks** (`current_user_can`) on all admin/config actions; sanitize all inputs.
7. **Bans / moderation**: block known-bad **IPs and emails** before processing.
8. **Robust IP detection** with proxy headers + `FILTER_VALIDATE_IP`.
9. **Bound token/cost**: cap query length and trim conversation history.
10. **Graceful UX on block**: custom, translatable messages (with placeholders), optional fallback answer, and only auto-retry on genuinely transient provider errors.
11. **Admin tooling** to inspect/clear rate-limit state.

---

## Comparison with ChatHearth - AI Chatbot (our plugin)

Reviewed files: `includes/Security/class-rate-limiter.php`, `includes/Security/class-recaptcha.php`, `includes/Rest/class-chat-controller.php`, `includes/class-options.php`.

### A. What we already use (implemented)

| Protection | How ChatHearth does it | vs. other plugins |
| --- | --- | --- |
| **Per-IP rate limiting** | Per-minute + per-hour quotas, IP hashed with `md5` (`rate_limit_per_minute` / `rate_limit_per_hour`). | On par / better — same idea as AskAny, MXChat, AI Chat Search. |
| **Concurrency-safe counters** | Atomic `wp_cache_incr` when an external object cache (Redis/Memcached) is present, with a **locked-transient fallback** (`add_option` lock) otherwise. | **Better than all five** — the others use plain `get/set_transient` or options with race conditions. |
| **Global (site-wide) cap** | Global per-minute + per-hour ceiling evaluated before per-IP (`global_rate_limit_per_minute` / `_per_hour`). | On par with MXChat's global cap; more robust implementation. |
| **Abuse escalation** | Counts global-limit incidents; on threshold → emails admin, stores an admin notice, and can **auto-disable the chat** (with email cooldown). | **Unique to ChatHearth** — none of the others auto-disable or alert an admin. |
| **CAPTCHA** | Google reCAPTCHA v2 with a short-lived, hashed **human-pass cookie** (transient-backed) so solving once unlocks further messages. | **Unique to ChatHearth** — none of the reviewed plugins integrate CAPTCHA. |
| **Nonce verification** | REST `wp_rest` / `X-WP-Nonce` verified in `permission_callback`. | On par (all use nonces). |
| **Input sanitization** | `sanitize_textarea_field` on message; history sanitized with a **role whitelist** (`user`/`assistant` only). | On par / cleaner. |
| **Server-side length cap** | `max_message_length` (truncates, 100–8000) enforced server-side; not just the client attribute. | On par with MXChat / AI Chat Search; better than plugins that only cap client-side. |
| **History trimming** | `max_history_messages` (2–50) bounds token/cost per request. | On par with AI Chat Search. |
| **Kill switch** | `chat_enabled` toggle checked on every request. | On par. |
| **Safe IP detection** | `REMOTE_ADDR` + `FILTER_VALIDATE_IP`, with a `chathearth_client_ip` filter. | Intentionally spoof-resistant (ignores `X-Forwarded-For` by default, unlike AskAny/MXChat). |
| **Config clamping** | All limits clamped to sane min/max on save. | On par / better. |

### A2. What we use that the other plugins do NOT (ChatHearth advantages)

- **reCAPTCHA v2 integration** with a hashed, short-lived human-pass cookie — none of the five reviewed plugins ship CAPTCHA.
- **Automatic abuse escalation**: emails the admin, stores an admin notice, and can **auto-disable the chat** after repeated global-limit hits (with an email cooldown) — no other plugin alerts or self-protects like this.
- **Concurrency-safe counters**: atomic `wp_cache_incr` on an external object cache with a proper **`add_option` lock fallback** — the others use race-prone plain transients/options.
- **Spoof-resistant IP handling by default** (`REMOTE_ADDR` + `FILTER_VALIDATE_IP`, proxy headers only opt-in via filter) — AskAny/MXChat trust `X-Forwarded-For`/`HTTP_CLIENT_IP`, which is spoofable.
- **Hard min/max clamping of every limit on save** — prevents an admin from accidentally setting unsafe values.

### B. Recommended to add (meaningful gaps worth closing)

1. **Cache-safe nonce refresh endpoint.** We rely on the `wp_rest` nonce embedded in the page. Behind full-page caches (WP Rocket, LiteSpeed, Cloudflare APO) guests can receive a **stale nonce → 403** on first message. MXChat, AskAny and Ask My Content all expose a lightweight public endpoint that issues a fresh nonce (MXChat even rate-limits the issuer itself). **Highest-impact gap for real deployments.**
2. **AI content moderation.** No filtering of harmful/abusive input before it reaches the model. Adding an OpenAI moderation pass (or keyword/disallowed-content check) protects against ToS-violating usage and prompt-injection-style abuse. MXChat ships a moderation add-on; we currently have none.
3. **Per-role / logged-in-user quotas.** All users (guest or logged-in) currently share the same per-IP bucket. AskAny, MXChat, Chatbot/WPBot and AI Chat Search all grant **higher limits to trusted roles** and separate logged-in users from the shared guest IP pool. Fairer and reduces false positives for staff/customers.

### C. Extra protection used elsewhere — optional (nice-to-have, not critical)

1. **IP + email ban / blocklist** (MXChat `MX_Chat_Ban_Handler`): let an admin hard-block specific IPs/emails before any processing.
2. **Sliding-window rate limiting** (AI Chat Search): store request timestamps instead of fixed-window counters for more accurate limits and no burst-at-window-edge. Our fixed-window counters are simpler and cheaper — only worth it if precision matters.
3. **Honeypot field** in the chat form to trap naive bots cheaply (none of the reviewed plugins use this, but it's a low-cost addition alongside our CAPTCHA).
4. **Proxy/CDN-aware IP detection** (AskAny/MXChat read `X-Forwarded-For`): only behind a **trusted** proxy — otherwise it's spoofable. We could support it opt-in via the existing `chathearth_client_ip` filter.
5. **Admin tooling to clear rate-limit state** (AI Chat Search): a button to reset transients/counters when testing or after a false-positive block.
6. **Placeholder-rich, per-limit custom messages** (MXChat: `{limit}`, `{remaining}`, `{timeframe}`): friendlier UX than our fixed strings.
7. **Cron-based counter reset with fallback** (MXChat/Chatbot): our TTL-based expiry already handles this, so this is only relevant if we move to option-based counters.
8. **Auto-retry only on transient provider errors** (MXChat): improves resilience to upstream 429/5xx without retrying hard client errors.
