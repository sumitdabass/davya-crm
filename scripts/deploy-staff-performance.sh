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

echo "=== clear caches (Laravel + Filament panel-component cache) ==="
$PHP artisan optimize:clear
# Filament 3 caches the discovered pages/resources/widgets per panel.
# If `php artisan filament:cache-components` was run earlier, the new
# StaffPerformance page won't appear until that cache is purged.
rm -rf bootstrap/cache/filament 2>/dev/null || true
echo "(filament panel cache directory removed if it existed)"

echo "=== verify the staff-performance route is registered ==="
$PHP artisan route:list 2>&1 | grep -iE "staff-performance" | head -3
echo "(should print at least one row; if empty, the page wasn't discovered)"

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
