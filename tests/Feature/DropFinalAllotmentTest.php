<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropFinalAllotmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_are_dropped(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'final_college'));
        $this->assertFalse(Schema::hasColumn('students', 'final_course'));
        $this->assertFalse(Schema::hasColumn('students', 'admission_date'));
    }

    public function test_global_search_attributes_do_not_include_final_college(): void
    {
        $this->assertNotContains('final_college', \App\Filament\Resources\StudentResource::getGloballySearchableAttributes());
    }
}
