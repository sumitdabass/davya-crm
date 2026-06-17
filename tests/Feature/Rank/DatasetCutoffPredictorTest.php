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
use Tests\TestCase;

class DatasetCutoffPredictorTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    private University $jac;

    private Course $course;

    private QualifyingExam $exam;

    private AdmissionProcess $process;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(JacDelhiSeeder::class);
        $this->jac = University::where('code', 'JAC')->first();
        Cutoff::where('university_id', $this->jac->id)->forceDelete();
        $this->course = Course::where('university_id', $this->jac->id)->where('name', 'B.Tech')->first();
        $this->exam = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $this->process = AdmissionProcess::where('code', 'JAC')->first();
    }

    private function cutoff(string $institute, string $branchName, string $category, string $sub, string $region, string $round, int $cr): void
    {
        $inst = Institute::firstOrCreate(['university_id' => $this->jac->id, 'name' => $institute]);
        $branch = Branch::firstOrCreate(['course_id' => $this->course->id, 'name' => $branchName]);
        Cutoff::create([
            'university_id' => $this->jac->id, 'course_id' => $this->course->id,
            'qualifying_exam_id' => $this->exam->id, 'admission_process_id' => $this->process->id,
            'year' => 2025, 'round' => $round, 'institute_id' => $inst->id, 'branch_id' => $branch->id,
            'shift' => null, 'region' => $region, 'category' => $category, 'sub_category' => $sub,
            'min_rank' => 0, 'max_rank' => $cr, 'source' => 'official',
        ]);
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
