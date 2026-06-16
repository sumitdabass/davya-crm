<?php

namespace Tests\Feature\Rank;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentRankFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function students_have_gender_and_reservation_category(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'gender'));
        $this->assertTrue(Schema::hasColumn('students', 'reservation_category'));
    }
}
