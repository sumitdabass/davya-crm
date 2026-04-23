# Phase A (Custom Student Fields) — Local Smoke Checklist

Pre-merge to main / pre-prod-deploy. Run on local with `php artisan serve`. Login as `sumit@davya.local`.

## Field Config page

- [ ] /admin/student-fields renders. Two seeded sections (Identity, Academic) visible. 8 built-in fields visible.
- [ ] Built-in field "Phone" badge shows "🔒 built-in", required toggle is disabled-checked, no archive option in the menu.
- [ ] Create a new section "Demographics" via "+ Add section". It appears at the bottom.
- [ ] Drag "Demographics" above "Academic". Order persists after page reload.
- [ ] Add a custom Text field "Email" under Demographics. Confirm `key=email` in DB. Mark it `Show in table`.
- [ ] Add a custom Date field "DOB" under Demographics.
- [ ] Add a custom Number field "Marks" under Demographics.
- [ ] Add a custom Email field "Alternate Email" under Demographics.
- [ ] Add a custom Dropdown "Board" with options CBSE / ICSE / State.
- [ ] Add a custom Checkbox "Demo Attended". Mark `Show in kanban tile`.
- [ ] Add a custom Multi-select "Subjects" with options Maths / Physics / Chemistry.
- [ ] Add a custom Textarea "Notes". (All 8 types now exist.)

## Student form (Create / Edit / View)

- [ ] Open /admin/students/create. Demographics section appears with all 7 custom fields. Identity + Academic are at the top.
- [ ] Save a student with: phone `9000099001`, name "Test", DOB 2009-05-12, Marks 92.5, Board CBSE, Demo Attended ✓, Subjects [Maths, Physics], Notes "test note", Email "a@b.com".
- [ ] Edit the student. All values reload correctly. Change DOB to 2009-06-15, save.

## Students table + kanban

- [ ] /admin/students table now shows "Email" column. Sort by Email.
- [ ] Open /admin/kanban. The student tile shows "Demo Attended: Yes" in the extras block.
- [ ] Enable `Show in kanban` on a 4th field. Tile still shows only 3 (KanbanExtrasFormatter::MAX caps at 3).

## CSV import (Phase A note)

- [ ] /admin/lead-import. Existing per-source templates (sonam, nikhil, sumit-website, canonical) still work. NOTE: Custom fields are NOT yet wired into the import flow — that's a Phase A.2 follow-up tracked separately. The `App\StudentFields\ImportColumnMapper` service is available but not yet integrated into the static-template pipeline.

## Lifecycle

- [ ] Archive "Notes" field via Field Config menu. It disappears from /admin/students/edit form. /admin/student-fields → Archived tab shows it.
- [ ] Restore "Notes". It returns to Demographics. Old value still present on the test student.
- [ ] Mark "Notes" required. Try to save the student with empty Notes. Save fails with validation message.

## Built-in lock rules

- [ ] Try to archive built-in "Name". Action blocked.
- [ ] Try to set `is_required=false` on built-in "Phone" via the Field Config form. Toggle disabled in UI; if forced via Livewire call, value is corrected to `true` on save.

## Hard delete

- [ ] Hard-delete an archived custom field (with a value). Type DELETE to confirm. Field + value purged.

## Activity log

- [ ] /admin/activity-log shows entries for created/updated custom field values with `field.<Label>: old → new` descriptions.

If all green: open PR for Phase A and run prod deploy via the standard pull → migrate runbook.
