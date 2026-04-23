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
}
