<?php

namespace App\Books\Services;

use App\Models\Book\Company;
use App\Models\Book\Field;
use App\Models\Book\Section;

class BuiltInFieldsSeeder
{
    public function seed(Company $company): void
    {
        $sections = [
            ['slug' => 'salary',  'name' => 'Salary',       'kind' => 'generic', 'sort_order' => 1],
            ['slug' => 'rent',    'name' => 'Rent',         'kind' => 'generic', 'sort_order' => 2],
            ['slug' => 'loan',    'name' => 'Loan',         'kind' => 'generic', 'sort_order' => 3],
            ['slug' => 'assets',  'name' => 'Depreciation', 'kind' => 'asset',   'sort_order' => 4],
            ['slug' => 'expense', 'name' => 'Expense',      'kind' => 'generic', 'sort_order' => 5],
        ];

        foreach ($sections as $s) {
            Section::firstOrCreate(
                ['company_id' => $company->id, 'slug' => $s['slug']],
                array_merge($s, ['company_id' => $company->id])
            );
        }

        $salary = $company->sections()->where('slug', 'salary')->first();

        $builtins = [
            ['key' => 'pan',              'label' => 'PAN',              'type' => 'text', 'show_in_table' => true],
            ['key' => 'aadhaar',          'label' => 'Aadhaar',          'type' => 'text', 'show_in_table' => false],
            ['key' => 'cancelled_cheque', 'label' => 'Cancelled Cheque', 'type' => 'file', 'show_in_table' => false],
            ['key' => 'account_number',   'label' => 'Account Number',   'type' => 'text', 'show_in_table' => false],
            ['key' => 'ifsc',             'label' => 'IFSC',             'type' => 'text', 'show_in_table' => false],
            ['key' => 'offer_letter',     'label' => 'Offer Letter',     'type' => 'file', 'show_in_table' => false],
            ['key' => 'joining_letter',   'label' => 'Joining Letter',   'type' => 'file', 'show_in_table' => false],
        ];

        foreach ($builtins as $idx => $b) {
            Field::firstOrCreate(
                ['company_id' => $company->id, 'section_id' => $salary->id, 'key' => $b['key']],
                array_merge($b, [
                    'company_id' => $company->id,
                    'section_id' => $salary->id,
                    'is_built_in' => true,
                    'sort_order' => $idx + 1,
                ])
            );
        }
    }
}
