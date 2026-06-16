<?php

namespace Tests\Feature\Rank;

use App\Filament\Resources\Rank\CutoffResource;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `ranks` connection holds a persistent dev fixture that RefreshDatabase does
 * NOT reset. We create our own ranks rows with firstOrCreate and tear them down by
 * id in tearDown so the shared fixture (IPU/JAC universities + cutoffs) is untouched.
 */
class ResourceDatasetScopingTest extends TestCase
{
    use RefreshDatabase;

    private University $ipu;

    private University $jac;

    /** @var array<int,int> cutoff ids we created (force-delete on teardown) */
    private array $createdCutoffIds = [];

    /** @var array<int,int> branch ids we created */
    private array $createdBranchIds = [];

    /** @var array<int,int> course ids we created */
    private array $createdCourseIds = [];

    /** @var array<int,int> institute ids we created */
    private array $createdInstituteIds = [];

    private QualifyingExam $exam;

    private AdmissionProcess $process;

    /** @var array<int,int> user ids we created */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RankRoleSeeder::class);

        $this->ipu = University::on('ranks')->firstOrCreate(['code' => 'IPU'], ['name' => 'IPU (test fixture)']);
        $this->jac = University::on('ranks')->firstOrCreate(['code' => 'JAC'], ['name' => 'JAC Delhi (test fixture)']);

        $this->exam = QualifyingExam::on('ranks')->firstOrCreate(['code' => 'TST'], ['name' => 'Test Exam']);
        $this->process = AdmissionProcess::on('ranks')->firstOrCreate(['code' => 'TSTP'], ['name' => 'Test Process']);

        // One full cutoff chain per university so assertion 1 (scoping) is meaningful.
        $this->makeCutoffFor($this->ipu);
        $this->makeCutoffFor($this->jac);
    }

    protected function tearDown(): void
    {
        Cutoff::on('ranks')->whereIn('id', $this->createdCutoffIds)->forceDelete();
        Branch::on('ranks')->whereIn('id', $this->createdBranchIds)->delete();
        Course::on('ranks')->whereIn('id', $this->createdCourseIds)->delete();
        Institute::on('ranks')->whereIn('id', $this->createdInstituteIds)->delete();
        // Universities / exam / process are firstOrCreate'd and may pre-exist in the
        // fixture; leave them in place. Default-connection users get cleaned here.
        User::whereIn('id', $this->createdUserIds)->forceDelete();

        parent::tearDown();
    }

    private function makeCutoffFor(University $u): void
    {
        $course = Course::on('ranks')->create([
            'university_id' => $u->id,
            'name' => "Test Course {$u->code}",
            'code' => "TC{$u->id}",
        ]);
        $this->createdCourseIds[] = $course->id;

        $institute = Institute::on('ranks')->create([
            'university_id' => $u->id,
            'name' => "Test Institute {$u->code}",
            'code' => "TI{$u->id}",
        ]);
        $this->createdInstituteIds[] = $institute->id;

        $branch = Branch::on('ranks')->create([
            'course_id' => $course->id,
            'name' => "Test Branch {$u->code}",
            'family' => 'cs',
        ]);
        $this->createdBranchIds[] = $branch->id;

        $cutoff = Cutoff::on('ranks')->create([
            'university_id' => $u->id,
            'course_id' => $course->id,
            'qualifying_exam_id' => $this->exam->id,
            'admission_process_id' => $this->process->id,
            'year' => 2099,
            'round' => '1',
            'institute_id' => $institute->id,
            'branch_id' => $branch->id,
            'region' => 'delhi',
            'min_rank' => 1,
            'max_rank' => 100,
            'source' => 'official',
        ]);
        $this->createdCutoffIds[] = $cutoff->id;
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    /** @test */
    public function dtu_analyse_user_cutoff_query_excludes_ipu_rows(): void
    {
        $user = $this->makeUser('rank-dtu-analyse');
        $this->actingAs($user);

        $universityIds = CutoffResource::getEloquentQuery()->pluck('university_id')->unique();

        $this->assertFalse(
            $universityIds->contains($this->ipu->id),
            'rank-dtu-analyse query must NOT include IPU cutoffs',
        );
        $this->assertTrue(
            $universityIds->contains($this->jac->id),
            'rank-dtu-analyse query must include JAC cutoffs',
        );
    }

    /** @test */
    public function legacy_admin_cutoff_query_is_unscoped(): void
    {
        $user = $this->makeUser('rank-admin');
        $this->actingAs($user);

        $sql = CutoffResource::getEloquentQuery()->toSql();

        $this->assertStringNotContainsString('university_id` in', $sql);
        $this->assertStringNotContainsString('`code` in', $sql);
    }

    /** @test */
    public function can_access_is_true_for_analyse_and_false_for_predict_only(): void
    {
        $analyse = $this->makeUser('rank-dtu-analyse');
        $this->actingAs($analyse);
        $this->assertTrue(CutoffResource::canAccess());

        $predict = $this->makeUser('rank-dtu-predict');
        $this->actingAs($predict);
        $this->assertFalse(CutoffResource::canAccess());
    }
}
