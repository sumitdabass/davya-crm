<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentNotesTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $this->student = Student::create([
            'phone' => '9100009999', 'name' => 'Ankit',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil', 'stage' => 'Meeting Scheduled',
        ]);
    }

    public function test_student_notes_relationship_returns_notes_ordered_newest_first(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $older = StudentNote::create([
            'student_id' => $this->student->id, 'author_id' => $nisha->id,
            'body' => 'older', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        $newer = StudentNote::create([
            'student_id' => $this->student->id, 'author_id' => $nisha->id,
            'body' => 'newer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $notes = $this->student->fresh()->notes;

        $this->assertSame($newer->id, $notes->first()->id);
        $this->assertSame($older->id, $notes->last()->id);
    }

    public function test_admin_can_create_note_via_relation_manager(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => \App\Filament\Resources\StudentResource\Pages\EditStudent::class])
            ->callTableAction('create', data: ['body' => 'admin added this', 'author_id' => $sumit->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('student_notes', [
            'student_id' => $this->student->id,
            'author_id'  => $sumit->id,
            'body'       => 'admin added this',
        ]);
    }

    public function test_member_can_create_note_on_own_student(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        // Ensure student is owned by Nisha for this test
        $this->student->update(['owner_id' => $nisha->id]);

        $this->actingAs($nisha);

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->student->fresh(), 'pageClass' => \App\Filament\Resources\StudentResource\Pages\EditStudent::class])
            ->callTableAction('create', data: ['body' => 'nisha said hi', 'author_id' => $nisha->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('student_notes', [
            'author_id' => $nisha->id,
            'body'      => 'nisha said hi',
        ]);
    }

    public function test_cascades_delete_notes_when_student_is_deleted(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        StudentNote::create([
            'student_id' => $this->student->id, 'author_id' => $nisha->id, 'body' => 'bye',
        ]);
        $this->assertSame(1, StudentNote::count());

        $this->student->delete();

        $this->assertSame(0, StudentNote::count());
    }
}
