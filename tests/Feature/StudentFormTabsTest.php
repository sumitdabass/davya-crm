<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFormTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_renders_top_level_tabs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->assertSee('Identity')
            ->assertSee('Academic')
            ->assertSee('Deal')
            ->assertSee('Counselling')
            ->assertSee('Account')
            ->assertSee('Closure')
            ->assertSee('Quick notes')
            ->assertDontSee('Final allotment')
            ->assertDontSee('Logistics');
    }
}
