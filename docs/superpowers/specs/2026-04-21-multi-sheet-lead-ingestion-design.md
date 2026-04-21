# Multi-Sheet Lead Ingestion — Design Spec

**Date:** 2026-04-21
**Status:** Approved for planning
**Owner:** Sumit

## Context

Lead intake today goes through a single pipeline: Google Forms → n8n → `POST /api/leads` → `students` table. Three additional lead sources exist as live Google Sheets that team members maintain manually:

- **Sonam's sheet** — `11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4` — phone-and-enquiry log, no names captured today
- **Nikhil's sheet** — `13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM` — richer lead log, inconsistent schema (referrers in unnamed columns, stage info in remarks, state as free text)
- **Sumit's website-form sheet** — `1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w` — clean output from the ipu.co.in lead form, ~600 existing rows, has visible form-bounce duplicates (same phone submitted 2–3× within seconds)

Goal: all three sheets flow into the CRM as unique `Student` records, with phone as the dedup key and the sheet's owner as the CRM owner.

## Non-Goals

- Backfilling Nikhil's or Sonam's historical rows (data is too inconsistent to usefully migrate; they start the new format on cutover day)
- Two-way sync (CRM → sheet). Sheets are source; CRM is sink.
- An `Enquiry` vs `Lead` dual-record model. A lead is a `Student` row. Phone-only contacts without a course stop being logged.
- Slack notifications. Error surfacing moves into a per-sheet "Rejected" tab.

## Architecture

Three independent n8n workflows, one per sheet, built from the same template:

```
Google Sheets Trigger (poll 1 min, "row added")
  → Set node (hardcode owner_name, normalize fields, map columns → JSON)
  → IF (phone empty OR course empty) → append to "Rejected" tab, end
  → HTTP Request POST /api/leads (X-Lead-Token auth)
  → Switch on response status
      ├─ 201 → end
      ├─ 409 → silent (expected duplicate)
      ├─ 422 → append row to sheet's "Rejected" tab with error body
      └─ 5xx → leave error in n8n execution log (existing monitoring catches it)
```

**Separation of concerns:**
- **n8n:** row detection, column mapping, pre-flight check for required fields, forward to API. No business logic.
- **Laravel (`LeadIntakeService`):** phone normalization, category/course normalization, owner resolution, dedup enforcement, persistence.
- **MySQL:** `students.phone` UNIQUE index — the ultimate dedup guarantee.

## Standard Sheet Format

All three sheets adopt this column layout. Nikhil and Sonam migrate on cutover day. Sumit's existing sheet already matches closely — a rename of one column plus a new "Source" passthrough.

| Col | Field | Required | Validation |
|-----|-------|----------|------------|
| A | Date | ✓ | Any parseable date; year defaults to current year if missing |
| B | Phone | ✓ | 10 digits after stripping `+91`/spaces |
| C | Course | ✓ | Google Sheets dropdown: BTech, BCA, BBA, BBA LLB, BA LLB, LLM, MBA, BCom, BArch, BSc, BA, PGDM, B.Ed, BJMC, Management Quota, Other |
| D | Name | | Free text |
| E | Father Name | | Free text |
| F | 12th Marks | | Free text (%, CGPA, marks) |
| G | Rank | | Free text (supports "55000", "81%", "Cat 30") |
| H | Category | | Dropdown: Delhi / Outside |
| I | State | | Free text |
| J | College | | Free text |
| K | Reference | | Free text — referrer name (optional, column can be added later) |
| L | Remarks | | Free text — maps to `students.extra_notes` |
| M | Email | | Free text |
| N | Source | | Free text — defaults to `Sheet:<owner>` if blank |

**Owner derivation:** Hardcoded per n8n workflow, not per row. `Sonam`'s sheet → owner = Sonam user; same pattern for Nikhil and Sumit. A row-level owner column is deliberately not used — one sheet, one owner.

**Rejected tab:** Each sheet has a second tab named `Rejected` with columns: `Original Row Number`, `Row Data JSON`, `Error`, `Timestamp`. n8n writes to it when phone or course is missing, or when `/api/leads` returns 422.

## CRM Schema Changes

Migration `2026_04_21_add_multi_sheet_fields_to_students`:

```php
Schema::table('students', function (Blueprint $table) {
    $table->string('rank', 40)->nullable()->after('twelfth_marks');
    $table->string('state', 40)->nullable()->after('category');
    $table->string('email', 120)->nullable()->after('phone_2');
    $table->string('name', 120)->nullable()->change();  // was NOT NULL
});
```

- `rank`, `state`, `email` — new nullable columns
- `name` — relaxed to nullable per the new rule (phone + course are the only truly required fields)
- `extra_notes` — reused for incoming `remarks` field (no migration)
- `preference_r1` — reused for incoming `college` field (no migration)
- `lead_source` — reused for incoming `source` field (no migration)

No other schema change. `referrer_id` is already nullable from the 2026-04-17 migration.

## API Changes (`POST /api/leads`)

Controller update to `LeadController` + `LeadIntakeService`:

| Field | Required | Notes |
|-------|----------|-------|
| `phone` | ✓ | Unchanged — 10-digit normalized |
| `course` | ✓ | **Newly required.** Max 80 chars. |
| `name` | | **Now optional.** Max 120. |
| `owner_name` | | **New.** If present, look up User by name (case-insensitive) → `owner_id`. Overrides the referrer-derived owner. |
| `referrer_name` | | Unchanged lookup logic; now optional |
| `father_name` | | Unchanged |
| `twelfth_marks` | | Unchanged |
| `rank` | | **New.** Max 40, free text. |
| `category` | | Existing enum (`Delhi` / `Outside`); unchanged |
| `state` | | **New.** Max 40, free text. |
| `college` | | **New.** Writes to `preference_r1`. Max 120. |
| `email` | | **New.** Max 120. |
| `remarks` | | **New.** Writes to `extra_notes`. |
| `source` | | **New.** Writes to `lead_source`. Max 60. Defaults to `Sheet:<owner>` server-side if blank. |

**Owner resolution order:**
1. `owner_name` in payload → User lookup → use as owner
2. Else existing referrer → owner mapping (from the 8-entry dropdown table)
3. Else default to Sumit (admin)

**Responses unchanged:**
- `201` with id/stage/owner/referrer
- `401` unauthorized (missing/bad token)
- `422` validation errors
- `409` `{error: "duplicate_phone", existing_id: N}`

**Backward compatibility:** The current Google Forms workflow keeps sending `name` and `referrer_name`. Making them optional doesn't break it. Making `course` required does — the existing form already collects course, so this needs a n8n mapping check on rollout.

## Sumit's Sheet Backfill

One-off artisan command: `php artisan leads:backfill-sumit-sheet /path/to/export.csv`

- Reads CSV, normalizes phones, in-memory dedup (keeps first occurrence — handles the bounce-duplicate pattern)
- For each unique row, calls `LeadIntakeService::ingest()` with owner_name=Sumit
- Idempotent: rerunning skips phones already in `students`
- Dry-run flag: `--dry-run` prints what would be inserted without writing
- Output: `Imported: N | Skipped (duplicate): M | Rejected (missing phone/course): K`

Run once before wiring Sumit's n8n workflow so the live trigger only handles new rows.

Nikhil's and Sonam's sheets are **not** backfilled. Historical rows stay in the old sheet tabs as read-only reference.

## n8n Workflow Configuration

Three workflows, same template, differing only by sheet ID and hardcoded owner:

| Workflow | Sheet ID | `owner_name` | Rejected tab name |
|----------|----------|--------------|-------------------|
| `lead-sonam` | `11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4` | `Sonam` | `Rejected` |
| `lead-nikhil` | `13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM` | `Nikhil` | `Rejected` |
| `lead-sumit-website` | `1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w` | `Sumit` | `Rejected` |

**Column mapping (Set node):**

```
phone        ← row["Phone"] || row["Ph no"] || row["Contact no"]
course       ← row["Course"]
name         ← row["Name"] || row["Student Name"]
father_name  ← row["Father Name"]
twelfth_marks← row["12th marks"]
rank         ← row["Rank"]
category     ← normalize(row["Category"] || row["D/OD"])   // "D"/"delhi" → "Delhi"; "OD"/"outsider" → "Outside"; else null
state        ← row["State"]
college      ← row["College"]
email        ← row["Email"]
referrer_name← row["Reference"]
remarks      ← row["Remarks"] || row["enquiry"] || row["Message"]
source       ← row["Source"] || "Sheet:<owner>"
owner_name   ← "<hardcoded per workflow>"
```

**Auth:** Reuse existing `X-Lead-Token` credential in n8n. No new token.

**Rollout order:**
1. Deploy CRM changes (migration + API) to prod
2. Run Sumit's backfill script from a local checkout against prod DB (or via `artisan tinker` on the server — TBD at plan stage)
3. Configure + enable `lead-sumit-website` n8n workflow; verify with a test row
4. Share standard sheet template with Nikhil and Sonam; have them migrate
5. Configure + enable `lead-sonam` and `lead-nikhil` workflows

## Testing

**Unit tests** (Laravel):
- `LeadIntakeServiceTest` — phone normalization variants, course required, `owner_name` override wins, `owner_name` unknown falls through to referrer mapping, remarks → extra_notes, source default
- `LeadControllerTest` — new 422 on missing course, 201 with name omitted, 409 on duplicate phone including across different owners

**Integration**:
- One sample n8n run per sheet against a staging row, verify `students` insert has expected owner and fields
- Bounce-duplicate test: insert Sumit's CSV with known 2-row and 3-row duplicates, confirm only one survives

**Smoke test after rollout:**
- Add one row to each of the three sheets, confirm it lands in `/admin/students` with correct owner within 2 minutes
- Add a duplicate phone across two sheets, confirm first wins and second appears in the second sheet's Rejected tab

## Open Items (decided at plan stage, not design stage)

- Whether to run the Sumit backfill from a developer machine vs on the server
- Exact Rejected-tab schema column types (text vs dynamic from n8n — minor)
- Whether `source` column's default `Sheet:Sonam`/`Sheet:Nikhil`/`Sheet:Sumit` should instead be a structured enum value (e.g. keep `lead_source` clean)

## Risks

- **Sheet schema drift:** Nikhil/Sonam could silently edit column order after cutover and break mapping. Mitigation: n8n Set node references column headers by name, not by position, so minor order edits are safe; deleting a required header fails loudly on next poll.
- **Google Sheets API quota:** n8n polls every minute × 3 sheets = 4,320 reads/day. Well inside the default 300 requests/min/user quota.
- **Phone-number collision across non-leads:** an existing `Student` (phone X) gets a new row in Sonam's sheet for an unrelated person dialing the same number (rare but possible in India's recycled-number ecosystem). 409 triggers — owner sees it in Rejected tab and can manually handle.
