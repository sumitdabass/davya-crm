<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentChoicePredictorRoundTest extends TestCase
{
    use RefreshDatabase;

    // RefreshDatabase only manages the DEFAULT connection. The `ranks`
    // connection is a separate PERSISTENT DB holding a shared read-only
    // cutoffs fixture (used by RankLookupTest) plus IPU rows the
    // DatabaseSeeder leaves behind. Wrap `ranks` in a transaction so
    // everything we do — including the fixture clear below — rolls back at
    // teardown and the shared fixture survives regardless of test order.
    protected $connectionsToTransact = ['ranks'];

    // Clear `ranks` with DML deletes (NOT truncate, which implicitly commits
    // and would permanently destroy the shared fixture) so each method starts
    // from a clean slate and `University::create('IPU')` cannot collide.
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('ranks')->disableForeignKeyConstraints();
        foreach (['cutoffs', 'seats', 'branches', 'institutes', 'courses', 'admission_processes', 'qualifying_exams', 'universities'] as $table) {
            DB::connection('ranks')->table($table)->delete();
        }
        Schema::connection('ranks')->enableForeignKeyConstraints();
    }

    private function seedIpuCell(string $round, int $max): array
    {
        $u = University::create(['name' => 'IPU', 'code' => 'IPU']);
        $c = Course::create(['university_id' => $u->id, 'name' => 'B.Tech']);
        $e = QualifyingExam::firstOrCreate(['code' => 'JEE_MAIN'], ['name' => 'JEE Main']);
        $p = AdmissionProcess::firstOrCreate(['code' => 'COUNSELLING'], ['name' => 'Counselling']);
        $i = Institute::create(['university_id' => $u->id, 'name' => 'USICT']);
        $b = Branch::create(['course_id' => $c->id, 'name' => 'CSE']);
        Cutoff::create([
            'university_id' => $u->id, 'course_id' => $c->id, 'qualifying_exam_id' => $e->id,
            'admission_process_id' => $p->id, 'year' => 2026, 'round' => $round,
            'institute_id' => $i->id, 'branch_id' => $b->id, 'region' => 'delhi',
            'min_rank' => 0, 'max_rank' => $max, 'source' => 'official',
        ]);

        return compact('u', 'c', 'e', 'p', 'i', 'b');
    }

    /** @test */
    public function general_student_is_scored_against_sliding_round(): void
    {
        $this->seedIpuCell('sliding', 120000);
        $student = new Student(['rank' => '100000', 'category' => 'Delhi', 'reservation_category' => 'general']);

        $choices = (new StudentChoicePredictor)->topChoices($student, 3);

        $this->assertNotEmpty($choices);
        $this->assertSame('USICT', $choices[0]['college']);
    }

    /** @test */
    public function reserved_student_is_scored_against_round_3(): void
    {
        $this->seedIpuCell('3', 120000);
        $student = new Student(['rank' => '100000', 'category' => 'Delhi', 'reservation_category' => 'sc']);

        $choices = (new StudentChoicePredictor)->topChoices($student, 3);

        $this->assertNotEmpty($choices);
    }
}
