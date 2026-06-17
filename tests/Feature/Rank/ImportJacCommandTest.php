<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\Cutoff;
use App\Models\Rank\University;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesInMemoryRanksDatabase;
use Tests\TestCase;

class ImportJacCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesInMemoryRanksDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryRanksDatabase();
        // Isolate from any JAC cutoffs already imported into the shared ranks DB;
        // the delete rolls back with the ranks transaction.
        $jac = University::where('code', 'JAC')->first();
        if ($jac) {
            Cutoff::where('university_id', $jac->id)->forceDelete();
        }
    }

    /** @test */
    public function command_imports_csv(): void
    {
        $this->seed(JacDelhiSeeder::class);
        $csv = "institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file\n"
            ."DTU,2025,R5,x,Delhi,Computer Science and Engineering,General,Gender-Neutral,11352,f.pdf\n";
        $path = tempnam(sys_get_temp_dir(), 'jac').'.csv';
        file_put_contents($path, $csv);

        $this->artisan("rank:import-jac --file={$path} --year=2025")
            ->expectsOutputToContain('Imported')
            ->assertExitCode(0);

        $jac = University::where('code', 'JAC')->first();
        $this->assertSame(1, Cutoff::where('university_id', $jac->id)->count());
        unlink($path);
    }
}
