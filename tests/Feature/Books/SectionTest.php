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
        // The 'salary' section is auto-seeded by CompanyObserver, so look
        // it up rather than create a duplicate (which would collide on the
        // unique (company_id, slug) constraint).
        $c = Company::factory()->create();
        $s = $c->sections()->where('slug', 'salary')->firstOrFail();

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
        // 'salary' already exists on every company (auto-seeded). Trying
        // to insert it a second time must raise the unique violation.
        $c = Company::factory()->create();

        $this->expectException(QueryException::class);

        Section::create([
            'company_id' => $c->id,
            'slug' => 'salary',
            'name' => 'Other',
            'kind' => 'generic',
            'sort_order' => 99,
        ]);
    }

    public function test_allows_same_slug_across_different_companies(): void
    {
        // Both companies get an auto-seeded 'salary' section on create —
        // that itself proves the unique constraint is scoped per-company.
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $sA = $a->sections()->where('slug', 'salary')->firstOrFail();
        $sB = $b->sections()->where('slug', 'salary')->firstOrFail();

        $this->assertIsInt($sA->id);
        $this->assertIsInt($sB->id);
        $this->assertNotSame($sA->id, $sB->id);
    }
}
