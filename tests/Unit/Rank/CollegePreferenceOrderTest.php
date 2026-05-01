<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\CollegePreferenceOrder;
use Tests\TestCase;

class CollegePreferenceOrderTest extends TestCase
{
    /** @test */
    public function it_ranks_in_the_expected_order(): void
    {
        $names = [
            'Bharati Vidyapeeth\'s College of Engineering',
            'Maharaja Agrasen Institute of Technology',
            'University School of Information & Communication Technology',
            'HMR Institute of Technology & Management',
            'Maharaja Surajmal Institute of Technology',
            'Some Random College Not In List',
            'Bhagwan Parshuram Institute of Technology',
            'Vivekananda Institute of Professional Studies',
            'Guru Teg Bahadur Institute of Technology',
            'Dr. Akhilesh Das Gupta Institute',
        ];

        usort($names, fn ($a, $b) => CollegePreferenceOrder::sortKey($a) <=> CollegePreferenceOrder::sortKey($b)
            ?: strcasecmp($a, $b));

        $this->assertSame([
            'University School of Information & Communication Technology', // 1 USICT
            'Maharaja Agrasen Institute of Technology',                    // 2 MAIT
            'Maharaja Surajmal Institute of Technology',                   // 3 MSIT
            'Bharati Vidyapeeth\'s College of Engineering',                // 4 BVP
            'Bhagwan Parshuram Institute of Technology',                   // 5 BPIT
            'Vivekananda Institute of Professional Studies',               // 6 VIPS
            'Guru Teg Bahadur Institute of Technology',                    // 7 GTBIT
            'Dr. Akhilesh Das Gupta Institute',                            // 8 Dr Akhilesh
            'HMR Institute of Technology & Management',                    // 9 HMR
            'Some Random College Not In List',                             // fallback alphabetical
        ], $names);
    }

    /** @test */
    public function unknown_colleges_get_fallback_weight(): void
    {
        $this->assertSame(999, CollegePreferenceOrder::sortKey('Foo Institute'));
        $this->assertLessThan(999, CollegePreferenceOrder::sortKey('Maharaja Agrasen Institute of Technology'));
    }
}
