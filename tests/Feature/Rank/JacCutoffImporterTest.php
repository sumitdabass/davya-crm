<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\Cutoff;
use App\Models\Rank\University;
use App\Services\Rank\JacCutoffImporter;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JacCutoffImporterTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    protected function setUp(): void
    {
        parent::setUp();
        // The shared ranks DB may already hold imported JAC cutoffs. Clear them
        // inside the rolled-back ranks transaction so this test is isolated and
        // the real data is restored on rollback.
        $jac = University::where('code', 'JAC')->first();
        if ($jac) {
            Cutoff::where('university_id', $jac->id)->forceDelete();
        }
    }

    /** @test */
    public function imports_rows_mapping_campus_category_region_and_round(): void
    {
        $this->seed(JacDelhiSeeder::class);

        // NSUT campus comes from the branch code's asterisks (* East, ** West, none Main).
        $csv = implode("\n", [
            'institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file',
            'DTU,2025,R5,Round5 2025,Delhi,Computer Science and Engineering,General,Gender-Neutral,11352,DTU_Round5_2025.pdf',
            'NSUT,2025,R5,Round5 2025,Delhi,CSDA*,General,Gender-Neutral,28092,NSUT_Round5_2025.pdf',
            'NSUT,2025,R5,Round5 2025,Delhi,CE**,SC,Girl,200000,NSUT_Round5_2025.pdf',
            'NSUT,2025,R5,Round5 2025,Delhi,CSE,General,Gender-Neutral,6249,NSUT_Round5_2025.pdf',
            'IIITD,2025,R5,Round5 2025,Delhi,CSE,General,Gender-Neutral,9999,IIITD_Round5_2025.pdf',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'jac').'.csv';
        file_put_contents($path, $csv);

        $summary = (new JacCutoffImporter)->import($path, 2025);

        $this->assertSame(4, $summary['imported']);   // 1 DTU + 3 NSUT
        $this->assertSame(1, $summary['skipped']);     // IIITD skipped

        $dtu = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'DTU'))->first();
        $this->assertSame('5', $dtu->round);
        $this->assertSame('delhi', $dtu->region);
        $this->assertSame('general', $dtu->category);
        $this->assertSame('gender_neutral', $dtu->sub_category);
        $this->assertSame(11352, $dtu->max_rank);
        $this->assertSame(0, $dtu->min_rank);

        // NSUT campuses split by code, with full branch names.
        $east = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'NSUT East Campus'))->with('branch')->first();
        $this->assertNotNull($east);
        $this->assertSame('Computer Science & Engineering (Big Data Analytics)', $east->branch->name);

        $west = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'NSUT West Campus'))->with('branch')->first();
        $this->assertNotNull($west);
        $this->assertSame('Civil Engineering', $west->branch->name);

        $main = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'NSUT Main (Dwarka)'))->with('branch')->first();
        $this->assertNotNull($main);
        $this->assertSame('Computer Science & Engineering', $main->branch->name);

        unlink($path);
    }
}
