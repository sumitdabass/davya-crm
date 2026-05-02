#!/usr/bin/env bash
# Staff Performance Scoring — production deploy runner.
# Run from /home/ipuc/davya-crm on Hostinger SSH session.
set -u

PHP=/opt/alt/php84/usr/bin/php
cd /home/ipuc/davya-crm || { echo "ERR: chdir failed"; exit 1; }

echo "=== pre-pull git state ==="
git log -1 --oneline
git status --short

echo "=== git pull origin main ==="
git pull origin main

echo "=== post-pull head ==="
git log -1 --oneline

echo "=== migrate --force ==="
# Adds: students.rank_prob_first_choice + user_performance_scores table.
# Both reversible.
$PHP artisan migrate --force

echo "=== clear caches ==="
$PHP artisan config:clear
$PHP artisan route:clear
$PHP artisan view:clear

echo "=== verify scheduler picked up new entry ==="
$PHP artisan schedule:list | grep -E "performance:recalculate|backup:database"

echo "=== backfill rank probabilities for existing 533 students ==="
# Idempotent — safe to re-run. Only writes the cache, no other side effects.
# If the ranks DB connection isn't configured on this app, every student
# will get rank_prob_first_choice = NULL (observer is hardened to log + skip).
$PHP artisan performance:backfill-rank-probabilities

echo "=== first manual recalc (so /admin/staff-performance has data tonight) ==="
$PHP artisan performance:recalculate

echo "=== smoke check: row count on user_performance_scores ==="
$PHP artisan tinker --execute='echo "rows=" . \App\Models\UserPerformanceScore::count() . "\n";'

echo "=== DONE ==="
echo "Visit https://davyas.ipu.co.in/admin/staff-performance to verify (admin role only)."
echo "Nightly recalc runs at 02:30 IST (after 02:00 backup)."
