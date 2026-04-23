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

    public function test_admin_can_create_a_new_stage(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::test(PipelineConfigPage::class)
            ->call('createStage', 'New Thing', 'OPEN')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stages', ['name' => 'New Thing', 'stage_type' => 'OPEN']);
    }

    public function test_admin_cannot_create_21st_stage(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        for ($i = 1; $i <= 7; $i++) {
            Livewire::test(PipelineConfigPage::class)->call('createStage', "Extra $i", 'OPEN')->assertHasNoErrors();
        }
        // 13 seeded + 7 = 20. Next one should fail (via notification).
        Livewire::test(PipelineConfigPage::class)
            ->call('createStage', 'TooMany', 'OPEN')
            ->assertNotified();
        $this->assertDatabaseMissing('stages', ['name' => 'TooMany']);
    }

    public function test_delete_stage_with_students_requires_transfer_target(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $nikhil = User::where('email','nikhil@davya.local')->firstOrFail();
        $stage = \App\Models\Pipeline::default()->stages()->where('name','Meeting Scheduled')->firstOrFail();
        \DB::table('students')->insert([
            'name'=>'D','phone'=>'9222222200','owner_id'=>$nikhil->id,'referrer_id'=>$nikhil->id,
            'lead_source'=>'t','stage'=>$stage->name,'stage_id'=>$stage->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        // No target → notification warning, stage remains
        Livewire::test(PipelineConfigPage::class)
            ->call('deleteStage', $stage->id, null)
            ->assertNotified();
        $this->assertDatabaseHas('stages', ['id' => $stage->id]);

        // With target → succeeds, stage deleted, student moved
        $target = \App\Models\Pipeline::default()->stages()->where('name','Meeting Done')->firstOrFail();
        Livewire::test(PipelineConfigPage::class)
            ->call('deleteStage', $stage->id, $target->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('stages', ['id' => $stage->id]);
    }

    public function test_admin_can_reorder_stages(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $p = \App\Models\Pipeline::default();
        $openIds = $p->stages()->where('stage_type','OPEN')->orderBy('display_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $wonIds  = $p->stages()->where('stage_type','CLOSED_WON')->orderBy('display_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lostIds = $p->stages()->where('stage_type','CLOSED_LOST')->orderBy('display_order')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Reverse only the open stages; full list must contain all 13 ids (per StageRepository::reorder guard).
        $reversed = array_reverse($openIds);
        $allOrdered = array_merge($reversed, $wonIds, $lostIds);

        Livewire::test(PipelineConfigPage::class)
            ->call('reorderStages', $allOrdered);

        $newFirstOpen = $p->stages()->where('stage_type','OPEN')->orderBy('display_order')->first();
        $this->assertSame($reversed[0], (int) $newFirstOpen->id);
    }
}
