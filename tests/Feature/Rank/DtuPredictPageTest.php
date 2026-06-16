<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\DtuPredict;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\User;
use Database\Seeders\Rank\JacDelhiSeeder;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DtuPredictPageTest extends TestCase
{
    use RefreshDatabase;

    // Transact BOTH connections: the default ('sqlite' under testing) holds
    // roles/permissions/users that must roll back between tests; 'ranks' holds
    // the cutoffs we write.
    protected $connectionsToTransact = ['sqlite', 'ranks'];

    protected function setUp(): void
    {
        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @test */
    public function dtu_predict_returns_rows_for_a_rank(): void
    {
        $this->seed(RankRoleSeeder::class);
        $this->seed(JacDelhiSeeder::class);
        $jac = University::where('code', 'JAC')->first();
        Cutoff::where('university_id', $jac->id)->forceDelete();

        $course = Course::where('university_id', $jac->id)->where('name', 'B.Tech')->first();
        $exam = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $process = AdmissionProcess::where('code', 'JAC')->first();
        $inst = Institute::where('university_id', $jac->id)->where('name', 'DTU')->first();
        $branch = Branch::firstOrCreate(['course_id' => $course->id, 'name' => 'Computer Science and Engineering']);
        Cutoff::create([
            'university_id' => $jac->id, 'course_id' => $course->id, 'qualifying_exam_id' => $exam->id,
            'admission_process_id' => $process->id, 'year' => 2025, 'round' => '5',
            'institute_id' => $inst->id, 'branch_id' => $branch->id, 'shift' => null,
            'region' => 'delhi', 'category' => 'general', 'sub_category' => 'gender_neutral',
            'min_rank' => 0, 'max_rank' => 11352, 'source' => 'official',
        ]);

        $user = User::factory()->create();
        $user->assignRole('rank-dtu-predict');
        $this->actingAs($user);

        $result = Livewire::test(DtuPredict::class)
            ->set('data.user_rank', 11000)
            ->set('data.gender', 'male')
            ->set('data.category', 'general')
            ->set('data.sub_category', 'gender_neutral')
            ->set('data.region', 'delhi')
            ->instance()->getResultsProperty();

        $this->assertTrue($result['submitted']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('DTU', $result['rows'][0]['institute']);
        $this->assertSame('LIKELY', $result['rows'][0]['chance']);
    }

    /** @test */
    public function dtu_predict_denied_to_ipu_only_user(): void
    {
        $this->seed(RankRoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('rank-ipu-predict');
        $this->actingAs($user);

        $this->assertFalse(DtuPredict::canAccess());
    }
}
