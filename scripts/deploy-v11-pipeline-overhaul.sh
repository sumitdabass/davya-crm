#!/usr/bin/env bash
# v11 Student Pipeline Overhaul — production deploy runner.
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

echo "=== pre-migration safety check: orphan final_college values ==="
$PHP artisan tinker --execute='
$at = \App\Models\Student::whereNotNull("final_college")->whereDoesntHave("roundHistory", fn($q) => $q->whereNotNull("allotted_college"))->get(["id","name","final_college"]);
echo "orphan_final_college_count=" . $at->count() . "\n";
if ($at->count() > 0) { echo $at->toJson(JSON_PRETTY_PRINT) . "\n"; }
'
echo ""
echo "If orphan_final_college_count > 0 ABOVE, the drop_final_allotment migration will lose data."
echo "Abort now (Ctrl+C) and log those rows to notes before re-running."
echo "Press Enter to continue (all zero = safe), Ctrl+C to abort..."
read _

echo "=== migrate --force ==="
$PHP artisan migrate --force

echo "=== check for decrypt failures (ipu_login_code) ==="
tail -n 200 storage/logs/laravel.log 2>/dev/null | grep -c 'ipu_login_code decrypt failed' || true
echo "^ count of decrypt failures in last 200 log lines. Inspect manually if > 0."

echo "=== clear caches ==="
$PHP artisan config:clear
$PHP artisan route:clear
$PHP artisan view:clear

echo "=== post-migrate sanity: legacy stage counts should all be 0 ==="
$PHP artisan tinker --execute='
foreach (["Onboarded","University Registration","Full Payment Received","Admission Confirmed"] as $s) {
    $c = \App\Models\Student::where("stage", $s)->count();
    echo str_pad($s, 30) . " -> " . $c . "\n";
}
echo "\nNew stage distribution:\n";
foreach (\App\Enums\PipelineStage::values() as $s) {
    $c = \App\Models\Student::where("stage", $s)->count();
    echo str_pad($s, 20) . " -> " . $c . "\n";
}
'

echo "=== done ==="
echo "Next: open https://davyas.ipu.co.in/admin/students in a browser and smoke-test:"
echo "  1. Tabs render on the edit page (Identity / Source & Stage / Academic / Deal / Counselling / History / Closure)"
echo "  2. Owner dropdown shows only Sumit/Sonam/Nikhil"
echo "  3. Referrer name is a text input"
echo "  4. Counselling → IPU login code is plain text (no masking)"
echo "  5. Kanban: drag a lead to Meeting Scheduled without a meeting → yellow banner, student moves"
echo "  6. Kanban: drag a lead to Closed without close_reason → red banner, snaps back"
