<?php
// tests/Feature/Pipeline/PipelineConfigPageTest.php
namespace Tests\Feature\Pipeline;

use App\Filament\Pages\PipelineConfigPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineConfigPageTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User { $u->must_change_password = false; $u->save(); return $u; }

    public function test_admin_can_access_page(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(PipelineConfigPage::class)->assertStatus(200);
    }

    public function test_non_admin_cannot_access(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email','nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $this->get('/admin/pipeline-config')->assertStatus(403);
    }

    public function test_page_shows_13_seeded_stages(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(PipelineConfigPage::class)
            ->assertSeeText('Lead Captured')
            ->assertSeeText('Seat Allotted')
            ->assertSeeText('Complete Payment Received')
            ->assertSeeText('Closed');
    }
}
