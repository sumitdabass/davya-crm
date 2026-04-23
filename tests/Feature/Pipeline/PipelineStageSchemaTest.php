<?php

namespace Tests\Feature\Pipeline;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PipelineStageSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipelines_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('pipelines'));
        foreach (['id','name','icon','record_label','is_default','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('pipelines', $col), "missing column $col");
        }
    }

    public function test_stages_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('stages'));
        foreach (['id','pipeline_id','name','description','stage_type','display_order','color','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('stages', $col), "missing column $col");
        }
    }

    public function test_stage_transition_rules_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('stage_transition_rules'));
        foreach (['id','pipeline_id','name','from_stage_id','to_stage_id','severity','is_active','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('stage_transition_rules', $col), "missing $col");
        }
    }

    public function test_stage_transition_conditions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('stage_transition_conditions'));
        foreach (['id','rule_id','condition_type','field_or_relation','operator','value','display_order'] as $col) {
            $this->assertTrue(Schema::hasColumn('stage_transition_conditions', $col), "missing $col");
        }
    }
}
