<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Services\Rank\DatasetCutoffPredictor;
use App\Services\Rank\PredictorContext;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesInMemoryRanksDatabase;
use Tests\TestCase;

class DatasetCutoffPredictorTest extends TestCase
{
    use RefreshDatabase;
    use UsesInMemoryRanksDatabase;

    private University $jac;

    private Course $course;

    private QualifyingExam $exam;

    private AdmissionProcess $process;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryRanksDatabase();
        $this->seed(JacDelhiSeeder::class);
        $this->jac = University::where('code', 'JAC')->first();
        Cutoff::where('university_id', $this->jac->id)->forceDelete();
        $this->course = Course::where('university_id', $this->jac->id)->where('name', 'B.Tech')->first();
        $this->exam = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $this->process = AdmissionProcess::where('code', 'JAC')->first();
    }

    private function cutoff(string $institute, string $branchName, string $category, string $sub, string $region, string $round, int $cr, int $year = 2025): void
    {
        $inst = Institute::firstOrCreate(['university_id' => $this->jac->id, 'name' => $institute]);
        $branch = Branch::firstOrCreate(['course_id' => $this->course->id, 'name' => $branchName]);
        Cutoff::create([
            'university_id' => $this->jac->id, 'course_id' => $this->course->id,
            'qualifying_exam_id' => $this->exam->id, 'admission_process_id' => $this->process->id,
            'year' => $year, 'round' => $round, 'institute_id' => $inst->id, 'branch_id' => $branch->id,
            'shift' => null, 'region' => $region, 'category' => $category, 'sub_category' => $sub,
            'min_rank' => 0, 'max_rank' => $cr, 'source' => 'official',
        ]);
    }

    /** @test */
    public function each_institute_uses_its_own_latest_year_when_year_is_null(): void
    {
        // DTU has both 2025 (R5, looser) and 2026 (R1, tighter, latest). NSUT only 2025.
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 60000, 2025);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '1', 9000, 2026);
        $this->cutoff('NSUT Main (Dwarka)', 'Computer Science & Engineering', 'general', 'gender_neutral', 'delhi', '5', 40000, 2025);

        // year omitted -> per-institute latest
        $ctx = new PredictorContext('dtu', rank: 100000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male');
        $rows = collect((new DatasetCutoffPredictor)->predict($ctx)['rows'])->keyBy('institute');

        $this->assertSame(9000, $rows['DTU']['final_cr']);                 // DTU advanced to 2026 R1
        $this->assertSame(40000, $rows['NSUT Main (Dwarka)']['final_cr']); // NSUT still on 2025, not dropped
    }

    /** @test */
    public function ipu_uses_a_single_dataset_wide_latest_year(): void
    {
        // Per-institute-year is scoped to JAC. IPU keeps dataset-wide max year: an
        // institute with only an older year is dropped once any institute has newer.
        $ipu = University::firstOrCreate(['code' => 'IPU'], ['name' => 'IPU']);
        Cutoff::where('university_id', $ipu->id)->forceDelete();
        $course = Course::firstOrCreate(['university_id' => $ipu->id, 'name' => 'B.Tech']);
        $mk = function (string $instName, int $year, int $cr) use ($ipu, $course) {
            $inst = Institute::firstOrCreate(['university_id' => $ipu->id, 'name' => $instName]);
            $branch = Branch::firstOrCreate(['course_id' => $course->id, 'name' => 'CSE']);
            Cutoff::create([
                'university_id' => $ipu->id, 'course_id' => $course->id,
                'qualifying_exam_id' => $this->exam->id, 'admission_process_id' => $this->process->id,
                'year' => $year, 'round' => '1', 'institute_id' => $inst->id, 'branch_id' => $branch->id,
                'shift' => null, 'region' => 'delhi', 'category' => null, 'sub_category' => null,
                'min_rank' => 0, 'max_rank' => $cr, 'source' => 'official',
            ]);
        };
        $mk('Alpha College', 2026, 30000);   // newest year exists
        $mk('Beta College', 2024, 35000);     // only old year -> should be dropped

        $ctx = new PredictorContext('ipu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', courseId: $course->id);
        $names = array_column((new DatasetCutoffPredictor)->predict($ctx)['rows'], 'institute');

        $this->assertContains('Alpha College', $names);
        $this->assertNotContains('Beta College', $names); // dataset-wide max=2026 drops the 2024-only institute
    }

    /** @test */
    public function predicts_dtu_rows_with_chance_and_uses_final_round(): void
    {
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '1', 9000);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $ctx = new PredictorContext('dtu', rank: 11000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', year: 2025);
        $result = (new DatasetCutoffPredictor)->predict($ctx);

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('DTU', $row['institute']);
        $this->assertSame('Computer Science and Engineering', $row['branch']);
        $this->assertSame(11352, $row['final_cr']);
        $this->assertSame(9000, $row['r1_cr']);
        $this->assertSame('LIKELY', $row['chance']);
        $this->assertSame(1, $result['reach_count']);
    }

    /** @test */
    public function male_excludes_women_only_institute_and_female_subcategories(): void
    {
        $this->cutoff('IGDTUW', 'CSE-AI', 'general', 'gender_neutral', 'delhi', '5', 44405);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $male = new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', year: 2025);
        $names = array_column((new DatasetCutoffPredictor)->predict($male)['rows'], 'institute');
        $this->assertContains('DTU', $names);
        $this->assertNotContains('IGDTUW', $names);

        $female = new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'female', year: 2025);
        $namesF = array_column((new DatasetCutoffPredictor)->predict($female)['rows'], 'institute');
        $this->assertContains('IGDTUW', $namesF);
    }

    /** @test */
    public function predict_by_year_returns_three_views_per_option(): void
    {
        // DTU CSE: 2025 R1 6000 -> R5 12000; 2026 R1 9000. IGDTUW only 2025 (women-only).
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '1', 6000, 2025);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 12000, 2025);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '1', 9000, 2026);
        $this->cutoff('IGDTUW', 'CSE-AI', 'general', 'gender_neutral', 'delhi', '5', 44000, 2025);

        $ctx = new PredictorContext('dtu', rank: 10000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'female');
        $res = (new DatasetCutoffPredictor)->predictByYear($ctx);

        $this->assertSame(2025, $res['prior_year']);
        $this->assertSame(2026, $res['newer_year']);

        $rows = collect($res['rows'])->keyBy('branch');
        // DTU CSE: all three views present
        $this->assertSame(12000, $rows['Computer Science and Engineering']['cr_prior']);
        $this->assertSame(9000, $rows['Computer Science and Engineering']['cr_newer_r1']);
        $this->assertSame(18000, $rows['Computer Science and Engineering']['cr_newer_proj']); // 9000 * 12000/6000
        $this->assertNotNull($rows['Computer Science and Engineering']['chance_prior']);
        $this->assertNotNull($rows['Computer Science and Engineering']['chance_newer_r1']);
        // IGDTUW has no 2026 -> newer columns null, prior present
        $this->assertSame(44000, $rows['CSE-AI']['cr_prior']);
        $this->assertNull($rows['CSE-AI']['cr_newer_r1']);
        $this->assertNull($rows['CSE-AI']['chance_newer_r1']);
    }

    /** @test */
    public function predicts_ipu_rows_when_category_columns_are_null(): void
    {
        // Real imported IPU data carries NO category/sub_category (legacy source has
        // only region + shift). The predictor must not filter them out for IPU.
        $ipu = University::firstOrCreate(['code' => 'IPU'], ['name' => 'IPU']);
        Cutoff::where('university_id', $ipu->id)->forceDelete();
        $ipuCourse = Course::firstOrCreate(['university_id' => $ipu->id, 'name' => 'B.Tech']);
        $inst = Institute::firstOrCreate(['university_id' => $ipu->id, 'name' => 'USICT']);
        $branch = Branch::firstOrCreate(['course_id' => $ipuCourse->id, 'name' => 'CSE']);
        Cutoff::create([
            'university_id' => $ipu->id, 'course_id' => $ipuCourse->id,
            'qualifying_exam_id' => $this->exam->id, 'admission_process_id' => $this->process->id,
            'year' => 2026, 'round' => '1', 'institute_id' => $inst->id, 'branch_id' => $branch->id,
            'shift' => null, 'region' => 'delhi', 'category' => null, 'sub_category' => null,
            'min_rank' => 0, 'max_rank' => 30000, 'source' => 'official',
        ]);

        // Form defaults still send general/gender_neutral; predictor must ignore them for IPU.
        $ctx = new PredictorContext('ipu', rank: 25000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', courseId: $ipuCourse->id, year: 2026);
        $result = (new DatasetCutoffPredictor)->predict($ctx);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('USICT', $result['rows'][0]['institute']);
        $this->assertSame(1, $result['reach_count']);
    }

    /** @test */
    public function scopes_strictly_to_the_dataset_university(): void
    {
        $ipu = University::firstOrCreate(['code' => 'IPU'], ['name' => 'IPU']);
        $ipuCourse = Course::firstOrCreate(['university_id' => $ipu->id, 'name' => 'B.Tech']);
        $ipuInst = Institute::firstOrCreate(['university_id' => $ipu->id, 'name' => 'USICT']);
        $ipuBranch = Branch::firstOrCreate(['course_id' => $ipuCourse->id, 'name' => 'CSE']);
        Cutoff::create([
            'university_id' => $ipu->id, 'course_id' => $ipuCourse->id, 'qualifying_exam_id' => $this->exam->id,
            'admission_process_id' => $this->process->id, 'year' => 2025, 'round' => '5',
            'institute_id' => $ipuInst->id, 'branch_id' => $ipuBranch->id, 'shift' => null,
            'region' => 'delhi', 'category' => 'general', 'sub_category' => 'gender_neutral',
            'min_rank' => 0, 'max_rank' => 30000, 'source' => 'official',
        ]);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $names = array_column((new DatasetCutoffPredictor)->predict(
            new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', year: 2025)
        )['rows'], 'institute');
        $this->assertSame(['DTU'], array_values(array_unique($names)));
    }
}
