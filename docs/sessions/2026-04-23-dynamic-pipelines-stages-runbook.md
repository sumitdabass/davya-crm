# SP#1 — Dynamic Pipelines & Stages — Deploy Runbook

**Branch:** `feature/dynamic-pipelines-stages`
**Prod:** `https://davyas.ipu.co.in`
**Scope:** 44 files, +2520 / −401 lines, 6 new migrations, 0 destructive schema changes
**Tests at pause:** 455 tests / 1306 assertions / 0 failures

## What this ships

Replaces the hardcoded `App\Enums\PipelineStage` enum + `App\Services\StageTransitionValidator` with DB-backed, admin-editable pipelines / stages / transition rules behind a new `/admin/pipeline-config` Filament page. `/admin/kanban` keeps its URL and behavior; all 4 legacy validator rules are preserved byte-for-byte as seeded DB rows.

**New admin capability:** go to `/admin/pipeline-config` as admin → add/rename/delete stages, drag to reorder, re-type between Open/Won/Lost; on the Rules tab, create transition rules with Hard (blocks) or Soft (warns) severity, with field-check or has-relation conditions. Stage-rename + type-change are confirmation-gated because they affect kanban bucketing.

## Migrations (6 added, run in this order)

| Order | File | What it does |
|---|---|---|
| 1 | `2026_04_23_100000_create_pipelines_and_stages_tables.php` | `pipelines` (id, name, is_default), `pipeline_stages` (id, pipeline_id, name, display_order, stage_type ∈ {OPEN, CLOSED_WON, CLOSED_LOST}) |
| 2 | `2026_04_23_100100_create_stage_transition_rule_tables.php` | `stage_transition_rules`, `stage_transition_conditions` |
| 3 | `2026_04_23_100200_add_stage_id_to_students_and_widen_stage.php` | `students.stage_id` FK (nullable), widens `students.stage` ENUM → VARCHAR(60) |
| 4 | `2026_04_23_100300_seed_default_pipeline_and_stages.php` | Seeds "IPU Admission" pipeline + 13 stages |
| 5 | `2026_04_23_100400_seed_default_transition_rules.php` | Seeds 4 legacy rules (see "Known coverage gaps" below) |
| 6 | `2026_04_23_100500_backfill_student_stage_id.php` | Maps every existing `students.stage` string → matching `stage_id` |

All 6 are additive. No data loss. `students.stage` varchar is kept as a denormalized read-cache maintained by callers, same pattern as `meetings.student_id` after the SP#0 Meetings refactor.

## Pre-deploy checklist

- [ ] Full test suite green on branch
      `php -d memory_limit=1G ./vendor/bin/phpunit` → expect `455 tests / 1306 assertions / 0 failures` (PHP 8.5 DEPR noise is normal, treat as PASS per project memory)
- [ ] `git log main..HEAD --oneline` reviewed
- [ ] Decide on the 7 "silent guard" gaps below — either re-seed as rules now, or accept and document
- [ ] Tag prior state for rollback: `git tag pre-sp1-$(date +%Y%m%d-%H%M)` on local `main` before merging
- [ ] Staging copy or careful first-prod-smoke planned

## Deploy steps (Hostinger SSH)

Matches the existing davya-crm `DEPLOY.md` recurring recipe:

```bash
# On laptop — merge the feature branch to main and push
cd /Users/Sumit/davya-crm
git checkout main
git merge --ff-only feature/dynamic-pipelines-stages   # fast-forward since the branch was rebased onto main
git push origin main

# SSH to prod (davya-crm uses the ipu.co.in cPanel SSH, not davyas.ipu.co.in)
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in
cd /home/ipuc/davya-crm
PHP=/opt/alt/php84/usr/bin/php

# Recommended: brief maintenance window — the backfill migration touches every row in students
$PHP artisan down --render=errors::503

git pull --ff-only
$PHP artisan migrate --force
$PHP artisan view:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

$PHP artisan up
```

Tag the release from laptop after prod is confirmed healthy:

```bash
cd /Users/Sumit/davya-crm
git tag v12-dynamic-pipelines
git push --tags
```

## Post-deploy smoke (admin login required)

1. **Pipeline config page renders.** `https://davyas.ipu.co.in/admin/pipeline-config` — Stages tab shows 13 stages bucketed into Open / Won / Lost; Rules tab shows 4 seeded rules.
2. **Create a test stage.** Click `+ Stage` under Open → name it `Zzz Test` → confirm it appears, drag it to reorder. Delete it after — exercises `createStage`, `reorderStages`, `deleteStage`.
3. **Rename an existing stage.** Click ⋯ on any non-critical stage → Rename → new name → save. Confirms `renameStage` is now wired.
4. **Type change confirmation works.** Click ⋯ on an OPEN stage → Mark as Lost → confirm the browser confirm dialog appears (type change must be confirmation-gated to avoid accidental kanban re-bucketing).
5. **Rules list visible.** Rules tab shows all 4 rule cards with Hard/Soft badge, Active/Inactive badge, from→to stage pills, and any condition blocks.
6. **Rule toggle surfaces feedback.** Click the Active/Inactive badge on any rule → toast appears ("Rule activated" / "Rule deactivated").
7. **Both-sides-NULL guard.** Try to create a rule with Any (from) and Any (to) → red notification "Rule must specify at least one side" → no row persisted.
8. **Kanban still works.** `/admin/kanban` — drag a real student across one column → save succeeds. Try moving a non-closed student to Closed → should be blocked with the "[Closed requires reason]" notification.
9. **Backfill integrity.** Via `tinker` or a quick query runner:

   ```sql
   SELECT COUNT(*) FROM students WHERE stage_id IS NULL;   -- expect 0
   SELECT stage, COUNT(*) FROM students GROUP BY stage;    -- spot-check counts
   ```
10. **No silent regressions on existing flows.** Add a payment via the Student edit page; create a new lead via manual import; verify none of these hit a stack trace.

## Known coverage gaps (from T21 audit)

The deleted `StageTransitionValidator` enforced 12 legacy guards. Only 4 are seeded as DB rules. **7 are now silent no-ops** and **1 is partially covered** via an overlapping rule. Decide per-row whether to re-seed before deploy or accept as a product decision.

| # | Legacy guard | Status | Decision needed |
|---|---|---|---|
| 1 | Meeting Done without `student_response` set → SOFT warn | SILENT | Re-seed as `FIELD_CHECK student_response is_not_empty` rule? |
| 2 | Advance Received with zero payments → SOFT warn | SILENT | Re-seed as `HAS_RELATION payments count_min:1` rule? |
| 3 | Advance Received when `deal_amount` < SUM(payments.amount) → SOFT warn | SILENT | Current condition evaluator can't compare a scalar field to a SUM-of-relation; would need a new condition type. Punt unless critical. |
| 4 | Round 1 without matching `round_history` row → SOFT | SILENT | Re-seed as `HAS_RELATION roundHistory count_min:1` targeting Round 1? |
| 5 | Round 2 without `round_history` → SOFT | SILENT | Same as #4, targeting Round 2 |
| 6 | Round 3 without `round_history` → SOFT | SILENT | Same as #4, targeting Round 3 |
| 7 | Sliding without any `round_history` → SOFT | **PARTIAL** | The seeded "Sliding needs prior allotment" SOFT rule's `HAS_RELATION` on `roundHistory` with `outcome_like=Allotted%` fires when roundHistory is empty (count 0 < 1). User still gets warned, but the message no longer mentions the legacy phrasing. |
| 8 | Seat Allotted without `final_college` / `final_course` / `admission_date` → SOFT | SILENT | Re-seed as 3 `FIELD_CHECK is_not_empty` rules targeting Seat Allotted? |

If any row above needs re-seeding, add a follow-up migration `2026_04_23_100600_seed_additional_guard_rules.php` **before** running `artisan migrate` on prod. The test `tests/Feature/Pipeline/PipelineEndToEndTest.php::test_legacy_guards_coverage_audit` is the live catalog — re-seeding a guard will cause that test's corresponding `assertEmpty` to fail loudly, prompting a runbook update.

## Known UI deferrals (not blockers, but worth knowing)

- **Rule-editor modal is create-only.** The backend `saveRule(array $data, ?int $ruleId = null)` accepts a rule-id for edit, but the Rules-tab UI doesn't currently expose an "Edit" button. Workflow today: delete the bad rule + create a new one. Post-T22 task to wire the edit path (prefill + pass `ruleId`) — no backend change needed.
- **`forRoundChange` engine shim is a no-op.** The legacy validator's round-change warnings (fee-unpaid, sliding eligibility) aren't ported. Same reasoning as gaps #4–#7 above.

## Rollback

Fully reversible. Migrations are additive; `students.stage` varchar retains the original stage name as a denormalized cache, so reverting to the pre-SP#1 code keeps everything usable.

```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in
cd /home/ipuc/davya-crm
PHP=/opt/alt/php84/usr/bin/php

$PHP artisan down --render=errors::503
git fetch --tags
git checkout pre-sp1-<timestamp>
$PHP artisan migrate:rollback --step=6 --force
$PHP artisan view:clear
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan up
```

Note: rolling back drops the `pipeline_stages`, `pipelines`, `stage_transition_rules`, `stage_transition_conditions` tables and removes `students.stage_id`. `students.stage` (varchar) is preserved untouched, so no lead data is lost.

## Commits in this ship

- 30 commits on `feature/dynamic-pipelines-stages` after the last main rebase (`5fee3c7`).
- Trace the commits: `git log main..feature/dynamic-pipelines-stages --oneline`.
- Highlights: `feat(pipeline): rules-tab CRUD + stage rename/type menu` (23bda70), `feat(pipeline): rules-tab lists existing rules` (1cab053), `refactor(pipeline): drop legacy PipelineStage enum + StageTransitionValidator` (16815b4), `test(pipeline): end-to-end journey across all 4 seeded rules` (61819be).

## Authoritative docs

- Spec: `docs/superpowers/specs/2026-04-23-dynamic-pipelines-stages-design.md`
- Plan: `docs/superpowers/plans/2026-04-23-dynamic-pipelines-stages.md`
- Resume handoff (historical): `docs/superpowers/sessions/2026-04-23-sp1-resume.md`
