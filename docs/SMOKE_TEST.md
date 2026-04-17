# Davya CRM — Production Smoke Test Checklist

Run this whole checklist after every deploy-to-prod that changes behaviour. Each step should take ~15 s. Total ≈10 min. If any check fails, roll back to the previous tag (`git checkout v<previous> && redeploy`) before debugging.

Prereqs:
- You're logged in as `sumit@davya.local` or your own head-level account.
- Use a **different browser profile** from your daily one so you start with a clean session.
- Open the browser devtools Console + Network tab — a single red error here is a smoke-test failure.

---

## 0. Preconditions

- [ ] `https://davyas.ipu.co.in/` → 302 to `/admin` (never shows Laravel welcome).
- [ ] `https://davyas.ipu.co.in/robots.txt` returns `User-agent: *\nDisallow: /`.
- [ ] Response headers on any page include `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, ...`. (Devtools → Network → pick any request → Response Headers.)

## 1. Auth

- [ ] Visit `/admin/login`, submit with wrong password 3 times — 3rd attempt starts showing a "throttled" error (Filament's built-in 5/min).
- [ ] Submit with correct password — lands on Dashboard.
- [ ] Log in as the same user **in a second browser**. When the second session lands on Dashboard, refresh the first browser → should be booted to `/admin/login` with session invalid. (Single-active-session enforcement.)
- [ ] Log out. Visit `/admin` — redirected to login.

## 2. Dashboard widgets

- [ ] Pipeline Summary tiles render (may say "No students yet" if DB is thin).
- [ ] Seat Fee Pending / Re-entry Candidates / Stuck Leads tables render headers even when empty.
- [ ] Adding a Student at stage `Lead Captured` then waiting ~14 days is not testable here, but the SQL scope is covered by feature tests.

## 3. Students

- [ ] `/admin/students` list loads, shows team-visible students only.
- [ ] **Create** a new student with minimum fields (phone, name, owner, referrer, lead_source). Save → land on edit page.
- [ ] **Notes panel** (below the form): click **Add note** → enter text → save. Note appears timestamped with your name.
- [ ] Re-open the student as a different user (another head) — note is visible to them too, they can also add one.
- [ ] **Show Password** action on the `ipu_password` field works and the reveal is logged in `activity_log`.

## 4. Payments

- [ ] Open a student → **Payments** relation manager → Add a payment (type=advance, amount=10000, mode=cash, received_at=now). Save.
- [ ] Table shows row with Amount ₹10,000.00 and Recorded-by = you.
- [ ] **Proof upload** (only if Drive env vars are set on prod — skip otherwise). Pick any file → save. Click "Open proof" → file downloads from Drive.

## 5. Round history + stage transitions

- [ ] Add a Round History row: `Online_R1`, outcome=`Allotted — Fee Pending`.
- [ ] Change the student's stage to `Closed` **without** setting `close_reason` → Save → **hard error** blocks the save.
- [ ] Set `close_reason` = "Not Interested" and save — succeeds.
- [ ] Move stage back from `Closed` to `Meeting Scheduled` without `re_entry_reason` → **hard error**.
- [ ] Fill `re_entry_reason` and save — succeeds.

## 6. Kanban

- [ ] `/admin/kanban` loads; 10 columns with aggregates.
- [ ] Drag a student card from `Lead Captured` → `Meeting Scheduled`. Success toast fires; refresh confirms stage changed.
- [ ] Drag a student to `Closed` without a close_reason → move **snaps back**, red toast explains why.
- [ ] Click a card (without dragging) → opens that student's edit page.

## 7. Lead capture API

- [ ] `curl -sI https://davyas.ipu.co.in/api/leads` → 405 (POST-only route exists).
- [ ] `curl -X POST https://davyas.ipu.co.in/api/leads -H 'Content-Type: application/json' -d '{}'` → 401 `{"error":"unauthorized"}`.
- [ ] `curl -X POST https://davyas.ipu.co.in/api/leads -H 'Content-Type: application/json' -H "X-Lead-Token: $LEAD_CAPTURE_TOKEN" -d '{"phone":"9199911111","name":"Smoke","referrer_name":"Nisha"}'` → 201 JSON with id.
- [ ] Smoke-test row appears in `/admin/students` — delete it afterwards.

## 8. Backup

- [ ] SSH: `cd /home/ipuc/davya-crm && /opt/alt/php84/usr/bin/php artisan backup:database --skip-drive` — produces a `.sql.gz` in `storage/app/backups/`, exit 0.
- [ ] `ls -lh storage/app/backups/` — file is several KB+.

## 9. No-index

- [ ] Open any /admin page in an **incognito** window — response header `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` present.
- [ ] Google "site:davyas.ipu.co.in" once a week for a month after going live — should return 0 results. If not, check robots.txt + headers still serving correctly.

---

## Rollback

If anything above fails that wasn't failing before:

```sh
ssh ipuc@ipu.co.in
cd /home/ipuc/davya-crm
git fetch --tags
git log --oneline --decorate -10   # find the last known-good tag (v<N>-<name>)
git checkout v<N>-<name>
/opt/alt/php84/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
/opt/alt/php84/usr/bin/php artisan migrate:rollback --force   # only if schema rollback is intentional
/opt/alt/php84/usr/bin/php artisan config:cache && ... route:cache && ... view:cache
```

File a note on what failed so it's fixed before the next deploy.
