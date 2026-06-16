<?php

namespace App\Services\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use RuntimeException;

class JacCutoffImporter
{
    private const CATEGORY = [
        'general' => 'general', 'ews' => 'ews', 'obc' => 'obc', 'sc' => 'sc', 'st' => 'st',
    ];

    private const SUBCATEGORY = [
        'gender-neutral' => 'gender_neutral', 'girl' => 'girl', 'single-girl' => 'single_girl',
        'pwd' => 'pwd', 'defense-cw' => 'defense_cw', 'kashmiri-migrant' => 'kashmiri_migrant',
    ];

    /**
     * NSUT branch code -> [full branch name, campus]. The source CSV encodes NSUT
     * campus in the branch code's asterisks (* = East, ** = West, none = Main);
     * the institute column is just "NSUT". Mirrors the offline tool's mapping.
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const NSUT_MAP = [
        'CSAI' => ['Computer Science & Engineering (AI)', 'Main (Dwarka)'],
        'CSE'  => ['Computer Science & Engineering', 'Main (Dwarka)'],
        'CSDS' => ['Computer Science & Engineering (Data Science)', 'Main (Dwarka)'],
        'IT'   => ['Information Technology', 'Main (Dwarka)'],
        'ITNS' => ['Information Technology (Network & Info Security)', 'Main (Dwarka)'],
        'MAC'  => ['Mathematics & Computing', 'Main (Dwarka)'],
        'EVDT' => ['Electronics Engg (VLSI Design & Technology)', 'Main (Dwarka)'],
        'ECE'  => ['Electronics & Communication Engineering', 'Main (Dwarka)'],
        'EE'   => ['Electrical Engineering', 'Main (Dwarka)'],
        'ICE'  => ['Instrumentation & Control Engineering', 'Main (Dwarka)'],
        'ME'   => ['Mechanical Engineering', 'Main (Dwarka)'],
        'BT'   => ['Bio-Technology', 'Main (Dwarka)'],
        'CSDA' => ['Computer Science & Engineering (Big Data Analytics)', 'East Campus'],
        'CIOT' => ['Computer Science & Engineering (IoT)', 'East Campus'],
        'ECAM' => ['Electronics & Comm Engg (AI & ML)', 'East Campus'],
        'MEEV' => ['Mechanical Engineering (Electric Vehicles)', 'West Campus'],
        'CE'   => ['Civil Engineering', 'West Campus'],
        'GI'   => ['Geoinformatics', 'West Campus'],
    ];

    /**
     * Resolve the CSV (institute, branch) cells to the seeded institute name and
     * a clean branch name. NSUT rows get campus + full name from the branch code;
     * other institutes (DTU, IGDTUW) pass through unchanged.
     *
     * @return array{0:string,1:string} [instituteName, branchName]
     */
    private function resolve(string $instRaw, string $branchRaw): array
    {
        $inst = trim($instRaw);
        $branch = trim($branchRaw);

        if (strtolower($inst) === 'nsut') {
            $stripped = preg_replace('/^Branch\s+/i', '', $branch);
            $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $stripped));
            if (isset(self::NSUT_MAP[$code])) {
                [$full, $campus] = self::NSUT_MAP[$code];

                return ['NSUT '.$campus, $full];
            }

            return ['NSUT Main (Dwarka)', $stripped];
        }

        return [$inst, $branch];
    }

    /**
     * @return array{imported:int, skipped:int}
     */
    public function import(string $path, int $year): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV not readable: {$path}");
        }

        $jac = University::where('code', 'JAC')->firstOrFail();
        $course = Course::where('university_id', $jac->id)->where('name', 'B.Tech')->firstOrFail();
        $exam = QualifyingExam::where('code', 'JEE_MAIN')->firstOrFail();
        $process = AdmissionProcess::where('code', 'JAC')->firstOrFail();

        $institutes = Institute::where('university_id', $jac->id)->get()->keyBy('name');

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip($header);

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $instRaw = $row[$idx['institute']] ?? '';
            $branchName = trim($row[$idx['branch']] ?? '');

            if (strtoupper($instRaw) === 'IIITD' || stripos($branchName, 'arch') !== false) {
                $skipped++;
                continue;
            }

            [$instName, $branchName] = $this->resolve($instRaw, $branchName);
            $institute = $institutes->get($instName);
            if (! $institute) {
                $institute = Institute::create(['university_id' => $jac->id, 'name' => $instName]);
                $institutes->put($instName, $institute);
            }

            $branch = Branch::firstOrCreate(['course_id' => $course->id, 'name' => $branchName]);

            $round = ltrim((string) ($row[$idx['round']] ?? ''), 'Rr');
            $region = str_contains(strtolower($row[$idx['region']] ?? ''), 'outside')
                ? 'outside_delhi' : 'delhi';
            $category = self::CATEGORY[strtolower(trim($row[$idx['category']] ?? ''))] ?? null;
            $sub = self::SUBCATEGORY[strtolower(trim($row[$idx['sub_category']] ?? ''))] ?? null;
            $closing = (int) ($row[$idx['closing_rank']] ?? 0);
            if ($closing <= 0 || ! in_array($round, ['1', '2', '3', '4', '5'], true)) {
                $skipped++;
                continue;
            }

            Cutoff::updateOrCreate(
                [
                    'university_id' => $jac->id, 'course_id' => $course->id,
                    'qualifying_exam_id' => $exam->id, 'admission_process_id' => $process->id,
                    'year' => $year, 'round' => $round,
                    'institute_id' => $institute->id, 'branch_id' => $branch->id,
                    'shift' => null, 'region' => $region,
                    'category' => $category, 'sub_category' => $sub,
                ],
                ['min_rank' => 0, 'max_rank' => $closing, 'source' => 'official']
            );
            $imported++;
        }
        fclose($fh);

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
