# davya-crm — Operational handoff after M6 code ship

Code for M6 (multi-sheet ingestion, student payment upload, inline first-payment,
admin UI for rank/state/email) is merged to `main` / landed on the
`feature/student-admin-ui-multi-sheet-fields` branch. What remains is all in
external systems (n8n UI, Google Sheets, the browser, shell).

## 1. Activate `lead-sumit-website-sheet` in n8n

- Workflow id: `7cqS00mq6r2yGJDG`
- Source sheet: `1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w`
- Host: https://n8n.srv1117424.hstgr.cloud

Steps:
1. Open the workflow.
2. Edit the **"Append to Rejected tab"** Google Sheets node and attach a
   `googleSheetsOAuth2Api` credential (it currently has no credential bound).
3. Save and toggle **Active**.
4. Trigger a row on the sheet; confirm a student appears in `/admin/students`
   and that rejection payloads land on the Rejected tab.

## 2. Activate `lead-nikhil-sheet` in n8n

- Workflow id: `v3b8K2UC08QY4V3H`
- Source sheet: `13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM`

Same steps as #1. The Sheets Trigger credential (`A8Grx7J6ZfarJVR1`) is
already bound; only the Rejected-tab node needs the OAuth credential.

## 3. Create `lead-sonam` workflow

Blocked on Sonam's 2026 sheet ID. Once you have it:
1. Duplicate `lead-nikhil-sheet` in n8n UI.
2. Rename to `lead-sonam-sheet`.
3. Swap the sheet id on the Trigger node.
4. Attach the same OAuth credential to "Append to Rejected tab".
5. Save + activate.

## 4. Standardize Nikhil's and Sonam's column layouts

Owner-driven manual task. Both sheets must match the layout the webhook maps
(see `docs/LEAD_CAPTURE_API.md` for canonical column → field map). Columns
expected: `phone`, optional `name`, `father_name`, `phone_2`, `email`, `rank`,
`state`, `category`, `course`, `college` (→ preference_r1), `remarks`,
`owner_name`, `referrer_name`, `source`.

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
