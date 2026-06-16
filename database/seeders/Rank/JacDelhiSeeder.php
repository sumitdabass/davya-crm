<?php

namespace Database\Seeders\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use Illuminate\Database\Seeder;

class JacDelhiSeeder extends Seeder
{
    public function run(): void
    {
        $jac = University::firstOrCreate(
            ['code' => 'JAC'],
            ['name' => 'JAC Delhi', 'country' => 'India', 'state' => 'Delhi']
        );

        Course::firstOrCreate(['university_id' => $jac->id, 'name' => 'B.Tech'], ['code' => 'BTECH']);
        QualifyingExam::firstOrCreate(['code' => 'JEE_MAIN'], ['name' => 'JEE Main']);
        AdmissionProcess::firstOrCreate(['code' => 'JAC'], ['name' => 'JAC Delhi Counselling']);

        foreach ([
            'DTU' => 'New Delhi',
            'NSUT Main (Dwarka)' => 'New Delhi',
            'NSUT East Campus' => 'New Delhi',
            'NSUT West Campus' => 'New Delhi',
            'IGDTUW' => 'New Delhi',
        ] as $name => $city) {
            Institute::firstOrCreate(['university_id' => $jac->id, 'name' => $name], ['city' => $city]);
        }

        $this->command?->info('JAC Delhi university, B.Tech, process + institutes seeded.');
    }
}
