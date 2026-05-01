# Smart Rank Prediction — Design

**Date:** 2026-05-01
**Owner:** Sumit
**Builds on:** `docs/superpowers/plans/2026-05-01-rank-module.md` (Phase 0–7 already shipped locally; this is a follow-up enhancement before prod deploy.)

## Goal

Make the **Rank Lookup** page a counsellor-grade tool that hands a student a list of eligible colleges in **IPU's actual student-demand sequence** (USICT first, then MAIT, MSIT, …), filtered to their preferred branch family, with **AI-generated 1–2 sentence counselling notes per college**. The first 7 expand by default; remaining eligible colleges sit behind a "Show more" link. Replaces the current behavior (filter+sort all 84 eligible rows by max rank).

## Out of scope (this spec)

- Multi-year (2025) data ingestion — already deferred.
- Auto-fetching cutoffs from `official_website` — already deferred.
- Reaching out to other universities — single university (IPU B.Tech) only for V1; design is generic so other unis just need their own preference order.

## Form additions (Rank Lookup page)

Existing fields stay (University, Course, Qualifying Exam, Admission Process, Year, Region, Your Rank).

| New field | Component | Notes |
|---|---|---|
| **Branch preference** | Multi-select with grouped options | Top group label = family (e.g. "Computer / IT"). Selecting the family auto-checks all member branches. User can uncheck individual branches (e.g. CS family minus Cyber Security). Empty = no filter. |
| **Show top 7 + more** | Inline expand link | Always shows top 7 colleges (in preference order) by default. A "Show N more colleges →" link appears below the 7th when more eligible colleges exist; clicking expands the list inline. No hard cap — student can always see the full list when they ask for it. |
| **Generate AI notes** | Toggle | Default ON. When OFF, skips the Gemini call (saves API quota); just shows bucket badges. |

## Branch family taxonomy

Hard-coded in `App\Filament\Pages\Rank\BranchFamilies` (a small constant map). When V2 brings new courses (MBA, BCA), add families there.

| Family code | Display label | Member branches (matched by case-insensitive substring on branch name) |
|---|---|---|
| `cs_it` | Computer / IT | "computer science", "cse", "cs/" prefix variants, "information technology", "it (dual", "ai&ds", "ai&ml", "data science", "computer applications" |
| `electronics` | Electronics | "electronics & communication", "ece", "electrical", "vlsi", "instrumentation", "industrial internet of things", "advance comm" |
| `mechanical` | Mechanical | "mechanical", "mechatronics", "automation & robotics", "robotics & artificial intelligence" |
| `civil_arch` | Civil / Architecture | "civil", "architecture", "3d modelling" |
| `chem_energy` | Chemical / Energy | "chemical", "energy", "nanoscience" |

Branch names that don't match any family fall through and only appear if the user explicitly picks them in the branch list.

## Prediction logic (unchanged base)

```
predictionRound  = userRegion === 'delhi' ? 'sliding' : '3'
predictionRegion = 'delhi'                                  -- always Delhi cutoffs

eligible(institute, branch, shift) :=
    cutoffs(university, course, exam, year, predictionRegion, predictionRound,
            institute, branch, shift)
       .min_rank ≤ userRank ≤ .max_rank
```

## College preference order (the "smart" sort)

Hard-coded ranking; lower number = appears first:

| Rank | College | Match key |
|---|---|---|
| 1 | USICT | "university school of information" |
| 2 | MAIT | "maharaja agrasen" |
| 3 | MSIT | "maharaja surajmal" |
| 4 | BVP | "bharati vidyapeeth" |
| 5 | BPIT | "bhagwan parshuram" |
| 6 | VIPS | "vivekananda institute of professional" |
| 7 | GTBIT | "guru teg bahadur institute" |
| 8 | Dr Akhilesh | "akhilesh das gupta" |
| 9 | HMR | "hmr institute" |
| 10 | GTB 4th Centenary | "guru tegh bahadur 4th centenary" |
| 11 | USAR | "university school of automation" |
| 12 | USCT | "university school of chemical" |
| ≥1000 | (everything else) | alphabetical |

The list is editable via a `RankCollegePreference` enum/constant — adding a college is a one-line change.

## Output structure

```
{
  rank: <integer>,
  prediction_round: 'sliding' | '3',
  prediction_region: 'delhi',
  user_region: 'delhi' | 'outside_delhi',
  colleges: [
    {
      institute_name: ...,
      preference_rank: 1..N,
      branches: [
        {
          branch_name, shift,
          rounds: { '1', '2', '3', 'sliding' → {min, max} | null },
          seat_count,
          prediction_max,
          bucket: 'safe' | 'probable' | 'reach',
          cushion_pct: <int>,    -- (max - rank) / max * 100, signed
          yoy_delta_pct: <int> | null,  -- 2024 vs 2026 sliding shift, when both exist
        }, ...
      ],
      ai_note: string | null,
    }, ...
  ]
}
```

## Bucket math (deterministic, no LLM needed)

```
cushion_pct = (max_rank - user_rank) / max_rank * 100

eligibility filter:
    rank > max_rank            → excluded (rank too low to get in)
    cushion_pct > 50           → excluded (too overqualified — student would never pick this; hides clutter)

bucket (for surviving rows):
    cushion_pct between 25 and 50 → 'safe'      (comfortable fit, top of safe range)
    cushion_pct between 10 and 25 → 'probable'  (likely fit)
    cushion_pct between 0  and 10 → 'reach'     (just inside the cutoff)
```

The min-rank check (`rank ≥ min_rank`) is also kept — if the student's rank is *better* than the lowest admit in the band, they'd realistically pick a higher college. Combined with the >50% cushion cap, the result list stays counsellor-tight.

YoY delta = sliding 2026 vs sliding 2024 max-rank percent change, when both years exist for the same (institute, branch, shift). Used as a volatility tag: `|yoy_delta_pct| > 30` → flagged as "volatile" for the AI prompt + UI.

## AI counselling note

**Trigger:** one Gemini call per college, only when "Generate AI notes" is ON. **Eager** for the first 7 colleges (rendered with the page). **Lazy** for any colleges revealed via "Show more" — Livewire fires a request that generates notes for the newly visible colleges, then re-renders. Cap per lookup = 7 + (additional shown when expanded).

**Cache key:** SHA-256 of `(institute_id, year, region, branch_filter_hash, rank-bucket-of-1000)`. TTL 24h. Cached in `cache` table (existing davya-crm setup uses MySQL `database` cache driver). Repeat lookups within ~1k rank of a previous lookup hit cache.

**Prompt (template):**

```
You are an IPU B.Tech admission counsellor. Given a student's rank, region,
and the eligible branches at one college, write 1–2 sentences in plain English
advising them on this college. Mention the safest branch first if multiple
fit, flag any volatility (>30% YoY change), and avoid hype.

Student rank: {rank} ({region})
Year: {year}
College: {institute_name}
Eligible branches (sorted by safety):
{for each branch}: {branch_name} (shift {shift|—}) — bucket={bucket}, cushion={cushion_pct}%, sliding-max={sliding_max}, R3-max={r3_max}, YoY={yoy_delta_pct or 'n/a'}, seats={seat_count or 'n/a'}

Output: 1–2 sentences. No markdown. No college name repetition. No emojis.
```

**Model:** `gemini-2.5-flash` (already configured in `config/finance.assistant`); reuse `App\Services\Finance\GeminiClient` *or* extract a small `App\Services\Rank\GeminiCounsellor` if reuse causes coupling.

**Failure handling:** If Gemini errors out or times out (>5s), return `null` for `ai_note` — UI shows just the bucket badges, no note. Never let an LLM failure 500 the page.

## UI changes

- Form: branch multi-select inserted between Region and Your Rank.
- Result table per branch row, columns left to right:
  `# | Branch (+ shift if any) | Bucket badge | Prediction max-rank | Cushion % | Seats | R1 max | R2 max | R3 max | Sliding max`
  — so the prediction round's max rank sits **right next to** the cushion %, both visible at a glance (e.g. `Safe · 208,257 · +34%`).
- Each college renders as a grouped block: institute name as a bold header row, AI note in italics directly under it, then the branch rows for that college.
- Print: AI notes + bucket badges + both max-rank and cushion always render.

## Files added / changed

| File | Why |
|---|---|
| `app/Filament/Pages/Rank/RankLookup.php` | Add branch filter + top-7 cap + bucket math + Gemini call orchestration. |
| `app/Services/Rank/BranchFamilies.php` | New — family taxonomy constant + matching helpers. |
| `app/Services/Rank/CollegePreferenceOrder.php` | New — extracted from RankLookup so the order is editable in one place. |
| `app/Services/Rank/RankPredictor.php` | New — bucket math, YoY delta computation. Pure (no DB), unit-testable. |
| `app/Services/Rank/GeminiCounsellor.php` | New — prompts Gemini, caches, returns string|null. |
| `resources/views/filament/pages/rank/rank-lookup.blade.php` | Render bucket badges + AI notes; group rows under college headers. |
| `tests/Unit/Rank/RankPredictorTest.php` | New — bucket math + YoY delta + edge cases. |
| `tests/Feature/Rank/RankLookupTest.php` | New — branch filter, top-7 cap, AI-off path. |

## Testing

- Unit tests for `RankPredictor` (bucket boundaries, negative cushion = not eligible, YoY delta with missing year).
- Feature tests for `RankLookup` page: filter narrowed correctly, exact 7-college cap honored when more eligible, fewer-than-7 case shows all without padding, branch family filter matches expected branches, AI-off path skips Gemini calls (mocked).
- Smoke test with rank=50000 Delhi: USICT → MAIT → MSIT → BVP → BPIT → VIPS → GTB expanded by default; "Show more" reveals Dr Akhilesh, HMR, etc. with on-demand AI notes.

## Risks

- **Family substring matching is brittle** — if IPU adds a branch named "Mathematical CS Engineering", it won't match "computer science" cleanly. Mitigation: family map is small + editable; failing branches just don't show under any family (still reachable via individual-branch picker).
- **Gemini quota** — 7 calls × N counselling sessions/day. With cache, near-free. Monitor first week.
- **YoY delta with only 2 datapoints (2024, 2026)** — no smoothing; a single weird year skews the volatility tag. Acceptable for V1; revisit when 2025 data lands.
