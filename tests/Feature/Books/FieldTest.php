<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\Field;
use App\Models\Book\FieldValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_custom_field_scoped_to_a_section(): void
    {
        $c = Company::factory()->create();
        // Use the salary section auto-seeded by CompanyObserver.
        $s = $c->sections()->where('slug', 'salary')->firstOrFail();

        $f = Field::create([
            'company_id' => $c->id,
            'section_id' => $s->id,
            'key' => 'custom_pan',
            'label' => 'Custom PAN',
            'type' => 'text',
            'sort_order' => 99,
            'show_in_table' => true,
        ]);

        $this->assertSame('salary', $f->section->slug);
    }

    public function test_stores_a_text_value_for_an_entry_field_pair(): void
    {
        $c = Company::factory()->create();
        $s = $c->sections()->where('slug', 'salary')->firstOrFail();
        $e = Entry::factory()->create([
            'company_id' => $c->id,
            'section_id' => $s->id,
        ]);
        $f = Field::create([
            'company_id' => $c->id,
            'section_id' => $s->id,
            'key' => 'custom_pan',
            'label' => 'Custom PAN',
            'type' => 'text',
            'sort_order' => 99,
        ]);

        $v = FieldValue::create([
            'entry_id' => $e->id,
            'field_id' => $f->id,
            'value_text' => 'ABCDE1234F',
        ]);

        $this->assertSame('ABCDE1234F', $v->value_text);
    }

    public function test_enforces_unique_entry_field_pair(): void
    {
        $c = Company::factory()->create();
        $s = $c->sections()->where('slug', 'salary')->firstOrFail();
        $e = Entry::factory()->create([
            'company_id' => $c->id,
            'section_id' => $s->id,
        ]);
        $f = Field::create([
            'company_id' => $c->id,
            'section_id' => $s->id,
            'key' => 'custom_pan',
            'label' => 'Custom PAN',
            'type' => 'text',
            'sort_order' => 99,
        ]);

        FieldValue::create([
            'entry_id' => $e->id,
            'field_id' => $f->id,
            'value_text' => 'ABCDE1234F',
        ]);

        $this->expectException(QueryException::class);

        FieldValue::create([
            'entry_id' => $e->id,
            'field_id' => $f->id,
            'value_text' => 'ZZZZZ9999Z',
        ]);
    }
}
