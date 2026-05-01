<?php

namespace Database\Seeders\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use Illuminate\Database\Seeder;

class RankReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $ipu = University::firstOrCreate(
            ['code' => 'IPU'],
            [
                'name' => 'Guru Gobind Singh Indraprastha University',
                'official_website' => 'https://ipu.ac.in',
                'country' => 'India',
                'state' => 'Delhi',
            ]
        );

        Course::firstOrCreate(
            ['university_id' => $ipu->id, 'name' => 'B.Tech'],
            ['code' => 'BTECH']
        );
        Course::firstOrCreate(
            ['university_id' => $ipu->id, 'name' => 'MBA'],
            ['code' => 'MBA']
        );
        Course::firstOrCreate(
            ['university_id' => $ipu->id, 'name' => 'BBA'],
            ['code' => 'BBA']
        );

        foreach ([
            ['name' => 'JEE Main', 'code' => 'JEE_MAIN'],
            ['name' => 'JEE Advanced', 'code' => 'JEE_ADV'],
            ['name' => 'CUET', 'code' => 'CUET'],
            ['name' => 'CUET-PG', 'code' => 'CUET_PG'],
            ['name' => 'IPU CET', 'code' => 'IPU_CET'],
        ] as $exam) {
            QualifyingExam::firstOrCreate(['code' => $exam['code']], ['name' => $exam['name']]);
        }

        foreach ([
            ['name' => 'Counselling', 'code' => 'COUNSELLING'],
            ['name' => 'Sliding', 'code' => 'SLIDING'],
            ['name' => 'Open Round', 'code' => 'OPEN_ROUND'],
            ['name' => 'Spot Round', 'code' => 'SPOT_ROUND'],
            ['name' => 'Offline', 'code' => 'OFFLINE'],
        ] as $process) {
            AdmissionProcess::firstOrCreate(['code' => $process['code']], ['name' => $process['name']]);
        }

        $this->command?->info('Rank reference data seeded.');
    }
}
