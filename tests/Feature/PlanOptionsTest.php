<?php

namespace Tests\Feature;

use App\Models\StudentField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_field_has_new_options(): void
    {
        $options = StudentField::where('key', 'plan')->value('options');
        $values = collect($options)->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            ['Sitting', 'Counselling Online', 'Counselling Offline'],
            $values
        );
    }
}
