<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_company_with_inr_default(): void
    {
        $c = Company::create(['name' => 'Davyas Consultancy', 'slug' => 'davyas']);

        $this->assertSame('INR', $c->currency);
        $this->assertSame('davyas', $c->slug);
    }

    public function test_enforces_unique_company_slug(): void
    {
        Company::create(['name' => 'A', 'slug' => 'foo']);

        $this->expectException(QueryException::class);

        Company::create(['name' => 'B', 'slug' => 'foo']);
    }

    public function test_rejects_non_inr_currency_at_the_model_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Company::create(['name' => 'X', 'slug' => 'x', 'currency' => 'USD']);
    }

    public function test_soft_deletes_a_company(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $c->delete();

        $this->assertSame(0, Company::count());
        $this->assertSame(1, Company::withTrashed()->count());
    }
}
