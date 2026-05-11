<?php

namespace Tests\Feature\Books;

use App\Models\Book\Attachment;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EntryAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_uploaded_file_to_entry(): void
    {
        Storage::fake('public');

        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Vendor X']);

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');
        $path = $file->store('books/test', 'public');

        Attachment::create([
            'attachable_type' => $entry::class, 'attachable_id' => $entry->id,
            'disk' => 'public', 'path' => $path, 'original_name' => 'invoice.pdf',
            'mime' => 'application/pdf', 'size' => 100, 'uploaded_by' => null,
        ]);

        $this->assertSame(1, $entry->fresh()->attachments()->count());
        Storage::disk('public')->assertExists($path);
    }
}
