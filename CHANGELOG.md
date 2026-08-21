# Changelog

A running record of the work done on this repository, newest first. Every pull
request gets an entry describing what changed, why, how it was verified, and the
commits it contains.

## How to add an entry

Add a new `##` section at the top for the pull request you are working on, using
the heading `## PR #<number> — <title>` followed by the date. Inside it, keep the
subsections that apply and drop the ones that do not:

- **Summary** — what the change accomplishes, in a sentence or two.
- **Added / Changed / Fixed / Removed** — the substance of the change.
- **Verification** — the checks that were run and their results, so a later
  reader can tell what was actually proven rather than assumed.
- **Notes** — decisions, trade-offs, and anything surprising that a future
  contributor would otherwise have to rediscover.
- **Commits** — each commit hash with its subject line.

Write for someone returning to this repository months from now with no memory of
the session. Prefer a sentence that explains a decision over a bullet that only
restates the diff. Use `_this entry_` for the changelog-only commit itself because
its hash cannot be known before the entry is committed.

---

## PR #4 — ChatHearth RAG, website grounding, and chat commerce

_2026-08-19_

### Summary

Adds Cursor Milestone 01 to ChatHearth: a knowledge base that exports selected site content to markdown, incrementally reindexes changed entries, retrieves passages from the WordPress database, always grounds the assistant in this website, and lets visitors compare products and add them to the WooCommerce cart from chat.

### Added

- `plugins/chathearth/plan/cursor-milestone-01.md` with the locked architecture and implementation steps for this milestone.
- Knowledge Base settings tab: source post types and taxonomies, site/store identity, Sync now, Test knowledge base, and per-entry include/exclude.
- Markdown exporters for pages, posts, public custom post types, WooCommerce products, taxonomies, site identity, and store summary; files under `uploads/chathearth/kb/`.
- Incremental indexer (`save_post`, terms, WooCommerce product hooks, settings changes) that re-embeds only a source whose markdown hash changed.
- Vector store: cosine search in `wp_chathearth_kb_chunks` (WordPress database).
- Always-on website grounding on `chathearth_system_prompt` (priority 5), including off-topic refusal even when RAG retrieval is off.
- Chat REST extras: `sources[]`, `products[]`, `commerce` cart/checkout URLs; `POST /chathearth/v1/cart` with the same `wp_rest` nonce.
- Widget UI: source chips, product cards, Compare these products, Add to cart, Markdown tables, and links to matching pages/posts/products.

### Changed

- Plugin version **1.4.0**.
- Catalog matching attaches product cards from names in the visitor message so comparison and add-to-cart still work when RAG is off or retrieval misses a product document.
- Comparison questions that name two or more products show those cards instead of every related RAG hit.
- Chat card prices use `wc_price()` and entity-decoding so visitors see `$18.00` rather than HTML entities.
- Plugin version **1.4.4**: RAG embeddings stay in the WordPress database. Chroma and Pinecone are not used; they need extra software or accounts.

### Removed

- Chroma HTTP client, Pinecone client, `bin/run-chroma.sh`, and the local Chroma starter in `.cursor/start.sh` / `.cursor/lib.sh`. The plugin must install and run on WordPress only.

### Verification

| Check | Result |
| --- | --- |
| `composer check` | PHPCS clean; PHPStan level 5 clean; 36 tests / 82 assertions (ChatHearth 18 tests, 58 assertions). PHPUnit injects vectors via `chathearth_pre_embed` and does not call OpenAI. |
| Live KB | 29 entries indexed (pages, posts, sample products, terms, `site:identity`, `site:woocommerce`) on the builtin store |
| Incremental index | Editing Sample Page (`post:2`) set only that `source_id` to `pending`, then `indexed` after `process_queue`; unchanged markdown hash-skipped |
| Include/exclude | `post:34` excluded (vectors dropped) then re-included and reindexed |
| Admin Knowledge Base tab | Enable RAG, WordPress-database storage note (no store picker), Sync now, Test knowledge base, post types, taxonomies, In RAG, search |
| Admin REST | `kb/status` indexed count, builtin ping ok; `kb/entries?search=Sample` returns Sample Page |
| Off-topic chat | Refused general trivia and stayed on this site (grounding) |
| Product compare (OpenAI) | Markdown table for Shirt vs Jacket with prices, stock, and product URLs; `products[]` included Shirt and Jacket |
| Page question (OpenAI) | Answered Sample Page with a link to `/sample-page/` and that page in `sources[]` |
| Cart service / REST | Shirt (17) and Jacket (25) added; responses include `cart_url` and `checkout_url` |
| Homepage widget | Launcher, `restUrl`/`cartUrl`, Add to cart / Compare these / Sources i18n, `woocommerce` enabled |
| Browser widget | Compare Shirt vs Jacket rendered a table, product cards, Compare these products, and sources; Add to cart on Jacket showed “Added to cart. View cart · Checkout”; cart page listed Jacket at $25.00 |
| Chat card prices | Shirt `$18.00`, Jacket `$25.00` after entity-decode |
| Shop-style cards (1.4.1) | Shirt thumbnail + `$20.00` struck through / `$18.00`; Jacket thumbnail + `$25.00`; dark rectangular Add to cart; cards in a horizontal scroller |
| Expand / restore | Header doubles the panel; restore returns original size; only one of the two controls is visible |
| Smooth resize | Width and height ease over ~0.4s in both directions; no snap or flicker |
| WordPress-only store | Knowledge Base has no Chroma/Pinecone fields; Test knowledge base reports the WordPress-database store |
| Runtime logs | Only earlier WP-CLI eval mistakes (`Repository` vs `Kb_Repository`); no widget/REST errors |

### Notes

- RAG retrieval defaults to **off** in plugin settings. This environment enabled it locally for live tests; that option is not committed.
- Embeddings use OpenAI `text-embedding-3-small` through the same Connectors key as chat. The plugin still does not store the OpenAI key.
- Chat can add items to the WooCommerce cart and send the visitor to cart/checkout. It does **not** complete payment.
- Official Chroma is a Python HTTP service. PHP cannot open a Chroma data folder, and this project does not install Python or other libraries for the plugin. Pinecone needs an extra account and settings. The shipped store is the WordPress database.
- This PR is stacked on `cursor/chathearth-plugin-36a8` so the diff is the RAG milestone only.

### Commits

- `0dcf7c7` Implement ChatHearth RAG, grounding, and chat commerce
- `0bb2d28` Match catalog products in chat even when RAG misses
- `5964a42` Fix PHPStan on WooCommerce product id query
- `748bcd8` Prefer named products on comparison chat cards
- `47ba910` Show decoded catalog prices on chat product cards
- `2a6b061` Document ChatHearth RAG milestone in the changelog
- `973d741` Style chat product cards and add a window size toggle
- `8f9a3a4` Document shop-style chat cards and expand/restore
- `47d319a` Animate chat window expand and restore
- `1760204` Document the chat window size animation
- `a717512` Clarify local Chroma files and improve store ping errors
- `c83cdad` Document local Chroma persist files versus HTTP
- `_this entry_` Store RAG embeddings in the WordPress database only

---

## PR #3 — Import ChatHearth as the first plugin project

_2026-08-19_

### Summary

Imported **ChatHearth - AI Chatbot 1.3.0** from the supplied archive and installed
it as the repository's first ongoing plugin project. The original source is
preserved as a baseline commit before repository-specific quality changes.

### Added

- `plugins/chathearth/` with its frontend chatbot, admin settings, OpenAI gateway,
  REST controller, moderation, rate limiting, optional reCAPTCHA, documentation,
  and product plans.
- Six integration tests covering bootstrap, defaults, settings boundaries, REST
  registration, missing-nonce rejection, and history sanitization.
- PHPStan discovery for WordPress 7's bundled PHP AI Client.

### Changed

- Applied the repository's WordPress coding standard to the imported source.
- Removed redundant runtime type checks exposed by PHPStan and simplified the
  reCAPTCHA cookie path to match the plugin's declared PHP 7.4 minimum.
- Kept the downloaded source and the integration changes in separate commits so
  future work can distinguish upstream behavior from repository maintenance.

### Verification

| Check | Result |
| --- | --- |
| Archive safety | One top-level directory; no path traversal or symlinks |
| Archive SHA-256 | `b4ba14e2b65742d2059e876b94f7f443ccba393d428ae2759bcc32fe8e11c198` |
| Plugin state | Active, version 1.3.0 |
| Frontend widget | Launcher, welcome, starters, input, and controls render |
| Direct REST/OpenAI request | Returned exactly `ChatHearth works` |
| Browser chat | Returned exactly `Browser chat works` |
| Admin settings | Welcome, Protection, Appearance, and AI Settings tabs render |
| Protection settings | Rate limits, moderation, reCAPTCHA, and kill switch present |
| ChatHearth tests | 6 tests, 17 assertions |
| Full suite | PHPCS/PHPStan clean; 24 tests, 41 assertions |
| Runtime logs | No PHP or application errors |

### Notes

- WordPress core, uploads, database state, generated ZIP files, and credentials
  remain outside Git. Only the plugin's source, tests, and documentation belong
  under `plugins/chathearth/`.
- Future ChatHearth work should use one focused branch and PR per feature or fix,
  with regression tests and browser/API evidence added alongside the change.
- The public chat endpoint consumes a paid AI API. Existing per-IP/global limits,
  moderation, and kill switch are therefore part of the product's safety
  boundary and must remain covered as the plugin evolves.
- The OpenAI credential remains owned by WordPress Connectors; ChatHearth does
  not store it.

### Commits

| Commit | Subject |
| --- | --- |
| `0a21dd1` | Import ChatHearth AI Chatbot 1.3.0 |
| `d5fad2d` | Bring ChatHearth under repository quality gates |
| _this entry_ | Document the project import and workflow |

---

## PR #1 — Add the MZM Current Year shortcode plugin

_2026-08-19_

### Summary

Added a small plugin that renders the current year anywhere WordPress processes
the `[mzm-current-year]` shortcode.

### Added

- `plugins/mzm-current-year` — version 0.1.0 of **MZM Current Year**.
- `[mzm-current-year]` returns the four-digit year from `wp_date( 'Y' )`, so the
  output follows WordPress's configured timezone instead of the server timezone.
- Integration tests for plugin loading, shortcode registration, direct output,
  and rendering through filtered post content.

### Verification

| Check | Result |
| --- | --- |
| Plugin state | Active; version 0.1.0 |
| Direct WP-CLI rendering | `2026` |
| Published page | Browser rendered `Copyright © 2026` |
| Plugin tests | 4 tests, 5 assertions |
| Full repository suite | PHPCS/PHPStan clean; 18 tests, 24 assertions total |
| Browser/admin errors | None |

### Commits

| Commit | Subject |
| --- | --- |
| `7678d33` | Add the MZM current-year shortcode plugin |
| _this entry_ | Document the plugin and verification evidence |

---

## PR #1 — WordPress plugin development environment for Cloud Agents (continued)

_2026-08-17_

### Summary

Added WooCommerce and the official OpenAI provider to the environment. Every
future agent starts with a stocked store and an OpenAI connector ready for a
runtime credential, so commerce and AI plugin work need no stack setup first.

### Added

- `data/sample-products.csv` — nine sample fashion products in WooCommerce's own
  product CSV format, kept in the repository so the catalogue is reproducible.
- `bin/import-products.php` — imports a product CSV through WooCommerce's own
  importer, so category hierarchies, attributes, stock, dimensions and remote
  images are handled exactly as the Products → Import screen handles them. Run it
  with `wp eval-file bin/import-products.php <csv> --user=admin`.
- `install_woocommerce` and `seed_products` in `.cursor/lib.sh` — install and
  activate WooCommerce, configure the store for development, and seed the
  catalogue when the store is empty.
- `install_ai_provider` — installs and activates **AI Provider for OpenAI**,
  which registers `openai` with WordPress 7's bundled PHP AI Client and exposes
  its credential under Settings → Connectors.

### Changed

- `.cursor/install.sh` provisions the store as part of the normal install.
- `bin/wp-reset.sh` rebuilds the store as well as the site. The reset drops
  WooCommerce's tables, settings, pages, and products along with everything else,
  so it would otherwise have left an empty store behind.
- WordPress core is downloaded as a ZIP and verified against its official
  checksums. A bad core tree is replaced exactly while preserving `wp-content`
  and `wp-config.php`.
- `start_apache` passes `OPENAI_API_KEY` from the agent environment into mod_php
  without writing the value to disk. It restarts Apache when the key is added,
  changed, or removed, tracking only a one-way fingerprint under `/run`.

### Verification

| Check | Result |
| --- | --- |
| Clean database, full install | Site and store built in about 15 seconds, no warnings |
| Products imported | 9 created, 0 failed, 0 skipped |
| Categories | `Clothing` with `Shirts` (4), `Accessories` (3), `Jackets` (1), `Sweater` (1) |
| Images | All 9 products have a featured image; Sweater also has its gallery image |
| Shop page | "Showing all 9 results" with prices and sale badges |
| Product page | Image, price, SKU, and a `Clothing / Jackets` breadcrumb |
| Cart and checkout in a browser | Jacket added, cart subtotal $25.00, checkout renders with cash on delivery available |
| Admin products screen | All 9 listed with thumbnails, SKUs, prices, categories |
| Onboarding suppressed | No coming-soon banner, wizard, task list, or setup widget |
| `bin/wp-reset.sh --yes` | Site and store rebuilt, 9 products re-imported |
| Install idempotence | Second run reports "Store already has 9 products" and changes nothing |
| `bin/test.sh`, `composer lint`, `composer analyse` | Still clean with WooCommerce active |
| Missing AI Client class repair | Deliberately truncated the class, removed the provider and reset the database; one install repaired core, installed the provider, and rebuilt the store |
| Core integrity | `wp core verify-checksums` passes with no warnings |
| OpenAI provider | Plugin 1.0.3 active; provider ID `openai` registered |
| Runtime key handoff | Environment secret produced authentication in WP-CLI and mod_php; no key value in database, repository, Apache config, or install logs |
| OpenAI API | Provider validation reports the credential valid and OpenAI reachable |
| Connectors UI | Browser shows “Connected”, a masked key, and “configured using an environment variable” |
| Compatibility | WooCommerce remains active and all nine products still render after provider installation |
| Environment build | `bld-20260817-d4a574ce-c290-4612-89f3-b8471ec319d1` succeeded |
| Fresh agent from exact build | Automatic start exited 0; locks recreated; site/store HTTP 200; 9 products; plugins active; checksums and `composer check` pass; logs clean |

### Notes

- **The importer needs a user.** WooCommerce checks `manage_product_terms` before
  creating product categories and, without a user, drops every category without
  raising an error — the first import produced nine products all in
  "Uncategorized". `bin/import-products.php` now refuses to run unless the current
  user has the capability, rather than leaving that to be rediscovered.
- **`update_existing` means update-only.** Passing it made the importer skip all
  nine rows with "No matching product exists to update". Seeding wants create
  mode; SKUs are unique, so re-running cannot duplicate products.
- **"Coming soon" mode hides the whole store.** New WooCommerce installs enable
  it, which replaced every store page with a launch placeholder while returning
  HTTP 200 — the shop looked fine to a status-code check and was empty to a
  reader.
- **Hiding the setup task list takes the right option.** The dashboard's
  "WooCommerce Setup" widget consults `woocommerce_task_list_hidden_lists`;
  `woocommerce_admin_task_list_hidden` alone left it on screen. WooCommerce
  renames these over time, so each store option is written tolerantly and a
  rejected write warns instead of aborting the install.
- **Three products are not purchasable, by data rather than by fault.** Blouse
  carries no price in the CSV, and Sweater and Socks are variable products whose
  variation rows the file does not contain, so the shop shows "Read more" for
  them. Left faithful to the supplied file rather than invented.
- `WC_Product_CSV_Importer_Controller::auto_map_columns()` is protected, so the
  script subclasses the controller to reach it. That is deliberate: the
  alternative is maintaining a copy of every column name WooCommerce understands.
- **The provider uncovered silent core corruption.** WP-CLI downloaded WordPress
  as a tar archive. The archive format's 100-character filename field truncated
  16 paths in the bundled PHP AI Client, often removing the `.php` extension.
  WordPress otherwise worked, but AI Provider for OpenAI fatally missed
  `ListModelsApiBasedProviderAvailability`. ZIP preserves all names. Core
  checksums now detect and self-repair both missing full names and stale
  shortened files.
- **The OpenAI key is runtime state, not repository state.** Future agents need
  a Cursor Cloud secret named `OPENAI_API_KEY`. Ubuntu's SysV Apache wrapper
  strips arbitrary variables even when `sudo` preserves them, so keyed starts
  use `apache2ctl` directly with `PassEnv`. The value is never written to the
  repository, Apache configuration, WordPress database, logs, or snapshots.
- **Snapshot boots lose `/run`.** `/var/lock` points into that volatile tree, so
  environment builds first lacked `/var/lock/apache2`, then proved the
  `/run/lock` parent itself is absent. Apache configuration now recreates the
  sticky parent and the package directory with Debian's expected ownership and
  modes both during install and runtime startup. This distinction matters:
  building the image succeeded before a fresh agent proved `start.sh` still
  encountered the same empty volatile tree.
- The persistent install log contains only the latest run. Appending preserved
  resolved warnings in a successful snapshot and made a fresh agent correctly
  flag historical failures as current health problems.
- Branch-specific environment builds can be tested but cannot become active.
  The final build passed, but Cursor will only promote a build made from the
  repository's default branch. Merge PR #1, then build the default branch.
- A draft-build agent does not inherit a newly supplied, unsaved environment
  secret. The exact-build agent proved all code and runtime behavior but correctly
  reported no `OPENAI_API_KEY`; the current agent proved the supplied key valid
  end to end. Saving the proposed environment is the step that makes the secret
  available to ordinary future agents.

### Commits

| Commit | Subject |
| --- | --- |
| `e2a0ec0` | Install WooCommerce and seed the sample catalogue |
| `1f15c06` | Install the OpenAI AI provider by default |
| `285ac64` | Preserve the OpenAI environment during Apache config checks |
| `8a62b41` | Recreate Apache lock state after snapshot boot |
| `259ead8` | Recreate the volatile lock parent after snapshot boot |
| `00faf89` | Recreate volatile runtime state during startup |
| _this entry_ | Document the OpenAI provider, credential setup, and verification |

---

## PR #1 — WordPress plugin development environment for Cloud Agents

_2026-08-17_

### Summary

Turned an empty repository into a WordPress plugin development workspace. A Cloud
Agent started here boots with a running WordPress site and the tooling to write,
test, and lint a plugin in the same session, so plugin work can be requested
directly of an agent without any local setup.

### Added

**Environment definition (`.cursor/`)**

- `environment.json` declares the environment: runs as `ubuntu`, installs with
  `install.sh`, starts with `start.sh`, forwards port 8080, and opens two
  terminals that tail the WordPress debug log and the Apache logs.
- `install.sh` builds the stack once: Apache 2.4, PHP 8.3 with the extensions
  WordPress expects, MariaDB 10.11, WP-CLI 2.12.0, Composer, WordPress itself at
  `/var/www/wordpress`, the WordPress core PHPUnit test suite at
  `/var/www/wp-tests-lib`, Query Monitor, and the repository's Composer
  dependencies. It is idempotent and keeps a copy of its output in
  `/tmp/wordpress-install.log`.
- `start.sh` runs on every boot: starts MariaDB and Apache, recreates the
  `wp-content` symlinks for the plugins in the checked-out revision, prunes links
  to directories that no longer exist, and checks that the site answers.
- `lib.sh` holds the helpers both scripts share, including the service startup
  that tolerates stale pidfiles and the `.htaccess` rewrite block.

**Tooling**

- `composer.json` with PHPUnit 9.6, PHP_CodeSniffer with the WordPress Coding
  Standards, PHPCompatibilityWP, PHPStan, and the WordPress stubs. `composer check`
  runs lint, static analysis, and tests together.
- `phpcs.xml.dist` applies the `WordPress` standard to `plugins/`, targeting
  WordPress 6.5 and PHP 8.1 and up, relaxing only the shipped-code documentation
  and output-escaping rules for test files.
- `phpstan.neon.dist` analyses `plugins/` at level 5 with WordPress stubs.
- `bin/new-plugin.sh` scaffolds a plugin, symlinks it into the site, and activates
  it. `bin/test.sh` runs one or every plugin's PHPUnit suite. `bin/wp-reset.sh`
  rebuilds the site without touching repository code.
- `wp-cli.yml` points WP-CLI at the site so `wp` works from the repository root.

**Reference plugin (`plugins/hello-cursor`)**

A plugin meant to be copied, showing the structure the workspace expects: the
plugin header and constants in the main file, one class per responsibility under
`includes/`, and integration tests under `tests/`. It implements a settings page,
the `[hello_cursor]` shortcode, and `GET /wp-json/hello-cursor/v1/greeting`, all
driven by the same configurable greeting.

**Documentation**

`README.md` explains what the environment provides, how to ask an agent for a
plugin, and the commands for testing, linting, debugging, and resetting the site.

### Verification

| Check | Result |
| --- | --- |
| Home page, admin, pretty permalinks | HTTP 200 |
| Shortcode on a published page | Renders `Hello, Ada!` with the plugin stylesheet enqueued |
| REST route | `{"greeting":"Hello, Ada!","name":"Ada"}`, and follows the settings value |
| Admin round-trip in a browser | Changed the greeting to `Howdy` in Settings → Hello Cursor, saw it on the front end, restored it |
| PHPUnit | 14 tests, 19 assertions, all passing |
| PHPCS and PHPStan level 5 | Clean |
| `bin/new-plugin.sh` output | Passes tests, lint, and static analysis unchanged |
| Install idempotence | Passed twice; the second run takes about two seconds |
| Full stop, then `start.sh` alone | Site back to HTTP 200, tests still pass |
| Fresh Cloud Agent on the branch | Stack ready 80 seconds after boot with no manual step; every check above reproduced, `debug.log` and the Apache error log clean |

### Notes

- **WordPress core is deliberately outside the repository.** It lives at
  `/var/www/wordpress` so it survives checkouts and is never committed. Only
  `plugins/` (and `themes/`, if added) is versioned, and it is symlinked into
  `wp-content/` on each boot. This is why per-boot symlink reconciliation belongs
  in `start.sh` rather than `install.sh`.
- **No credentials are stored anywhere.** The database account is authenticated
  by MariaDB's `unix_socket` plugin, and the site's admin password is a local
  placeholder. The trade-off is that Apache, WP-CLI, and PHPUnit must all run as
  the same non-root user that installed the site, which is why `environment.json`
  pins `"user": "ubuntu"` and `install.sh` refuses to run as root.
- **WP-CLI never writes the `.htaccess` rewrite block.** It runs under the CLI
  SAPI, so WordPress does not detect Apache; `wp rewrite structure` silently skips
  the file. Pretty permalinks and `/wp-json/` both returned 404 until
  `ensure_htaccess` was added.
- **Composer needs a GitHub token.** Anonymous clients get 60 requests an hour
  and this dependency tree needs about 45, which reliably tripped a 429. The
  install reuses whichever token the machine already has, never writes it to disk,
  and falls back to throttled retries and `--prefer-source`.
- **PHPStan cannot see constants defined from function calls.** A plugin defining
  `MY_PLUGIN_URL` with `plugin_dir_url( __FILE__ )` and using it from another file
  looks undefined. `bin/phpstan-bootstrap.php` declares those names for every
  plugin automatically, so the false positive does not return with each new plugin.
- **Prebuilt environment builds were not available.** Two draft builds failed
  within 30 seconds with no logs, which looked platform-side rather than caused by
  these scripts. Boots therefore run `install.sh`, measured at 80 seconds on a
  clean machine. Enabling Environment Builds later would bake that into the base
  image.

### Commits

| Commit | Subject |
| --- | --- |
| `a9ebd8a` | Add Cloud Agent environment for WordPress development |
| `1c97b7c` | Add plugin development tooling |
| `6f1a444` | Add reference plugin and workspace documentation |
| `99c2766` | Throttle Composer downloads when no GitHub token is available |
| `d479a2f` | Fail clearly when install runs as root |
| `92f0e61` | Keep a copy of the install output |
