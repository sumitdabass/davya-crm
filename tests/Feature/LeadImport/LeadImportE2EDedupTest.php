<?php

namespace Tests\Feature\LeadImport;

use App\Filament\Pages\LeadImport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadImportE2EDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_sonam_import_demotes_existing_sumit_row_and_reparents_payments(): void
    {
        // Existing Sumit-owned student with a payment
        $intake = app(LeadIntakeService::class);
        $existing = $intake->ingest(['phone' => '9000002000', 'course' => 'BCA', 'owner_name' => 'Sumit'])['student'];
        Payment::factory()->create(['student_id' => $existing->id, 'amount' => 100]);

        $admin = User::role('admin')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000002000\tBCA\t1234\tD\t\t\n";

        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', $tsv)
            ->call('runPreview')
            ->assertSet('previewMergeCount', 1)
            ->call('commitPreview')
            ->assertSet('committedMergeCount', 1);

        // Old student gone; new student exists; payment re-parented
        $this->assertDatabaseMissing('students', ['id' => $existing->id]);
        $new = Student::where('phone', '9000002000')->first();
        $this->assertNotNull($new);
        $this->assertSame(User::whereRaw('LOWER(name) = ?', ['sonam'])->firstOrFail()->id, $new->owner_id);
        $this->assertSame(1, Payment::where('student_id', $new->id)->count());
    }
}
