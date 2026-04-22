<?php

namespace Tests\Feature;

use App\Models\RoundHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemapStudentPipelineStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_stages_remap_to_new_stages(): void
    {
        $this->seed();
        $owner = User::first();

        // Seed one student per old stage. Use raw DB insert because some values
        // are no longer valid for the model's validator.
        $mkStudent = function (string $oldStage, array $extra = []) use ($owner): int {
            return DB::table('students')->insertGetId(array_merge([
                'phone' => '9999910' . random_int(1000, 9999),
                'name' => "Old {$oldStage}",
                'owner_id' => $owner->id,
                'lead_source' => 'Website',
                'stage' => $oldStage,
                'created_at' => now(),
                'updated_at' => now(),
            ], $extra));
        };

        $idOnboarded = $mkStudent('Onboarded');
        $idUniReg = $mkStudent('University Registration');
        $idCipWithRound = $mkStudent('Counselling In Progress');
        RoundHistory::create([
            'student_id' => $idCipWithRound,
            'round_name' => 'Online_R2',
            'outcome' => 'Not Allotted',
        ]);
        $idCipNoRound = $mkStudent('Counselling In Progress');
        $idFullPaid = $mkStudent('Full Payment Received');
        $idAdmConf = $mkStudent('Admission Confirmed');
        $idClosed = $mkStudent('Closed', ['close_reason' => 'Not Interested']);

        // Rollback the remap migration (it already ran during RefreshDatabase
        // but against an empty students table), then re-run migrate so the
        // freshly-inserted legacy rows get remapped.
        $this->rollbackRemapMigration();
        $this->artisan('migrate')->assertExitCode(0);

        $stage = fn (int $id): ?string => DB::table('students')->where('id', $id)->value('stage');
        $closeReason = fn (int $id): ?string => DB::table('students')->where('id', $id)->value('close_reason');

        $this->assertSame('Advance Received', $stage($idOnboarded));
        $this->assertSame('Advance Received', $stage($idUniReg));
        $this->assertSame('Round 2', $stage($idCipWithRound));
        $this->assertSame('MQ', $stage($idCipNoRound));
        $this->assertSame('Seat Allotted', $stage($idFullPaid));
        $this->assertSame('Closed', $stage($idAdmConf));
        $this->assertSame('Completed', $closeReason($idAdmConf), 'Admission Confirmed must set close_reason');
        $this->assertSame('Closed', $stage($idClosed));
        $this->assertSame('Not Interested', $closeReason($idClosed), 'existing Closed reasons preserved');
    }

    private function rollbackRemapMigration(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_04_24_000400_remap_student_pipeline_stages')
            ->delete();
    }
}
