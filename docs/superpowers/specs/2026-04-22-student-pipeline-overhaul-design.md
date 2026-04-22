# Student Form + Pipeline Overhaul — Design

**Date:** 2026-04-22
**Status:** Design approved, pending implementation plan
**Author:** Sumit + Claude (brainstorming session; visual companion artifacts at `.superpowers/brainstorm/74333-1776872762/` — gitignored)
**Supersedes scope from:** n/a (new)
**Follow-ups:** SP#2 (call_attempts + follow-up strip) and SP#3 (customizable dashboard) — captured as Future Work below, not in this spec.

## Problem

Six months of operating the CRM surfaced a cluster of friction points in the two most-used screens: the **Student form** and the **Pipeline kanban**. They share a single underlying stage/field model, so fixing them in one pass is cheaper than one-offs.

Concrete gaps:

1. **Source & Owner section is three User dropdowns.** `owner_id`, `referrer_id`, and `lead_source` are all `User` pickers (`StudentResource.php:93-102`). Reff and Lead Source do the same job from the user's mental model. There's no way to record "referred by Rahul Sharma, class of 2023" — you have to shoehorn a user record or leave it blank.
2. **Pipeline has redundant and missing stages.** University Registration duplicates what Counselling rounds already capture. Full Payment Received and Admission Confirmed are states of `payments` / `close_reason`, not pipeline positions. Meanwhile Marketing-Qualified and Advance-Received are real transitions that have no stage today.
3. **No per-stage completion gates.** `StageTransitionValidator::forStageChange()` only checks `close_reason` and `re_entry_reason`. A counsellor can drag a student to "Meeting Scheduled" with no meeting, to "Advance Received" with no payment, to "Seat Allotted" with no round allotment. Data integrity drifts silently.
4. **IPU credential UX is over-engineered for the actual need.** `ipu_password` is encrypted at rest, masked in the form, revealed only via an audited `RevealIpuPassword` action (`app/Actions/RevealIpuPassword.php`). The credential is routinely shared with the student and is not a security boundary — the ceremony blocks routine work.
5. **"Final allotment" duplicates round_history.** `final_college`, `final_course`, `admission_date` on `students` restate what the latest `round_history` row already holds. Two sources of truth for one fact.
6. **Activity log is unreadable.** Default Spatie `logAll()->logOnlyDirty()` produces rows like `"updated"` with raw attribute diffs in JSON. Nobody opens this tab to understand history.
7. **The form is a 13-section scroll.** Payments live in a separate relation-manager below the form; a counsellor opening a student to log a payment scrolls past 10 sections they don't need.

## Scope

**In:**

1. Student form restructured into **top-level tabs** (Filament `Tabs` component).
2. Source & Owner section reshaped: Owner (heads-only), Lead Source (all team + freelancers), Referrer (freeform text).
3. Pipeline stages replaced with a 12-stage list using a single-source-of-truth enum.
4. Per-stage gate rules enforced as **soft warnings** on every stage change (hard block remains for `Closed` only).
5. `ipu_password` → plain text `ipu_login_code` (no encryption, no masking, no reveal action).
6. "Final allotment" section and columns (`final_college`, `final_course`, `admission_date`) removed.
7. Activity log humanized via targeted observers and an `ActivityDescriber` service.
8. Data migration: existing students with deprecated stages mapped forward; `ipu_password` decrypted in place; rename to `ipu_login_code`.
9. Tech debt bundled: deduplicate `StudentResource::STAGES` vs `PipelineSummary::STAGES`; delete obsolete "Logistics" form section.

**Out (deferred):**

- **SP#2** — Follow-up strip + `call_attempts` table + "Log call" action.
- **SP#3** — Customizable card dashboard + universal drill-down.
- Renaming `students.referrer_id` → `referrer_name` is done in a **two-migration** sequence (add `referrer_name`, backfill, then drop `referrer_id` in a later release) — only the **add** migration is in this spec; the **drop** is a hygiene follow-up.
- Dropping `students.current_round` column (the field becomes redundant once rounds are pipeline stages) — hygiene follow-up after a stable week on the new stages.

## Hard rules from user (locked)

1. **Owner = heads only.** Dropdown restricted to users with role `admin` or `head` — today that's Sumit, Sonam, Nikhil. Implementation must not hard-code names; use the role filter.
2. **Lead Source = all team + freelancers.** Keeps today's `User::pluck('name','name')` behavior; new team members (Neetu, Poonam, Nisha, etc.) appear automatically as they're added to `users`.
3. **Referrer = freeform text**, no FK, no autocomplete. The existing `referrer_id` column is left in place for one release (migration-safety) but removed from the form and from `Student::scopeVisibleTo`.
4. **12-stage pipeline** in this exact order: Lead Captured · Meeting Scheduled · Meeting Done · Advance Received · MQ · Round 1 · Round 2 · Round 3 · Sliding · Offline · Seat Allotted · Closed.
5. **Round stages use simplified labels** at the pipeline level. The detailed `round_history.round_name` enum (Online_R1, S2_R1, Online_Sliding, Offline_R1, etc.) stays as-is — it's the internal journal of actual university rounds. A mapping table documents which detailed names roll up to which simplified stage (see §3).
6. **Soft warnings only on stage gates.** The drag succeeds; a yellow notification lists incomplete fields. Hard blocks are kept only for `Closed` (needs `close_reason`) and re-opening (needs `re_entry_reason`).
7. **IPU login code is not a security field.** Remove the encrypted cast, the masked input, the reveal action, and the `ipu_password_revealed` activity event. The field holds what the student would type into the GGSIPU portal — it is routinely shared by phone/WhatsApp.
8. **Final allotment section is dropped, not moved.** Information about the final chosen college goes in notes; the gate for `Seat Allotted` reads from `round_history.allotted_college`.
9. **Top-level tabs form layout.** Replace the scrolling sections with Filament `Tabs`. Payments becomes a sub-tab under Deal.
10. **Activity log humanized rows.** Default Spatie logging is replaced by targeted observer calls; the table shows When / Who / What (3 columns, no raw Event badge).

## 1. Form restructure — top-level tabs

The Filament form is wrapped in `Tabs::make()` as the root schema component. Seven tabs, in this order:

| Tab | Contents |
|---|---|
| **Identity** | phone · name · father_name · phone_2 · email |
| **Source & Stage** | Owner · Lead Source · Referrer name · Stage · Student response |
| **Academic** | exam_appeared · twelfth_marks · rank · category · state · course · preference_r1/r2/r3 |
| **Deal** | Sub-tabs: *Deal details* (deal_amount, plan, split if applicable) · *Payments* (PaymentsRelationManager moved inline) |
| **Counselling** | is_ipu_registered · ipu_user_id · **ipu_login_code** · current_round · seat_fee_due (disabled) · Rounds sub-table (RoundHistoryRelationManager moved inline) |
| **History** | ActivityRelationManager (renamed "History", human-readable rows) · Notes (NotesRelationManager) |
| **Closure** | close_reason · refund_amount · re_entry_reason — tab badge turns red when stage = Closed |

**Meetings** keeps its existing `MeetingsRelationManager` but is surfaced as a top-level tab on the **student edit page** (separate from the form tabs) — same pattern Filament uses today for relation managers below the form. Rationale: the Meetings tab has its own header actions (+ Schedule, reschedule chains) that don't belong inside a form tab.

**Removed sections:** "Logistics" (dead — `meeting_date` is a read-only cache; the section offered nothing else meaningful), "Final allotment" (see §6), "First payment (optional)" on create (reuse the Deal tab's Payments sub-section instead; show an "Add advance payment" inline action on create).

### Existing relation managers — where each lands

| Current | New location |
|---|---|
| `NotesRelationManager` | History tab |
| `PaymentsRelationManager` | Deal tab → Payments sub-tab |
| `RoundHistoryRelationManager` | Counselling tab, inline |
| `ActivityRelationManager` | History tab |
| `MeetingsRelationManager` | Top-level relation manager (unchanged placement) |

`StudentResource::getRelations()` returns only `MeetingsRelationManager` after this change. The rest are mounted inline via `Forms\Components\Tabs\Tab::schema([])` with inline relation-manager adapters (Filament v3: `Section::make(...)->relationship('payments')` or `Repeater::make('payments')->relationship()` — pick per fidelity need in implementation plan).

## 2. Source & Owner section (new)

```php
Section::make('Source & Owner')->schema([
    Select::make('owner_id')
        ->label('Owner')
        ->relationship('owner', 'name', fn ($query) =>
            $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head'])))
        ->required()
        ->searchable(),

    Select::make('lead_source')
        ->label('Lead Source')
        ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
        ->required(),

    TextInput::make('referrer_name')
        ->label('Referrer name')
        ->maxLength(120),
])->columns(3);
```

**Schema change:**
- Add column: `students.referrer_name VARCHAR(120) NULL`
- Keep column: `students.referrer_id` (not read by the form anymore; dropped in a later hygiene migration once no backfill edge cases remain)

**Visibility implications:** `Student::scopeVisibleTo` currently uses `referrer_id` to give heads visibility into leads their team referred. With Referrer going freeform, that path is gone — only `owner_id` and `lead_source` (matching teammate names) drive visibility. This is acceptable: the team-head-unit visibility is already carried by `owner_id` and the `Sheet:<TeamMember>` lead_source convention.

## 3. Pipeline stages (12, reordered)

**Canonical list** — `app/Enums/PipelineStage.php`:

```php
enum PipelineStage: string
{
    case LeadCaptured = 'Lead Captured';
    case MeetingScheduled = 'Meeting Scheduled';
    case MeetingDone = 'Meeting Done';
    case AdvanceReceived = 'Advance Received';
    case Mq = 'MQ';
    case Round1 = 'Round 1';
    case Round2 = 'Round 2';
    case Round3 = 'Round 3';
    case Sliding = 'Sliding';
    case Offline = 'Offline';
    case SeatAllotted = 'Seat Allotted';
    case Closed = 'Closed';
}
```

`PipelineSummary::STAGES` becomes `PipelineStage::cases()` mapped to values. `StudentResource::STAGES` is deleted.

### Round name mapping (detailed → simplified)

`round_history.round_name` keeps its existing enum. When the pipeline stage is computed or set, use:

| round_history.round_name | Pipeline stage |
|---|---|
| `Online_R1`, `S2_R1` | Round 1 |
| `Online_R2` | Round 2 |
| `Online_R3`, `S2_R3` | Round 3 |
| `Online_Sliding`, `Online_Reporting` | Sliding |
| `Offline_R1`, `Offline_R2` | Offline |

The mapping is a method `PipelineStage::fromRoundName(string $roundName): self` used by `StageTransitionValidator` when gating a move to a round stage (see §4).

### Data migration — existing students

For each student, update `stage` based on current value:

| Old stage | New stage |
|---|---|
| `Lead Captured` | `Lead Captured` |
| `Meeting Scheduled` | `Meeting Scheduled` |
| `Meeting Done` | `Meeting Done` |
| `Onboarded` | `Advance Received` |
| `University Registration` | Derived — if any round_history row exists, set to the simplified stage of the latest row; else → `Advance Received` |
| `Counselling In Progress` | Derived from latest round_history row's simplified stage; fallback → `MQ` |
| `Seat Allotted` | `Seat Allotted` |
| `Full Payment Received` | `Seat Allotted` |
| `Admission Confirmed` | `Closed` with `close_reason = 'Completed'` (set if unset) |
| `Closed` | `Closed` |

Migration runs once; idempotent (safe to re-run). Logs a summary count per transition for verification.

## 4. Stage gate rules (soft warnings)

Extend `StageTransitionValidator` to return:

```php
/** @return array{hard: string[], soft: string[]} */
public function forStageChange(Student $student, string $newStage): array;
```

Rules (all produce `soft` messages; only the existing `Closed` and re-opening checks produce `hard`):

| Target stage | Soft-warn condition | Message |
|---|---|---|
| Meeting Scheduled | No future `meetings` row with `status='scheduled'` | "Meeting Scheduled incomplete: schedule a meeting (date + title) in the Meetings tab." |
| Meeting Done | `student_response` is null | "Meeting Done incomplete: set student_response (Ready / Not Interested / Needs Time)." |
| Advance Received | No `payments` row exists for student | "Advance Received incomplete: record the advance payment on the Deal tab." |
| MQ | (none — manual qualification) | — |
| Round 1 / 2 / 3 / Sliding / Offline | No `round_history` row whose round_name maps to this stage | "Round N incomplete: create a round_history row with round_name matching Round N." |
| Seat Allotted | Latest `round_history` row has null `allotted_college` | "Seat Allotted incomplete: set allotted_college on the latest round row." |
| Closed | `close_reason` missing | **HARD** — "close_reason is required when moving to Closed." |

Additional hard rule (unchanged): moving **out of** `Closed` requires `re_entry_reason`.

### Where the validator fires

1. **Kanban drag-drop** (`KanbanBoard::moveStudentToStage`):
   - If `hard` errors → snap back, red notification.
   - If `soft` errors → save, yellow notification listing each item.
2. **Form edit** (`StudentResource` stage Select's `afterStateUpdated`):
   - Same split: hard → reset field + red; soft → keep change + yellow.

`Notification::warning()->title(...)->body(implode('\n', $soft))` for the yellow path.

## 5. IPU login code

**Model changes** (`app/Models/Student.php`):
- Remove `'ipu_password' => 'encrypted'` from `$casts`.
- Add column rename migration: `ipu_password` → `ipu_login_code`. Decrypts existing values and stores plain text. Laravel's `decrypt()` runs once per row inside the migration's up() — any rows that fail to decrypt (e.g., ones modified manually) are logged and left as-is.

**Form field** (in new Counselling tab):
```php
TextInput::make('ipu_login_code')->label('IPU login code')->maxLength(60)
    ->helperText('Shared with the student during counselling.');
```

**Deletions:**
- `app/Actions/RevealIpuPassword.php`
- The `suffixAction(Action::make('reveal'))` block in the Counselling section.
- Any global search references to `ipu_password` (currently `ipu_user_id` is searched, not `ipu_password` — already safe; verify in implementation).

**Activity log:** no more `ipu_password_revealed` events. The field is logged as a normal updated-attribute via the humanized `ActivityDescriber` (see §7).

## 6. Drop "Final allotment"

**Schema changes** (one migration):
- Drop columns: `students.final_college`, `students.final_course`, `students.admission_date`.

**Code changes:**
- Remove the `Section::make('Final allotment')` block in the form.
- Remove `'final_college'` from `StudentResource::getGloballySearchableAttributes`.
- Remove `admission_date` from `Student::$casts`.
- `Seat Allotted` gate (§4) reads `round_history.allotted_college` for the latest row.

**Data loss risk:** any student whose only record of "final college chosen" was in `final_college` loses that record unless it's already in their latest round_history row. Mitigation: the migration first runs a check — for any student with `final_college` set and no `round_history` row, emit a warning log line with student_id + final_college. Sumit reviews the log and manually logs a note before the column drop.

## 7. Humanized activity log

**New service** — `app/Services/ActivityDescriber.php`:

```php
class ActivityDescriber
{
    public function stageChanged(Student $s, string $from, string $to): void;
    public function ownerChanged(Student $s, ?User $from, User $to): void;
    public function ipuCodeChanged(Student $s): void;
    public function closed(Student $s, string $reason): void;
    public function reopened(Student $s, string $reason): void;
    public function paymentAdded(Payment $p): void;
    public function paymentUpdated(Payment $p): void;
    public function paymentDeleted(Payment $p): void;
    public function meetingScheduled(Meeting $m): void;
    public function meetingRescheduled(Meeting $m, \DateTimeInterface $from): void;
    public function meetingCancelled(Meeting $m): void;
    public function roundEntered(RoundHistory $r): void;
    public function roundOutcomeUpdated(RoundHistory $r): void;
    public function noteAdded(StudentNote $n): void;
    public function leadCaptured(Student $s, string $source): void;
}
```

Each method formats a one-line description and writes:

```php
activity()
    ->performedOn($subject)
    ->causedBy(auth()->user())
    ->event($eventKey)
    ->log($description);
```

**Observer wiring:**
- `StudentObserver::updated()` — stage, owner, ipu_login_code, close_reason, student_response transitions.
- `PaymentObserver` — created / updated / deleted.
- `MeetingObserver` — existing; extended with the descriptions.
- `RoundHistoryObserver` — created / outcome updated.
- `StudentNoteObserver` — created.
- `LeadIntakeService` — already creates leads; call `ActivityDescriber::leadCaptured` at creation.

**Default Spatie `LogsActivity` is disabled on Student** — replace `getActivitylogOptions()` to return `LogOptions::defaults()->dontLogFillable()->logOnly([])` (or equivalent "log nothing by default"). All log writes become explicit.

**`ActivityRelationManager` table:**
- Columns: `created_at` (When) · `causer.name` (Who) · `description` (What).
- The `event` badge column is removed.
- Sort: latest first.
- Pagination: 25 default.

## 8. Test plan (high-level)

Each area gets unit-level coverage; integration tests cover the drag-and-form paths.

1. **Enum + migration:** `PipelineStageTest` — enum values, round_name → stage mapping, fromRoundName fallback. Migration test — old stage values map to expected new values.
2. **Validator:** `StageTransitionValidatorTest` — hard vs soft split per stage target; all rules from §4.
3. **Kanban:** `KanbanBoardTest` — soft-warn on incomplete move (student saves, notification body includes expected messages); hard-block on Closed without reason.
4. **Form:** `StudentResourceFormTest` — stage Select produces warning on soft-incomplete; form doesn't reset the value; Closed reset behavior preserved.
5. **IPU code:** `StudentResourceTest` — field renders plain (no `->password()`), no reveal action present, saved value readable back in plain text.
6. **Activity log:** `ActivityDescriberTest` — each describer method produces the expected `description` + `event` + `causer`. `ActivityLogIntegrationTest` — moving a student through stages produces the expected chronological rows.
7. **Backfill migration test:** seed a DB with each old stage; run migration; assert expected new stages. Includes an `Admission Confirmed` student → `Closed` with `close_reason = 'Completed'`.

Existing `scopeVisibleTo` tests must stay green — visibility no longer reads `referrer_id` in a meaningful way (referrer is freeform), but the owner and lead_source paths are unchanged.

## 9. Deployment notes

- **Migration order** (one deploy — all in the same release):
  1. `add_referrer_name_to_students` — adds `referrer_name VARCHAR(120) NULL`. Reversible.
  2. `rename_ipu_password_to_ipu_login_code` — renames column + decrypts values in-place. **Not reversible** (decrypted values can't be re-encrypted with the original key state).
  3. `drop_final_allotment_columns` — drops `final_college`, `final_course`, `admission_date`. **Not reversible** (data loss on down).
  4. `remap_student_stages` — runs the mapping table from §3. Reversible only if pre-migration stages were captured (they aren't).
- The `PipelineStage` enum and `StudentResource::STAGES` dedup is a code change, not a migration.
- **Rollback:** Tag release before deploy (milestone `v11-pipeline-overhaul`). Rollback = tag checkout + restored DB backup (no safe down-migration path once deployed).
- **Feature flag:** none. This is a schema + UX change; no phased rollout.
- **PHP 8.5 deprecations:** existing tests will show `DEPR` under CI — that's Laravel 11's PDO::MYSQL_ATTR_SSL_CA (known issue per project memory). Treat as PASS.

## 10. Future Work (not in this spec)

### SP#2 — Follow-ups + call_attempts

Mirror the SP#1 meetings pattern:
- New `call_attempts` table: `student_id`, `user_id` (caller), `called_at`, `outcome` (`connected` / `no_answer` / `busy` / `wrong_number`), `notes`, `next_followup_at` (nullable).
- Optional denormalized `next_followup_at` on `students` for fast "due today" queries.
- `TodayCallsWidget` on `/admin/today` — overdue follow-ups first.
- Inline "Log call" action on student list and the widget.
- Potential Gate addition: soft warning on Meeting Scheduled = at least one call_attempt with `outcome='connected'`.
- Potential integration with Kyne's WhatsApp AI OS — deferred to a later phase of SP#2.

### SP#3 — Customizable card dashboard + universal drill-down

- **Card contract** (`app/Cards/Card.php`): every dashboard aggregate becomes a Card with methods `id()`, `title()`, `query()`, `aggregate()`, `drillColumns()`, `canView()`, `icon()`, `color()`.
- Existing widgets refactored to implement Card. New cards registerable via a `CardRegistry` service.
- **User prefs:** `users.dashboard_prefs` JSON column storing `{cards: [...], order: [...]}`. Default per role.
- **Customize modal:** Filament modal with per-category checkbox list + drag-to-reorder.
- **Universal drill-down:** clicking any card opens a slide-over with the rows behind the aggregate + CSV export. Same pattern proven in PaymentReport's Today Received tab.
- Estimated effort: 4–5 days.

Each is its own brainstorm → spec → plan cycle, run after this spec ships.

## 11. Open questions (none blocking)

- Should the Closure tab show a "red dot" indicator when stage=Closed? Cosmetic — defer to implementation taste.
- Add `referrer_name` global search? — default yes; will include in the implementation plan.
- Keep `students.current_round` field alive during the transition? — Yes, read-only for one release; drop in a later hygiene migration after validating no reports consume it.
