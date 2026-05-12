<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Rename existing "Loan" sections to "Loans Given" (slug stays 'loan'
        // for URL stability). Only touch rows whose name is still the default.
        DB::table('book_sections')
            ->where('slug', 'loan')
            ->where('name', 'Loan')
            ->update(['name' => 'Loans Given']);

        // Add a "Loans Taken" section to every existing company that lacks one.
        $companies = DB::table('book_companies')->select('id')->get();
        foreach ($companies as $co) {
            $exists = DB::table('book_sections')
                ->where('company_id', $co->id)
                ->where('slug', 'loans_taken')
                ->exists();
            if ($exists) {
                continue;
            }
            $maxOrder = (int) DB::table('book_sections')
                ->where('company_id', $co->id)
                ->max('sort_order');
            DB::table('book_sections')->insert([
                'company_id' => $co->id,
                'slug' => 'loans_taken',
                'name' => 'Loans Taken',
                'kind' => 'generic',
                'sort_order' => $maxOrder + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Pure restore — only remove auto-seeded "Loans Taken" sections that have
        // no entries, and rename "Loans Given" back to "Loan" where untouched.
        DB::table('book_sections')
            ->where('slug', 'loans_taken')
            ->whereNotIn('id', DB::table('book_entries')->select('section_id'))
            ->delete();

        DB::table('book_sections')
            ->where('slug', 'loan')
            ->where('name', 'Loans Given')
            ->update(['name' => 'Loan']);
    }
};
