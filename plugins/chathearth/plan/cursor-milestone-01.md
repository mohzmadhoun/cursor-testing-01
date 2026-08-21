---
name: ChatHearth Cursor Milestone 01 — RAG, grounding, and chat commerce
overview: "First RAG release: export selected site content to markdown, admin dashboard to choose what is indexed, incremental reindex of changed entries, WordPress-database embeddings (no extra server), always-on site-grounded system prompt, and frontend comparison plus add-to-cart from chat."
todos:
  - id: plan
    content: Record architecture decisions and implementation steps in this file
    status: completed
  - id: schema-options
    content: KB tables, uploads markdown directory, RAG settings, activation/upgrade
    status: completed
  - id: exporters
    content: Markdown exporters for posts/CPTs, products, taxonomies, site/store identity
    status: completed
  - id: sync
    content: Scan sources, hash-based skip, incremental hooks, WP-Cron queue, embeddings
    status: completed
  - id: vector-stores
    content: Vector_Store_Interface plus WordPress-database cosine search
    status: completed
  - id: admin
    content: Knowledge Base settings tab — sources, sync, per-entry include
    status: completed
  - id: grounding
    content: Always-on site context + off-topic refusal in the system prompt (RAG on or off)
    status: completed
  - id: retrieval
    content: Query-time retrieve, inject context, return sources and matched products
    status: completed
  - id: commerce
    content: Product comparison prompting, add-to-cart REST, chat UI actions and links
    status: completed
  - id: tests-docs
    content: PHPUnit coverage, PHPCS/PHPStan, README/changelog, browser and REST checks
    status: completed
isProject: false
---

# Cursor Milestone 01 — RAG, grounding, and chat commerce

This is the first RAG release for ChatHearth. It implements future items **1–5** and **3b** from [`functionalities.md`](functionalities.md), using the hooks reserved in [`architecture.md`](architecture.md).

v1 already ships the chat widget, OpenAI via Connectors, and filters `chathearth_system_prompt` / `chathearth_before_generate`. This milestone plugs retrieval and commerce into those contracts. It does **not** add N8N, streaming, server-side chat logs, or manual document upload/versioning (those remain later roadmap items).

## Goals

1. **Export site content to markdown** for pages, posts, public custom post types, WooCommerce products, taxonomies (core and custom), and other useful store/site identity data.
2. **Admin dashboard** to choose which post types, taxonomies, and individual entries are in the knowledge base.
3. **Incremental updates:** when a source changes, regenerate and re-embed **only that entry** (hash skip if the markdown is unchanged). Deletes/trash remove the entry from the store.
4. **WordPress-only vector store:** embeddings live in `wp_chathearth_kb_chunks` with cosine search in PHP. Chroma is a Python HTTP service and cannot run inside a WordPress plugin zip. Pinecone needs an external account and extra settings. Neither is used.
5. **Always-on grounding:** even when RAG retrieval is off, the system prompt includes main website data (name, tagline, URL, key pages, store facts) and **must refuse** questions that are not about this website.
6. **Frontend:**
   - Answer questions about pages, posts, products, and other indexed data.
   - Include **Markdown links** to the matching URLs.
   - **Compare products** using catalog/KB facts only (structured table / side-by-side).
   - **Add products to the WooCommerce cart** from the chat (then the visitor can check out on the store).

## Non-goals (this milestone)

- Manual KB document upload, version history UI, or N8N.
- Auto-placing a paid order (payments still happen on WooCommerce checkout). Chat can add to cart and send the visitor to cart/checkout.
- Streaming replies, extra AI providers, server-side conversation transcripts.
- Extra server software (Python, Chroma, Pinecone) or extra settings beyond the Knowledge Base tab.

## Architecture

```
Content change (save_post, terms, Woo, options)
        → Markdown exporter → uploads/chathearth/kb/{id}.md
        → KB table (hash, include flag, status)
        → if hash changed: embed → Vector store upsert (only that id)

Visitor chat
        → Kill switch / CAPTCHA / limits / moderation (unchanged)
        → Site grounding always injected into system prompt
        → if RAG enabled: embed query → vector query → inject top-k chunks
        → Ai_Gateway generate
        → reply + sources[] + products[]
        → widget renders links, comparison-friendly markdown, Add to cart
```

Retrieval uses the existing `chathearth_system_prompt` filter. Commerce uses a new REST route; it does not send the OpenAI key anywhere new.

### Vector store contract

```php
interface Vector_Store_Interface {
    public function upsert( array $records ): bool;
    public function delete( array $ids ): bool;
    /** @return list<array{id:string,score:float,content:string,meta:array}> */
    public function query( array $embedding, int $limit = 5 ): array;
    public function ping(): bool;
}
```

| Driver | When to use | How |
|--------|-------------|-----|
| `builtin` | Always (shipped store) | Embeddings in `wp_chathearth_kb_chunks`; cosine similarity in PHP |

Embeddings: OpenAI `text-embedding-3-small` via the **same Connectors key** already used for chat/moderation (`AiClient` registry). The plugin still does not store the OpenAI key. Tests inject vectors through `chathearth_pre_embed` so PHPUnit never calls the network.

### Data model

**Table `{$wpdb->prefix}chathearth_kb_entries`**

| Column | Role |
|--------|------|
| `source_id` | Stable id, e.g. `post:123`, `term:45`, `site:identity`, `site:woocommerce` |
| `object_type` | `post`, `term`, `site` |
| `object_id` | WP object id (0 for site docs) |
| `post_type` / `taxonomy` | For filtering |
| `title`, `url` | Shown as citations |
| `markdown` | Full generated document |
| `content_hash` | SHA-256; skip re-embed when unchanged |
| `included` | Per-entry include/exclude |
| `status` | `pending`, `indexed`, `excluded`, `error` |
| `error_message` | Last index error (no secrets) |

**Table `{$wpdb->prefix}chathearth_kb_chunks`**

Chunk text + embedding JSON in the WordPress database. Chunk ids: `{source_id}-c{n}`.

**Files:** `wp-content/uploads/chathearth/kb/{sanitized-source-id}.md` with YAML front matter (`id`, `type`, `title`, `url`, `updated`). Directory is not publicly listable.

### Source types

| Source | Markdown contents |
|--------|-------------------|
| Post / page / CPT | Title, permalink, excerpt, body (blocks expanded, shortcodes stripped), taxonomies |
| Product | Name, permalink, SKU, price, stock, short + full description, categories/tags, attributes, purchasable flag, variation summary |
| Term | Name, description, permalink, taxonomy, count |
| `site:identity` | Blog name, tagline, URL, language, public page title+URL list (capped) |
| `site:woocommerce` | Shop/cart/checkout URLs, currency, catalog size, category names (when WooCommerce is active) |

Only **published** posts and non-empty public terms are indexed. Revisions, autosaves, attachments, and internal WP types are skipped.

### Incremental pipeline

1. Hooks: `save_post`, `trashed_post`, `before_delete_post`, `created_term` / `edited_term` / `delete_term`, WooCommerce product create/update/delete, `blogname` / `blogdescription`, plugin settings that change selected types.
2. Exporter writes markdown. If `content_hash` matches the stored hash and status is `indexed`, **stop**.
3. Otherwise mark `pending` and schedule `chathearth_process_kb_queue` (single event, plus hourly safety net).
4. Worker embeds changed chunks, deletes old vector ids for that source, upserts new ones.
5. Unpublish / trash / delete / per-entry exclude → delete vectors + chunks; keep or drop the row as appropriate.

Admin **Sync now** scans selected types into the table and runs a batch immediately.

### Always-on grounding (RAG on or off)

`Site_Grounding` prepends to the system prompt:

- Who the assistant is (this website only).
- Site name, tagline, home URL, and a compact list of public pages / shop facts.
- Hard rules: refuse off-topic (news, homework, unrelated products, other companies, general trivia); do not invent pages, prices, or products; when referring to a page or product, use a Markdown link; comparisons only from catalog/KB facts.

The admin “System prompt” textarea remains and is **appended after** these rules so a custom prompt cannot quietly drop the refuse policy (the grounding block states that site-scope rules win).

### Chat response shape

`POST /wp-json/chathearth/v1/chat` still returns `reply`, and additionally:

```json
{
  "reply": "Markdown…",
  "sources": [ { "title": "", "url": "", "type": "page" } ],
  "products": [ { "id": 12, "name": "", "url": "", "price": "", "purchasable": true } ]
}
```

The model is instructed to use Markdown links and, for comparisons, a GitHub-flavored table. The widget also renders source chips and **Add to cart** on matched products.

`POST /wp-json/chathearth/v1/cart` — same `wp_rest` nonce as chat; `{ product_id, quantity, variation_id? }` → WooCommerce `WC()->cart->add_to_cart()`. Returns cart count, cart URL, checkout URL. Does not complete payment.

### Frontend

- Render source links under assistant messages.
- Product cards with Add to cart when WooCommerce is active and the reply matched products.
- “Compare these” sends a follow-up user message asking for a grounded table.
- Markdown: keep current subset; add **tables** and same-site / relative links (not `https` only).
- Persist sources/products in `localStorage` with the thread.

## Admin: Knowledge Base tab

New settings tab **Knowledge Base**:

- Enable RAG retrieval.
- Storage note: WordPress database only (no store picker).
- Checkboxes: post types (public), taxonomies (public), site identity, WooCommerce store summary.
- Sync now, counts (pending / indexed / errors), last error (no secrets).
- Paginated entry list: title, type, URL, status, include toggle.

Capability: `manage_options`. KB admin REST is that capability; chat and cart stay guest-usable with the existing nonce.

## Implementation steps (code)

1. **Options + schema** — new settings keys, `dbDelta` tables, version option, uninstall cleanup (tables, kb files, cron).
2. **Exporters + chunker** — HTML→markdown, per-source builders, front matter, upload writer.
3. **Embeddings + store** — OpenAI embeddings helper (shared credentials with moderation), WordPress-database store, factory + `chathearth_vector_store` filter.
4. **Indexer** — scan, hash skip, hooks, cron worker.
5. **Admin tab + KB REST** — settings UI, sync, include/exclude.
6. **Grounding + retriever** — always inject site block; retrieve when enabled; attach sources/products.
7. **Commerce** — cart route, product catalog helper, widget UI/CSS.
8. **Tests + docs** — PHPUnit without live embeddings; README, `readme.txt`, architecture/functionalities notes, changelog.

## Testing

Automated (no paid API in PHPUnit):

- Markdown export for a post and a term; hash skip on unchanged content.
- Chunker splits and overlap.
- Builtin store upsert/query ranking.
- Grounding prompt contains site name and refuse rules even if `rag_enabled` is false.
- Options sanitization keeps the WordPress-database store even if another store id is posted.
- Chat REST still rejects missing nonce; cart REST rejects missing nonce.
- KB REST requires `manage_options`.
- Action/reply payload exposes sources array shape.

Manual / environment (this Cloud WordPress site):

- Enable RAG, Sync now, confirm pages and sample Woo products appear as indexed.
- Ask an off-topic question with RAG **off** — model should refuse and stay on-site.
- Ask about a page/post — reply includes a link.
- Ask to compare two in-stock products — structured comparison from catalog data.
- Add a product to cart from the chat, open cart/checkout.
- Edit a page, confirm only that entry goes pending then indexed.
- Confirm Knowledge Base has no Chroma/Pinecone fields.

## Files (expected)

```
plan/cursor-milestone-01.md          this document
includes/Rag/                        schema, repository, exporters, indexer, stores, retriever, grounding
includes/Commerce/                   cart + product catalog
includes/Rest/class-kb-controller.php
includes/Rest/class-cart-controller.php
includes/Ai/class-openai-credentials.php
includes/Admin/class-settings-page.php  (+ Knowledge Base tab)
assets/js/frontend.js, admin.js
assets/css/frontend.css, admin.css
tests/test-rag-*.php, test-commerce.php
```

## Decisions locked in this milestone

| Topic | Choice |
|-------|--------|
| Local vs remote store | **WordPress database only.** Chroma cannot run in PHP; remote indexes need extra accounts/settings. |
| Embeddings | OpenAI `text-embedding-3-small`, Connectors key, never stored in this plugin. |
| Incremental unit | One KB source id (post/term/site doc), chunked for embedding. |
| Grounding | Always on; RAG retrieval is a separate toggle. |
| Checkout | Add to cart + links; no charging cards from chat. |
| Manual documents | Deferred (future item 2). |
