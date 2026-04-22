# davya-crm — Operational handoff after M6 code ship

Code for M6 (multi-sheet ingestion, student payment upload, inline first-payment,
admin UI for rank/state/email) is merged to `main` / landed on the
`feature/student-admin-ui-multi-sheet-fields` branch. What remains is all in
external systems (n8n UI, Google Sheets, the browser, shell).

## 0. Central Rejections spreadsheet — ALREADY CREATED

**Rule:** we never modify owner-driven sheets (Sonam / Nikhil). Rejected rows
go to a **central spreadsheet owned by `admission@ipu.co.in`** instead.

- **Sheet id:** `10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs`
- **URL:** https://docs.google.com/spreadsheets/d/10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs/edit
- **Owner:** admission@ipu.co.in (same account as the existing n8n OAuth
  credential `A8Grx7J6ZfarJVR1`, so no re-consent flow needed).
- **Headers row 1:** `Timestamp | Owner | Source Sheet Id | Original Row Number | Row Data JSON | Error`

**One tiny UI check:** the first tab may be named `Sheet1` after CSV import.
Rename it to `Rejections` (double-click the tab → rename) before activating
any of the three workflows. The n8n nodes reference `sheetName: "Rejections"`.

The Sonam workflow JSON (`docs/n8n-lead-sonam-workflow.json`) already has this
id wired in — you can import it as-is.

## 1. Activate `lead-sumit-website-sheet` in n8n

- Workflow id: `7cqS00mq6r2yGJDG`
- Source sheet: `1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w`
- Host: https://n8n.srv1117424.hstgr.cloud

Steps:
1. Open the workflow.
2. Edit the **"Append to Rejected tab"** Google Sheets node:
   - Change `documentId` from Sumit's sheet id to the **central Rejections
     sheet id** from §0.
   - Change `sheetName` from `Rejected` to `Rejections`.
   - Update the columns list to:
     `Timestamp | Owner | Source Sheet Id | Original Row Number | Row Data JSON | Error`.
   - Attach a `googleSheetsOAuth2Api` credential (none bound today).
3. Save and toggle **Active**.
4. Trigger a row on the sheet; confirm a student appears in `/admin/students`
   and that rejection payloads land on the central Rejections sheet.

## 2. Activate `lead-nikhil-sheet` in n8n

- Workflow id: `v3b8K2UC08QY4V3H`
- Source sheet: `13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM`

Same steps as §1: re-point the Rejected-tab node at the central Rejections
sheet id (from §0), rename sheetName to `Rejections`, update columns to
`Timestamp | Owner | Source Sheet Id | Original Row Number | Row Data JSON | Error`,
attach the `googleSheetsOAuth2Api` credential, save, activate.

The Sheets Trigger credential (`A8Grx7J6ZfarJVR1`) is already bound on this
workflow — don't touch it.

## 3. Create `lead-sonam` workflow

- Sheet id: `11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4`
- Sheet URL: https://docs.google.com/spreadsheets/d/11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4/edit
- Pre-built workflow JSON: `docs/n8n-lead-sonam-workflow.json`

Her sheet columns are `Date | Ph no | Course | Rank | D/OD | enquiry | connected to.` (narrower than Nikhil's). The workflow maps them as-is — no sheet edits needed. `connected to.` → `referrer_name`, so Poonam/Neetu get referrer credit while Sonam stays owner.

Steps:
1. In n8n UI: **Workflows → Import from file** → upload `docs/n8n-lead-sonam-workflow.json` as-is (the central sheet id is already wired in).
2. Open the imported workflow.
3. Bind the **Google Sheets Trigger** node to credential `A8Grx7J6ZfarJVR1` (the same one used by Nikhil's and Sumit's workflows).
4. Bind the **"Append to central Rejections sheet"** node to a `googleSheetsOAuth2Api` credential (the same admission@ipu.co.in account already owns the central sheet).
5. Bind **POST /api/leads** to the `httpHeaderAuth` credential carrying `X-Lead-Token`.
6. Save + activate. **No edits to Sonam's sheet needed.**
7. Smoke test: ask Sonam to add a row with just `Ph no` + `Course` and confirm a student appears in `/admin/students` with owner=Sonam. Then add a row with no phone and confirm a line lands on the central Rejections sheet.

## 4. (Optional) Nikhil's column layout

Nikhil's sheet is already close enough to the canonical layout that the
template maps all its columns. If you want richer data capture later, add
`Email` and `Reference` columns; see `docs/LEAD_CAPTURE_API.md` for the
canonical column → field map. **No immediate action required** — this is a
future-polish task, not a blocker. Sonam's sheet is handled entirely by the
workflow in section 3 (no sheet edits needed).

## 5. Prod smoke — inline first-payment block

1. SSH into Hostinger (`ipuc@ipu.co.in`), pull `main`, run `scripts/deploy.sh`.
2. In browser: visit `https://davyas.ipu.co.in/admin/students/create`.
3. Fill identity + stage + preference, expand "First payment (optional)",
   enter type=`advance`, amount=100, received_at=now.
4. Create. Confirm:
   - Student row in `/admin/students` with correct phone.
   - One payment on the Payments tab, `recorded_by_user_id` = you.
   - Delete this test student afterward (or use 9100000301 phone so scripts
     already skip it).

## 6. Rotate the SSH deploy key

Reason: the private key at `~/.ssh/davyas-active` was pasted in chat; treat
it as public knowledge.

```sh
# Local — generate a new keypair
ssh-keygen -t ed25519 -C "davya-crm-deploy@ipuc-hostinger-$(date +%Y%m%d)" -f ~/.ssh/davyas-active-new

# Copy the new public key to the server
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cat >> ~/.ssh/authorized_keys" < ~/.ssh/davyas-active-new.pub

# Verify new key works
ssh -i ~/.ssh/davyas-active-new ipuc@ipu.co.in "whoami && date"

# On server (ipuc@ipu.co.in) — remove the compromised public key line
# Easiest: open ~/.ssh/authorized_keys in vim and delete the old entry
# (its comment ends with davya-crm-deploy@ipuc-hostinger and was present
# before today's rotation).

# Local — swap the names so scripts keep working
mv ~/.ssh/davyas-active      ~/.ssh/davyas-active-revoked
mv ~/.ssh/davyas-active-new  ~/.ssh/davyas-active
mv ~/.ssh/davyas-active-new.pub ~/.ssh/davyas-active.pub

# Final check
ssh ipuc@ipu.co.in "hostname"   # should succeed with the new key
shred -u ~/.ssh/davyas-active-revoked  # or rm, if shred not installed
```

Once verified, also delete the legacy passphrase-locked keys:
`~/Downloads/davyas-key` and `~/.ssh/davyas-deploy`.

## 7. Merge the UI branch

```sh
cd /Users/Sumit/davya-crm
gh pr create --base main --head feature/student-admin-ui-multi-sheet-fields \
  --title "feat(filament): surface rank/state/email on student admin UI" \
  --body "Adds rank, state, email to the Student create/edit form and as toggleable list columns. Relaxes the form name requirement (column is already nullable). Tests: StudentMultiSheetFieldsUiTest (4 cases, full suite green)."
```

Or merge locally + deploy pull-based.

## Done-criteria for "M6 fully shipped"

- [ ] Three n8n workflows active (sumit-website, nikhil, sonam).
- [ ] Rejection row appears on Rejected tab for a deliberately bad sample.
- [ ] Prod `/admin/students/create` first-payment smoke passes once.
- [ ] Old `davyas-active` key no longer in server `authorized_keys`.
- [ ] `feature/student-admin-ui-multi-sheet-fields` merged into `main`.
