<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Section;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_section_with_default_visible_money_columns(): void
    {
        $c = Company::factory()->create();

        $s = Section::create([
            'company_id' => $c->id,
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ]);

        $this->assertSame(['salary', 'paid', 'balance'], $s->visible_money_columns);
    }

    public function test_lets_admin_override_visible_columns(): void
    {
        $c = Company::factory()->create();

        $s = Section::create([
            'company_id' => $c->id,
            'slug' => 'mixed',
            'name' => 'Mixed',
            'kind' => 'generic',
            'sort_order' => 1,
            'visible_money_columns' => ['salary', 'loan', 'paid', 'received_back', 'balance', 'loan_outstanding'],
        ]);

        $this->assertSame(
            ['salary', 'loan', 'paid', 'received_back', 'balance', 'loan_outstanding'],
            $s->fresh()->visible_money_columns
        );
    }

    public function test_enforces_unique_slug_per_company(): void
    {
        $c = Company::factory()->create();

        Section::create([
            'company_id' => $c->id,
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ]);

        $this->expectException(QueryException::class);

        Section::create([
            'company_id' => $c->id,
            'slug' => 'salary',
            'name' => 'Other',
            'kind' => 'generic',
            'sort_order' => 2,
        ]);
    }

    public function test_allows_same_slug_across_different_companies(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        Section::create([
            'company_id' => $a->id,
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ]);

        $sB = Section::create([
            'company_id' => $b->id,
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ]);

        $this->assertIsInt($sB->id);
    }
}
