<?php

namespace Tests\Feature;

use App\Livewire\StudentSearch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('head');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    /** @test */
    public function it_renders_the_search_input(): void
    {
        Livewire::test(StudentSearch::class)
            ->assertSee('Search students by name, phone, email');
    }

    /** @test */
    public function it_returns_no_results_under_two_characters(): void
    {
        Student::factory()->create(['name' => 'Aarav Sharma', 'phone' => '9810000001']);

        Livewire::test(StudentSearch::class)
            ->set('query', 'a')
            ->assertDontSee('Aarav Sharma');
    }

    /** @test */
    public function it_matches_by_each_searchable_field(): void
    {
        $student = Student::factory()->create([
            'name'          => 'Priya Verma',
            'phone'         => '9810000002',
            'phone_2'       => '9810099999',
            'email'         => 'priya@example.com',
            'father_name'   => 'Suresh Verma',
            'ipu_user_id'   => 'IPU2026X042',
            'course'        => 'BBA',
        ]);

        $cases = [
            ['Priya',           'name'],
            ['9810000002',      'phone'],
            ['9810099999',      'phone_2'],
            ['priya@example',   'email'],
            ['Suresh',          'father_name'],
            ['IPU2026X042',     'ipu_user_id'],
            ['BBA',             'course'],
        ];

        foreach ($cases as [$query, $field]) {
            Livewire::test(StudentSearch::class)
                ->set('query', $query)
                ->assertSee('Priya Verma', false, "search by {$field} did not surface the student");
        }
    }

    /** @test */
    public function it_respects_visible_to_scope_for_non_admin(): void
    {
        $head = User::factory()->create(['name' => 'Sonam Sumit']);
        $head->assignRole('head');

        $hidden = Student::factory()->create([
            'name' => 'NotVisibleStudent',
            'phone' => '9999999991',
            'owner_id' => $this->admin->id, // admin-owned, not visible to head
        ]);

        $this->actingAs($head);

        Livewire::test(StudentSearch::class)
            ->set('query', 'NotVisible')
            ->assertDontSee('NotVisibleStudent');
    }

    /** @test */
    public function it_renders_correct_edit_url_in_results(): void
    {
        $student = Student::factory()->create([
            'name'  => 'Kabir Singh',
            'phone' => '9810000005',
        ]);

        Livewire::test(StudentSearch::class)
            ->set('query', 'Kabir')
            ->assertSee('/admin/students/'.$student->id.'/edit', false);
    }
}
