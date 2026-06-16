<?php

namespace Tests\Feature\Rank;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CutoffSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cutoffs_has_category_subcategory_and_extended_rounds(): void
    {
        $conn = Schema::connection('ranks');
        $this->assertTrue($conn->hasColumn('cutoffs', 'category'));
        $this->assertTrue($conn->hasColumn('cutoffs', 'sub_category'));

        $type = $conn->getConnection()
            ->selectOne("SHOW COLUMNS FROM cutoffs WHERE Field = 'round'");
        $this->assertStringContainsString("'5'", $type->Type);
        $this->assertStringContainsString("'4'", $type->Type);
    }
}
