<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuiltInFieldsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_salary_section_and_7_built_in_fields_on_company_create(): void
    {
        $c = Company::create(['name' => 'Davyas', 'slug' => 'davyas']);

        $salary = $c->sections()->where('slug', 'salary')->first();
        $this->assertNotNull($salary);

        $this->assertSame(
            7,
            Field::where('section_id', $salary->id)->where('is_built_in', true)->count(),
            'Expected 7 built-in fields seeded on the salary section: PAN, Aadhaar, Cancelled Cheque, Account Number, IFSC, Offer, Joining'
        );
    }

    public function test_does_not_re_seed_on_update(): void
    {
        $c = Company::create(['name' => 'X', 'slug' => 'x']);

        $c->update(['name' => 'X (renamed)']);

        $this->assertSame(
            7,
            Field::where('company_id', $c->id)->where('is_built_in', true)->count()
        );
    }
}
