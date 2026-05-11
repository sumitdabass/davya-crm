<?php

namespace Tests\Feature\Books;

use App\Models\Book\Attachment;
use App\Models\Book\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_a_file_polymorphically_to_an_entry(): void
    {
        $e = Entry::factory()->create();

        $a = Attachment::create([
            'attachable_type' => $e::class,
            'attachable_id' => $e->id,
            'disk' => 'gdrive',
            'path' => 'books/test/2025-26/salary/1/file.pdf',
            'original_name' => 'salary-slip.pdf',
            'mime' => 'application/pdf',
            'size' => 12345,
            'uploaded_by' => null,
        ]);

        $this->assertSame($e->id, $a->attachable_id);
        $this->assertSame(1, $e->attachments()->count());
    }
}
