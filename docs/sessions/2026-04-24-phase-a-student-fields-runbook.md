# Phase A — Custom Student Fields — Pre-Deploy Runbook (2026-04-24)

## Status
T1-T17 of the Phase A plan are shipped on `main`. T18 (smoke checklist) is the
next gate; this runbook closes T17.

## What shipped
- New tables: `student_field_sections`, `student_fields`, `student_field_values`
- Built-in seed: 2 sections (Identity, Academic), 8 fields (phone, name,
  father_name, phone_2, category, state, course, final_course)
- Admin config page (`StudentFieldsConfigPage`) with create / rename / reorder /
  archive / hard-purge / section-transfer flows; built-ins protected
- Dynamic form hydration + persist on Student create/edit
- Dynamic table columns + KanbanExtras formatter (caps at three)
- Phone-required lock; soft archive + restore; activity-log integration
- `ImportColumnMapper` (built-ins + customs); CSV apply-row writes both column
  and field value paths

## Verification (T17)
- Phase A test suite: **60 tests / 178 assertions, green** (DEPR is the
  Laravel-11 PDO::MYSQL_ATTR_SSL_CA noise — treated as PASS per memory).
- Adjacent regression chunk (StudentResource, KanbanBoard, PipelineConfig,
  UserPrefsResolver, CustomizeCardsModal, AdminPanelGlobal): **46 tests /
  113 assertions, green.**
- `migrate:fresh --seed` against MySQL **failed** in
  `2026_04_24_010200_create_student_field_values_table` — index
  `sfv_field_text_idx` covers TEXT column `value_text` without a key length.
  SQLite tolerates it (tests pass), MySQL rejects it. **Must be patched before
  prod deploy** — either drop the value_text index or add a key-length prefix
  (e.g. `value_text(191)`).

## Tags
- `pre-phase-a-student-fields-20260424` (pre-Phase-A baseline)
- `pre-phase-a-student-fields-deploy-20260424` (T17 gate, this run)

## Deferred
- Wire `ImportColumnMapper` into the live `LeadImport` flow (task #14 — pending)
- Patch the `value_text` index for MySQL compatibility (blocker for T18 deploy)
