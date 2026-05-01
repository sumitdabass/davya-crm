<?php

namespace Tests\Unit\Rank;

use App\Filament\Pages\Rank\BranchFamilies;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use Tests\TestCase;

class BranchFamiliesTest extends TestCase
{
    /** @test */
    public function it_lists_all_5_families(): void
    {
        $families = BranchFamilies::all();

        $this->assertSame(
            ['cs_it', 'electronics', 'mechanical', 'civil_arch', 'chem_energy'],
            array_keys($families),
        );
        $this->assertSame('Computer / IT', $families['cs_it']);
    }

    /** @test */
    public function it_matches_branch_names_to_families(): void
    {
        $cases = [
            'Computer Science & Engineering' => 'cs_it',
            'Computer Science & Engineering - AIML' => 'cs_it',
            'CSE-DS' => 'cs_it',
            'Information Technology' => 'cs_it',
            'AI & Data Science' => 'cs_it',
            'Electronics & Communication Engineering' => 'electronics',
            'Electrical & Electronics Engineering' => 'electronics',
            'VLSI Design & Technology' => 'electronics',
            'Industrial Internet of Things' => 'electronics',
            'Mechanical Engineering' => 'mechanical',
            'Mechatronics' => 'mechanical',
            'Automation & Robotics' => 'mechanical',
            'Civil Engineering' => 'civil_arch',
            'B.Tech. (Architecture & interior Decoration)' => 'civil_arch',
            'Chemical Engineering' => 'chem_energy',
            'B. Tech. (Energy)' => 'chem_energy',
            'Some random new branch' => null,
        ];

        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function expand_resolves_family_keys_into_branch_ids_for_a_course(): void
    {
        $course = Course::where('name', 'B.Tech')->firstOrFail();

        $ids = BranchFamilies::expandToBranchIds(['cs_it'], $course->id);
        $this->assertNotEmpty($ids);
        $branchNames = Branch::whereIn('id', $ids)->pluck('name')->all();
        $this->assertTrue(collect($branchNames)->contains(fn ($n) => str_contains(strtolower($n), 'computer science')));
        $this->assertFalse(collect($branchNames)->contains(fn ($n) => str_contains(strtolower($n), 'mechanical')));
    }
}
