# Next Action Engine v1

**Date:** 2026-05-23
**Owner:** Sumit Dabas (super_admin)
**Status:** Draft — pending Sumit review
**Blocked-by:** `2026-05-23-workflow-connectors-v1-design.md` (Bundle A) — NAE's CTAs reuse Bundle A's F1/F2/F3 modals; ship Bundle A first.

> Supersedes the placeholder at `2026-05-23-next-action-engine-candidate.md`. That stub can be deleted once this spec lands.

## Goal

Recommend exactly one "next action" per student so counsellors don't have to scan 50 fields to decide what to do. The recommendation is deterministic, rule-based, and computed on-demand for visible rows. Each recommendation is paired with the single click that performs it.

When this ships, opening `/admin/students` should answer the question "who needs my attention right now and why?" in one column.

## Non-Goals

- Not "AI". No model inference, no learned ranking. Pure rule-evaluation in PHP.
- Not a CRM-wide notification system. The Engine surfaces signal in 2 places (edit page header, list column). It doesn't email, Slack-ping, or push.
- Not a replacement for `StuckLeadsWidget` / `ReEntryCandidatesWidget` / `SeatFeePendingWidget`. Those stay; they aggregate. The Engine personalizes.
- Not configurable per role in v1. Every viewer sees the same recommendation (filtered by their scope via `Student::scopeVisibleTo`).
- Not adding a Kanban card badge or a dashboard summary widget. v2 candidates.
- Not adding a rule registry / dynamic registration. Four rules in a hardcoded array is fine.
- Not a cron-cached column. Engine runs on-demand; cost is bounded by eager-loading.

## Users

- **Heads + admin + super_admin** — see the recommendation per student in their scope.
- **Members** — see recommendations for their own + their team's students (via `scopeVisibleTo`).
- **Freelancers** — see recommendations for their own students only.

No role-based suppression of rules in v1. (Phase 2: maybe hide PreferenceMissing for non-counselling staff.)

## Architecture

### Service classes

```
app/Services/NextAction/
├── NextActionEngine.php          orchestrator
├── NextAction.php                immutable DTO: priority, label, cta_url, cta_label, color, source_rule
├── Rules/
│   ├── RuleContract.php          interface: eligible(Student): bool + run(Student): ?NextAction
│   ├── PaymentDueRule.php        priority 100, color=red
│   ├── NoRecentMeetingRule.php   priority  60, color=amber
│   ├── StageStuckRule.php        priority  40, color=amber
│   └── PreferenceMissingRule.php priority  20, color=blue
```

`NextActionEngine::for(Student $s): ?NextAction` returns the single highest-priority eligible action (or `null` when no rule fires — common for healthy students).

`NextActionEngine::forAll(Student $s): array<NextAction>` returns the sorted list (used by the edit-page expander only).

### Rule contract

```php
interface RuleContract
{
    /** Cheap pre-check — returns false to short-circuit before run(). */
    public function eligible(Student $student): bool;

    /** Returns NextAction or null. Must be safe even when relations are null. */
    public function run(Student $student): ?NextAction;

    public function priority(): int;
    public function color(): string;   // 'red' | 'amber' | 'blue' | 'gray'
    public function code(): string;    // stable identifier for filter / tests
}
```

The split between `eligible()` and `run()` matters: `eligible()` answers the "should we even look at this student?" question fast (e.g., PaymentDueRule short-circuits when `deal_amount` is null). `run()` does the actual evaluation.

### Engine algorithm

```php
public function for(Student $s): ?NextAction
{
    return collect($this->rules)
        ->filter(fn ($r) => $r->eligible($s))
        ->map(fn ($r) => $r->run($s))
        ->filter()                                    // drop nulls
        ->sortByDesc(fn ($a) => $a->priority)
        ->first();
}
```

Tie-break is deterministic by rule registration order (`collect` preserves order on tied keys), which mirrors the priority literals (PaymentDue 100 > NoRecentMeeting 60 > StageStuck 40 > PreferenceMissing 20 — no ties in practice).

### Rule definitions

| Rule | Eligibility | Action label | CTA |
|---|---|---|---|
| **PaymentDue** | `pending_amount > 0 AND stage NOT IN [Closed, Refunded]` | `"Record ₹{shorthand}"` (e.g. "Record ₹2.5L") | Opens shared **PaymentFormSchema modal** pre-filled with `amount = pending_amount, type='advance', received_at=now()` |
| **NoRecentMeeting** | `latestMeeting?.scheduled_at < now()−5d AND stage IN openStageNames AND latestMeeting?.status ≠ 'scheduled'` | `"Schedule meeting"` | Opens shared **MeetingFormSchema modal** (the one Bundle A introduces) pre-bound to `student_id` |
| **StageStuck** | `stage_changed_at < now()−7d AND stage IN openStageNames` | `"Review pipeline · {N}d stuck"` | Navigates to `/admin/students/{id}/edit#stage` (Filament anchor + sticky form rail already supports this) |
| **PreferenceMissing** | `preference_r1 IS NULL OR '' AND rank IS NOT NULL AND stage IN openStageNames` | `"Pick preferences"` | Navigates to `/admin/rank-lookup?student_id={id}` (the Bundle A F1 entry point) |

`openStageNames` = `app(PipelineConfig::class)->openStages()->pluck('name')->all()` — the existing typed-`TYPE_OPEN` stages from the configured pipeline. Won / Lost stages are excluded automatically. This replaces the hardcoded `['Closed', 'Refunded', 'Admission Confirmed']` literal in `Student::scopeStuck` (Phase 2.5 follow-up: flip `scopeStuck` to use the same helper for parity).

**Why all 4 reuse Bundle A or existing surfaces:** the Engine is a recommender. It does not own its own modals. Every CTA either opens an existing form or jumps to an existing page. **Hence the blocked-by Bundle A constraint.**

### Color → priority mapping

| Color | Priority range | Visual |
|---|---|---|
| `red` | 80+ | red wash bg + dark red text (`--vermilion`/`--vermilion-wash` in tokens.css) |
| `amber` | 40–79 | amber wash bg + brown text (`--amber`/`--amber-wash`) |
| `blue` | 1–39 | blue-ish info wash (use existing `--info` if present, else neutral gray) |
| `gray` | reserved | (no rule uses gray in v1; reserved for "FYI" rules in v2) |

## Data Model

### One schema change

```php
// database/migrations/2026_05_2x_000100_add_stage_changed_at_to_students.php

Schema::table('students', function (Blueprint $t) {
    $t->timestamp('stage_changed_at')->nullable()->after('updated_at')->index();
});

// Backfill (in the same migration's up()):
DB::statement('UPDATE students SET stage_changed_at = COALESCE(updated_at, created_at) WHERE stage_changed_at IS NULL');
```

### Observer

```php
// app/Observers/StudentObserver.php (existing file — add the new hook)

public function updating(Student $student): void
{
    if ($student->isDirty('stage')) {
        $student->stage_changed_at = now();
    }
}
```

The observer is intentionally on `updating` (not `updated`) so the column is set in the same UPDATE statement — no second write, no race window.

### Side-effect win

Existing `Student::scopeStuck`:

```php
// app/Models/Student.php (lines 19–24)
public function scopeStuck(Builder $query): Builder
{
    return $query
        ->where('updated_at', '<', now()->subDays(14))
        ->whereNotIn('stage', ['Admission Confirmed', 'Closed']);
}
```

Flips to:

```php
return $query
    ->where('stage_changed_at', '<', now()->subDays(14))
    ->whereNotIn('stage', ['Admission Confirmed', 'Closed']);
```

This fixes the silent bug where `StuckLeadsWidget` reset a student's "stuck" count whenever ANY column was edited (typo fix, phone update, etc.). After this migration: only an actual stage change resets the counter.

**One test to add:** `StuckLeadsWidgetTest` — assert that updating a student's phone DOES NOT remove them from the widget if they were stuck. (Currently this would silently regress; we'd never know.)

## Data Flow

```
Student list (/admin/students)
  → StudentResource::getEloquentQuery()->with(['latestMeeting', 'payments'])
  → Filament renders rows; each row's "next_action" TextColumn calls
    NextActionEngine::for($row)
  → Engine runs the 4 rules in PHP against already-loaded data
  → Returns NextAction (or null)
  → Column renders <x-next-action-pill :action="$action"> or empty cell

Student edit (/admin/students/{id}/edit)
  → Filament loads single record; relations eager-loaded
  → New "header section" above tabs calls NextActionEngine::for($record)
  → Pill renders with color, label, and CTA button
  → Expander chevron triggers ::forAll() to show secondary actions

Click CTA:
  → Engine attached the cta_url at construction time
  → For PaymentDue / NoRecentMeeting: opens Filament modal (URL pattern: ?action=payment&for={id})
  → For StageStuck: anchor scroll within edit page
  → For PreferenceMissing: full page nav to /admin/rank-lookup?student_id={id}
```

## UI Components

### `<x-next-action-pill>`

```blade
{{-- resources/views/components/next-action-pill.blade.php --}}
@props(['action', 'compact' => false])

@if ($action)
  <a href="{{ $action->cta_url }}"
     class="davya-action davya-action--pill davya-next-action davya-next-action--{{ $action->color }}"
     {{ $compact ? 'data-compact="true"' : '' }}>
    <span class="davya-next-action__dot"></span>
    <span class="davya-next-action__label">{{ $action->label }}</span>
    @unless ($compact)
      <span class="davya-next-action__cta">{{ $action->cta_label }} →</span>
    @endunless
  </a>
@endif
```

Compact mode is used by the list column; full mode by the edit page header.

### tokens.css additions

```css
.davya-next-action {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 12px;
  letter-spacing: 0.02em;
  border: 1px solid transparent;
  transition: background 160ms, border-color 160ms;
}
.davya-next-action__dot { width: 6px; height: 6px; border-radius: 50%; }
.davya-next-action__label { font-weight: 500; }
.davya-next-action__cta { font-size: 11px; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.08em; }
.davya-next-action[data-compact="true"] .davya-next-action__cta { display: none; }

.davya-next-action--red    { background: var(--vermilion-wash); color: var(--vermilion); }
.davya-next-action--red    .davya-next-action__dot { background: var(--vermilion); }
.davya-next-action--amber  { background: var(--amber-wash); color: var(--amber); }
.davya-next-action--amber  .davya-next-action__dot { background: var(--amber); }
.davya-next-action--blue   { background: var(--info-wash, #E6F1FB); color: var(--info, #185FA5); }
.davya-next-action--blue   .davya-next-action__dot { background: var(--info, #185FA5); }
.davya-next-action:hover { border-color: currentColor; }
```

## Performance Budget

For a list page of 50 students:
- 1 students query (already happening)
- 1 eager-loaded `payments` aggregate
- 1 eager-loaded `latestMeeting` via `hasOne::ofMany`
- 0 queries inside the Engine (all data is loaded)

**Total: 3 queries** for the entire list page's recommendations. Verified by `tests/Feature/Students/NextActionColumnTest` asserting `assertQueryCountLessThan(5)`.

## Error Handling

| Path | Failure | Handling |
|---|---|---|
| Any rule | Null relation (no payments, no meetings) | `eligible()` returns false; `run()` is never called with bad state |
| PaymentDue | `deal_amount` is null | `eligible()` returns false |
| NoRecentMeeting | Student has 0 meetings ever | `eligible()` returns false (no signal — could be a new student) |
| StageStuck | `stage_changed_at` is null (newly migrated) | Backfill ran; if still null, eligibility returns false |
| PreferenceMissing | `rank` is null | `eligible()` returns false (can't pick prefs without rank) |
| Engine | All rules return null | `for()` returns null; UI renders empty cell |
| CTA click | Modal fails to open / target page 403 | Filament standard error handling; Engine doesn't try to recover |

## Testing

| File | Cases |
|---|---|
| `tests/Unit/NextAction/PaymentDueRuleTest.php` | (i) eligible when pending > 0, (ii) skips Closed/Refunded stage, (iii) skips when deal_amount null, (iv) `Record ₹X` label formats Indian-style |
| `tests/Unit/NextAction/NoRecentMeetingRuleTest.php` | (i) eligible when latestMeeting > 5d, (ii) skips when future scheduled, (iii) skips when zero meetings, (iv) skips terminal stages |
| `tests/Unit/NextAction/StageStuckRuleTest.php` | (i) eligible when stage_changed_at > 7d, (ii) skips terminal stages, (iii) handles null stage_changed_at safely |
| `tests/Unit/NextAction/PreferenceMissingRuleTest.php` | (i) eligible when preference_r1 null AND rank set, (ii) skips when rank null, (iii) skips when preference_r1 = "" |
| `tests/Unit/NextAction/NextActionEngineTest.php` | (i) returns highest priority of multiple firing rules, (ii) returns null when no rule fires, (iii) `forAll()` returns priority-desc list |
| `tests/Unit/NextAction/NextActionDtoTest.php` | (i) immutable, (ii) serializes to array, (iii) cta_url constructed correctly per source rule |
| `tests/Feature/Students/NextActionColumnTest.php` | (i) list column renders pill, (ii) sortable by priority via CASE-WHEN, (iii) filterable by rule code, (iv) eager-loads → `assertQueryCountLessThan 5` |
| `tests/Feature/Students/NextActionEditPagePillTest.php` | (i) edit page header shows pill when action exists, (ii) hidden when no action, (iii) expander reveals secondary actions |
| `tests/Feature/Observers/StudentStageChangedAtTest.php` | (i) observer stamps on stage change, (ii) NOT stamped on other-column changes (regression for the `updated_at`-proxy bug), (iii) backfill populates existing rows |
| `tests/Feature/Widgets/StuckLeadsWidgetRegressionTest.php` | Asserts that updating Student.phone does NOT remove them from the widget if previously stuck (because scopeStuck now reads stage_changed_at). |
| `tests/Feature/NextActionCtaIntegrationTest.php` | End-to-end: clicking each rule's CTA opens the right surface with the right pre-fill (depends on Bundle A) |

**Target: +28–32 tests.** All green before merge.

## Sequencing

- **First:** Bundle A (`2026-05-23-workflow-connectors-v1-design.md`) ships. F1, F2, F3 modals exist and are tested. Shared `MeetingFormSchema` / shared CTA URL conventions are stable.
- **Then:** NAE migration + observer + StuckLeadsWidget regression test ship (this is independently valuable — it fixes the `updated_at`-proxy bug regardless of NAE).
- **Then:** Engine + Rules + DTO ship with unit tests.
- **Then:** UI (pill component, edit page header, list column) ships with feature tests.
- **Then:** `NextActionCtaIntegrationTest` confirms the wiring end-to-end against Bundle A's shipped modals.

## Out of Scope (v1 — explicit)

- Rule registry / dynamic rule discovery (4 hardcoded for now)
- Configurable thresholds per role / per pipeline (5d, 7d are literals; PR to change)
- Aggregate dashboard widget ("12 urgent / 8 warning") — Phase 2.5 candidate
- Kanban card badge — Phase 2.5 candidate
- Per-role rule suppression (e.g., hide PreferenceMissing for non-counselling)
- Cron-cached `next_action_*` columns (revisit if list-column perf degrades at scale)
- Slack / email notifications (out of scope; the dashboard widget is the closest v2 step)
- Engine output exposed as a JSON API endpoint (only consumed by Filament UI in v1)

## Open questions for v1.5 / v2

- **Fold into Bundle B?** Bundle B's "Payment Due This Week" widget and the NAE's PaymentDueRule overlap. A unified spec might be cleaner. For now NAE ships standalone; merge later if both feel duplicate.
- **StageStuck CTA** — "Review pipeline" feels weak. Real-world might want "Move to next stage" with a stage dropdown. v1 keeps the anchor scroll; v2 adds an inline stage-set action.
- **Audit log** — every CTA click should arguably write a Timeline row "Acted on Next Action: ..."  so we can measure adoption. Easy to add post-ship.
