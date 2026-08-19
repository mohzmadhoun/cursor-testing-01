# Metrics and methods for AI model and RAG quality

Practical metrics and methodologies to evaluate and monitor an AI chatbot + RAG system (as planned for ChatHearth - AI Chatbot)—covering quality, accuracy, reliability, observability, explainability, latency, hallucination detection, cost ceilings with admin escalation, transparency, and controllability.

## Quality and accuracy

| Approach | What you measure | How |
|----------|------------------|-----|
| **Groundedness / faithfulness** | Answer supported by retrieved context | LLM-as-judge or NLI: claim → evidence in chunks |
| **Answer relevance** | Reply matches the question | LLM-as-judge scoring 1–5 + rubric |
| **Context relevance (RAG)** | Retrieved docs actually useful | Precision@k, nDCG; % of chunks cited vs unused |
| **Correctness vs gold set** | Known Q&A pairs | Exact match / F1 / semantic similarity on a curated eval set |
| **RAGAS / ARES-style suites** | Faithfulness, answer relevancy, context precision/recall | Offline + periodic batch eval |
| **Citation coverage** | % answers with usable citations | Require/validate quote or source links |

## Reliability

| Approach | What you measure |
|----------|------------------|
| **Success rate** | % chats completing without error / fallback |
| **Retry / failover rate** | How often backup provider or fallback copy is used |
| **Consistency** | Same question → similar answer across runs (low variance) |
| **Tool/RAG failure isolation** | Retriever fail vs generator fail vs timeout |
| **Canary / shadow traffic** | New prompt/model/index on a slice of traffic before full roll out |

## Observability (ops)

| Signal | Why it matters |
|--------|----------------|
| **Request traces** | Query → retrieve → prompt compose → generate → post-process |
| **Token usage** | Prompt / completion / embedding tokens (tiktoken or provider usage) |
| **Latency breakdown** | p50/p95/p99 for retrieve, embed, generate, total TTFB / end-to-end |
| **Error taxonomy** | Rate-limit, auth, model 5xx, empty retrieval, safety filter |
| **Index health** | Doc count, stale %, embedding job lag, failed chunk counts |
| **User signals** | Thumbs up/down, “report answer”, regenerate, abandonment |

OpenTelemetry-style spans + structured logs + an admin dashboard fit WordPress well later.

## Latency

- **Time to first token** (when streaming is available)
- **Time to complete reply**
- **RAG retrieve latency** vs **LLM latency**
- **Budgets**: e.g. soft warn at 3s, fail/fallback at 8s
- **SLOs**: e.g. p95 end-to-end under a defined threshold

## Hallucination / inaccuracy detection

| Method | Notes |
|--------|------|
| **Grounding check** | Reject or rewrite if claims aren’t in retrieved context |
| **Self-consistency** | Generate N answers; flag high disagreement |
| **Contradiction check** | Answer vs retrieved passages (entailment model/LLM judge) |
| **Known-unknown policy** | Force “I don’t know / contact us” when retrieval score is below threshold |
| **Domain deny-list / policy validators** | Prices, medical/legal claims, phone numbers not in KB |
| **Human review queue** | Sample low-confidence or reported answers |
| **Regression suite** | “Must not invent” questions that only have KB answers |

## Cost and hard cost ceiling → escalate to admin

**Already in v1 (request-count protection):** per-IP and global rate limits; hourly incident counting on global denials; admin email + notice; optional auto-disable kill switch. See [`architecture.md`](architecture.md) Protection section and Settings → Protection.

**Still planned (token / $ ceilings):**

| Control | Idea |
|---------|------|
| **Hard ceiling** | Daily/monthly $ or token budget site-wide |
| **Soft alerts** | Email/Slack/admin notice at 50%/80%/100% |
| **Per-IP / per-session caps** | Extend protection settings with token caps |
| **Model tiering** | Cheap model first; escalate only for hard queries |
| **Kill switch** | Auto-disable chat when **cost** ceiling hit (manual kill switch + rate-based auto-disable already exist) |
| **Admin escalation** | WP admin email + notice + optional webhook when ceiling / spike / repeated failures |
| **Cost attribution** | By model, day, feature (chat vs embeddings vs reindex) |

Use **provider billed usage** when available; estimate with **tokenizer × published $/1M tokens** as fallback.

Related future items in [`functionalities.md`](functionalities.md): tokens monitoring (e.g. tiktoken) and cost monitoring.

## Explainability and transparency

| Feature | User / admin value |
|---------|-------------------|
| **Citations / sources** | “Based on: page X, doc Y” with excerpts |
| **Confidence / retrieval score** | Show or only use internally for gating |
| **“Why this answer” panel** (admin) | Chunks used, model, prompt version, tools called |
| **Prompt & model versioning** | Trace which system prompt / model served a reply |
| **Disclosure** | Clear “AI assistant” labeling; limits of knowledge |
| **Data use notice** | What is stored, retention (GDPR-friendly) |

### Explainability vs transparency

- **Transparency**: what the system is, which model, that it’s AI, when it used the website KB, privacy/retention.
- **Explainability**: *why this answer*—sources, scores, which chunks, which policy blocked a reply.

## Controllability

| Lever | Purpose |
|-------|---------|
| **System prompt / policy packs** | Tone, scope, refuse topics |
| **Allowed post types / KB scope** | RAG only over selected content |
| **Temperature / max tokens** | Creativity vs cost/length |
| **Grounding-required mode** | No answer without sources |
| **Human handoff / N8N** | Booking, support ticket, escalate |
| **Admin override** | Disable chat, swap model, freeze index |
| **Red-team / safety filters** | Moderation on input/output |

## Methodologies (process, not just metrics)

1. **Offline golden set** – Fixed Q&A + expected sources; run on every prompt/index/model change.
2. **Online evaluation** – Sample live chats weekly with LLM-judge + spot human review.
3. **A/B or interleaving** – Compare retrieval configs / models on relevance and cost.
4. **Error analysis loops** – Cluster failures: wrong retrieval, bad chunking, prompt drift, hallucinations.
5. **SLO + error budget** – Latency, groundedness rate, cost; freeze features if budgets are blown.
6. **Security / eval red teaming** – Prompt injection, jailbreaks, poisoned docs in RAG.

## Compact admin dashboard (future)

A realistic observability pack for later plugin releases:

- Tokens and estimated cost (day/month) + **hard ceiling → disable chat + email admin**
- Latency p95 + error rate
- Groundedness score on a sample (or % with citations)
- Thumbs down + “report” tags
- Retrieval emptiness rate (% answers with zero useful chunks)
- Model/provider mix (main vs backup)

## Related plan docs

- [`functionalities.md`](functionalities.md) — core and future feature list
- [`architecture.md`](architecture.md) — extension points for RAG, N8N, providers
- [`implementation-plan.md`](implementation-plan.md) — v1 build plan
