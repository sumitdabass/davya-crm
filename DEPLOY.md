# Davya CRM — Deploy Recipe

Production: `https://davyas.ipu.co.in` (Hostinger shared, user `ipuc`).

## Prerequisites (one-time)

- SSH key loaded in ssh-agent: `~/Downloads/davyas-key` → connects as `ipuc@ipu.co.in`.
- On the server:
  - Clean app path: `/home/ipuc/davya-crm/` (the git clone).
  - Symlink: `/home/ipuc/home/ipuc/davya-crm` → `/home/ipuc/davya-crm` (needed because cPanel stored the docroot with a doubled `/home/ipuc/` prefix; the symlink lets LiteSpeed resolve its configured path to the clean app path).
  - GitHub deploy-key alias `github-davya-crm` in `~/.ssh/config` → uses `~/.ssh/davya-crm-deploy` (read-only deploy key on the `davya-crm` GitHub repo).
- Webserver PHP version: **8.5.x** (set via cPanel → MultiPHP Manager for `davyas.ipu.co.in`).
- CLI PHP for composer/artisan: **8.4+** (default shell PHP on Hostinger is 8.2 which is too old for the locked deps; use `/opt/alt/php84/usr/bin/php` or `/opt/alt/php85/usr/bin/php`).
- DB: `ipuc_ipuc_davyapp` (both database name and user name — cPanel doubled the prefix on creation; kept as-is). Grants = `ALL PRIVILEGES` on `ipuc_ipuc_davyapp.*`.

## First-time deploy (done 2026-04-17)

```sh
# Laptop → Hostinger
ssh ipuc@ipu.co.in

# On server
rm -rf /home/ipuc/home/ipuc/davya-crm   # cPanel's empty default
git clone git@github-davya-crm:sumitdabass/davya-crm.git /home/ipuc/davya-crm
ln -sfn /home/ipuc/davya-crm /home/ipuc/home/ipuc/davya-crm

cd /home/ipuc/davya-crm
PHP=/opt/alt/php84/usr/bin/php   # or php85 — either works

# Write .env (see .env template at bottom of this file)
$PHP artisan key:generate --force
$PHP artisan storage:link
$PHP artisan migrate --force
$PHP artisan db:seed --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
chmod 600 .env
```

## Recurring deploy (every milestone after)

```sh
ssh ipuc@ipu.co.in
cd /home/ipuc/davya-crm
PHP=/opt/alt/php84/usr/bin/php

git pull
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate --force
# Rank module lives on a separate connection — its migrations live in a separate path:
$PHP artisan migrate --database=ranks --path=database/migrations/ranks --force
$PHP artisan db:seed --class="Database\\Seeders\\Rank\\RankRoleSeeder" --force
$PHP artisan db:seed --class="Database\\Seeders\\Rank\\RankReferenceDataSeeder" --force
$PHP artisan db:seed --class="Database\\Seeders\\Rank\\SumitSuperAdminSeeder" --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

After first prod deploy of the Rank module, also import existing IPU B.Tech 2024+2026 cutoffs (one-time):

```sh
# Either copy the standalone rank-predictor SQLite up to the server first…
scp /Users/Sumit/davya-crm/rank/database/database.sqlite ipuc@ipu.co.in:/home/ipuc/rank-predictor.sqlite
# …then run the importer pointing at it:
$PHP artisan rank:import-from-predictor --sqlite=/home/ipuc/rank-predictor.sqlite
```

Tag the deploy on your laptop afterwards:

```sh
cd /Users/Sumit/davya-crm
git tag v<milestone>-<name>
git push --tags
```

## Rollback

Every milestone is tagged (`v0-scaffold`, `v1-users`, `v2-students`, `v3-payments`, `v4-rounds`, …). To roll back:

```sh
ssh ipuc@ipu.co.in
cd /home/ipuc/davya-crm
PHP=/opt/alt/php84/usr/bin/php

git fetch --tags
git checkout v<previous-tag>
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate:rollback --force   # only if the rolled-back tag undoes a migration
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

Warning: `migrate:rollback` is destructive to data. Only use when the previous tag's schema is intentionally different. If you just want code rollback without schema rollback, skip it.

## Environment template

`.env` on the server (600 perms, not in git). Keep an up-to-date sanitized copy in the password manager, NOT in the repo.

```
APP_NAME="Davya CRM"
APP_ENV=production
APP_KEY=<generated on server via artisan key:generate>
APP_DEBUG=false
APP_TIMEZONE=Asia/Kolkata
APP_URL=https://davyas.ipu.co.in

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ipuc_ipuc_davyapp
DB_USERNAME=ipuc_ipuc_davyapp
DB_PASSWORD="<password>"

# Rank Predictor module — separate DB on the same MySQL server.
RANKS_DB_HOST=127.0.0.1
RANKS_DB_PORT=3306
RANKS_DB_DATABASE=ipuc_rank
RANKS_DB_USERNAME=ipuc_rank
RANKS_DB_PASSWORD="<rank-db-password>"

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
CACHE_PREFIX=

MAIL_MAILER=log

# Fill these before payment-proof uploads to Drive will work (M4).
GOOGLE_DRIVE_CLIENT_ID=
GOOGLE_DRIVE_CLIENT_SECRET=
GOOGLE_DRIVE_REFRESH_TOKEN=
GOOGLE_DRIVE_FOLDER=

VITE_APP_NAME="${APP_NAME}"
```

## Known gotchas

- **composer.lock requires PHP 8.4+** — symfony 8.x is locked. Do not composer install with PHP 8.2 or older; use `/opt/alt/php84/usr/bin/php` or `php85`.
- **Webserver vs CLI PHP diverge** — webserver runs via cPanel MultiPHP (currently 8.5); CLI default is still 8.2. Always prefix CLI artisan/composer with the alt-php binary.
- **cPanel doubled docroot** — `/var/cpanel/userdata/ipuc/davyas.ipu.co.in` stores `documentroot: /home/ipuc/home/ipuc/davya-crm/public`. Don't "fix" this in cPanel without also updating the symlink; the current setup works because the symlink bridges the doubled path back to the clean `/home/ipuc/davya-crm/`.
- **`must_change_password` flow** — seeded users have random passwords; use `artisan tinker` to set a temp password for each new teammate, then share it; first-login flow forces them to set their own.
- **Activity log** — every `ipu_password` reveal is logged via M3's Show-Password action. Leave the activitylog table in place for audit.
