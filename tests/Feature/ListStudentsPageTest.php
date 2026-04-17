<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListStudentsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_renders_without_error_for_admin(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();

        $this->actingAs($sumit);

        Livewire::test(ListStudents::class)->assertStatus(200);
    }

    public function test_list_page_tabs_do_not_break_filter_form(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();

        $this->actingAs($sumit);

        // Switching to each tab triggers a refresh of the table including filter form render.
        foreach (['all', 'active', 'counselling', 'admitted', 'closed'] as $tab) {
            Livewire::test(ListStudents::class, ['activeTab' => $tab])
                ->assertStatus(200);
        }
    }
}
