# M6 Finish Runbook — 15-min Single Sit-down

**Written:** 2026-04-22
**For:** Sumit, solo, when you have 15 min with hands on a laptop.
**Purpose:** close the last three items on the M6 done-criteria list.

Open these tabs BEFORE starting:

1. n8n UI: https://n8n.srv1117424.hstgr.cloud
2. Central Rejections sheet: https://docs.google.com/spreadsheets/d/10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs/edit
3. Prod admin: https://davyas.ipu.co.in/admin/students/create

Have your Hostinger SSH terminal ready (optional, only for cleanup at the end).

---

## Step 0 — Central sheet tab check (30 sec)

Open the central Rejections sheet (tab 2 above). If the visible tab is named `Sheet1`, double-click it and rename to `Rejections`. If it's already `Rejections`, move on.

**Why:** the three workflow nodes reference `sheetName: "Rejections"` by name. Mismatch = silent append failure.

---

## Step 1 — Reconnect the broken Google Sheets OAuth cred (2–3 min)

The `googleSheetsOAuth2Api` credential has been broken since 2026-04-17. All three lead workflows poll silently with zero output until this is fixed. Per `reference_n8n_sheets_trigger_broken.md`.

1. In n8n UI: **Credentials** (left sidebar).
2. Find the **`googleSheetsOAuth2Api`** credential bound to the admission@ipu.co.in account (the one used by the Append-to-Rejections nodes).
3. Click it → **Reconnect** / **Sign in with Google** → approve.
4. Save. Confirm green "Connected" state.
5. **Do NOT touch** the Sheets Trigger credential `A8Grx7J6ZfarJVR1` — that one is working.

**Verification:** once this step is done, proceed. The next step triggers real polls, so the cred must be healthy first.

---

## Step 2 — Import the Sonam workflow (3 min)

The Sonam workflow isn't in n8n yet; the JSON is ready on disk.

1. In n8n UI: **Workflows → Import from file**.
2. Upload: `/Users/Sumit/davya-crm/docs/n8n-lead-sonam-workflow.json`.
3. Open the imported workflow (it's named `lead-sonam-sheet`).
4. Click the **Google Sheets Trigger** node → Credentials dropdown → select **`A8Grx7J6ZfarJVR1`** (the one that already works).
5. Click the **Append to central Rejections sheet** node → Credentials dropdown → select the `googleSheetsOAuth2Api` cred you just reconnected in Step 1.
6. Click the **POST /api/leads** node → Credentials dropdown → select the `httpHeaderAuth` credential carrying `X-Lead-Token` (same one Nikhil/Sumit-website workflows use).
7. **Save** (top-right).
8. Toggle **Active** switch (top-right). Confirm it flips to green.

**No edits to Sonam's sheet.** The workflow maps her narrow column set (`Date | Ph no | Course | Rank | D/OD | enquiry | connected to.`) as-is.

---

## Step 3 — Smoke test lead capture (3 min)

In the central Rejections sheet, keep it open — you'll watch rows appear (or not) here.

**Test A — happy path (Sonam):**
1. Open Sonam's sheet: https://docs.google.com/spreadsheets/d/11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4/edit
2. Add a new row with only two fields: `Ph no = 9100000901`, `Course = BCA`. Leave the rest blank.
3. Wait ~60 seconds (n8n poll interval).
4. Visit https://davyas.ipu.co.in/admin/students — search for `9100000901`.
5. Confirm: 1 student exists, owner=Sonam, course=BCA.

**Test B — rejection path:**
1. On Sonam's sheet, add another row: leave `Ph no` blank, `Course = BBA`.
2. Wait ~60 seconds.
3. Check the central Rejections sheet.
4. Confirm: a row appears with `Owner = Sonam`, `Error` mentioning missing phone.

**Test C — Nikhil + Sumit workflows still work (smoke only):**
1. In n8n: **Executions** view. Filter to `lead-nikhil-sheet` and `lead-sumit-website-sheet`.
2. Confirm each shows recent successful executions (not all red-failed). If they're red, the OAuth reconnect in Step 1 didn't take — re-do it.

If any of A, B, C fail, stop and debug before Step 4.

---

## Step 4 — Prod smoke: inline first-payment block (3 min)

1. Open https://davyas.ipu.co.in/admin/students/create
2. Fill:
   - **Identity:** name `Smoke Test 20260422`, phone `9100000302`, course `BCA`
   - **Stage:** `Meeting Scheduled`
   - **Preference:** any college
   - Expand **"First payment (optional)"**:
     - Type: `advance`
     - Amount: `100`
     - Received at: now
3. Click **Create**.
4. Confirm on the student detail page:
   - Student row saves cleanly (no validation errors).
   - Payments tab shows 1 payment, `recorded_by_user_id` = your user.
   - Activity log shows both creation + payment entries.
5. **Clean up:** delete this test student (or change phone to `9100000301` which the test-debris cleanup script auto-skips).

---

## Step 5 — Cleanup + memory update (2 min)

**Old SSH key safety delete** (only if 7+ days have passed with no incidents — skip for now if before 2026-04-29):

```sh
# Local:
shred -u ~/.ssh/davyas-active-revoked-20260422

# Server (via SSH):
rm ~/.ssh/authorized_keys.bak-20260422
```

**Optional obsolete keys:**

```sh
rm ~/Downloads/davyas-key
rm ~/.ssh/davyas-deploy
```

**Tell Claude to update memory** — after smoke tests pass, start a new session with:

> "M6 finish runbook complete — all 3 workflows polling, central rejections writing, prod first-payment smoke green. Update memory."

That triggers a clean pass: remove the "OAuth reconnect pending" note, mark M6 fully shipped.

---

## Done-criteria — verify all five

- [ ] Central sheet tab is named `Rejections` (Step 0)
- [ ] `googleSheetsOAuth2Api` reconnected, green (Step 1)
- [ ] `lead-sonam-sheet` imported + active in n8n (Step 2)
- [ ] Smoke A, B, C all pass (Step 3)
- [ ] Prod first-payment smoke passes (Step 4)

If all five tick, M6 is done. Update memory (Step 5) and move to planning the Today tab implementation post-session.

---

## If something breaks

- **Step 1 cred reconnect fails / Google refuses consent:** the admission@ipu.co.in Google account may have 2FA blocking programmatic access. Use a recovery code or a browser already signed in. Don't create a new cred — bind-point on all three workflows references the existing cred id.
- **Step 2 import fails with "Unknown node type":** n8n version drift. Check the JSON's `typeVersion` fields (should be `3` for triggers, `4` for Sheets append). Bump if node UI complains.
- **Step 3 Test A shows no student but n8n execution was green:** check the HTTP response body in the POST node — likely a 200 with `status=rejected` because phone dedup caught a prior test row. Try a different phone.
- **Step 4 create fails on "First payment" validation:** verify `type` is `advance` (not `Advance`; enum is lowercase).

Anything else — paste the error into a fresh Claude session and reference this runbook path.
