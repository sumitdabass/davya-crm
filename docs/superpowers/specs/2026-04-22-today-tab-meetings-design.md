# Today Tab — Meetings Strip + Today Payments (SP#1) — Design

**Date:** 2026-04-22
**Status:** Design approved, pending implementation plan
**Author:** Sumit + Claude (brainstorming session; prep at `docs/sessions/2026-04-22-today-tab-brainstorm-prep.md`)
**Sub-project:** #1 of 3 for the **Today Tab** initiative. SP#2 = Follow-up strip with call tracking. SP#3 = Universal clickable drill-down on every aggregate across admin + Today Reports strip.

## Problem

davya-crm is organized around entities (Students, Payments). The team's day is organized around actions — which meetings are happening, which follow-ups are owed, which payments landed today. Counsellors and heads currently open the Dashboard, scan widgets, and bounce to Students / Kanban / PaymentReport to answer "what am I doing next". The friction is real but the schema is sound — the fix is a new action-oriented surface, not a rewrite.

Three concrete gaps SP#1 closes:

1. **No authoritative meetings record.** `students.meeting_date` (a single nullable datetime column) is the only meeting data. No history, no reschedule audit, no mode/status, no notes tied to meetings, no way to query "all Priya's past meetings". Stage `Meeting Scheduled` exists but has no datetime coupling.
2. **No "my day" view.** There's no page that answers "what meetings do I have this week, and which payments landed today". Heads have to piece it together from Kanban + PaymentReport + sheets.
3. **No Today-view on payments.** PaymentReport shows date-range aggregates, not the ticker of "payments that just came in". For a head checking on the team at 11am, this is the single most useful view and it doesn't exist.

## Scope (sub-project #1)

**In:**
1. New Filament page `TodayPage` at `/admin/today`, nav label "Today", sort above Dashboard.
2. New `meetings` table — first-class entity with status machine, reschedule audit chain, owner, mode, notes.
3. `Meeting` model + `MeetingPolicy` + `MeetingObserver` (wires into existing `StageTransitionValidator`).
4. `TodayMeetingsWidget` — 5-day strip on `/admin/today`, cards grouped by day, overdue flagging.
5. `MeetingsRelationManager` on `StudentResource` — full CRUD from the student page.
6. `TodayPaymentsWidget` — compact list of today's payment rows on `/admin/today`.
7. Tabs on existing `PaymentReport` page — `Report` (current view) + `Today Received` (list with CSV download).
8. Backfill migration — existing non-null `students.meeting_date` values copied into Meeting rows.
9. Existing `students.meeting_date` column kept as a denormalized read cache (maintained by `MeetingObserver`). StudentResource form displays it disabled with "Managed in Meetings tab" helper.

**Out (deferred to SP#2/SP#3 or hygiene):**
- Follow-up strip with `call_attempts` table, "Log call" action, calls-made column → **SP#2**.
- Clickable drill-down on Today Reports strip, Dashboard widgets, Pipeline Report, PaymentReport aggregates → **SP#3**.
- Reports strip (Core 4 metrics: Meetings held, Follow-ups completed vs missed, Leads handled, Admissions closed) → **SP#3**.
- Dropping `students.meeting_date` column → hygiene pass after SP#3, once no external readers remain.
- Calendar sync (Google/Outlook), WhatsApp inline send, AI-generated summaries — dropped during prep Card 5.
- Bulk meeting operations, recurring meetings, member-level daily targets — not in v1; revisit after 4 weeks of data.

## Hard rules from user (locked)

1. **5-day window** on the Meetings strip (prep Card 1A option D) — Today, Today+1, +2, +3, +4. Earlier prep default of 3-day is overridden.
2. **Scheduling entry points: both** (prep 1B.C) — student page relation-manager AND "+ Schedule" on each day column of the Today strip.
3. **Rollover: stay visible flagged overdue** (prep 1D.B) — past-scheduled meetings with `status='scheduled'` keep rendering in the Today column with an OVERDUE pill. No auto-no-show, no midnight cron.
4. **Card fields: baseline + phone** (prep 1C) — time · student name · course · mode icon · owner initials · phone.
5. **Head-to-head isolation** (prep Card 4 E1 default) — Sonam cannot see Nikhil's team's meetings and vice versa.
6. **Counsellor cannot reschedule a teammate's meeting** (prep Card 4 E4 default) — update/reschedule/mark-held require `owner_id === auth user id` for non-admin/non-head.
7. **Admin acts-as with audit** (prep Card 4 E5 default) — admin can create/update a Meeting owned by another user; `created_by_id` captures the admin, `owner_id` stays on the actual owner, ActivityLog causer records the admin as actor, slide-over shows an "Acting as <Owner>" pill.
8. **Reassign = clean break** (prep Card 4 E2 default) — when a student is reassigned across teams, their meetings' `owner_id` syncs to the new student owner. Old owner loses visibility immediately.
9. **Slack / n8n untouched.** SP#1 adds nothing to ingestion or outbound integrations.

## Roles & access matrix

| Action | Admin | Head | Counsellor | Freelancer |
|--------|-------|------|------------|------------|
| `viewAny` meetings | ✅ all | ✅ team (via Student.scopeVisibleTo) | ✅ own | ✅ own |
| `view($m)` | ✅ | ✅ if `$m->student` in team | ✅ if `$m->owner_id === $user->id` | ✅ if owner matches |
| `create` | ✅ | ✅ | ✅ (student selector scoped to visible) | ✅ (own leads only) |
| `update($m)` | ✅ | ✅ if in team | ✅ **own only** (E4) | ✅ own only |
| `delete($m)` | ✅ | ✅ if in team | ❌ | ❌ |
| `reschedule($m)` | ✅ | ✅ if in team | ✅ own only (E4) | ✅ own only |
| `markHeld($m)` | ✅ | ✅ if in team | ✅ own only | ✅ own only |

**`Meeting::scopeVisibleTo($user)`** delegates via `whereHas('student', fn ($q) => $q->visibleTo($user))` — reuses the already-hardened Student team-unit rule shipped this morning. No duplicate team/head logic in MeetingPolicy.

Payment widget scoping: `TodayPaymentsWidget` + `Today Received` tab filter via `whereHas('student', fn ($q) => $q->visibleTo($user))`. Counsellors see only their team's payments.

## Architecture

### Data model — `meetings` table

```
id                       bigint PK
student_id               bigint FK → students(id) ON DELETE CASCADE
owner_id                 bigint FK → users(id)
scheduled_at             datetime NOT NULL
mode                     enum('in_person','phone','video','whatsapp') default 'in_person'
status                   enum('scheduled','held','no_show','rescheduled','cancelled') default 'scheduled'
notes                    text nullable          -- pre-meeting prep notes
outcome_notes            text nullable          -- post-meeting notes
held_at                  datetime nullable      -- populated when status moves to 'held'
rescheduled_from_id      bigint FK → meetings(id) nullable  -- self-FK, audit chain
created_by_id            bigint FK → users(id)  -- for admin acts-as (may differ from owner_id)
created_at, updated_at   timestamps
```

**Indexes:**
- `(owner_id, scheduled_at)` — powers the Today page per-owner window query.
- `(student_id, scheduled_at)` — powers the relation-manager chronological list.
- `(status, scheduled_at)` — powers overdue sweeps (`status='scheduled' AND scheduled_at < now()`).

**Reschedule workflow:** reschedule does NOT update a row in place. The existing row's status flips to `rescheduled`, and a new Meeting is created with `rescheduled_from_id` pointing to the old row. Full audit chain; no data loss.

**Mode enum:** four values (`in_person`, `phone`, `video`, `whatsapp`). Can be extended with a migration if ops calls out additional modes (e.g. `office_visit` distinct from `in_person`, `home_visit`).

### New Filament surfaces

| File | Purpose |
|------|---------|
| `app/Filament/Pages/TodayPage.php` | Custom page at `/admin/today`, nav label "Today", sort=1 (renders above Dashboard) |
| `app/Filament/Widgets/TodayMeetingsWidget.php` | 5-day strip (Today / +1 / +2 / +3 / +4) with cards + "+ Schedule" per day |
| `app/Filament/Widgets/TodayPaymentsWidget.php` | Today's payment rows compact list + running total |
| `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php` | Full CRUD on meetings within student context |

### Backend

| File | Purpose |
|------|---------|
| `app/Models/Meeting.php` | Model with `scopeVisibleTo($user)`, relations, `LogsActivity` trait |
| `app/Policies/MeetingPolicy.php` | Role gate per table above |
| `app/Observers/MeetingObserver.php` | Stage auto-advance on create/mark-held, `student.meeting_date` cache sync |
| `database/migrations/2026_04_23_000000_create_meetings_table.php` | Schema above |
| `database/migrations/2026_04_23_000100_backfill_meetings_from_students.php` | Data migration |

### Existing code touched (minimal)

- `app/Filament/Resources/StudentResource.php:200` — `DateTimePicker::make('meeting_date')` becomes `->disabled()->helperText('Managed in Meetings tab')`. Scheduling UI lives in the new relation-manager.
- `app/Filament/Pages/PaymentReport.php` — wrapped in `Tabs` (Filament `\Filament\Schemas\Components\Tabs` or page-level `Tab` depending on Filament 3 version in lockfile). Existing form + summary table becomes the `Report` tab; new `Today Received` tab is a Livewire Table of today's payments with a CSV download header action.
- `app/Providers/AppServiceProvider.php::boot()` — one-line `Meeting::observe(MeetingObserver::class)`.
- `app/Providers/Filament/AdminPanelProvider.php` — register `TodayPage` + the two Today widgets.

### Not touched
- Slack / n8n ingestion paths.
- Default Dashboard page (stays unchanged; Today is a new sibling, not a replacement).
- Kanban / Pipeline.
- Finance resources.
- `StageTransitionValidator` (new code calls it; validator itself unchanged).
- Existing `students.meeting_date` column (kept as denormalized read cache; dropped in a later hygiene pass).

## UX

### `/admin/today` layout

```
Today — Wednesday, 22 Apr 2026

MEETINGS — next 5 days
┌────────┬────────┬────────┬────────┬────────┐
│ Today  │ Thu 23 │ Fri 24 │ Sat 25 │ Sun 26 │
│  (3)   │  (2)   │  (0)   │  (1)   │  (0)   │
│        │        │        │        │        │
│ [card] │ [card] │  —     │ [card] │  —     │
│ [card] │ [card] │        │        │        │
│ [card] │        │        │        │        │
│ +Sched │ +Sched │ +Sched │ +Sched │ +Sched │
└────────┴────────┴────────┴────────┴────────┘

PAYMENTS RECEIVED — today
  10:42  Priya Sharma  ₹5,000   Cash  Sonam
  09:15  Rahul Verma   ₹10,000  UPI   Nisha
  09:02  Aman Gupta    ₹25,000  Bank  Poonam
  (total today: ₹40,000 · 3 payments)
```

### Meeting card fields

`time · student name (truncated at 18 chars) · course · mode icon · owner initials · phone`

### Card visual states

| Status condition | Visual |
|------------------|--------|
| `scheduled` AND `scheduled_at >= now()` | Blue left-border, standard card |
| `scheduled` AND `scheduled_at < now()` (overdue) | Amber left-border + "OVERDUE" pill |
| `held` | Green check, collapsed / greyed |
| `cancelled` / `no_show` | Slate, strikethrough |
| `rescheduled` | Not rendered (the replacement row renders in its place) |

### Card interactions

Clicking a card opens a slide-over with full detail:
- Student link, pre-meeting notes (editable), outcome_notes (editable only after Mark Held)
- Actions: `[ Mark Held ]  [ Reschedule ]  [ Mark No-show ]  [ Cancel ]`
- Reschedule opens a nested time picker → creates new Meeting row, flips old to `rescheduled`

### "+ Schedule" per day column

Modal with:
1. Student selector — autocomplete scoped via `Student::visibleTo($user)->whereNotIn('stage', ['Admission Confirmed', 'Closed'])`
2. Time picker — day pre-filled from the column clicked
3. Mode dropdown — defaults to `in_person`
4. Notes textarea (optional)

Submit → creates Meeting row, closes modal, refreshes widget.

### Student-page relation manager

`MeetingsRelationManager` renders on `StudentResource::getRelations()` as a new tab alongside the existing Payments tab. Table: `scheduled_at · mode · status badge · owner · notes preview`. Standard Filament actions: create, edit, delete, reschedule (custom action that marks row `rescheduled` and opens a create form for the new row).

### Today Payments widget

Flat list. Row: `time · student (link) · amount (money_inr) · method · owner`. Footer: `total today · count`. Filter: `paid_at >= today 00:00 AND paid_at < tomorrow 00:00`, scoped via `whereHas('student', fn ($q) => $q->visibleTo($user))`. No drill-down in SP#1 (ships in SP#3).

### PaymentReport tabs

```
[ Report ] [ Today Received ]
```

- **Report tab** — existing from/to/owner form + summary table, behavior unchanged (regression-locked by test).
- **Today Received tab** — Livewire Table of today's `payments` rows with the same columns as `TodayPaymentsWidget`, plus a `Download CSV` header action. Filter is implicit (today only). Role scoping identical to the widget.

### Acting-as banner

When a meeting's `created_by_id !== owner_id`, the edit slide-over renders a small pill at the top: `"Acting as <Owner Name>"` (amber). This is a read-only indicator; the admin can still perform all actions.

## Observers & stage integration

**`MeetingObserver` — registered in `AppServiceProvider::boot()`.**

### On `created`
```
if (student.stage === 'Lead Captured') {
    result = StageTransitionValidator::forStageChange(student, 'Meeting Scheduled')
    if (result is ok) {
        advance student.stage → 'Meeting Scheduled'
        # Spatie ActivityLog causer is automatically auth user
    } else {
        log.warning('Meeting created but stage auto-advance blocked', context)
    }
}
# If stage is already ≥ 'Meeting Scheduled', no-op. Never regresses.
student.meeting_date = MIN(scheduled_at WHERE status='scheduled' AND student_id=this.student_id)
```

### On `updated` when status transitions to `held`
```
if (student.stage === 'Meeting Scheduled') {
    advance student.stage → 'Meeting Done'
}
recompute student.meeting_date (nullable if no more scheduled meetings)
this.held_at = now()
```

### On `updated` when status transitions to `rescheduled`
No stage change. The new row (with `rescheduled_from_id` back to this row) provides the audit chain.

### On `updated` when status transitions to `cancelled` or `no_show`
**No automatic stage regression.** Counsellor decides manually (via Kanban) whether the lead goes back to "Lead Captured". Rationale: auto-regressing would fight StageTransitionValidator and surprise the user. Surface the decision, don't bury it.

### On `deleted`
Recompute `student.meeting_date` (null if no remaining scheduled meetings).

## Backwards compatibility — `students.meeting_date`

The column stays in place for SP#1. Exists in exactly 2 places outside migrations (per grep): the Eloquent cast in `Student.php` and the DateTimePicker in `StudentResource.php:200`. Observer keeps it in sync with the earliest upcoming Meeting:

| Event | `student.meeting_date` value |
|-------|------------------------------|
| Meeting created | MIN(scheduled_at) across `status='scheduled'` |
| Meeting edited (scheduled_at changed) | recomputed |
| Meeting marked held | recomputed (likely null if this was the only upcoming) |
| Meeting cancelled / no_show / rescheduled | recomputed |
| Meeting deleted | recomputed |

StudentResource form: `DateTimePicker::make('meeting_date')` stays visible but `->disabled()->helperText('Managed in Meetings tab')`. No user-facing regression.

**Dropping the column is explicitly out of scope for SP#1.** Handled in a hygiene commit post-SP#3 once we've verified no external readers (seeders, raw SQL in docs, n8n workflow fields, etc.).

## Testing

Target: ~30 new tests. Full existing suite stays green (`php -d memory_limit=1G vendor/bin/phpunit`).

### Unit / model
- `MeetingTest` — `scopeVisibleTo` returns correct rows for admin / head / counsellor / freelancer / unauthenticated.
- `MeetingObserverTest`:
  - Creating a Meeting on a `Lead Captured` student advances stage to `Meeting Scheduled`.
  - Marking status `held` advances `Meeting Scheduled → Meeting Done`.
  - No regression on cancel / no-show.
  - `student.meeting_date` syncs correctly on create / update / delete / reschedule.
  - Reschedule creates a new row with `rescheduled_from_id` set; stage does not change.

### Policy
- `MeetingPolicyTest` — 4 roles × 6 actions = 24 assertions. Covers E1 isolation (Sonam cannot view Nikhil's team meeting), E4 own-only (Nisha cannot update Kapil's meeting), E5 acts-as (admin creating on Nisha's student records `created_by_id = Sumit.id, owner_id = Nisha.id`, ActivityLog causer = Sumit).

### Feature (Livewire)
- `MeetingsRelationManagerTest` — create / update / delete / reschedule flows from student page.
- `TodayMeetingsWidgetTest`:
  - 5-day window exactness: today+4 inclusive, today-1 excluded.
  - Overdue detection: past-scheduled meeting renders with overdue flag in Today column.
  - Role scoping: Nikhil sees only Team 2 meetings; Sumit sees all.
  - `+ Schedule` button dispatches the expected event; submitted modal creates a Meeting row.
- `TodayPaymentsWidgetTest` — only today's rows; total matches sum; counsellor scope respected.

### Migration
- `CreateMeetingsTableTest` — migrate up/down cycle; all indexes present (`owner_id+scheduled_at`, `student_id+scheduled_at`, `status+scheduled_at`).
- `BackfillMeetingsFromStudentsTest` — seeded student with `meeting_date = now()+2 days` creates exactly one Meeting with `scheduled_at = meeting_date`, `status = 'scheduled'`, `owner_id` matching; past `meeting_date` creates a Meeting with `status = 'held'` and `held_at` non-null.

### PaymentReport
- `PaymentReportTabsTest`:
  - `Report` tab renders the existing form + summary (regression lock).
  - `Today Received` tab shows only `paid_at` today; correct scope per role.
  - CSV download action returns 200 with expected content-type.

## Rollout

1. Branch: `feature/today-tab-meetings` off `main`.
2. TDD task sequence defined in the implementation plan (writing-plans skill output).
3. Merge to main after full suite green + manual local smoke.
4. Push to origin: `git push origin main`.
5. Deploy to prod:
   ```bash
   ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && git pull --ff-only origin main && /opt/alt/php84/usr/bin/php artisan migrate --force && /opt/alt/php84/usr/bin/php artisan optimize:clear && git log -1 --oneline"
   ```
6. Prod smoke:
   - `/admin/today` renders for Sumit.
   - Schedule a test meeting for today via the Today strip → card appears.
   - Schedule a meeting from a student page via the Meetings relation-manager tab → card appears on Today strip.
   - Mark held → student.stage flips to `Meeting Done`; card greys; `meeting_date` cache updates.
   - Reschedule a meeting → new card in future column, old row flips to `rescheduled`.
   - Today Payments widget shows today's live payment rows.
   - PaymentReport shows two tabs; `Report` behaves exactly as before; `Today Received` lists today's rows with CSV download working.
   - Log out and log in as Nikhil → cannot see Sonam's team's meetings (E1 verified in prod).

## Rollback

- Pure additive migration. Revert flow:
  ```bash
  git revert <merge commit sha>
  git push origin main
  ssh ...  "cd /home/ipuc/davya-crm && git pull && /opt/alt/php84/usr/bin/php artisan migrate:rollback --step=2 --force && /opt/alt/php84/usr/bin/php artisan optimize:clear"
  ```
  Rolling back both migrations (create table + backfill) drops meetings data. Acceptable since all data was backfilled from `students.meeting_date` which remains intact.
- `students.meeting_date` is untouched by either migration; nothing to restore there.
- The observer and policy and resource files are pure code — removed by the revert.

## Known risks

1. **Undiscovered `meeting_date` readers.** Grep showed 2 references in `/app`. External consumers (seeders, raw SQL in docs, n8n workflow fields referring to the column by name) may exist. Observer keeps the column in sync on every Meeting change, so any stale reader still works. This is also why the column isn't dropped in SP#1.
2. **StageTransitionValidator blocking auto-advance.** If a student is in a stage that cannot legally move to `Meeting Scheduled` per the validator's rules, the Meeting is still created but stage stays. Logged as `warning`. Counsellor resolves manually. Not a failure, but a gap to monitor — add a Dashboard alert in SP#3 if it becomes common.
3. **Backfill volume.** 533 students in prod; roughly ~50 likely have `meeting_date` set (estimate). Single-pass migration — no performance concern.
4. **Filament 3 Tabs API drift.** PaymentReport tabbing depends on the Filament 3 tabs component shape. Lockfile pins the version — verify the exact namespace during plan phase (could be `\Filament\Schemas\Components\Tabs` or `\Filament\Forms\Components\Tabs` depending on minor version). Fallback if neither is available: convert PaymentReport to a `Resource` with two list pages instead of tabs. Adds ~1 hr.
5. **Reassign cascade on Meeting.owner_id.** When a student's owner changes, existing unheld meetings' `owner_id` needs to update. This is a separate concern from SP#1 scope but worth a regression test: add `MeetingReassignTest` confirming that changing `Student::owner_id` syncs `Meeting::owner_id` for `status='scheduled'` rows. Implementation: a StudentObserver hook (pre-existing if any, or a new one). Flag: the manual-lead-import path's dedup-demote already re-parents payments — same mechanism needed for meetings.

## Acceptance criteria

SP#1 is done when:
- All ~30 new tests pass; full existing suite is green.
- Prod HEAD is at the SP#1 merge commit.
- `/admin/today` renders for all 4 role tiers with correct scoping.
- Scheduling works from both Today strip and student page.
- Mark Held flips student stage automatically.
- Reschedule creates the audit chain (old row `rescheduled`, new row with `rescheduled_from_id`).
- Overdue meetings stay in Today column with amber pill.
- Today Payments widget shows today's rows, scoped.
- PaymentReport Report tab is unchanged; Today Received tab works + CSV downloads.
- `students.meeting_date` stays in sync with the earliest scheduled Meeting per student.
