<?php

namespace Database\Seeders\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Asset;
use Illuminate\Database\Seeder;

class SumitSpreadsheetSeeder extends Seeder
{
    public function run(): void
    {
        $c = Company::firstOrCreate(['slug' => 'davyas-fy25'], [
            'name' => 'Davyas (Spreadsheet)',
            'slug' => 'davyas-fy25',
        ]);
        $fy = FiscalYear::firstOrCreate(
            ['company_id' => $c->id, 'label' => '2025-26'],
            ['company_id' => $c->id, 'start_date' => '2025-04-01',
             'end_date' => '2026-03-31']
        );

        IncomeEntry::firstOrCreate([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'source' => 'Total Income (lumped)',
        ], [
            'occurred_on' => '2025-04-01', 'amount' => 12500000,
        ]);

        $rows = [
            // [section_slug, title, salary, loan, paid_out, received_in]
            ['salary', 'Usha',          1200000, 0,       200000, 0],
            ['salary', 'Magha',         1200000, 0,       450000, 0],
            ['salary', 'Lansdown',      0,       1000000, 0,      100000],
            ['salary', 'Shri Bhagwan',  0,       1000000, 0,      0],
            ['salary', 'Shubham Deswal',0,       1000000, 0,      0],
            ['salary', 'Gagandeep',     0,       1000000, 0,      0],
            ['salary', 'Poonam Sanju',  0,       800000,  0,      0],
            ['salary', 'Nisha',         0,       800000,  0,      0],
            ['rent',   'Parmit',        0,       0,       450000, 0],
            ['loan',   'Spillin Beans', 0,       1500000, 0,      0],
            ['loan',   'Kyne',          0,       2000000, 0,      0],
            ['expense','Credit Card',   0,       0,       400000, 300000],
            ['expense','Expenses',      0,       0,       1860000, 0],
        ];

        foreach ($rows as $r) {
            [$sectionSlug, $title, $sal, $loan, $paid, $back] = $r;
            $section = Section::firstWhere(['company_id' => $c->id, 'slug' => $sectionSlug]);
            if (! $section) {
                $this->command?->warn("Skipping {$title}: section '{$sectionSlug}' not found (CompanyObserver should have seeded it)");
                continue;
            }
            $entry = Entry::firstOrCreate(
                ['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
                 'section_id' => $section->id, 'title' => $title],
                ['salary_amount' => $sal, 'loan_amount' => $loan]
            );
            if ($paid > 0) {
                EntryPayment::firstOrCreate(
                    ['entry_id' => $entry->id, 'occurred_on' => '2025-05-01',
                     'direction' => 'out', 'amount' => $paid],
                    ['mode' => 'bank']
                );
            }
            if ($back > 0) {
                EntryPayment::firstOrCreate(
                    ['entry_id' => $entry->id, 'occurred_on' => '2025-07-01',
                     'direction' => 'in', 'amount' => $back],
                    ['mode' => 'bank']
                );
            }
        }

        // Assets: Car (₹3,00,000 / Dep ₹2,00,000) + Solar (₹4,90,000 / Dep ₹4,90,000)
        $assetSection = Section::firstWhere(['company_id' => $c->id, 'slug' => 'assets']);
        if ($assetSection) {
            foreach ([
                ['Car', 300000, 200000, 5],
                ['Solar', 490000, 490000, 1],
            ] as [$name, $original, $depYear1, $life]) {
                $e = Entry::firstOrCreate(
                    ['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
                     'section_id' => $assetSection->id, 'title' => $name],
                    ['salary_amount' => 0, 'loan_amount' => 0]
                );
                $percent = round($depYear1 / $original * 100, 2);
                Asset::firstOrCreate(
                    ['entry_id' => $e->id],
                    ['original_value' => $original, 'dep_percent' => $percent,
                     'dep_years' => $life, 'dep_started_at' => '2025-04-01',
                     'method' => 'straight_line']
                );
            }
        }
    }
}
