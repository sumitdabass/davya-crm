# Student create/edit form — mobile-first redesign (DESIGN, LOCKED 2026-06-10)

**Status:** Design approved by Sumit, implementation deferred to a later session.
This is the first piece of a broader **"update the entire CRM with a mobile-first
policy"** initiative (see closing note).

## Approved mockups (source of truth for the UI)
- `docs/superpowers/specs/mockups/student-form-mobile.html` — the form, mobile-first, every tab/field, interactive (tabs, stage stepper, chips, Records sub-switcher).
- `docs/superpowers/specs/mockups/student-form-inframe.html` — same form shown **inside the current admin chrome** (topbar + breadcrumb stay current look; only the form card is re-skinned; relation managers below stay current look). All 4 tabs fully populated.
- `docs/superpowers/specs/mockups/student-dossier.html` — the *detail/show* view in the same aesthetic (reference; not part of this build's scope).

## Aesthetic
Per `feedback_davya_crm_typography`: **Instrument Serif** (display, italic accents)
+ **Bricolage Grotesque** (body) + **JetBrains Mono** (numerals); brand **emerald**
+ **vermilion** accent; warm cream paper. NB: the current davya v3 chrome already
uses emerald + vermilion + Bricolage + JetBrains Mono on warm paper, so the only
net-new elements are the Instrument Serif headers, the deeper cream form cards, and
the stepper — it blends, not clashes.

## Decisions made during brainstorming
1. **Visual scope = student form pages ONLY** (`/admin/students/create` + `/admin/students/{id}/edit`). CSS namespaced under one wrapper class carried only by those two pages; fonts load only there. The surrounding Filament chrome (topbar/breadcrumb) and the relation-manager panels keep the current look for now.
2. **Stage = tappable stepper** that *replaces* the dropdown visually + behaviorally (tap a stage to set it), but the existing **StageTransitionEngine** stays the source of truth — taps route through the same `afterStateUpdated` path so hard blocks revert+notify, soft warnings fire, and `stage_id` syncs exactly as today.
3. **Hard constraint: ZERO missing fields or features** vs the current form. See inventory below.

## Field & feature inventory (nothing dropped)
Tabs and field order match the live `StudentResource::form()` exactly.

- **Stage section (top, always visible):** `stage` (now stepper, all 13 real pipeline stages: Lead Captured → Meeting Scheduled → Meeting Done → Advance Received → MQ → Round 1 → Round 2 → Round 3 → Sliding → Offline → Seat Allotted → Complete Payment Received [won] → Closed [lost]) + money-summary view (restyled, same data: deal · received · pending · payouts · paid out · to pay · profit).
- **Tab 1 Identity (order):** owner_id, referrer_id (Lead Owner, +lock badge + helper texts), lead_source→chips, student_response→chips, phone, name, father_name, phone_2, email, email_2, address.
- **Tab 2 Academic (order):** course, university, exam_appeared, rank, twelfth_marks, category→chips, sub_category, state, preference_r1 (required), preference_r2, preference_r3.
- **Tab 3 Deal & Counselling (order):** deal_amount, plan→chips, registration_status→chips, counselling_registration_status→chips, ipu_user_id, ipu_login_code (+helper "Shared with the student during counselling."), current_round, seat_allotment_fee_status→chips.
- **Tab 4 Account:** +New Note action, Closure section (close_reason, refund_amount, re_entry_reason + "Fill these only when wrapping up" description), account-summary view (timeline restyled).
- **Outside tabs:** `description` "Quick notes" (placeholder "Jot anything — visible on every tab…"), pinned-bottom styling + sticky Save / Save & close.
- **Relation managers (below form, unchanged):** Payments, Payouts, Notes, Activity, Meetings, Round History.
- **Preserved untouched:** StageTransitionEngine, all validation/required/unique rules, StudentField-driven dynamic options (`optionsFor`), custom/dynamic fields (`customFieldsForSection`, `dynamicSections` via FieldRenderer), policies/permissions (owner disabled for non-admin, lead-owner lock, super-admin gates), save behavior.

## Components to build/touch (implementation, next session)
- Custom Blade **stepper** view bound to the `stage` form field.
- Swap short-enum Selects → Filament **ToggleButtons** (options still from `optionsFor()`): lead_source, student_response, category, plan, registration_status, counselling_registration_status, seat_allotment_fee_status. (owner_id/referrer_id stay searchable Selects; close_reason stays a Select.)
- Restyle `account-summary.blade.php` timeline + `student-money-summary.blade.php`.
- New **scoped skin stylesheet** + 3 fonts, loaded only on create/edit pages.

## Testing (next session)
Existing suite stays green. New: stepper sets stage + blocked transition still reverts; ToggleButtons persist the same values a Select did (esp. `plan`); skin class present only on create/edit, absent elsewhere. No DB/schema changes.

## Risks
ToggleButtons with long labels (seat-fee status) wrap on mobile — acceptable. Scoped CSS must not leak — verify other pages visually unchanged.

---

## Closing note — broader initiative (next session)
Sumit wants to **update the ENTIRE CRM to a mobile-first policy**, not just this form.
This student-form redesign is the pilot. Next session: decide whether to (a) finish
this form first as the reference implementation, then (b) extend the mobile-first +
re-skin pass across the rest of the admin (dashboard, kanban, reports, payment report,
detail/dossier view, settings). The "Global admin re-skin" option was deferred earlier
precisely because that larger effort is coming.
