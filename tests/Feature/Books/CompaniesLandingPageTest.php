<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\CompaniesLanding;
use App\Models\Book\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompaniesLandingPageTest extends TestCase
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

    public function test_lists_companies(): void
    {
        Company::create(['name' => 'Davyas', 'slug' => 'davyas']);
        Company::create(['name' => 'Spillin Beans', 'slug' => 'spillin-beans']);

        $this->get('/admin/books')
            ->assertSuccessful()
            ->assertSee('Davyas')
            ->assertSee('Spillin Beans');
    }

    public function test_creates_a_company_via_livewire_action(): void
    {
        Livewire::test(CompaniesLanding::class)
            ->callAction('createCompany', ['name' => 'Kyne', 'slug' => 'kyne'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Company::where('slug', 'kyne')->exists());
    }
}
