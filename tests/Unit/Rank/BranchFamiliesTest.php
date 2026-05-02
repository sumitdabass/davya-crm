<?php

namespace Tests\Unit\Rank;

use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Services\Rank\BranchFamilies;
use Tests\TestCase;

class BranchFamiliesTest extends TestCase
{
    /** @test */
    public function it_lists_all_families_in_display_order(): void
    {
        $families = BranchFamilies::all();

        $this->assertSame(
            ['cs', 'it', 'aiml', 'aids', 'iiot', 'ece', 'eee', 'instrumentation', 'mechanical', 'civil_arch', 'chem_energy'],
            array_keys($families),
        );
    }

    /** @test */
    public function cs_family_includes_all_cse_variants_but_not_it_or_standalone_ai(): void
    {
        $cases = [
            'Computer Science & Engineering'                          => 'cs',
            'Computer Science & Engineering - AI'                     => 'cs',
            'Computer Science & Engineering - AIML'                   => 'cs',
            'Computer Science & Engineering - Data Science'           => 'cs',
            'Computer Science & Engineering - DS'                     => 'cs',
            'Computer Science & Engineering - Cyber Security'         => 'cs',
            'Computer Science & Engineering (Dual Degree)'            => 'cs',
            'CSE-AI'                                                  => 'cs',
            'CSE-AIML'                                                => 'cs',
            'CSE-DS'                                                  => 'cs',
            'CSE (Cyber Security)'                                    => 'cs',
            'CS'                                                      => 'cs',
            'B.Tech (CS)'                                             => 'cs',
            'B. Tech. (Computer Science)'                             => 'cs',
            'B. Tech. (Computer Science & Applied Mathematics)'       => 'cs',
            'B.Tech. CSE (IOT) & CS including Blockchain Technology'  => 'cs',
            'Computer Science & Technology'                           => 'cs',
        ];
        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function it_aiml_aids_iiot_are_each_their_own_family(): void
    {
        $cases = [
            'Information Technology'                          => 'it',
            'Information Technology (Dual Degree)'            => 'it',
            'Information Technology & Engineering'            => 'it',
            'Artificial Intelligence & Machine Learning'      => 'aiml',
            'Artificial Intelligence & Data Science'          => 'aids',
            'Industrial Internet of Things'                   => 'iiot',
        ];
        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function ece_family_keeps_all_communication_and_vlsi_variants(): void
    {
        $cases = [
            'Electronics & Communication Engineering'              => 'ece',
            'Electronics & Communication Engineering (Dual Degree)' => 'ece',
            'Electronics & Comm.- Advance Comm. Technology'         => 'ece',
            'Electronics & Comm.-Advance Comm. Technology'          => 'ece',
            'Electronics Engg.- VLSI Design & Technology'           => 'ece',
            'Electronics Engg.-VLSI Design & Technology'            => 'ece',
            'ECE'                                                   => 'ece',
            'ECE 1st Shift'                                         => 'ece',
            'ECE 2nd Shift'                                         => 'ece',
            'ECE-VLSI'                                              => 'ece',
        ];
        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function eee_and_ee_collapse_into_one_family_separate_from_ece(): void
    {
        $this->assertSame('eee', BranchFamilies::familyFor('Electrical & Electronics Engineering'));
        $this->assertSame('eee', BranchFamilies::familyFor('EEE'));
        $this->assertSame('eee', BranchFamilies::familyFor('Electrical Engineering (EE)'));
        // ECE must NOT match the EEE family.
        $this->assertNotSame('eee', BranchFamilies::familyFor('Electronics & Communication Engineering'));
    }

    /** @test */
    public function the_remaining_families_match_their_expected_branches(): void
    {
        $cases = [
            'Instrumentation & Control Engineering'         => 'instrumentation',
            'Mechanical Engineering'                         => 'mechanical',
            'Mechanical & Automation Engineering'            => 'mechanical',
            'Mechatronics'                                   => 'mechanical',
            'Automation & Robotics'                          => 'mechanical',
            'B.Tech. (Robotics & Artificial Intelligence)'   => 'mechanical',
            'Civil Engineering'                              => 'civil_arch',
            'B.Tech. (Architecture & interior Decoration)'   => 'civil_arch',
            'B.Tech. (3D Modelling & Animation)'             => 'civil_arch',
            'Chemical Engineering (Dual Degree)'             => 'chem_energy',
            'Bio-chemical Engineering (Dual Degree)'         => 'chem_energy',
            'B. Tech. (Energy)'                              => 'chem_energy',
            'B.Tech. (Nanoscience & Technology)'             => 'chem_energy',
            'Some random new branch'                         => null,
        ];
        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function expand_resolves_family_keys_into_branch_ids_for_a_course(): void
    {
        $course = Course::where('name', 'B.Tech')->firstOrFail();

        $cs = BranchFamilies::expandToBranchIds(['cs'], $course->id);
        $this->assertNotEmpty($cs);
        $csNames = Branch::whereIn('id', $cs)->pluck('name')->all();
        $this->assertTrue(collect($csNames)->contains(fn ($n) => str_contains(strtolower($n), 'computer science')));
        // IT must not be lumped into cs.
        $this->assertFalse(collect($csNames)->contains(fn ($n) => $n === 'Information Technology'));

        // ECE and EEE land in different buckets even within the same course.
        $eceIds = BranchFamilies::expandToBranchIds(['ece'], $course->id);
        $eeeIds = BranchFamilies::expandToBranchIds(['eee'], $course->id);
        $this->assertNotEmpty($eceIds);
        $this->assertNotEmpty($eeeIds);
        $this->assertEmpty(array_intersect($eceIds, $eeeIds), 'ECE and EEE share branch ids');
    }
}
