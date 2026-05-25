<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\SectionPage;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EntryDocumentUploadActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('books.enabled', true);
        config()->set('books.attachments_disk', 'public');
        Role::firstOrCreate(['name' => 'super_admin']);
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);
    }

    public function test_upload_documents_action_stores_file_under_entry_directory(): void
    {
        Storage::fake('public');

        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);

        $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('uploadDocuments', ['id' => $entry->id])
            ->setActionData(['files' => [$file]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, $entry->fresh()->attachments()->count());

        $attachment = $entry->attachments()->first();
        $expectedPrefix = 'books/a/2025-26/salary/'.$entry->id.'/';
        $this->assertStringStartsWith($expectedPrefix, $attachment->path,
            "Attachment path should start with {$expectedPrefix}, got {$attachment->path}");
        Storage::disk('public')->assertExists($attachment->path);
    }
}
