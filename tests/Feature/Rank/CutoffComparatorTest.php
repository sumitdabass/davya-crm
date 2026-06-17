<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Services\Rank\CutoffComparator;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesInMemoryRanksDatabase;
use Tests\TestCase;

class CutoffComparatorTest extends TestCase
{
    use RefreshDatabase;
    use UsesInMemoryRanksDatabase;

    private University $jac;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryRanksDatabase();
        $this->seed(JacDelhiSeeder::class);
        $this->jac = University::where('code', 'JAC')->first();
        $this->course = Course::where('university_id', $this->jac->id)->where('name', 'B.Tech')->first();
    }

    private function cut(string $inst, string $branch, int $year, string $round, int $cr): void
    {
        $i = Institute::firstOrCreate(['university_id' => $this->jac->id, 'name' => $inst]);
        $b = Branch::firstOrCreate(['course_id' => $this->course->id, 'name' => $branch]);
        Cutoff::create([
            'university_id' => $this->jac->id, 'course_id' => $this->course->id,
            'qualifying_exam_id' => QualifyingExam::where('code', 'JEE_MAIN')->value('id'),
            'admission_process_id' => AdmissionProcess::where('code', 'JAC')->value('id'),
            'year' => $year, 'round' => $round, 'institute_id' => $i->id, 'branch_id' => $b->id,
            'shift' => null, 'region' => 'delhi', 'category' => 'general', 'sub_category' => 'gender_neutral',
            'min_rank' => 0, 'max_rank' => $cr, 'source' => 'official',
        ]);
    }

    /** @test */
    public function compares_two_years_and_projects_final_round(): void
    {
        // DTU: 2025 R1 6000 -> R5 12000 (x2); 2026 R1 9000 -> projected R5 18000 (looser).
        $this->cut('DTU', 'CSE', 2025, '1', 6000);
        $this->cut('DTU', 'CSE', 2025, '5', 12000);
        $this->cut('DTU', 'CSE', 2026, '1', 9000);
        // ECE: tighter (2026 R1 below 2025 R1).
        $this->cut('DTU', 'ECE', 2025, '1', 20000);
        $this->cut('DTU', 'ECE', 2025, '5', 30000);
        $this->cut('DTU', 'ECE', 2026, '1', 16000);

        $res = (new CutoffComparator)->compare('dtu', 'delhi', 'general', 'gender_neutral');

        $this->assertSame(2025, $res['prior_year']);
        $this->assertSame(2026, $res['newer_year']);
        $this->assertSame('5', $res['final_round']);
        $this->assertSame(1, $res['up']);
        $this->assertSame(1, $res['down']);

        $by = collect($res['rows'])->keyBy('branch');
        $this->assertSame(18000, $by['CSE']['projected']);
        $this->assertSame('up', $by['CSE']['direction']);
        $this->assertFalse($by['CSE']['is_actual']);
        $this->assertSame(24000, $by['ECE']['projected']);
        $this->assertSame('down', $by['ECE']['direction']);
    }

    /** @test */
    public function uses_actual_final_round_once_published(): void
    {
        $this->cut('DTU', 'CSE', 2025, '1', 6000);
        $this->cut('DTU', 'CSE', 2025, '5', 12000);
        $this->cut('DTU', 'CSE', 2026, '1', 9000);
        $this->cut('DTU', 'CSE', 2026, '5', 15000); // final published -> projection = actual

        $row = (new CutoffComparator)->compare('dtu', 'delhi', 'general', 'gender_neutral')['rows'][0];
        $this->assertSame('5', $row['anchor_round']);
        $this->assertTrue($row['is_actual']);
        $this->assertSame(15000, $row['projected']);
    }
}
