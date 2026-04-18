#!/usr/bin/env bash
# Delete the phantom Expense #1 (amount 422) that was inserted when the
# pre-patch n8n workflow looped on its own bot reply. Safe to re-run —
# quietly no-ops if the row is already gone.
set -u
cd /home/ipuc/davya-crm || exit 1
PHP=/opt/alt/php84/usr/bin/php

cat > /tmp/cleanup-m12.php <<'PHP'
<?php
require __DIR__ . '/../bootstrap/app.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = App\Models\LedgerEntry::where('source_type', 'expense')
    ->where('source_id', 1)
    ->delete();
echo "ledger rows deleted: $rows\n";

$deleted = App\Models\Expense::destroy(1);
echo "expenses deleted: $deleted\n";
PHP

# Simpler: use `tinker --execute` via a file so shell quoting can't mangle it.
cat > /tmp/cleanup-m12.tinker <<'TINKER'
App\Models\LedgerEntry::where('source_type', 'expense')->where('source_id', 1)->delete();
echo "expenses deleted: " . App\Models\Expense::destroy(1) . "\n";
TINKER

$PHP artisan tinker < /tmp/cleanup-m12.tinker
rm -f /tmp/cleanup-m12.tinker /tmp/cleanup-m12.php
