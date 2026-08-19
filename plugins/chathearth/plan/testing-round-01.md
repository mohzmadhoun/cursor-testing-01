# Testing Round 01 — WordPress plugin review

**Date:** 15 July 2026  
**Plugin version:** 1.0.8  
**Scope:** WordPress plugin best practices, security checks, and code guidelines  
**Environment:** WordPress 7.0.1 at `mzmchatbot.local` (admin verified in browser)

## Verdict

The plugin is in solid shape for a v1 release. It correctly uses the Settings API, capability checks, escaping, REST nonces, rate limits, and does not store API keys. Verified in admin (`Settings → ChatHearth - AI Chatbot`) and on the front end (launcher + widget). Remaining issues are mostly hardening and WordPress.org-style polish, not foundational security holes.

---

## What's already done well

- `ABSPATH` guard in bootstrap; `WP_UNINSTALL_PLUGIN` in `uninstall.php`
- Settings under `manage_options` + `register_setting` with a real `sanitize_callback`
- Admin output consistently escaped (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`)
- REST uses `permission_callback`, `wp_rest` nonce, input sanitization, history role/content allowlist
- Kill switch, message length caps, provider/model allowlists, hex colors via `sanitize_hex_color`
- Markdown path escapes HTML before parsing; user bubbles use `textContent`
- No API key in plugin options (Connectors / env / constant)

---

## Findings

### High — API cost / abuse surface

Public `POST /chathearth/v1/chat` is intentionally open to anyone who can load the page nonce. Rate limits help, but:

1. **Transient counters are not atomic** — concurrent requests can race and exceed limits (`includes/Security/class-rate-limiter.php`).
2. **IP-only limiting** — shared IPs (NAT, VPN) and rotating IPs weaken protection; no CAPTCHA / challenge / token budget.
3. **Provider errors are returned raw** to clients (`$result->get_error_message()` in `includes/Ai/class-ai-gateway.php`) — can leak upstream details. **Fixed (v1.1.2):** clients get a generic message; upstream code/message (plus provider/model) are written via `Logger` only when `WP_DEBUG_LOG` is already enabled.

**Recommendation:** Harden rate limiting (e.g. atomic counters / object cache), map AI errors to generic client messages (log details server-side), and plan cost ceilings / kill-switch integration from the metrics roadmap.

### Medium

4. **`__()` in `Options::defaults()`** — running at activation stores locale-frozen strings in the option. Prefer English defaults in PHP (or translate only at display time for unsaved keys). File: `includes/class-options.php`.
5. **`strlen` / `substr` for message length** — byte-based; UTF-8 can truncate mid-character. Prefer `mb_strlen` / `mb_substr`. File: `includes/Rest/class-chat-controller.php`.
6. **Admin notice on every admin screen** (`includes/Admin/class-admin-notices.php`) — prefer plugins/settings pages only to match WP UX norms. -- It's better to have the Admin notices on all pages, so the website admin get notified if something went wrong.
7. **History REST arg has no `sanitize_callback`** — sanitized in the handler (good), but declaring it in `args` is clearer and safer for the API layer. File: `includes/Rest/class-chat-controller.php`.

### Low / polish

8. Missing `ABSPATH` guards on included PHP files (common for WordPress.org review checklists).
9. No empty `index.php` in plugin subdirs (directory listing hardening).
10. `max_history_messages` is sanitized but not exposed in settings UI; save path falls back to defaults when absent.
11. Model/provider labels (`'OpenAI'`, `'GPT-4.1'`, …) are not wrapped for i18n.
12. SVG loaded via `innerHTML` from a same-origin plugin file — low risk today; prefer `<img>` or sanitized SVG if uploads are added later. File: `assets/js/frontend.js`.
13. Frontend trusts CSS vars from localized settings — fine after `sanitize_hex_color`; re-validate hex when localizing for defense in depth. File: `includes/Frontend/class-assets.php`.

---

## Guidelines checklist

| Area                                 | Status                                  |
| ------------------------------------ | --------------------------------------- |
| Plugin headers / GPL / text domain   | Good                                    |
| Namespace + WPCS-style class files   | Good                                    |
| Settings API + nonces                | Good                                    |
| Capabilities                         | Good (`manage_options`)                 |
| Escape on output / sanitize on input | Good                                    |
| REST `permission_callback`           | Present (nonce)                         |
| Uninstall cleanup                    | Options only (OK for v1)                |
| Direct file access on all PHP        | Partial                                 |
| Abuse / cost controls for AI         | Partial — rate limit + kill switch only |

---

## Browser verification

- Logged in at `https://mzmchatbot.local/wp-admin` as `testing@mzmchatbot.local`
- Settings page loads with tabs: Welcome, Protection, Appearance, AI Settings
- Protection tab shows enable chatbot, rate limits (10/min, 60/hour), max message length 2000
- Front end shows the floating launcher; chat UI mounts correctly

---

## Suggested follow-ups (not done in this round)

- ~~Generic AI error messages to clients; log provider details server-side~~ (done in v1.1.2)
- Multibyte-safe message length checks (`mb_*`)
- Scope admin notices to relevant screens
- Atomic / stricter rate limiting
- REST `sanitize_callback` (and optionally `validate_callback`) for `history`
- `ABSPATH` guards + empty `index.php` files in subdirectories
- Avoid storing translated strings via `__()` inside defaults at activation
