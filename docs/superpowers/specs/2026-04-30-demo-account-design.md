# Davyas CRM Demo — design

**Project name:** Davyas CRM Demo
**Date:** 2026-04-30
**Status:** Approved by Sumit during brainstorming session 2026-04-30
**Supersedes:** `docs/sessions/2026-04-22-demo-account-prebrainstorm.md` (pre-brainstorm input packet)

## Problem

Sumit needs a demo environment for showing the Davyas CRM to prospects. Two distinct entry needs:

1. **Public funnel** — a self-serve "try the demo" experience landed by prospects from cold outreach or the marketing site, gated behind email verification so phone+email is captured back into the real CRM as a hot lead.
2. **Team-issued direct links** — an internal tool for Sumit / Sonam / Nikhil to generate a tokenized URL after a sales call and send it to a known prospect with no signup friction. Each link is short-lived (60 min after first click) and bounded (≤ 5 logins).

Prospects need real time — multi-day evaluation, role-by-role exploration. The demo must feel like the real product (full feature parity except Slack), but no demo activity may touch real data, real customers, or real outbound integrations (n8n, Slack, real emails to live recipients).

The pre-brainstorm note enumerated five code-level landmines (ActivityLog leak, Finance Assistant LLM leak, encrypted-IPU-password reveal, dedup collision, sequential student IDs). All five evaporate under physical separation: a separate Hostinger account, separate cPanel, separate MySQL DB. The DB boundary IS the isolation; no application-level `is_demo_only` flag is needed.

## Goals

- A demo at `https://demo.ipu.co.in` that is, from a prospect's perspective, **bit-for-bit identical** to the Davyas CRM in design and function. Every Filament page, every CSS rule, every Livewire component, every kanban card layout, every dashboard tile is the same code as prod. The demo is not a stripped-down or differently-styled "preview" — it is the product, with seeded fake data.
- Two parallel entry flows (public-funnel email-verified signup + internal HMAC-token invite).
- Daily auto-reset to a clean seeded state at 03:30 IST.
- No outbound side-effects from demo writes (Slack, n8n, real student-facing emails) other than the OTP transactional email needed by the public funnel itself.
- Lead capture: every public-funnel email verification posts the prospect's phone+email to prod's `/api/leads` so they enter the real lead pipeline as `lead_source='Demo Signup'`.

## Non-goals

- Multi-tenant refactor of the prod codebase. The demo runs the same code unchanged; only `.env` differs.
- Per-prospect isolated demo datasets. All demo users share one seeded dataset; a prospect's drag of a kanban card is visible to any other prospect signed in concurrently. Acceptable until proven otherwise — current sales volume doesn't produce concurrent prospects often, and the 03:30 IST reset bounds the mess.
- A "switch role" UI inside the demo. Prospects who want to see a different role's view log out and click the next role's invite. Future polish.
- Read-only mirroring of prod data. Demo data is wholly fake/seeded.
- SMS verification of phone. Phone is captured-not-verified (no SMS gateway provisioned).

## Architecture

**Sibling-clone topology with account-level isolation:**

```
                  ┌─────────────────────────────────────┐
                  │  Existing Hostinger account (prod)  │
                  │  cPanel "ipuc"                      │
                  │  davyas.ipu.co.in                   │
                  │  ~/davya-crm  (git pull main)       │
                  │  DB: ipuc_davyacrm                  │
                  └────────────────┬────────────────────┘
                                   │
                                   │ HTTPS POST /api/leads
                                   │ (X-Lead-Token header)
                                   │ when public-funnel verifies
                                   ▲
                                   │
                  ┌────────────────┴────────────────────┐
                  │  NEW Hostinger account (demo)        │
                  │  Separate cPanel, separate billing  │
                  │  demo.ipu.co.in                     │
                  │  ~/davya-demo  (git pull main)      │
                  │  DB: ipuc_davyademo                 │
                  │  .env: APP_DEMO_MODE=true           │
                  └─────────────────────────────────────┘
```

- Both cPanels clone the same `davya-crm` git repo. Demo can lag prod for safe pre-promotion testing — when a prod ship looks good, SSH into demo and `git pull` to promote.
- DNS for `demo.ipu.co.in` (zone is on the prod Hostinger account) is updated to A-record at the demo Hostinger account's IP.
- SSL via Let's Encrypt auto-provisioned by demo cPanel.
- New MySQL user on demo cPanel scoped exclusively to `ipuc_davyademo`. Cross-DB pivot is physically impossible because the demo MySQL server is on a separate Hostinger account.
- Demo `.env` carries the `APP_DEMO_MODE=true` flag; code reads `config('app.demo_mode')` to gate outbound side-effects.

## Two entry flows

### Public funnel

```
[demo.ipu.co.in landing page]
    │
    │ Plain Blade view, full-bleed marketing-style
    │ ("Try the Davyas CRM — see how lead pipelines, finance,
    │  and admissions tracking work together")
    │ Form: name + phone + email + Cloudflare Turnstile widget
    │
    ▼
POST /demo/signup
    │
    │ Validates Turnstile token server-side.
    │ Rate limits: 3 OTP/email/hr, 10 OTP/IP/hr.
    │ Creates demo_signups row with hashed magic_token, 15-min expiry.
    │ Sends DemoOtpMail with one-click verification link.
    │
    ▼
[email arrives, prospect clicks link]
    │
    ▼
GET /demo/verify/{token}
    │
    │ Validates token (single-use, not expired, not consumed).
    │ Marks email_verified_at.
    │ Auth::loginUsingId(<demo admin user id>) — single shared role; we'll
    │   re-evaluate role-picker UX as a v2 polish if prospect feedback asks.
    │ Fires async job: post phone+email to https://davyas.ipu.co.in/api/leads
    │   with X-Lead-Token header, lead_source='Demo Signup'.
    │   Failure logged, doesn't block UX.
    │
    ▼
Redirect /admin → demo dashboard with "Demo environment — refreshes daily at 03:30 IST" banner
```

### Team-issued invite

```
[prod /admin/demo-invites] (admin/head only via Spatie role policy)
    │
    │ Filament page with form: optional label, optional prospect_name.
    │ "Generate" → server constructs HMAC-signed token:
    │   payload = base64( JSON({ "iat": <epoch>, "lbl": <label>, "v": 1 }) )
    │   signature = HMAC-SHA256(payload, DEMO_INVITE_SECRET)
    │   token = "{payload}.{signature}"
    │ Server inserts demo_invite_log row (audit trail) and returns URL.
    │
    ▼
[admin copies https://demo.ipu.co.in/i/<token>, sends via WhatsApp/email]
    │
    ▼
GET /i/<token> on demo
    │
    │ Validates HMAC against DEMO_INVITE_SECRET (must match prod's secret —
    │   single shared secret in both .env files).
    │ Computes token_hash = sha256(token).
    │ Looks up demo_invite_uses by token_hash:
    │   - no row: INSERT row, first_used_at=now, uses_count=1, expires_at=now+60min.
    │             Auth::loginUsingId(<demo admin user id>). Redirect /admin.
    │   - row exists:
    │       if now > expires_at OR uses_count >= 5 → 410 Gone with friendly page.
    │       else → uses_count += 1, Auth::loginUsingId(...), redirect /admin.
    │
    ▼
Demo session, banner visible
```

**A "fresh login" is a new Auth session creation.** Page reloads with an active session do NOT consume a use. Logout-then-click-link does. Same browser, same tab, no logout = single use no matter how many reloads.

## Tables added

### Demo DB

```sql
CREATE TABLE demo_signups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    email VARCHAR(190) NOT NULL,
    magic_token_hash CHAR(64) NOT NULL,         -- sha256 of plaintext token
    token_expires_at DATETIME NOT NULL,
    email_verified_at DATETIME NULL,
    lead_synced_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_demo_signups_email (email),
    INDEX idx_demo_signups_token_hash (magic_token_hash)
);

CREATE TABLE demo_invite_uses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,        -- sha256 of HMAC-signed token
    first_used_at DATETIME NOT NULL,
    uses_count INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
```

### Prod DB

```sql
CREATE TABLE demo_invite_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issued_by_user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NULL,                    -- "ACME Corp", "Aug 2026 conf"
    prospect_name VARCHAR(120) NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_demo_invite_log_issued_by (issued_by_user_id),
    FOREIGN KEY (issued_by_user_id) REFERENCES users(id)
);
```

## Outbound suppression matrix

All gated by `config('app.demo_mode') === true`:

| Subsystem | Behavior in demo |
|---|---|
| Slack notifications | Skip — early-return in any `Notification::send(..., new SlackChannel)` site, also no `SLACK_*` env keys present |
| n8n outbound webhooks | Skip — early-return in any `Log::info('n8n: ...')` / outbound-HTTP site that fires on lead/payment/stage events |
| Real student-facing emails (forgotPassword, future welcomes) | `MAIL_MAILER=log` for the default mailer; `DemoOtpMail` is dispatched with explicit `Mail::mailer('smtp')->send(...)` so the OTP itself reaches the prospect |
| Drive uploads | Work normally, but `GOOGLE_DRIVE_FOLDER_ID` in demo `.env` points at a separate "demo" folder — uploads never pollute prod's folder |
| Gemini API | Works normally with a separate `GEMINI_API_KEY` whose Google AI Studio project has a $5/day spend cap — abuse can't burn prod's budget |
| Forgot-password route | Disabled (route gate: `if (config('app.demo_mode')) abort(404)`) so a malicious prospect can't reset the demo admin password and lock Sumit out |
| Top-bar (visible to all logged-in demo users) | Render banner: *"Demo environment — data refreshes daily at 03:30 IST. Changes will not persist."* |

## Nightly reset

**Artisan command:** `php artisan demo:reset`

Runs in this order:
1. `Artisan::call('migrate:fresh', ['--force' => true])` — drops all tables, re-runs all migrations against the demo DB. Picks up any prod schema changes naturally.
2. `Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true])`.
3. Truncate `demo_invite_uses` (already gone via `migrate:fresh`, this is defensive).
4. Append a line to `storage/logs/demo-reset.log` with start/end timestamps and seed counts.

**Cron entry on demo cPanel:**
```
0 22 * * * cd /home/<demo-user>/davya-demo && /opt/alt/php84/usr/bin/php artisan demo:reset >> storage/logs/demo-reset-cron.log 2>&1
```

22:00 UTC = 03:30 IST (after Hostinger's typical backup window).

**Why `migrate:fresh` instead of surgical truncate:** schema migrations land on prod, then on demo at the next demo `git pull`. `migrate:fresh` picks up new migrations automatically, so a schema change shipped to prod doesn't require a separate "migrate demo" step — the next 03:30 reset handles it. Surgical truncate would need maintenance whenever the table list changes.

## Seed dataset (`Database\Seeders\DemoSeeder`)

A single class that calls a sequence of sub-seeders. Counts are starting points; tune during implementation if the demo feels under- or over-populated:

| Entity | Count | Notes |
|---|---|---|
| Users | 5 | 1 admin (`admin@davya-demo.local`), 2 heads (`priya.head@`, `arjun.head@`), 2 counsellors (`riya.counsellor@`, `karan.counsellor@`, one under each head), 1 freelancer (`vikram.freelancer@`). All passwords set to a fixed hash; not used for login (Auth::loginUsingId on shared admin from public/team flows). |
| Pipelines + stages | 1 default pipeline, 13 stages | Mirror the prod stage seed list at the time of writing. |
| Students | 30 | Spread across stages. Phones in `9000000001`–`9000000030`. Indian names (Aarav, Diya, Ishaan, etc.). Each owned by a different counsellor or head; some referrals. |
| Payments | 50 | Mix of advance / full / seat-fee against students with deal_amount set. |
| Expenses | 10 | Categories: Travel, Marketing, Office, Other. |
| Investments | 5 | Realistic amounts (₹50k–₹5L). |
| Meetings | 6 | 4 today, 2 in past week. |
| Round history | 5 | A handful of "Allotted — Fee Pending" rows so the Round-history features aren't empty. |
| Duplicate flags | 3 | So the duplicate-review page is non-empty. |
| Custom student-fields | 2 | One dropdown ("Hostel preference"), one date ("Document submission deadline"). Demonstrates Phase A. |
| Activity log | (none seeded) | Fills naturally as prospects click. |

Realistic phone uniqueness preserved within the seed so `LeadIntakeService` dedup logic doesn't fire false positives on seed.

## Suppression implementation pattern

Every suppression site reads `config('app.demo_mode')`. The `config/app.php` entry:

```php
'demo_mode' => env('APP_DEMO_MODE', false),
```

Demo `.env`: `APP_DEMO_MODE=true`. Prod `.env`: not set, so `false`. No code branches hardcoded — toggling the env var (with `php artisan config:cache`) flips the behavior cleanly. This is what lets local dev safely run with `APP_DEMO_MODE=true` for testing.

## Rate limiting

- `POST /demo/signup` (Turnstile + email-OTP) — Laravel `RateLimiter::for('demo-signup', ...)` keyed on `email + IP`. 3/email/hr, 10/IP/hr.
- `GET /i/<token>` — Laravel throttle middleware `throttle:60,1` (60 hits/min/IP). Prevents scripted abuse of a leaked link.
- `GET /demo/verify/<token>` — same throttle.
- `POST /api/finance-assistant` (Gemini) — `throttle:20,60` (20 calls/hr/IP). Belt-and-suspenders against quota burn.

## Error handling

| Situation | Response |
|---|---|
| Public form: invalid Turnstile | 422 with field error "Please complete the security check." |
| Public form: rate limit hit | 429 with retry-after, generic "Too many signups from this email/IP. Try again later." |
| Email-verify: token expired | Friendly page: "This verification link has expired. Please sign up again." |
| Email-verify: token already consumed | Friendly page: "This link has already been used. Please sign up again." |
| Team-invite: HMAC invalid | 404 (don't leak that it's a token route) |
| Team-invite: expired or uses exhausted | 410 Gone with friendly page: "This demo link has expired. Ask your contact to issue a fresh one." |
| Cross-post to prod `/api/leads` failure | Logged at WARNING; UX continues. The prospect's eval isn't blocked by a downstream sync failure. Manual reconciliation possible from `demo_signups.lead_synced_at IS NULL`. |
| Demo `migrate:fresh` failure during cron | Cron output captured to `demo-reset-cron.log`; log includes traceback. Demo continues with whatever state survived. Next manual run by Sumit recovers. |

## Testing

**Unit (Pest):**
- HMAC token signing + verification roundtrip
- HMAC token tamper detection (bit-flip → invalid)
- `demo_invite_uses` increment logic — first use creates row; subsequent within window increment; expired returns 410-flag; exhausted returns 410-flag
- `OtpRateLimiter` exhausts at 3/email/hr and 10/IP/hr
- `config('app.demo_mode')` gates: Slack notification skipped, n8n outbound skipped, forgot-password route 404'd

**Feature:**
- `public_funnel_full_flow` — POST /demo/signup with valid Turnstile (mocked) → assert mail dispatched → simulate clicking verify link → assert session active → assert async job dispatched to post to prod `/api/leads` (mocked HTTP)
- `team_invite_full_flow` — generate token in prod (mocked DEMO_INVITE_SECRET) → hit demo /i/<token> 5 times across 5 sessions → assert 6th returns 410 → assert any hit after 60min returns 410
- `demo_reset_command` — seed some user-created junk → run `demo:reset` → assert tables truncated and reseeded to expected counts

**Manual smoke checklist** (post-deploy, in `docs/sessions/2026-04-30-demo-account-smoke.md` to be written during execution):
- DNS resolves `demo.ipu.co.in` to new account IP
- HTTPS cert valid
- Public funnel: form → email → click → land in admin
- Team invite: prod admin creates → URL works on demo → exhausts at 5 uses
- `migrate:fresh --seed` runs clean
- Cron entry installed; manual run shows clean output
- Slack/n8n suppression: trigger a stage move, confirm no outbound
- Drive upload: payment proof on demo lands in demo Drive folder, not prod's
- Gemini Q&A: works with demo key; spend cap configured
- Forgot-password route returns 404 on demo
- Banner visible on all admin pages
- Cross-post to prod `/api/leads` fires on email verification (verify lead lands in prod with `lead_source='Demo Signup'`)

## Deploy / ops sequence

1. Provision new Hostinger account + cPanel for `demo.ipu.co.in` (manual; Sumit owns this).
2. Update DNS A record for `demo.ipu.co.in` to new account's IP. Wait for propagation (≤ 1 hr typically).
3. cPanel: enable Let's Encrypt SSL.
4. cPanel: create MySQL DB `ipuc_davyademo` + user `ipuc_demo` with full grants on that DB only. Verify via `SHOW GRANTS FOR 'ipuc_demo'@'localhost';`.
5. SSH: deploy SSH key to new account, clone `davya-crm` repo to `~/davya-demo`. Branch: `main`.
6. Copy `.env.example` → `.env`, fill in demo-specific values:
   - `APP_URL=https://demo.ipu.co.in`
   - `APP_DEMO_MODE=true`
   - `DB_DATABASE=ipuc_davyademo`, `DB_USERNAME=ipuc_demo`, `DB_PASSWORD=<gen>`
   - `MAIL_MAILER=log` (default; OTP mailable will explicitly use `smtp`)
   - `MAIL_SMTP_*` populated for OTP transport
   - `GOOGLE_DRIVE_FOLDER_ID=<demo folder id>`
   - `GEMINI_API_KEY=<demo key with $5/day cap>`
   - `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`
   - `LEAD_CAPTURE_TARGET_URL=https://davyas.ipu.co.in/api/leads`
   - `LEAD_CAPTURE_TOKEN=<existing X-Lead-Token>`
   - `DEMO_INVITE_SECRET=<gen — same value as prod's>`
7. `composer install --no-dev`, `php artisan key:generate`, `php artisan storage:link`, `php artisan migrate:fresh --seed --force`.
8. cPanel cron: install nightly `demo:reset` entry.
9. Smoke checklist (see above).
10. Deploy `/admin/demo-invites` Filament page + `DEMO_INVITE_SECRET` to prod's `.env`. `php artisan optimize:clear`.

## Rollback

- Pre-deploy git tag on prod: `pre-demo-account-20260430`.
- Demo issues are auto-isolated — prod is unaffected.
- If `/admin/demo-invites` page or `DEMO_INVITE_SECRET` causes prod issues: `git revert <merge>`, `php artisan optimize:clear`. Demo continues to work but no new invites issuable until prod recovers.
- If demo cPanel goes sideways: prod is fine. Take demo offline by removing the DNS A record; restore later.

## Effort

| Stream | Days |
|---|---|
| Subdomain + separate-DB scaffolding + migrations + seeder | 1.5 |
| Public funnel (Turnstile + form + OTP mailable + verify route + cross-post job) | 2.0 |
| Team-issued invite UI in prod + token model + demo /i/<token> route | 1.5 |
| Suppression flag + Slack/n8n/email gates + Drive separate folder + banner | 0.5 |
| Tests (unit + feature) + smoke runbook | 1.0 |
| **Subtotal — dev** | **6.5** |
| Hostinger ops (new account, DNS, SSL, MySQL user, Drive folder, Turnstile keys, demo Gemini key, $5/day cap config) | 1.0 |
| **Total** | **~7.5 days** |

## Open questions deferred to implementation

- **Banner copy exact wording:** finalize during implementation. Working text: *"Demo environment — data refreshes daily at 03:30 IST. Changes will not persist."*
- **`demo:reset` exact seed counts:** seed counts above are a starting point; refine after first demo session if it feels under- or over-populated.
- **Turnstile vs hCaptcha:** Turnstile is the default per this spec (free, faster, less intrusive). If site-key provisioning hits friction, hCaptcha is a drop-in fallback.
- **Whether to expose a "switch role" UI:** v2 polish. v1 has all prospects landing as the demo admin. If prospect feedback says "I want to see this as a counsellor," add role-picker.
