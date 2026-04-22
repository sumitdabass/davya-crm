<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentResourceQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->sumit = User::where('email', 'sumit@davya.local')->first();
        $this->nikhil = User::where('email', 'nikhil@davya.local')->first();
        $this->nisha = User::where('email', 'nisha@davya.local')->first();
        $this->sonam = User::where('email', 'sonam@davya.local')->first();
        $this->poonam = User::where('email', 'poonam@davya.local')->first();
        $this->kapil = User::where('email', 'kapil@davya.local')->first();

        Student::create(['phone' => '9100000001', 'name' => 'SumitOwned', 'owner_id' => $this->sumit->id, 'referrer_id' => $this->sumit->id, 'lead_source' => 'Sumit']);
        Student::create(['phone' => '9100000002', 'name' => 'NikhilOwned', 'owner_id' => $this->nikhil->id, 'referrer_id' => $this->nikhil->id, 'lead_source' => 'Nikhil']);
        Student::create(['phone' => '9100000003', 'name' => 'NishaOwned', 'owner_id' => $this->nisha->id, 'referrer_id' => $this->nisha->id, 'lead_source' => 'Nisha']);
        Student::create(['phone' => '9100000004', 'name' => 'PoonamOwned', 'owner_id' => $this->poonam->id, 'referrer_id' => $this->poonam->id, 'lead_source' => 'Poonam']);
        Student::create(['phone' => '9100000005', 'name' => 'KapilOwned', 'owner_id' => $this->kapil->id, 'referrer_id' => $this->kapil->id, 'lead_source' => 'Kapil']);
    }

    public function test_admin_sees_all_students(): void
    {
        $this->actingAs($this->sumit);
        $names = StudentResource::getEloquentQuery()->pluck('name')->sort()->values()->all();
        $this->assertEquals(
            ['KapilOwned', 'NikhilOwned', 'NishaOwned', 'PoonamOwned', 'SumitOwned'],
            $names,
        );
    }

    public function test_head_sees_own_team_plus_self(): void
    {
        $this->actingAs($this->nikhil);
        $names = StudentResource::getEloquentQuery()->pluck('name')->sort()->values()->all();
        $this->assertEquals(['NikhilOwned', 'NishaOwned'], $names);
    }

    public function test_member_shares_team_visibility_with_head(): void
    {
        // Members now share team visibility with their head (Nisha is on Nikhil's team).
        $this->actingAs($this->nisha);
        $names = StudentResource::getEloquentQuery()->pluck('name')->sort()->values()->all();
        $this->assertEquals(['NikhilOwned', 'NishaOwned'], $names);
    }

    public function test_freelancer_sees_only_own(): void
    {
        $this->actingAs($this->kapil);
        $names = StudentResource::getEloquentQuery()->pluck('name')->all();
        $this->assertEquals(['KapilOwned'], $names);
    }

    private User $sumit;
    private User $nikhil;
    private User $nisha;
    private User $sonam;
    private User $poonam;
    private User $kapil;
}
