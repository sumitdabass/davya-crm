# SP#3 Local Smoke Checklist (2026-04-23)

Run `php artisan migrate --force && php artisan optimize:clear` before starting.

**Prerequisite accounts:**
- Sumit (admin): `sumit@davya.local`
- A head (Sonam or Nikhil)
- A counsellor test account

## Dashboard (`/admin`)

- [ ] Login as Sumit. Dashboard renders.
- [ ] Default layout visible: Stuck Leads, Re-Entry Candidates, Seat Fee Pending, plus one stat card per stage in the current pipeline config.
- [ ] Each stage card shows count + ₹ total.
- [ ] Click any stage card count → slide-over opens with filtered students, search field works, CSV button visible, paginated if >20 rows.
- [ ] CSV download works — opens a .csv file with matching headers (Name, Phone, Owner, Course, Days in stage).
- [ ] Slide-over "Open in full table →" routes to filtered StudentResource.
- [ ] Close slide-over (click backdrop or ✕) → returns to dashboard.
- [ ] Click `✕` on a card → undo toast appears bottom-right → click undo within 8s → card restored.
- [ ] Click `✕` again → wait 9s → refresh page → card stays removed.
- [ ] Open Customize modal → list matches current layout + all available cards.
- [ ] Toggle a disabled card ON → drag it into middle position → Save → page reflects.
- [ ] Reset to defaults → layout back to day-0 defaults.

## Today (`/admin/today`)

- [ ] Default layout: Today Meetings, Today Payments, Meetings Held Today, Leads Captured Today, Admissions Closed Today.
- [ ] Click each stat card → slide-over opens with matching rows + CSV.
- [ ] Same Customize modal flow works.

## Role scoping

- [ ] Login as a counsellor.
- [ ] Stage card counts reflect only that counsellor's leads (no other team's leakage).
- [ ] Drill-down slide-over rows are scoped.
- [ ] CSV download only contains the scoped rows.

## New-stage propagation

- [ ] As Sumit, go to `/admin/pipeline-config` → create a new stage.
- [ ] Refresh Dashboard. New stage card appears at the bottom of your layout.
- [ ] Open Customize modal. New card listed.
- [ ] Login as counsellor. They also see the new card (auto-appended).

## Regression

- [ ] `/admin/pipeline-config` still works (SP#1 feature unchanged).
- [ ] Student resource, Meetings relation manager, Kanban, PaymentReport, LeadsReport — all unchanged.
- [ ] PWA still installable.
- [ ] `/admin/today` meetings strip (from SP#1) still shows up via `today_meetings` card.

## Known limitations (per code review)

- List-card "View all" links for Stuck Leads / Re-Entry Candidates / Seat Fee Pending use filter keys (`stuck`, `re_entry`, `seat_fee_pending`) that do NOT exist on StudentResource yet. Clicking will show the unfiltered student list. NOT a regression; follow-up task. Safe to ignore for this smoke.
- Empty-state on Today page: removing all cards one-by-one via the Customize modal and saving still triggers auto-append of defaults on next load (resolver design). If that surprises Sumit, flag for a follow-up spec decision.

## Sign-off

Date: _______
Smoke walked by: Sumit
Ready to merge: Y / N
