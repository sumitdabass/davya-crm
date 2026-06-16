<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\Cutoff;
use App\Services\Rank\JacCutoffImporter;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JacCutoffImporterTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    /** @test */
    public function imports_rows_mapping_campus_category_region_and_round(): void
    {
        $this->seed(JacDelhiSeeder::class);

        $csv = implode("\n", [
            'institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file',
            'DTU,2025,R5,Round5 2025,Delhi,Computer Science and Engineering,General,Gender-Neutral,11352,DTU_Round5_2025.pdf',
            'NSUT,2025,R5,Round5 2025,Outside-Delhi,Civil Engineering,SC,Girl,200000,NSUT_Round5_2025.pdf',
            'IIITD,2025,R5,Round5 2025,Delhi,CSE,General,Gender-Neutral,9999,IIITD_Round5_2025.pdf',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'jac').'.csv';
        file_put_contents($path, $csv);

        $summary = (new JacCutoffImporter)->import($path, 2025);

        $this->assertSame(2, $summary['imported']);   // IIITD skipped
        $this->assertSame(1, $summary['skipped']);

        $dtu = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'DTU'))->first();
        $this->assertSame('5', $dtu->round);
        $this->assertSame('delhi', $dtu->region);
        $this->assertSame('general', $dtu->category);
        $this->assertSame('gender_neutral', $dtu->sub_category);
        $this->assertSame(11352, $dtu->max_rank);
        $this->assertSame(0, $dtu->min_rank);

        $this->assertNotNull(
            Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'NSUT Main (Dwarka)'))->first()
        );

        unlink($path);
    }
}
