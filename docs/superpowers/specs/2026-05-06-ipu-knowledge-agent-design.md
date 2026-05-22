# IPU Knowledge Agent — Design

**Status:** APPROVED 2026-05-22 — ready for `writing-plans` handoff.
**Skill in flight:** `superpowers:brainstorming` (Q1–Q9 locked, architecture approved).

---

## What we're building

An internal AI assistant inside davya-crm's Filament admin. Staff click an icon in the TopBar, a right-side drawer opens with a chat thread, they ask questions about ipu.co.in admission content (courses, eligibility, fees, dates, hostel, etc.), and a Groq-backed agent answers with citations to the ipu.co.in pages it read.

**Primary use case:** a counsellor on a live call with a student lead opens the drawer, types "BBA fee at VIPS-TC?" or "MAIT hostel availability?", and gets a grounded answer in 5–10 seconds without leaving Filament.

**Out of scope (v1):** cutoffs Q&A (deferred to v2, depends on rank-predictor prod deploy); public ipu.co.in widget; streaming responses; file/image uploads; embedding-based RAG (revisit if the filesystem grows >1000 pages — currently 208).

---

## Decisions locked

| # | Decision | Choice |
|---|---|---|
| Q1 | User-facing surface | Filament TopBar icon → right-side drawer (mirrors Visual v2 peek-drawer) |
| Q2 | Grounding | A2 — filesystem tool-calls against the live ipu.co.in source on shared cPanel |
| Q3 | KB storage | **None.** ipu.co.in source files (`/home/ipuc/public_html/*.php`) ARE the knowledge base — always fresh, no editor |
| Q4 | Conversation style | Multi-turn chat in drawer, persistent during session, "New chat" reset, last 10 turns sent per call |
| Q5 | Audience | Spatie permission `use ai-agent`; settings page toggles per-role; default: `admin` only during testing |
| Q6 | Logging | Full Q&A log → `/admin/ai-conversations` browser; per-message token + latency + tool-call trace |
| Q7 | Citations | Yes — every answer auto-appends `Source: <slug1>, <slug2>` derived from `read_page` tool log (not LLM-trusted) |
| Q8 | LLM | Groq Llama 3.3 70B, fresh key (NOT the 2026-05-05 leaked one), provider-abstracted via `LlmProvider` interface so OpenAI is a one-config swap |
| Q9 | Tool round-trips | Cap at 3 per question (cost guard against runaway tool loops) |

---

## Architecture

```
[ Filament admin (any page) ]
        │ click ✦ icon in TopBar
        ▼
[ Livewire: AiAssistantDrawer ]
   • session-scoped chat thread
   • textarea + send + "New chat"
        │ wire:click "ask"
        ▼
[ AiAssistantController ]
   • role-gate via Spatie permission `use ai-agent`
   • per-user daily cap (config default 50/day)
   • persist user message → start/resume Conversation
        ▼
[ App\Services\Ai\AssistantService::ask() ]
   • build messages: system + last 10 turns + new user
   • loop up to AI_MAX_TOOL_ROUNDTRIPS (3):
       ├─ provider->chat(messages, tools)
       ├─ if tool_calls: execute server-side, append tool-result message, continue
       └─ else: break with final answer
   • append citations from accumulated read_page slugs
        ▼
[ App\Services\Ai\Providers\GroqProvider implements LlmProvider ]
   • POST api.groq.com/openai/v1/chat/completions
   • model: llama-3.3-70b-versatile
   • passes `tools` + `tool_choice: auto`
        ▼
[ persist assistant message: content + tool_calls JSON + tokens + latency + citations ]
        ▼
[ render markdown + Source line in drawer ]
```

### Tools

Two function-call tools registered with every Groq request:

**`search_pages(query: string) → [{slug, title, snippet}]`**
- Runs `grep -lir --include=*.php` against `config('ai.ipu_docroot')` (= `/home/ipuc/public_html`), excluding `config('ai.excluded_dirs')` (default `api/ assets/ cgi-bin/ include/`).
- For each match: extracts first `<title>...</title>` tag; falls back to the slug (sans `.php`) if no title is present. Builds a ~200-char snippet centered on the first query hit.
- Returns up to 10 hits. Empty array if nothing found.

**`read_page(slug: string) → string`**
- Validates `slug` does not escape the docroot (rejects `..`, leading `/`, absolute paths).
- Reads file, strips `<?php ... ?>` blocks, strips HTML tags while preserving headings + text, caps output at 16 KB. **Limitation:** stripping PHP loses any runtime-generated content (loops, dynamic includes). ipu.co.in pages are mostly static HTML wrapped in PHP `include` directives for header/footer, so coverage is high; pages whose body content is PHP-generated (e.g. dynamic listings) may return partial info. If this becomes a problem we revisit with a PHP-render pass via curl in v2.
- Returns the cleaned text. Returns an error string (handed back to the LLM as a tool result) on invalid slug or missing file — LLM decides how to recover.

### System prompt (thin, no embedded KB)

```
You are a counsellor assistant for IPU/GGSIPU admissions at https://ipu.co.in.
Use the search_pages and read_page tools to ground every answer in real ipu.co.in content.
Never fabricate facts. If no relevant page is found, say so plainly.
Keep answers under 200 words. Always end with a "Source:" line citing the slug(s) you read.
```

### Citations

Citations are derived from the tool-call log, not from the LLM's text output. After the round-trip loop closes, `AssistantService` collects every `slug` argument passed to `read_page` (deduped, ordered by first appearance) and appends `\n\nSource: slug1, slug2` to the assistant content. The LLM is instructed to also write a Source line, but the controller's appended version is authoritative — eliminates citation hallucination.

### Provider abstraction

```php
interface LlmProvider {
    public function chat(array $messages, array $tools = []): LlmResponse;
}

class LlmResponse {
    public string $content;
    public ?array $toolCalls;   // null if final answer
    public int $tokenInput;
    public int $tokenOutput;
    public int $latencyMs;
    public string $model;
}
```

`GroqProvider` is the only implementation in v1. Pattern mirrors `crmkit/config/ai.php`.

---

## Data model

Two new tables, plus one Spatie permission.

**`ai_conversations`**
- `id` (PK)
- `user_id` (FK → users)
- `title` (nullable string; auto-set from first user message, first 60 chars)
- `started_at`, `last_message_at`, timestamps

**`ai_messages`**
- `id` (PK)
- `conversation_id` (FK → ai_conversations, cascade delete)
- `role` enum (`system` | `user` | `assistant` | `tool`)
- `content` (text)
- `tool_calls` (JSON, nullable — assistant messages with pending tool calls)
- `tool_call_id` (nullable string — set on tool-role messages to thread the response)
- `citations` (JSON array of slugs, nullable — assistant final messages)
- `token_input`, `token_output` (nullable int)
- `latency_ms` (nullable int)
- `model` (nullable string)
- `created_at`

**Spatie permission**: `use ai-agent`. Settings page at `/admin/settings/ai-agent` lets a super-admin toggle this per role. Initial seed assigns to `admin` only.

---

## Configuration

`config/ai.php`:
```php
return [
    'provider' => env('AI_PROVIDER', 'groq'),
    'providers' => [
        'groq' => [
            'key'   => env('AI_GROQ_KEY'),
            'model' => env('AI_GROQ_MODEL', 'llama-3.3-70b-versatile'),
        ],
    ],
    'daily_cap_per_user'   => (int) env('AI_DAILY_CAP', 50),
    'max_history_turns'    => (int) env('AI_MAX_HISTORY_TURNS', 10),
    'max_tool_roundtrips'  => (int) env('AI_MAX_TOOL_ROUNDTRIPS', 3),
    'ipu_docroot'          => env('AI_IPU_DOCROOT', '/home/ipuc/public_html'),
    'excluded_dirs'        => ['api', 'assets', 'cgi-bin', 'include'],
    'read_page_byte_cap'   => 16384,
];
```

`.env` additions (prod): `AI_PROVIDER=groq`, `AI_GROQ_KEY=<FRESH>`, `AI_DAILY_CAP=50`. Local dev: same, dev key.

---

## Error handling

| Condition | Behavior |
|---|---|
| Groq 429 | "Busy, try in a moment." Message NOT counted toward daily cap. Logged. |
| Groq 5xx / timeout | "Something went wrong. Try again." Logged with response body. |
| Daily cap exceeded | "Hit today's question cap (50). Resets midnight IST." Counter scope: calendar day in app timezone (`config('app.timezone')` = `Asia/Kolkata`). |
| Tool round-trip cap exceeded | Return whatever last assistant text Groq produced; if none, fallback: "I couldn't pin down an answer in our pages — try rephrasing." |
| `search_pages` returns empty | Tool returns `[]`. System prompt instructs LLM to say "no matching page" rather than fabricate. |
| `read_page` invalid slug or missing file | Tool returns error string as tool result; LLM handles recovery (typically retries with a corrected slug). |
| `ipu_docroot` missing on disk | Log error + admin notification. AssistantService returns: "Knowledge sources offline, try again later." Drawer shows error state. |
| Permission denied | TopBar icon hidden. Direct URL access returns 403. |

---

## Testing strategy

- **Unit**: `SearchPagesTool` against a temp fixture dir (5 fake PHP files with titles + bodies; assert top-10 limit, snippet extraction, excluded-dirs filter).
- **Unit**: `ReadPageTool` slug validation (`..`, `/etc/passwd`, absolute paths all rejected), PHP stripping, 16 KB cap, missing-file error response.
- **Feature**: `AssistantService::ask()` with a stub `LlmProvider` that returns scripted tool-call sequences — covers: (a) zero round-trips (LLM answers directly), (b) one search + one read → final, (c) round-trip cap hit, (d) tool error mid-loop, (e) citations dedupe.
- **Feature**: `/admin/ai-conversations` log browser — admin sees all, regular user sees own only.
- **Feature**: Daily cap enforcement — 50 questions allowed; 51st returns the cap message and does NOT consume a message slot for Groq error responses.
- **Feature**: Permission gating — drawer Livewire mount returns 403 without `use ai-agent`; settings page toggles permission and verifies effect on next request.
- **Feature**: TopBar icon visibility gated by permission.

Target: ≥ 95% line coverage on Ai namespace, ≥ 30 tests across the suite.

---

## Implementation surface (file-level)

New files:
- `app/Services/Ai/AssistantService.php`
- `app/Services/Ai/LlmProvider.php` (interface)
- `app/Services/Ai/LlmResponse.php` (DTO)
- `app/Services/Ai/Providers/GroqProvider.php`
- `app/Services/Ai/Tools/SearchPagesTool.php`
- `app/Services/Ai/Tools/ReadPageTool.php`
- `app/Livewire/AiAssistantDrawer.php` + view
- `app/Http/Controllers/AiAssistantController.php`
- `app/Filament/Pages/AiConversations.php` + view (read-only log browser)
- `app/Filament/Pages/AiAgentSettings.php` (role-permission toggles)
- `app/Models/AiConversation.php`, `app/Models/AiMessage.php`
- `app/Policies/AiConversationPolicy.php`
- `database/migrations/...create_ai_conversations_table.php`
- `database/migrations/...create_ai_messages_table.php`
- `database/migrations/...add_use_ai_agent_permission.php` (seed-style)
- `config/ai.php`
- Tests under `tests/Unit/Ai/` + `tests/Feature/Ai/`

Modified files:
- `resources/views/filament/components/top-bar.blade.php` — add ✦ icon when `auth()->user()->can('use ai-agent')`
- `app/Providers/AppServiceProvider.php` — bind `LlmProvider` to `GroqProvider`

---

## Deploy notes

- Append `AI_PROVIDER`, `AI_GROQ_KEY`, `AI_DAILY_CAP` to prod `.env` (Hostinger via cPanel File Manager OR via SSH — chmod 600 after).
- New migrations: 3 (conversations + messages + permission seed). Cold-safe on prod.
- New Filament pages → may need cPanel MultiPHP Manager FPM toggle to flush opcache before `/admin/ai-conversations` is reachable (per `reference_hostinger_fpm_opcache.md`).
- `AI_IPU_DOCROOT` defaults to `/home/ipuc/public_html` which is correct for prod; local dev override via `.env.local` if Sumit's machine doesn't have a clone of ipu.co.in.

---

## Memory pointer

`MEMORY.md` → see `project_ipu_knowledge_agent.md`. After this spec is approved, that memory entry should be updated to reflect APPROVED status + point at the implementation plan once written.
