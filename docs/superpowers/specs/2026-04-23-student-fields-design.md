# Custom Student Fields (Phase A) — Design

**Date:** 2026-04-23
**Status:** Design approved, pending implementation plan
**Sub-project of:** Student Segment Customisation roadmap (Phase A of A+B)
**Phase B (Segments & Cards):** separate spec written after Phase A ships
**Author/owner:** Sumit (admin)

## Problem

The `students` table is shaped for money-stage tracking: phone, name, father, course, category (Delhi/Outside), final_course, state, and a stage_id. It has no demographic depth (DOB, gender, email, address, board), no academic depth (class, marks), no source/engagement detail (lead source value, follow-up status, demo attended, drop-out reason).

That gap blocks two things:
1. Real student segmentation — counsellors and heads cannot answer "which MBBS leads from CBSE board with marks > 80% are still cold?" because the data isn't captured in structured form.
2. The deferred SP#3 "Composite/custom-filter card builder" — a segment builder is useless without queryable fields.

Phase A unblocks both by giving admins a self-serve UI to define any field they need (label + type + section + required + per-surface visibility), without developer involvement. Phase B builds the segment builder on top.

## Scope

**In (this spec):**
1. Three new tables: `student_field_sections`, `student_fields`, `student_field_values` — all additive, no changes to `students`.
2. `/admin/student-fields` Filament page (admin-only) — manage sections + fields with drag-reorder, soft-archive + restore.
3. Core 8 field types: text, textarea, number, date, email, dropdown, checkbox, multiselect.
4. Built-in columns (phone/name/father_name/phone_2/category/course/final_course/state) get a `student_fields` row each (`is_built_in=true`) so admin can rename label + toggle required, but cannot archive or change type.
5. `phone` is force-required regardless of admin toggle (dedup key).
6. Dynamic StudentResource Create/Edit/View form: sections + fields render from DB.
7. Dynamic StudentResource table columns from `show_in_table=true` fields.
8. Kanban tile extras block from `show_in_kanban=true` fields (cap 3, soft warning on 4th).
9. CSV import template dynamic columns from `show_in_import=true` fields; built-ins keep current names.
10. Spatie ActivityLog wiring for custom field value changes.

**Out (not in Phase A):**
- Segment builder — Phase B.
- File upload field type — deferrable to Phase A.2 if needed; not in v1.
- Datetime / phone / URL field types — text + validation gets you there until proven needed.
- Role-based field visibility — all roles see the same fields (row scoping by ownership stays as today).
- Per-user form layout — sections and ordering are global (one source of truth).
- Hard-deleting built-in columns or changing their type — locked.
- Migrating existing schema columns into `student_field_values` — built-ins continue to read/write `students` table directly.

## Key decisions (from brainstorming)

1. **Approach B (sequenced).** Phase A ships first, Phase B follows as its own spec/plan/PR.
2. **Strictly additive.** No deletions from current version — all 13 stages, all current student fields, all current widgets stay untouched.
3. **Pure self-serve, any field, any type (Q3:A).** No curated palette — admin creates fields with arbitrary labels, picks type from Core 8, sets required + per-surface visibility.
4. **Core 8 types (Q4:A).** Text, Textarea, Number, Date, Email, Dropdown (single-select w/ admin-defined options), Checkbox, Multi-select. File upload deferred.
5. **Soft archive on delete (Q5:B).** Delete hides the field; data preserved; "Archived fields" tab offers Restore + a final typed-confirmation hard-purge.
6. **Built-ins get rename + required toggle (Q6:B).** Cannot archive or change type. Phone stays type-locked and required-locked.
7. **Admin-defined sections with drag-reorder (Q7:A).** Same SP#1 SortableJS pattern. Section delete forces "move all fields to: [section]" transfer.
8. **Per-surface opt-in (Q8:B).** Form always shows the field; table/kanban/import are opt-in checkboxes per field.
9. **All roles see same fields (Q9).** Row scoping by ownership unchanged.
10. **No field count cap.** Schema supports any number; UI is sectioned to stay scannable.

## Data model

Three new tables, all additive. Migrations are pure adds — no `students` schema change.

### `student_field_sections`
| col | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string(80) | |
| position | int | for ordering |
| created_at, updated_at | timestamps | |

### `student_fields`
| col | type | notes |
|---|---|---|
| id | bigint pk | |
| section_id | bigint nullable FK → student_field_sections | nullable so seeding can run before sections insert; UI enforces non-null |
| key | string(80) unique | slug auto-generated from label, never changes after create |
| label | string(120) | admin-editable |
| type | enum | `text|textarea|number|date|email|dropdown|checkbox|multiselect` |
| is_required | boolean | admin toggle; ignored when `key='phone'` (always required) |
| is_built_in | boolean default false | true for seeded built-ins |
| built_in_column | string(40) nullable | when `is_built_in=true`, the `students` table column name (e.g. `father_name`) — read/write target |
| options | json nullable | for `dropdown` and `multiselect`: `[{value, label}]` |
| show_in_table | boolean default false | |
| show_in_kanban | boolean default false | |
| show_in_import | boolean default false | |
| position | int | order within section |
| archived_at | timestamp nullable | soft archive |
| created_at, updated_at | timestamps | |

### `student_field_values`
| col | type | notes |
|---|---|---|
| id | bigint pk | |
| student_id | bigint FK → students cascade delete | |
| student_field_id | bigint FK → student_fields cascade delete | |
| value_text | text nullable | for text/textarea/email/dropdown (stores the chosen value) |
| value_number | decimal(20,4) nullable | for number |
| value_date | date nullable | for date |
| value_json | json nullable | for multiselect (array of values) |
| created_at, updated_at | timestamps | |

**Constraints:**
- `unique(student_id, student_field_id)` — one value per student per field.
- Indexes on `(student_field_id, value_text)`, `(student_field_id, value_number)`, `(student_field_id, value_date)` to support Phase B segment queries.
- `checkbox` values stored in `value_text` as `'1'`/`'0'`. Reason: keeps the values table from needing a fifth column for one type.

**Why EAV over a JSON column on `students`:** Phase B's segment builder does `WHERE field_a=X AND field_b=Y` joins. EAV makes those joins natural and indexable. JSON-path queries are MySQL-version-fragile and harder to index well at our scale.

## Built-in field bridging

Built-ins are seeded into `student_fields` with `is_built_in=true` and `built_in_column='<column name>'`. The form/table/import code special-cases these:

- **Form load:** values come from `students.<column>`, not `student_field_values`.
- **Form save:** values write to `students.<column>`, not `student_field_values`.
- **Table column:** existing `StudentResource` column code can stay — we just respect the new `show_in_table`/`label`/`is_required` from the `student_fields` row instead of hardcoded values.
- **Type:** locked to whatever fits the current column (e.g. `category` is `dropdown` with `options=[{value:'Delhi',label:'Delhi'},{value:'Outside',label:'Outside'}]`, type cannot be changed).
- **Archive:** disabled on built-ins.

**Seed list (built-ins, in order, in section "Identity"):**
| key | label | type | required | column | options |
|---|---|---|---|---|---|
| phone | Phone | text | yes (locked) | phone | — |
| name | Name | text | yes | name | — |
| father_name | Guardian Name | text | no | father_name | — |
| phone_2 | Alternate Phone | text | no | phone_2 | — |
| category | Zone | dropdown | no | category | Delhi, Outside |
| state | State | text | no | state | — |

**Built-ins in section "Academic":**
| key | label | type | required | column | options |
|---|---|---|---|---|---|
| course | Course | text | no | course | — |
| final_course | Final Course | text | no | final_course | — |

(Admin can rename labels and reassign sections after seeding. The `key` is permanent.)

## Field Config page (`/admin/student-fields`)

Admin role only. Page has two tabs: **Sections & Fields** and **Archived**.

### Tab 1: Sections & Fields
- Two-pane layout. Left rail: list of sections (drag-reorder via SortableJS, "+ Add section" button, click to select, inline rename, delete with transfer modal).
- Right pane: fields belonging to the selected section. Drag-reorder. "+ Add field" button.
- Each field row shows: `[label] · [key — greyed] · [type pill] · [required toggle] · [📋 table] [🗂 kanban] [⬇ import] · [⋯ menu (Edit / Move / Archive)]`.
- Built-in fields display a small "🔒 built-in" badge; the Archive menu item is disabled and the Edit modal hides type/options/built-in-column controls.

### Add Field modal
- Label (required) — auto-generates `key` (slug) shown greyed under the label input.
- Type (dropdown, Core 8). Picking `dropdown` or `multiselect` reveals an options editor (rows of `value` + `label`, add/remove).
- Section (dropdown of existing sections + "+ New section…" inline option).
- Required (checkbox). Disabled-and-checked when `key='phone'`.
- Three visibility checkboxes: "Show in students table", "Show in kanban tile", "Include in CSV import".
- Save → field is live on the Student form for new and existing students immediately.

### Section delete with transfer
- If the section has fields, modal forces "Move all fields to: [section dropdown]" before allowing delete (same SP#1 transfer-on-delete pattern).

### Tab 2: Archived
- Table of archived fields with: label, type, archived date, value count.
- Restore action — returns the field to its old section/position (or first section if old section is gone).
- "Permanently delete + purge values" admin action — typed-confirmation modal ("Type DELETE to confirm wiping N values"). Built-ins cannot be hard-deleted.

### UI notes
- All action buttons use inline `style="background-color: #059669"` etc. to dodge the documented Filament/Tailwind color gotcha.
- Page class must NOT define a `getRules()` method (Filament `BasePage::getRules(): array` LSP gotcha from SP#1). Use `getFieldValidationRules()` if needed.

## Student form rendering (StudentResource)

Form is regenerated dynamically from `student_field_sections` + `student_fields` (excluding `archived_at NOT NULL`).

- One Filament `Section` per `student_field_sections` row, ordered by `position`. First section open by default; rest collapsed.
- Inside each section, fields render in `position` order via a `FieldRenderer` service:
  - `text` → `TextInput`
  - `textarea` → `Textarea`
  - `number` → `TextInput::numeric()`
  - `date` → `DatePicker`
  - `email` → `TextInput::email()`
  - `dropdown` → `Select::options(...)` from `options` JSON
  - `checkbox` → `Toggle`
  - `multiselect` → `Select::multiple()->options(...)`
- `is_required=true` → `->required()`. `key='phone'` → `->required()` regardless of toggle.
- Built-ins use `->statePath('<column>')` directly. Custom fields go through `StudentFormDynamicTrait` which hydrates from `student_field_values` on `mount` and persists on `save` inside a single DB transaction.

### Table
- Built-in columns stay as today (Filament's existing column toggle UX preserved).
- For each `student_field` with `show_in_table=true`, append a column. Column type:
  - text/textarea/email → text column, sortable
  - number → numeric column, sortable
  - date → date column, sortable
  - dropdown → badge column (color cycle by value)
  - checkbox → boolean icon column
  - multiselect → comma-joined text, not sortable
- Custom-field columns sort via `whereHas('fieldValues', …)` joined query.

### Kanban tile
- Existing tile body keeps stage info, owner, payment received chip.
- New "extras" block below: lists fields with `show_in_kanban=true` as `Label: value` pairs. Cap at 3.
- If admin enables a 4th in Field Config, show a soft warning toast: "Kanban tile shows max 3 — the 4th will be hidden." Field still saves; rendering takes the first 3 by `position`.

### CSV import
- Existing manual lead import CSV template is regenerated to include columns for every field where `show_in_import=true`. Built-ins keep their current header names; custom fields use `key` as the header.
- Importer maps incoming columns to either built-in setters or `student_field_values` writes (one upsert per `(student_id, student_field_id)` pair).
- Unknown columns: warn but accept (current importer behavior preserved).
- Required-field validation is enforced at row level — if a required field is empty in the CSV, the row is rejected with the existing rejection-sheet flow.

### View page
- Read-only render of the same sections + fields as the Edit form.

### Activity log
- Every custom field value change writes to existing Spatie ActivityLog with `description = "field.<label>: <old> → <new>"`.
- Built-in column changes already log via existing observer — unchanged.

## Lifecycle & data safety

- **New field added:** appears on form for new + existing students. Existing students have no value (nullable everywhere).
- **Field rename (label):** zero impact on stored data — label is read-only metadata; `key` and `id` never change.
- **Field reordered or moved between sections:** zero impact on values.
- **Field type change:** disallowed via UI. Admin must archive + create new field if they want a different type. (Built-ins can't change type at all.)
- **Field archived:** disappears from form/table/kanban/import; values preserved in `student_field_values`. Restorable.
- **Field hard-deleted (admin-only, typed confirmation):** all values in `student_field_values` are deleted via cascade. Built-ins cannot be hard-deleted.
- **Section delete:** forces field transfer first (no orphan fields possible).
- **Student delete:** existing `students` cascade unchanged; new cascade on `student_field_values.student_id` purges values.
- **Dropdown option removed by admin:** existing student values that referenced the removed option are *kept* in the DB and shown as `<old value> (removed)` in read-only views. Edit form forces a re-pick on save.
- **Migration rollback (Phase A down migration):** drops the three new tables; built-ins remain in `students` untouched (no data loss).

## Phase B forward references

These references confirm the Phase A schema is forward-compatible. Phase B implementation is its own spec.

- Phase B's `student_segments.conditions` JSON references `student_fields.id` for custom fields and `built_in_column` (or special key) for built-ins. The `key` field on `student_fields` exists partly to keep this stable across renames.
- Phase B's `SegmentQueryBuilder` joins `student_field_values` indexed by `(student_field_id, value_*)`.
- Field archive does not break segments — they continue to query archived fields and show a warning badge. Phase A's `archived_at` makes this trivially queryable.

## Architecture / file layout

```
app/
├── StudentFields/
│   ├── FieldRenderer.php             ← type → Filament component map
│   ├── StudentFormDynamicTrait.php   ← hydrate/persist custom values on StudentResource
│   ├── DynamicTableColumns.php       ← build table columns from show_in_table fields
│   ├── KanbanExtrasFormatter.php     ← format extras block (cap 3)
│   └── ImportColumnMapper.php        ← CSV header → target (built-in setter | field_value upsert)
├── Models/
│   ├── StudentFieldSection.php
│   ├── StudentField.php              ← scopes: active(), archived(), builtIn(), custom()
│   └── StudentFieldValue.php
├── Filament/
│   ├── Pages/
│   │   └── StudentFieldsConfigPage.php  ← /admin/student-fields
│   └── Resources/
│       └── StudentResource.php          ← updated to use dynamic form/table
└── Observers/
    └── StudentFieldValueObserver.php    ← ActivityLog wiring

database/migrations/
├── 2026_04_24_010000_create_student_field_sections_table.php
├── 2026_04_24_010100_create_student_fields_table.php
├── 2026_04_24_010200_create_student_field_values_table.php
└── 2026_04_24_010300_seed_built_in_student_fields.php  (data migration)

resources/views/filament/pages/
└── student-fields-config.blade.php
```

## Testing strategy (TDD)

- `FieldRenderer` — one test per type confirming the right Filament component class is returned with required props.
- `StudentFieldSection` — section CRUD, position swap on reorder, transfer-on-delete.
- `StudentField` — field CRUD per type, built-in lock rules (no archive, no type change, phone always required), key uniqueness, slug generation.
- `StudentFieldValue` — value upsert per type, dropdown option-removed handling, multiselect JSON serialization.
- `Soft archive + restore` — archived fields excluded from form/table/kanban/import; values preserved; restore returns to old section/position; hard-purge wipes values via cascade.
- `Built-in bridging` — Filament form load reads `students.<column>` for built-ins; save writes back; never touches `student_field_values` for built-ins.
- `Dynamic form rendering` — feature test creates 3 sections + 8 fields (one per type) + saves a Student form; asserts values persisted correctly to both tables.
- `Dynamic table` — feature test enables `show_in_table` on 3 custom fields; asserts columns appear and sort correctly.
- `Kanban tile extras cap` — enabling 4 kanban fields renders only the first 3; admin warning surfaced.
- `CSV import` — round-trip with custom fields; missing required field → rejection; unknown column → warning kept.
- `Section transfer-on-delete` — deleting non-empty section forces transfer modal.
- `Activity log` — custom field change writes one log entry per changed field with correct description.
- `Phone lock` — `is_required` toggle on `phone` field has no effect; UI checkbox is disabled-checked.
- `getRules() name guard` — page class doesn't shadow `Filament\Pages\BasePage::getRules()`.

Target: ≥ 50 new tests, all green before merge.

## Rollout

1. Local TDD pass → all tests green.
2. Local smoke checklist (Field Config CRUD, all 8 types, archive/restore, kanban tile cap, CSV import round-trip, sections drag-reorder, built-in lock rules, phone always-required).
3. PR review (you).
4. Merge to main → push to prod via existing pull-based deploy.
5. Run new migrations on prod (`/opt/alt/php84/usr/bin/php artisan migrate`).
6. Prod smoke: open `/admin/student-fields`, create one test field, archive it, restore it, hard-delete it.
7. Update project memory entry: mark Phase A shipped + capture commit hash + flag Phase B as next.

**Rollback plan:** tag pre-Phase-A commit (`pre-phase-a-student-fields-20260423`); rollback = git reset to tag + `migrate:rollback --step=4`. Built-in `students` columns are never altered, so rollback is clean.

## Pre-Phase-A pending hygiene (not part of this spec; do first)

Per the brainstorming-session decision:
- SP#3 follow-up (a) deep-link filter keys missing on StudentResource
- SP#3 follow-up (b) "uncheck all" → option C empty state
- SP#3 follow-up (d) SortableJS consolidation candidate
- Today Tab + Finance Admin prod smokes (you walk through with me)

These ship as small commits on main before Phase A starts.

## Known gotchas

- **Filament/Tailwind color classes don't reach admin pages.** Use inline styles on action buttons. (Bites every time.)
- **`getRules(): array` shadowing** — Filament `BasePage::getRules()` LSP-locks the signature. Don't name any page method `getRules`.
- **PHP 8.4+ required** for composer/artisan: `/opt/alt/php84/usr/bin/php`.
- **Built-in `category` enum.** It's currently `enum('Delhi','Outside')` at the DB level. Admin can rename the label and add option labels in `student_fields.options`, but adding a new option ('NCR') would not pass DB validation until a migration relaxes the enum. Out of scope for Phase A — flag it if it comes up.
- **Phase B will lean on `student_fields.id` stability** — never reuse IDs (no hard-delete with ID reset).

## Open questions

None blocking. To revisit during implementation if they bite:
- Should the Field Config page have a "Preview as Counsellor" mode? (Not in scope; revisit if asked.)
- Does the kanban extras cap (3) become a per-user setting instead of global? (Not in scope.)
