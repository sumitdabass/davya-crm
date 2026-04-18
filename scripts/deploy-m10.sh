#!/usr/bin/env bash
# Phase 2 M10 production deploy runner — idempotent.
# Run from /home/ipuc/davya-crm on Hostinger.
set -u
PHP=/opt/alt/php84/usr/bin/php
cd /home/ipuc/davya-crm || exit 1

echo "=== git status ==="
git log -1 --oneline

echo "=== migrate ==="
$PHP artisan migrate --force

echo "=== db:seed UsersSeeder ==="
$PHP artisan db:seed --class=UsersSeeder --force

echo "=== config:clear + route:clear ==="
$PHP artisan config:clear
$PHP artisan route:clear

echo "=== FINANCE_CAPTURE_TOKEN ==="
if grep -q "^FINANCE_CAPTURE_TOKEN=" .env; then
  echo "already-present"
  grep "^FINANCE_CAPTURE_TOKEN=" .env
else
  T=$(openssl rand -hex 16)
  printf "\nFINANCE_CAPTURE_TOKEN=%s\n" "$T" >> .env
  echo "added"
  grep "^FINANCE_CAPTURE_TOKEN=" .env
fi

echo "=== Nikhil split_pct sanity ==="
$PHP artisan tinker --execute='echo \App\Models\User::where("email","nikhil@davya.local")->value("split_pct"), PHP_EOL;'

echo "=== DEPLOY OK ==="
