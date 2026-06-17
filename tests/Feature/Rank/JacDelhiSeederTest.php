<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\Institute;
use App\Models\Rank\University;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesInMemoryRanksDatabase;
use Tests\TestCase;

class JacDelhiSeederTest extends TestCase
{
    use RefreshDatabase;
    use UsesInMemoryRanksDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryRanksDatabase();
    }

    /** @test */
    public function seeds_jac_university_btech_and_five_institutes(): void
    {
        $this->seed(JacDelhiSeeder::class);

        $jac = University::where('code', 'JAC')->first();
        $this->assertNotNull($jac);
        $this->assertNotNull(Course::where('university_id', $jac->id)->where('name', 'B.Tech')->first());
        $this->assertNotNull(AdmissionProcess::where('code', 'JAC')->first());

        $names = Institute::where('university_id', $jac->id)->pluck('name')->all();
        foreach (['DTU', 'NSUT Main (Dwarka)', 'NSUT East Campus', 'NSUT West Campus', 'IGDTUW'] as $n) {
            $this->assertContains($n, $names, "missing institute $n");
        }
    }
}
