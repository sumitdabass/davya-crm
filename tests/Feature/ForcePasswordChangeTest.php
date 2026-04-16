<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_must_change_password_is_redirected(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->assertTrue($sumit->must_change_password);

        $this->actingAs($sumit);
        $this->get('/admin')->assertRedirect(route('filament.admin.pages.change-password'));
    }

    public function test_user_without_flag_is_not_redirected_to_change_password(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false, 'password' => Hash::make('password123')]);

        $this->actingAs($sumit);
        $response = $this->get('/admin');
        $response->assertDontSee(route('filament.admin.pages.change-password'));
    }

    public function test_change_password_page_is_always_accessible_while_flag_set(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);

        $this->get(route('filament.admin.pages.change-password'))->assertStatus(200);
    }
}
