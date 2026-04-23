# Dynamic Pipelines & Stages — Design Spec

**Date:** 2026-04-23
**Status:** Approved for implementation planning
**Sub-project:** SP#1 of the 3-phase "Zero-Code Admin Configuration" roadmap
**Follow-ons:** SP#2 Custom Student Fields, SP#3 Customizable Dashboard

## Goal

Replace the hardcoded `PipelineStage` enum and `StageTransitionValidator` with DB-backed, admin-editable pipelines, stages, and transition rules. After this lands, an admin can add/rename/reorder/delete stages and define rules that gate stage moves — without a code deploy.

## Scope

### In scope

- **Single pipeline** (the existing "IPU Admission" pipeline). Schema supports multiple pipelines but admin UI only exposes edit for the default one. Adding a second pipeline is deferred.
- **Stages** — full CRUD, drag-reorder, three-type model (`OPEN`, `CLOSED_WON`, `CLOSED_LOST`), **20-stage hard cap per pipeline**.
- **Transfer-before-delete** — deleting a stage that has students blocks until admin picks a target stage; all students are migrated atomically.
- **Transition rules** — admin-defined guards on stage moves. Each rule has: from stage (or "any"), to stage, severity (Hard = blocks, Soft = warns), and one or more ANDed conditions.
- **Condition types:** `FIELD_CHECK` (is_empty / is_not_empty / = / ≠ / > / < / ≥ / ≤ on any Student scalar field) and `HAS_RELATION` (has row in `meetings` / `payments` / `student_notes` / `round_history` with optional sub-filter).
- **Seeded migration** — all 12 existing enum values become stage rows; a new "Complete Payment Received" stage is added as `CLOSED_WON`; "Closed" becomes `CLOSED_LOST`; the 4 existing hardcoded validator rules are seeded as DB rows.
- **Kanban reuse** — `/admin/kanban` stays; columns become dynamic from the DB.
- **Admin-only** edit access (Spatie `admin` role).

### Out of scope (deferred)

- **Sub-pipelines** (Bigin's 3-level hierarchy) — not needed; davya-crm is single-domain.
- **Creating a second pipeline from the UI** — schema supports it, admin page doesn't expose it yet.
- **Approval-required transitions, mandatory activities, post-transition actions** (webhooks / auto-emails / auto-task creation) — handled by n8n today; revisit only if demand emerges.
- **OR logic across conditions** — all conditions on a rule are ANDed. If admins need OR, they can create two rules with the same target stage.
- **Stage templates / stage library** (Bigin's "reuse existing stages" dropdown) — low value with only one pipeline.
- **Per-stage probability % and forecast categories** — not used today.
- **Rules referencing admin-created custom fields** — depends on SP#2; SP#1 rules reference only existing Student fields. The dropdown will auto-include SP#2 fields once they land.

## Architecture

One new Filament settings page, one new domain service replacing the hardcoded validator, and a thin repository that centralizes stage CRUD semantics (including the transfer-before-delete rule). Existing kanban page is re-wired, not rewritten.

### New services

**`App\Services\Pipeline\PipelineConfig`**
- Single entry point for code that needs to know "what are the stages?"
- Methods: `stages(): Collection<Stage>`, `openStages(): Collection`, `wonStages(): Collection`, `lostStages(): Collection`, `stageByName(string): ?Stage`, `stageById(int): ?Stage`.
- Reads from cache (Laravel cache, tag `pipeline-config`); repository writes invalidate.
- Replaces direct enum access in ~15 call sites (`PipelineSummary::stages()`, `Student::scopeActive()`, etc.).

**`App\Services\Pipeline\StageTransitionEngine`**
- Replaces `StageTransitionValidator` with same public shape: `forStageChange(Student $s, int $targetStageId): array{hard: string[], soft: string[]}`.
- Loads all active rules matching the `(from, to)` pair (where `from = NULL` means "any").
- Evaluates each rule's conditions against the Student. Returns human-readable messages on failure.
- Callers unchanged: `KanbanBoard::moveStudentToStage()`, `StudentResource` form save hook, lead intake service.

**`App\Services\Pipeline\StageRepository`**
- `create(array): Stage` — enforces 20-cap and validates stage_type
- `rename(Stage, string): Stage`
- `reorder(array $stageIdsInOrder): void` — renumbers `display_order`
- `changeType(Stage, string $newType): Stage` — requires explicit confirmation in UI
- `delete(Stage, ?int $transferToStageId): void` — atomic transaction: reassigns all students, then deletes; throws if stage has students and no target given
- All methods invalidate the `pipeline-config` cache tag.

### Data model

```
pipelines
├── id              bigIncrements
├── name            string(120)              -- "IPU Admission"
├── icon            string(60) nullable      -- heroicon name
├── record_label    string(40) default 'Student'
├── is_default      bool default false
├── created_at, updated_at

stages
├── id              bigIncrements
├── pipeline_id     FK → pipelines
├── name            string(80)
├── description     text nullable
├── stage_type      enum('OPEN','CLOSED_WON','CLOSED_LOST')
├── display_order   int
├── color           string(7) nullable       -- optional custom hex
├── created_at, updated_at
-- UNIQUE(pipeline_id, name), INDEX(pipeline_id, display_order)

stage_transition_rules
├── id              bigIncrements
├── pipeline_id     FK → pipelines
├── name            string(120)              -- human description
├── from_stage_id   FK → stages nullable     -- NULL = any stage (wildcard)
├── to_stage_id     FK → stages nullable     -- NULL = any stage other than from_stage_id
├── severity        enum('HARD','SOFT')
-- CHECK: NOT (from_stage_id IS NULL AND to_stage_id IS NULL)  -- at least one side specified
├── is_active       bool default true
├── created_at, updated_at
-- INDEX(pipeline_id, to_stage_id, is_active)

stage_transition_conditions
├── id              bigIncrements
├── rule_id         FK → stage_transition_rules ON DELETE CASCADE
├── condition_type  enum('FIELD_CHECK','HAS_RELATION')
├── field_or_relation string(60)             -- 'deal_amount' or 'payments'
├── operator        string(20)               -- '=', '>=', 'is_not_empty', 'has_where', …
├── value           json nullable            -- RHS of comparison, or sub-filter spec
├── display_order   int

students (modification)
├── + stage_id      FK → stages nullable (populated by backfill, then made NOT NULL)
├── stage (existing ENUM → altered to VARCHAR(80))  -- enum must widen to allow admin-added stages;
-- NOTE: an earlier (already-shipped) migration `2026_04_24_000000_alter_students_stage_to_varchar.php`
-- narrows this back to VARCHAR(60) on fresh installs. Prod state after SP#1 = 80; fresh dev = 60.
-- Both are sufficient for realistic stage names (longest seeded = 25 chars).
-- KEPT as denormalized read-cache for 1 release;
                                             --  MeetingObserver-style sync on stage_id change;
                                             --  dropped in post-SP#1 hygiene migration
```

Audit trail reuses Spatie ActivityLog on the Student model (already in place). No new `stage_history` table.

### Evaluation flow

On every stage change (kanban drag / form save / bulk import / API webhook):

1. Caller invokes `StageTransitionEngine::forStageChange($student, $toStageId)`.
2. Engine loads rules from cache: `WHERE pipeline_id = :p AND (from_stage_id IS NULL OR from_stage_id = :current) AND (to_stage_id IS NULL OR to_stage_id = :target) AND is_active = true`. (NULL on either side = wildcard, per schema.)
3. For each rule: evaluate conditions (all ANDed) via `ConditionEvaluator`.
4. Any failed `HARD` rule → pushed to `hard[]`. Any failed `SOFT` rule → pushed to `soft[]`.
5. Caller decides: non-empty `hard[]` blocks (HTTP 422 / Filament validation error); `soft[]` shows warnings the admin can acknowledge (checkbox "continue anyway").
6. On success, the caller writes the stage change + fires the existing `StageChanged` event. No engine-side side effects.

## UI

### New page: Settings → Pipeline Config (`/admin/pipeline-config`)

Two tabs, same page. Admin-only via Filament page authorization + Spatie `admin` role check. Emerald primary (existing panel theme).

**Tab 1 — Stages** (matches approved mockup)
- Pipeline card with name + star (default marker)
- Counter: "13 of 20 stages used · N slots free"
- Three sections: Open Stages, Won Stages, Lost Stages (dashed separator between)
- Each stage row: drag handle (⋮⋮), name, student count, Won/Lost badge if terminal, hover-reveal pencil + ⋯ menu
- ⋯ menu: Rename, Add description, Delete
- `+ Stage` button at bottom of each section (disabled when cap hit, tooltip explains)
- Drag within a section reorders; drag across sections opens "Change stage type to Won/Lost/Open?" confirmation

**Tab 2 — Transition Rules** (matches approved mockup)
- Rule list: each card shows name, Hard/Soft tag, Active tag, from → to arrow, condition summary
- `+ Add Rule` button (top-right, primary)
- Click rule or pencil opens **slide-over editor:** name field, from-stage + to-stage selects, Hard/Soft radio, condition list with `+ Add condition` repeater, Cancel/Save

**Kanban page (`/admin/kanban`) — minor rewire**
- `getBoard()` reads from `PipelineConfig::stages()` instead of enum
- `moveStudentToStage()` delegates to `StageTransitionEngine`
- Drag drops across terminal stages show Won/Lost icons on column header
- No visual redesign

### Delete-with-transfer flow

- Click Delete on a stage row
- If stage has 0 students → confirm dialog → delete
- If stage has N students → modal: "This stage has 37 students. Move them to which stage?" with select (all other stages except this one) + "Delete and move" button
- Delete happens in a single DB transaction; ActivityLog row per moved student

## Migration

Single deploy-time migration + a data seeder. Zero-downtime.

1. **Schema migration** — create `pipelines`, `stages`, `stage_transition_rules`, `stage_transition_conditions` tables; add nullable `students.stage_id` FK; **alter `students.stage` from ENUM to VARCHAR(80)** so admin-added stage names can be stored in the cache column during the 1-release deprecation window (MySQL-only `ALTER`; SQLite already stores enums as text so it's a no-op there).
2. **Seed migration** —
   - Insert 1 `pipeline` row: `name='IPU Admission'`, `is_default=true`, `record_label='Student'`
   - Insert 13 stages in order:
     1. Lead Captured (OPEN)
     2. Meeting Scheduled (OPEN)
     3. Meeting Done (OPEN)
     4. Advance Received (OPEN)
     5. MQ (OPEN)
     6. Round 1 (OPEN)
     7. Round 2 (OPEN)
     8. Round 3 (OPEN)
     9. Sliding (OPEN)
     10. Offline (OPEN)
     11. Seat Allotted (OPEN)
     12. **Complete Payment Received** (CLOSED_WON, new)
     13. Closed (CLOSED_LOST)
   - Seed 4 rules:
     - `from=NULL, to=Closed` / HARD / `field close_reason is_not_empty`
     - `from=Closed, to=NULL` / HARD / `field re_entry_reason is_not_empty` (fires on any move out of Closed)
     - `from=NULL, to=Meeting Scheduled` / SOFT / `has_relation meetings where status='scheduled' AND scheduled_at >= now() count >= 1`
     - `from=NULL, to=Sliding` / SOFT / `has_relation round_history where outcome like 'Allotted%' count >= 1`
3. **Backfill** — `UPDATE students SET stage_id = (SELECT id FROM stages WHERE name = students.stage AND pipeline_id = 1)` — wrapped in tx, failure mode logged but non-blocking (any unmatched rows land in "Lead Captured" with an ActivityLog note).
4. **Post-deploy** — keep `students.stage` as a read-mirror maintained by an observer; drop column in a follow-up hygiene migration one release later (same pattern used for `students.meeting_date` after the Today Tab SP).

## Testing

Feature-level tests (Pest / PHPUnit) covering:

- `StageRepository::create` — honors 20-cap, rejects duplicate names, sets display_order correctly
- `StageRepository::delete` — refuses when students exist + no target given; atomic move + delete when target given
- `StageRepository::reorder` — maintains contiguous display_order within each type section
- `StageRepository::changeType` — moves a stage across sections, recomputes display_order
- `StageTransitionEngine` — one test per seeded rule, asserting identical behavior to the old `StageTransitionValidator` (regression safety net); also tests custom rules (field operators, relation operators, ANDing)
- `PipelineConfig` caching — write invalidates, reads hit cache
- Filament page tests for Pipeline Config page — admin can edit, counsellor gets 403
- Kanban page test — drag from Open to `Complete Payment Received` triggers `StageTransitionEngine`, hard failure blocks move, soft failure allows override

Smoke test before deploy: run full kanban drag across all 13 stages on a copy of prod DB; run the 4 seeded rules against 10 representative students to confirm identical hard/soft output to the old validator.

## Rollout & rollback

- Ship on a feature branch → PR → deploy via existing pull-based runbook
- Post-deploy: migrate, seed, backfill in that order; verify stage counts match enum distribution
- Rollback: restore DB snapshot + revert commit (no schema surgery needed since old code reads `students.stage`, which we keep)

## Open questions (resolve during planning)

None. All design decisions locked during brainstorming on 2026-04-23.

## Decisions recap

| Decision | Choice |
|---|---|
| Pipeline hierarchy | 2-level (Pipeline → Stages); no sub-pipelines |
| Number of pipelines | 1 (IPU Admission); multi-pipeline deferred |
| Stage cap | 20 per pipeline |
| Delete with data | Transfer-before-delete required |
| Stage types | OPEN / CLOSED_WON / CLOSED_LOST |
| Won stage at migration | New stage "Complete Payment Received" (Seat Allotted stays OPEN) |
| Rule engine depth | Medium — conditions + hard/soft, no actions/approvals |
| Condition operators | field (empty/=/≠/>/</≥/≤) + has_relation with sub-filter |
| Condition composition | AND only (OR via multiple rules) |
| Permissions | admin role only |
| Kanban page | Reuse existing, re-wire to DB |
| students.stage column | Keep for 1 release as cache, drop in hygiene pass |
