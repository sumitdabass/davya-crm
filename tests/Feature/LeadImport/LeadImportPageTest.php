<?php

namespace Tests\Feature\LeadImport;

use App\Models\User;
use App\Filament\Pages\LeadImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadImportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_page(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        Livewire::test(LeadImport::class)->assertStatus(200);
    }

    public function test_non_admin_cannot_access_page(): void
    {
        $member = User::whereHas('roles', fn ($q) => $q->where('name', 'member'))->firstOrFail();
        $this->actingAs($member);

        $this->assertFalse(LeadImport::canAccess());
    }

    public function test_paste_preview_and_commit_creates_students_and_batch(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000001100\tBCA\t1234\tD\t\tNisha\n";

        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', $tsv)
            ->call('runPreview')
            ->assertSet('step', 'preview')
            ->assertSet('previewCreateCount', 1)
            ->call('commitPreview')
            ->assertSet('step', 'done')
            ->assertSet('committedCreateCount', 1);

        $this->assertDatabaseHas('students', ['phone' => '9000001100']);
        $this->assertDatabaseHas('lead_import_batches', ['source' => 'sonam', 'created_count' => 1]);
    }

    public function test_parse_error_surfaces_in_ui_and_does_not_advance_step(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', "Wrong\tHeaders\n1\t2\n")
            ->call('runPreview')
            ->assertSet('step', 'input')
            ->assertSet('parseError', fn ($v) => is_string($v) && str_contains($v, 'Missing required column'));
    }
}
