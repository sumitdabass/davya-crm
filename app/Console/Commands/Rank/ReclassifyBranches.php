<?php

namespace App\Console\Commands\Rank;

use App\Models\Rank\Branch;
use App\Services\Rank\BranchFamilies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReclassifyBranches extends Command
{
    protected $signature = 'rank:reclassify-branches {--apply : actually write the updates; without this flag prints a dry-run}';

    protected $description = 'Reclassify Rank Branches whose family="Other" using BranchFamilies::familyFor(). Dry-run by default.';

    /**
     * Classifier output → stored convention. Mirrors what already exists on the
     * ranks DB (CSE/IT/ECE/EEE/ME/AI-DS/Civil) and adds new families needed
     * for previously-unclassified branches (AI/ML, IIoT, Instrumentation,
     * Chemical) so the column never holds a mix of casing styles.
     */
    private const FAMILY_MAP = [
        'cs' => 'CSE',
        'it' => 'IT',
        'ece' => 'ECE',
        'eee' => 'EEE',
        'aiml' => 'AI/ML',
        'aids' => 'AI/DS',
        'iiot' => 'IIoT',
        'instrumentation' => 'Instrumentation',
        'mechanical' => 'ME',
        'civil_arch' => 'Civil',
        'chem_energy' => 'Chemical',
    ];

    public function handle(): int
    {
        $branches = Branch::where('family', 'Other')->get();

        if ($branches->isEmpty()) {
            $this->info('No branches with family="Other" — nothing to do.');

            return self::SUCCESS;
        }

        $changes = $branches->map(function ($b) {
            $code = BranchFamilies::familyFor($b->name);
            $proposed = $code !== null && isset(self::FAMILY_MAP[$code]) ? self::FAMILY_MAP[$code] : null;

            return [
                'id' => $b->id,
                'name' => $b->name,
                'from' => $b->family,
                'to' => $proposed,
            ];
        });

        $this->table(['id', 'from', 'to', 'name'], $changes->map(fn ($c) => [
            $c['id'],
            $c['from'],
            $c['to'] ?? '(no match — stays Other)',
            $c['name'],
        ])->all());

        $updatable = $changes->filter(fn ($c) => $c['to'] !== null);
        $keepers = $changes->count() - $updatable->count();

        $this->line('');
        $this->info("→ {$updatable->count()} rows would be reclassified, {$keepers} would stay as Other");

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('DRY-RUN — pass --apply to commit these changes.');

            return self::SUCCESS;
        }

        DB::connection('ranks')->transaction(function () use ($updatable) {
            foreach ($updatable as $c) {
                Branch::where('id', $c['id'])->update(['family' => $c['to']]);
            }
        });

        $this->line('');
        $this->info("✓ Updated {$updatable->count()} rows.");

        return self::SUCCESS;
    }
}
