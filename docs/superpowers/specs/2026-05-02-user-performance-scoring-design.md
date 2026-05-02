# User Performance Scoring — Design Spec

**Date:** 2026-05-02
**Status:** Approved (Sumit, 2026-05-02)
**Source:** Extracted from `/Users/Sumit/crmkit/docs/superpowers/specs/2026-05-02-crmkit-v1-design.md` §5.10, adapted for davya-crm's single-tenant context and education-consultancy signal set.

---

## 1. Purpose

Give Sumit a single 0-100 score per counsellor that:

1. Captures who is actually closing admissions and bringing in money (the highest-weighted signals)
2. Captures lead-quality coming in (rank probability of first choice)
3. Captures collection effectiveness (advance received, balance outstanding)
4. Penalises high lead-capture or meeting volume that isn't converting (volume signals act as denominators, never as direct positives)
5. Is computed on a fixed monthly cadence so it can back commission / split-pct / freelancer-vs-in-house decisions

The crmkit-style "Adaptive Intelligence Layer" (bi-weekly AI recalibration) is **explicitly deferred** — davya's volume (~50-100 admissions/year team-wide) is too low for meaningful calibration cycles.

## 2. Non-goals (v1)

- Lead scoring (separate spec, separate plan)
- Source scoring (requires migrating `lead_source` varchar to a `lead_sources` table)
- Counsellor self-view (admin-only in v1)
- AI insights / coaching notes
- Team-head aggregate scoring
- Real-time recalculation (monthly cadence is sufficient)

## 3. Architecture

| Component | Responsibility |
|---|---|
| `App\Services\Performance\UserPerformanceScorer` | Pure scorer: takes a `User` + period, returns score + signal breakdown |
| `App\Models\UserPerformanceScore` | Eloquent model — one row per (user, period_start) |
| `App\Jobs\RecalculateUserPerformanceJob` | Recomputes one user's score for the current calendar month; idempotent (upserts on `user_id + period_start`) |
| `App\Console\Commands\RecalculateAllPerformanceCommand` (`performance:recalculate`) | Dispatches the job for every active user; runs nightly via the scheduler |
| `App\Filament\Pages\Performance` | Admin-only Filament page listing all users with score, tier, signal breakdown, trend |
| `App\Observers\StudentRankProbabilityObserver` | Maintains `students.rank_prob_first_choice` cache when `rank`, `category`, or `preference_r1` changes |

### 3.1 Single-tenant simplifications vs crmkit

- No `tenant_id` plumbing
- No multi-tenant calibration locks
- No 5-layer isolation tests
- No subdomain routing
- Visibility matrix collapses to: admin sees everything, others see nothing

## 4. Data model

### 4.1 New column on `students`

```php
Schema::table('students', function (Blueprint $table) {
    $table->unsignedTinyInteger('rank_prob_first_choice')->nullable()->after('rank');
});
```

- 0-100, nullable
- Maintained by `StudentRankProbabilityObserver`: when a student is created/updated and `rank`, `category`, or `preference_r1` changes, run `StudentChoicePredictor::topChoices($student, 1)` and store `[0]['probability_pct']` (or null if predictor returns empty array)
- Backfill migration: one-time job recomputes for all 533 existing students

### 4.2 New table `user_performance_scores`

```php
Schema::create('user_performance_scores', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->date('period_start');           // first day of calendar month, e.g. 2026-05-01
    $table->date('period_end');             // last day of calendar month, e.g. 2026-05-31
    $table->unsignedTinyInteger('score');   // 0-100
    $table->string('tier', 20);             // Star | Strong | Solid | Growth | Coaching
    $table->json('signal_breakdown');       // see §4.3
    $table->json('team_max_snapshot');      // audit trail of team-max values used for normalization
    $table->timestamp('calculated_at');
    $table->timestamps();

    $table->unique(['user_id', 'period_start']);
    $table->index(['period_start', 'score']);   // ranking queries
});
```

History is implicit: older `period_start` rows ARE the history. No separate `_history` table.

Retention: indefinite (small data — 5 users × 12 months × N years is trivial).

### 4.3 `signal_breakdown` JSON shape

```json
{
  "closed_won": 12,
  "deal_won_amount": 480000,
  "rank_prob_avg": 64,
  "advance_received": 95000,
  "cases_captured": 30,
  "meetings_held": 22,
  "open_leads": 14,
  "balance_amount": 220000,
  "stale_open": 2,
  "conversion_rate": 0.40,
  "meeting_win_rate": 0.55,
  "sub_scores": {
    "closed_won_norm": 100,
    "deal_won_norm": 88,
    "rank_prob_avg_norm": 64,
    "advance_received_norm": 72,
    "conversion_rate_norm": 40,
    "meeting_win_rate_norm": 55,
    "pipeline_health": 80
  }
}
```

## 5. Score computation

### 5.1 Time window

- Period = current calendar month (1st 00:00:00 IST → last day 23:59:59 IST)
- "Snapshot" signals (open_leads, balance_amount, rank_prob_avg, stale_open) are computed at recalc time, NOT bucketed by period — they reflect current state
- "Period" signals (closed_won, deal_won_amount, advance_received, cases_captured, meetings_held) ARE bucketed by appropriate timestamp

### 5.2 Signal queries

All counsellor-scoped (`owner_id = $user->id`).

| # | Signal | SQL plan | Bucket field |
|---|---|---|---|
| 1 | `closed_won` | `students` where `stage = 'Admission Confirmed'` | `admission_date` in period |
| 2 | `deal_won_amount` | `SUM(deal_amount)` of (1) | as (1) |
| 3 | `rank_prob_avg` | `AVG(rank_prob_first_choice)` over the user's currently-OPEN students (stage NOT IN terminal); skip students with NULL probability | snapshot |
| 4 | `advance_received` | `payments JOIN students` where `payments.type='advance'` | `payments.received_at` in period |
| 5 | `cases_captured` | `COUNT(students)` (denom) | `students.created_at` in period |
| 6 | `meetings_held` | `COUNT(meetings)` where `status='held'` (denom) | `meetings.held_at` in period |
| 7 | `open_leads` | `COUNT(students)` where stage NOT IN terminal | snapshot |
| 8 | `balance_amount` | `SUM(deal_amount − Σ payments)` for non-terminal-stage owned | snapshot |
| 9 | `stale_open` | open leads with `students.updated_at < NOW() − 60d` | snapshot |

**Terminal stages** (centralised in `config/performance.php`): `Admission Confirmed`, `Closed`.

### 5.3 Normalisation against team max

For absolute-volume signals, normalise against the team's max for the same period:

```
normalize(x, max_in_team) =
    return 0 if max_in_team == 0
    return min(100, (x / max_in_team) × 100)
```

Team max is computed across the **scoring set** (see §6.1 — active users who own ≥1 student) once per recalc batch (pass 1 = collect raw signals for everyone, pass 2 = compute team max + normalise + write scores).

The recomputed team max for each absolute-volume signal is stored as `team_max_snapshot` JSON on each row → audit trail (you can see what "100% of team max" meant on that day).

### 5.4 Composite formula (weights confirmed by Sumit)

```
score =
    25% × normalize(closed_won, team_max)
  + 25% × normalize(deal_won_amount, team_max)
  + 15% × rank_prob_avg                              // already 0-100
  + 10% × normalize(advance_received, team_max)
  + 10% × clamp(conversion_rate × 100, 0, 100)       // closed_won / cases_captured
  +  5% × clamp(meeting_win_rate × 100, 0, 100)      // closed_won / meetings_held
  + 10% × pipeline_health
```

Cast to nearest integer 0-100.

### 5.5 `pipeline_health` sub-formula

```
base = 100
balance_penalty = min(50, (balance_amount / max(1, deal_won_amount + 1)) × 30)
                  // ratio of uncollected to won; capped at 50 points
stale_penalty = min(20, stale_open × 5)
                  // 4+ stale leads = full penalty
open_bonus = min(10, open_leads / 2)
                  // mild bonus for active pipeline; capped at 10

pipeline_health = clamp(base − balance_penalty − stale_penalty + open_bonus, 0, 100)
```

Concrete sub-weights are tunable in `config/performance.php` without code changes.

### 5.6 Min-sample floor (anti-noise)

If `cases_captured + meetings_held < 3`:
- `conversion_rate` and `meeting_win_rate` are NOT computed from this user's data
- Their two sub-scores fall back to the team average for the same period
- Prevents "1 lead, 1 win = 100% conversion" from dominating the score

### 5.7 Tiers

Single-admin context, bare-but-named (no coaching framing — Sumit is the only viewer):

| Score | Tier |
|---|---|
| 85-100 | Star |
| 70-84 | Strong |
| 55-69 | Solid |
| 40-54 | Growth |
| 0-39 | Coaching |

Cutoffs and labels live in `config/performance.php`.

## 6. Recalculation cadence

### 6.1 Nightly cron

- `php artisan performance:recalculate` — runs every night 02:00 IST via `app/Console/Kernel.php`
- **Scoring set:** all users where `is_active = true` AND who own at least one student (queried via `students.owner_id`). Sumit is included if he owns cases. Service users (non-counsellor admins who never own a student) are excluded by the "owns ≥1 student" filter.
- Pass 1: for each user in the scoring set, run signal queries → collect raw values
- Pass 2: compute team max per signal across all users
- Pass 3: for each user, compute composite score using normalised sub-scores → upsert row keyed by `(user_id, period_start)`

### 6.2 On-demand

- `Performance` Filament page has a "Recalculate now" header action that fires the same command synchronously
- Useful after manually fixing a stage or payment and wanting to see the immediate effect

### 6.3 Month roll-over

- No special handling — `period_start = today()->startOfMonth()`, so on May 1 the cron starts writing May rows; April rows remain frozen as the canonical April snapshot.

### 6.4 No real-time per-event recalc

For a 5-user team with monthly snapshots, real-time recalc per student stage change or payment recorded is overkill. The nightly cron + on-demand action covers all cases.

## 7. UI / visibility

### 7.1 `/admin/performance` (new Filament page)

Single-table list:

| Counsellor | Score | Tier | Won | Deal Won ₹ | Conv % | ▲▼ vs last month |

- Sortable on every column; default sort: score DESC
- Filters: month picker (default = current), `is_freelancer` only, `team_head_id` only
- Row click → side drawer with full `signal_breakdown` table + last-6-months trend (Filament chart if natively supported; else a 6-row mini-table)
- Header action: "Recalculate now"

### 7.2 Visibility

- Page is gated to `admin` role only (Spatie permission). Counsellors and team heads cannot access it.
- No widgets on the existing `/admin` dashboard — keeps M6 dashboard hardening untouched.

### 7.3 Per-user link

The existing user edit page (`/admin/users/{id}/edit`) gets one new tab "Performance" showing the same drawer content for that user. Same admin-only gate.

## 8. Configuration

`config/performance.php` (new file):

```php
return [
    'terminal_stages' => ['Admission Confirmed', 'Closed'],

    'tiers' => [
        ['min' => 85, 'label' => 'Star'],
        ['min' => 70, 'label' => 'Strong'],
        ['min' => 55, 'label' => 'Solid'],
        ['min' => 40, 'label' => 'Growth'],
        ['min' =>  0, 'label' => 'Coaching'],
    ],

    'weights' => [
        'closed_won'        => 0.25,
        'deal_won_amount'   => 0.25,
        'rank_prob_avg'     => 0.15,
        'advance_received'  => 0.10,
        'conversion_rate'   => 0.10,
        'meeting_win_rate'  => 0.05,
        'pipeline_health'   => 0.10,
    ],

    'pipeline_health' => [
        'balance_penalty_factor' => 30,   // multiplier for balance/deal ratio
        'balance_penalty_cap'    => 50,
        'stale_penalty_per_lead' => 5,
        'stale_penalty_cap'      => 20,
        'open_bonus_per_two'     => 1,    // 1 point per 2 open leads
        'open_bonus_cap'         => 10,
    ],

    'min_sample_floor' => 3,

    'stale_threshold_days' => 60,
];
```

## 9. Testing

### 9.1 Unit tests

- `UserPerformanceScorer` formula: each signal independently with synthetic numbers, then composite with known team-max
- `pipeline_health` sub-formula: edge cases (no won, no open, all stale, balance > deal)
- Min-sample floor: <3 cases falls back to team avg
- Tier mapping: every cutoff boundary
- Normalize: handles `max=0` (returns 0), handles `x > max` (clamps to 100)

### 9.2 Feature tests

- Factory builders that seed students/payments/meetings into a known month, run scorer, assert score + breakdown JSON
- `RecalculateAllPerformanceCommand` end-to-end: 3 users with different signal profiles → 3 rows written → team-max correctly applied
- Idempotency: running the command twice on the same day produces the same row (upsert)
- Month roll-over: simulate clock at May 1, ensure April rows are untouched and May row is written

### 9.3 Migration tests

- `rank_prob_first_choice` round-trip: create student → observer fires → column populated
- Observer fires only on relevant attribute changes (`rank | category | preference_r1`), not on unrelated updates
- Backfill command populates all existing students

### 9.4 Filament tests

- Admin can access `/admin/performance`; counsellor gets 403
- Recalculate action dispatches command and refreshes table
- Drawer renders signal breakdown correctly

### 9.5 Regression guard

- Existing dashboard tests still green
- No changes to public Student / Payment / Meeting CRUD behavior

## 10. Build phases

| Phase | Scope |
|---|---|
| P1 | Migrations: `rank_prob_first_choice` column, `user_performance_scores` table, observer wiring, backfill command |
| P2 | `UserPerformanceScorer` service + unit tests |
| P3 | `RecalculateUserPerformanceJob` + `RecalculateAllPerformanceCommand` + scheduler entry + feature tests |
| P4 | Filament `Performance` page + per-user tab + admin-only gate + Filament tests |
| P5 | Backfill rank probabilities + first manual recalc on prod + visual sanity check before pre-deploy |

Each phase has its own implementation plan written via `superpowers:writing-plans`.

## 11. Open questions / future work

- **Adaptive Intelligence Layer** — revisit when annual admissions ≥ 200
- **Counsellor self-view** — revisit after Sumit has used the data for one quarterly calibration cycle
- **AI insights** — revisit when there's enough history (12+ months) for AI to spot trends
- **Team-head aggregate** — small Filament widget for Sonam / Nikhil teams; one-pager spec when needed

---

**End of design spec.** Next step: `superpowers:writing-plans` for P1 (migrations + observer + backfill).
