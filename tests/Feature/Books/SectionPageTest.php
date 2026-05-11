<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\SectionPage;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SectionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
    }

    public function test_renders_the_section_table_with_entries(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
        $s = $c->sections()->where('slug', 'salary')->first();
        Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 1200000,
        ]);

        $this->get("/admin/books/{$c->slug}/{$fy->label}/section/salary")
            ->assertSuccessful()
            ->assertSee('Usha')
            ->assertSee('1,200,000.00');
    }

    public function test_creates_an_entry_through_the_page_action(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        Livewire::test(SectionPage::class, [
            'company' => 'a',
            'fy' => '2025-26',
            'section' => 'salary',
        ])
            ->callAction('createEntry', [
                'title' => 'Magha',
                'salary_amount' => 1200000,
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(Entry::where('title', 'Magha')->exists());
    }
}
