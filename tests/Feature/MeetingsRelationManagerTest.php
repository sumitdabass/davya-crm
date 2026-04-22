<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_head_can_create_meeting_via_relation_manager(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = Student::create([
            'name' => 'Rel Student',
            'phone' => '9888000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $nikhil->id,
            'lead_source' => 'Test',
        ]);

        Livewire::test(MeetingsRelationManager::class, [
            'ownerRecord' => $s,
            'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
        ])
        ->callTableAction('create', data: [
            'owner_id'     => $nikhil->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'mode'         => 'phone',
            'notes'        => 'Intro call',
        ])
        ->assertHasNoTableActionErrors();

        $m = Meeting::where('student_id', $s->id)->first();
        $this->assertNotNull($m);
        $this->assertSame('scheduled', $m->status, 'new meetings default to scheduled');
        $this->assertSame('phone', $m->mode);
        $this->assertSame($nikhil->id, $m->created_by_id);
    }

    public function test_head_can_mark_meeting_held(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = Student::create([
            'name' => 'Rel Student 2',
            'phone' => '9888000002',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $nikhil->id,
            'lead_source' => 'Test',
        ]);

        $m = Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addHour(),
            'mode' => 'phone',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        Livewire::test(MeetingsRelationManager::class, [
            'ownerRecord' => $s,
            'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
        ])
        ->callTableAction('markHeld', record: $m)
        ->assertHasNoTableActionErrors();

        $this->assertSame('held', $m->fresh()->status);
        $this->assertSame('Meeting Done', $s->fresh()->stage);
    }
}
