# Testing Round 03 — Pre-upload review response verification

**Date:** 18 July 2026
**Plugin version:** 1.2.0 (slug `chathearth`)
**Scope:** Verify every issue raised in the two WordPress.org reviewer emails is resolved, regression-check this session's edits, and re-check the open items from [testing-round-02.md](./testing-round-02.md) before uploading.
**Method:** Static review of the current source tree + `php -l` on all plugin PHP files. Cross-referenced against the reviewer emails (Review IDs `palchat-ai-chatbot/palwp/18Jul26/T1` and `AUTOPREREVIEW ... 17Jul26`), the WordPress.org guidelines, and the two project canvases (`plugin-guideline-audit`, `plugin-review-response-audit`).

## Verdict

Ready to upload. All five reviewer-flagged issues are resolved in code, `php -l` passes on every file, and the recent rename/cleanup introduced no regressions. The only remaining action is non-code: the new slug `chathearth` must be confirmed by the Plugins Team (already requested). Carried-over round 02 items are minor/polish and none block the directory submission.

---

## Reviewer email issues — resolution status

| # | Reviewer issue | Status | Evidence in current tree |
|---|----------------|--------|--------------------------|
| 1 | Translation files included (`.po`/`.mo`) | **Resolved** | `languages/` now holds only `chathearth.pot` (template) + `index.php` (guard). `en.po`/`en.mo` removed; no `.po`/`.mo` anywhere in the plugin. |
| 2 | Undocumented 3rd-party / external service | **Resolved** | `readme.txt` `== External services ==` documents OpenAI and Google reCAPTCHA v2 (what is sent / when + Terms of Service and Privacy Policy links) plus a Privacy & consent block. |
| 3 | Reading AI Client API keys directly | **Resolved** | `includes/class-plugin.php` uses `\WordPress\AiClient\AiClient::defaultRegistry()->isProviderConfigured('openai')` (`is_provider_configured()`, L108-119). No `get_option('connectors_ai_openai_api_key')` remains. |
| 4 | Generic / short prefix `mzm` | **Resolved** | Constants `CHATHEARTH_*`, namespace `ChatHearth`, options/hooks `chathearth_*`. No `mzm`/`MZM`/`palchat` strings remain in `*.php/js/css/txt/md` (grep: 0 matches). |
| 5 | Name / trademark — "PalChat" | **Resolved in code / awaiting slug reservation** | Display name `ChatHearth - AI Chatbot`, Text Domain + slug + folder + main file `chathearth`. Zip uploaded and new slug requested; pending Plugins Team confirmation (Text Domain warning until then is expected). |

---

## Regression check — this session's edits

| Change | Verified |
|--------|----------|
| Removed `migrate_legacy_options()` + both call sites | `activate()` only seeds defaults; `init()` only registers services. No dangling `self::migrate_legacy_options()` reference (grep: 0). |
| Removed legacy `mzm_chatbot_*` deletes from `uninstall.php` | File now deletes only `chathearth_settings` and `chathearth_abuse_alert`; `WP_UNINSTALL_PLUGIN` guard intact. |
| Removed bundled `languages/en.po` | Confirmed gone; changelog note in `readme.txt` matches reality. |
| PHP syntax | `php -l` (XAMPP PHP) passes on **all** plugin PHP files — no parse errors. |

Note: dropping `migrate_legacy_options()` means a site that previously stored settings under the old `mzm_chatbot_settings` key will start from defaults on activation. Acceptable for an unpublished plugin with no existing installs.

---

## Round 02 follow-up status

| Round 02 item | Status in this build |
|---------------|----------------------|
| #1 Unbounded `history` before cap | **Partly open** — `sanitize_history()` still walks every turn; `handle_chat()` caps via `array_slice(-max_history)` (L183-184). Consider an early hard cap. Low risk. |
| #2 `max_history_messages` reset on save | **Open (minor)** — not exposed in UI; `Options::sanitize()` L246 falls back to default `20` when the key is absent, so it resets on every Save. No functional break (clamped 2-50). |
| #3 Auto-disable availability trade-off | **Open by design** — documented behaviour; not a submission blocker. |
| #4 reCAPTCHA pass not IP-bound | **Open by design** — ~1h cookie+transient unlock; acceptable with rate limits. |
| #5 Privacy Policy suggested text | **Resolved** — `includes/Admin/class-privacy.php` registers `wp_add_privacy_policy_content()` covering AI, local storage, IP use, reCAPTCHA, and API-key handling. |
| #6 Re-validate hex at localize | **Open (low)** — values sanitized on save via `sanitize_hex_color`. |
| #7 Launcher SVG via `innerHTML` | **Open (low)** — same-origin plugin asset. |
| #8 Provider/model labels not i18n | **Open (low)** — `'OpenAI'`, `'GPT-4.1'`, etc. in `Options::available_*()`. |
| #9 `load_plugin_textdomain()` timing | **Resolved** — call removed; relies on WordPress.org language packs (correct for hosted plugins). |
| #10 Packaging (`.git`/`plan/`) | **Open — action before zip** — see Packaging below. |
| #11 Plugins list "Settings" link | **Open (UX polish)** — no `plugin_action_links_` filter. |
| #12 `architecture.md` omits reCAPTCHA | **Open (docs)** — internal doc; excluded from zip anyway. |

---

## Packaging checklist (do before/at zip)

- Exclude the `plan/` folder (contains `implementation-plan.md` referencing the literal `connectors_ai_openai_api_key` and local `mzmchatbot.local` URLs — could re-trigger scanners) and the redundant `README.md` from the uploaded zip. No `.distignore` exists yet; either add one or build the zip without these.
- No `.git/` directory is present inside the plugin folder (good).
- `languages/chathearth.pot` may stay (template, allowed).
- Optional: bump `Tested up to:` from `7.0` to the tested `7.0.1` to match the verification environment.

---

## Guidelines checklist

| Area | Status |
|------|--------|
| GPL / headers / text domain (matches slug) | Good |
| Unique prefixes (declarations, options, hooks, namespace) | Good |
| No direct read of Connectors AI keys | Good |
| External services documented (OpenAI, Google reCAPTCHA) | Good |
| No bundled translations | Good |
| Name/slug distinctiveness & trademark | Renamed; slug reservation pending |
| Escaping / sanitization / nonces / capabilities | Good (unchanged from round 02) |
| Privacy Policy integration | Good |
| Distribution packaging (`plan/`, `README.md`) | Action before zip |

---

## Canvas cross-reference

- `plugin-review-response-audit` — current and accurate for this build (4 code blockers resolved, name/slug awaiting confirmation).
- `plugin-guideline-audit` — **stale**: describes v1.1.3 under the old `PalChat`/`MZM` naming and an invalid WP-version note. Treat as superseded by this round and the review-response canvas.

---

## Go / no-go

**GO** for upload. Blocking review issues are cleared and the build is syntactically clean. Before creating the zip: drop `plan/` and `README.md`. After upload: wait only on the Plugins Team to reserve the `chathearth` slug; remaining items are post-approval polish for a later release.
