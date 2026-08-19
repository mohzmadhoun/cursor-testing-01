# Testing Round 02 — WordPress plugin review

**Date:** 15 July 2026  
**Plugin version:** 1.2.0  
**Scope:** WordPress plugin best practices, security checks, and code guidelines (follow-up to [testing-round-01.md](./testing-round-01.md))  
**Environment:** WordPress 7.0.1 at `mzmchatbot.local` (admin + front end verified in browser)

## Verdict

v1.1.x materially hardened the abuse surface called out in round 01 (atomic rate limiting, global limits + escalation, reCAPTCHA v2, generic AI errors + conditional logging, `mb_*` length checks, REST history sanitization, `ABSPATH` guards, empty `index.php` files). Foundations remain sound. Remaining issues are defense-in-depth, Settings API edge cases, request-size hardening, packaging/i18n polish, and one intentional availability trade-off (auto-disable on escalation).

---

## Round 01 follow-up status

| # | Round 01 item | Status in 1.2.0 |
|---|---------------|-----------------|
| 1 | Non-atomic transient rate counters | **Addressed** — `wp_cache_incr` when external object cache present; locked transients otherwise (`includes/Security/class-rate-limiter.php`) |
| 2 | IP-only limiting / no CAPTCHA | **Addressed** — site-wide limits + optional reCAPTCHA v2 + escalation/auto-disable |
| 3 | Raw provider errors to clients | **Addressed** (1.1.2) — generic client message; details via `Logger` only when `WP_DEBUG_LOG` |
| 4 | `__()` inside `Options::defaults()` | **Addressed** — English string defaults |
| 5 | `strlen` / `substr` for messages | **Addressed** — `mb_strlen` / `mb_substr` UTF-8 |
| 6 | Admin notices on every screen | **Accepted** (round 01 note) — intentionally global for `manage_options` |
| 7 | History REST `sanitize_callback` | **Addressed** — `sanitize_history` registered in `args` |
| 8 | Missing `ABSPATH` on includes | **Addressed** |
| 9 | Empty `index.php` in subdirs | **Addressed** |
| 10 | `max_history_messages` not in UI | **Still open** — and save path still resets it (see finding #2) |
| 11 | Provider/model labels not i18n | **Still open** |
| 12 | SVG via `innerHTML` | **Still open** (low) |
| 13 | Re-validate hex colors at localize | **Still open** (low) |

---

## What's already done well (confirmed)

- Bootstrap/`uninstall.php` guards; Settings API + `manage_options` + `sanitize_callback`
- Consistent escaping in admin templates; secret key not echoed (password field + blank-to-keep)
- REST: `permission_callback`, `wp_rest` nonce, input sanitization, role allowlist for history
- Protection stack: kill switch → reCAPTCHA (when keys set) → global limits → per-IP limits → AI
- Markdown path escapes HTML before parsing; user bubbles use `textContent`
- No OpenAI API key in plugin options (Connectors / env / constant)
- Abuse alert option saved with `autoload = false`; dismiss uses capability + nonce
- `declare(strict_types=1)`, namespaced classes, WPCS-style filenames

---

## Findings

### High

_None new._ Public chat remains an intentional cost surface; mitigations (limits, CAPTCHA, escalation) are adequate for v1 if reCAPTCHA keys stay configured in production.

### Medium

1. **Unbounded `history` array before capping** — `sanitize_history()` walks every turn before `handle_chat()` slices to `max_history_messages`. A large JSON `history` can waste CPU/memory before truncation. Prefer an early hard cap (e.g. reject or slice to ≤ 50) inside `sanitize_callback` / `validate_callback`. File: `includes/Rest/class-chat-controller.php`.

2. **`max_history_messages` reset on every settings save** — Field is not in the admin UI. `Options::sanitize()` uses `isset( $input[...] ) ? … : $defaults[...]`, so a missing key overwrites the merged value with the default (`20`). Any filter/`update_option` customization is lost on Save. Fix: keep `$out[$key]` when the input key is absent, **or** expose the field under Protection. File: `includes/class-options.php`.

3. **Auto-disable is an availability DoS against the chatbot** — Exhausting global limits (multi-IP) can trigger email + optional `chat_enabled = false`. Useful kill-switch, but attackers can turn chat off for everyone. Document clearly; consider requiring a higher threshold, a cool-down before re-arming, or admin confirmation instead of silent disable. File: `includes/Security/class-rate-limiter.php`.

4. **reCAPTCHA human-pass is cookie + transient, not bound to IP / session** — Expected UX trade-off (~1h unlock). Stolen cookie grants chat without another solve until expiry. Acceptable if rate limits remain; optional harden: bind pass to hashed IP or shorten TTL. File: `includes/Security/class-recaptcha.php`.

5. **No Privacy Policy suggested text** — Chat content is sent to a third-party AI provider. WordPress.org-style review often expects `wp_add_privacy_policy_content()` describing data leaving the site. Missing for GDPR/compliance polish. File: `includes/class-plugin.php` (or a small Privacy helper).

### Low / polish

6. **CSS color values not re-validated at `wp_localize_script`** — Stored values are sanitized on save via `sanitize_hex_color`; re-check (or fallback) when building `styles` for defense in depth. File: `includes/Frontend/class-assets.php`.

7. **Launcher SVG still injected with `innerHTML`** — Same-origin plugin asset today; prefer `<img src="…">` or a static inline SVG to avoid future XSS if the URL becomes configurable. File: `assets/js/frontend.js`.

8. **Provider/model labels not wrapped for i18n** — `'OpenAI'`, `'GPT-4.1'`, etc. in `Options::available_*()`. File: `includes/class-options.php`.

9. **`load_plugin_textdomain()` on `plugins_loaded`** — Prefer `init` (or omit for WordPress.org-hosted language packs) to match current WP i18n guidance. File: `includes/class-plugin.php`.

10. **Packaging hygiene** — Plugin directory contains `.git/` and `plan/` (internal docs). Exclude from distribution zip (`.distignore` / build script) so WordPress.org / customers do not get review notes or VCS metadata.

11. **Missing Plugins list “Settings” link** — `plugin_action_links_` shortcut to `options-general.php?page=chathearth` is common UX; absent today.

12. **Docs drift** — `plan/architecture.md` request-flow table omits reCAPTCHA (code runs kill switch → CAPTCHA → rate limits → AI). Update the diagram for accuracy.

13. **Global quota consumed even when per-IP later denies** — By design for flood control; document so admins understand multi-IP vs single-IP budgeting. File: `includes/Security/class-rate-limiter.php`.

14. **No `validate_callback` on REST `history`** — Sanitization exists; adding `validate_callback` for type/size would match WP REST best practice (pairs with finding #1).

---

## Guidelines checklist

| Area                                 | Status                                              |
| ------------------------------------ | --------------------------------------------------- |
| Plugin headers / GPL / text domain   | Good                                                |
| Namespace + WPCS-style class files   | Good                                                |
| Settings API + nonces                | Good                                                |
| Capabilities                         | Good (`manage_options`)                             |
| Escape on output / sanitize on input | Good                                                |
| REST `permission_callback`           | Present (nonce)                                     |
| Direct file access (`ABSPATH`)       | Good                                                |
| Directory listing (`index.php`)      | Good                                                |
| Uninstall cleanup                    | Options + abuse alert (transients TTL — OK for v1)  |
| Abuse / cost controls                | Stronger than round 01; still no token/$ ceiling    |
| Privacy Policy integration           | Missing                                             |
| Distribution packaging               | Partial (`.git` / `plan` present)                   |

---

## Browser verification

- Logged in at `https://mzmchatbot.local/wp-admin` as `testing@mzmchatbot.local`
- WordPress **7.0.1**; Settings → **ChatHearth - AI Chatbot** loads with tabs: Welcome, Protection, Appearance, AI Settings
- Protection tab: chatbot enabled; per-IP 10/min & 60/hour; global 60/min & 500/hour; escalate after 3; auto-disable on; max message length 2000; reCAPTCHA status **enabled** (site key set, secret saved/masked)
- No dependency warning on Dashboard (OpenAI / Connectors appear ready)
- Front end (`https://mzmchatbot.local/`): floating launcher renders; panel opens with welcome message, starter chips, composer; CAPTCHA checkbox not shown in this session (consistent with an existing human-pass cookie / prior unlock)

---

## Suggested follow-ups (for a later round)

1. Cap `history` size inside REST sanitization/validation (finding #1)
2. Preserve absent option keys in `Options::sanitize()` and/or expose `max_history_messages` in UI (#2)
3. Add `wp_add_privacy_policy_content()` for AI/third-party data flow (#5)
4. Re-validate hex colors when localizing styles (#6)
5. Prefer `<img>` for launcher icon (#7)
6. i18n + textdomain load timing (#8, #9)
7. `.distignore` / release zip excluding `.git` and `plan/` (#10)
8. `plugin_action_links` Settings shortcut (#11)
9. Align `architecture.md` with CAPTCHA in the request path (#12)
10. Token/cost ceilings (still planned in metrics roadmap — not in 1.2.0)
