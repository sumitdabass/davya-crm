<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFieldsConfigPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_accessible_to_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);
        $this->assertTrue(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_is_blocked_for_non_admin(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'counsellor']);
        $user->assignRole('counsellor');
        $this->actingAs($user);
        $this->assertFalse(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_renders_for_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin)->get('/admin/student-fields')->assertOk();
    }

    public function test_page_does_not_define_getRules_method(): void
    {
        // BasePage::getRules() exists; the gotcha is shadowing it on the subclass.
        // Check the declaring class is NOT our page (i.e. we did not override it).
        $reflection = new \ReflectionMethod(StudentFieldsConfigPage::class, 'getRules');
        $this->assertNotSame(
            StudentFieldsConfigPage::class,
            $reflection->getDeclaringClass()->getName(),
            'Defining getRules() shadows Filament BasePage::getRules() and triggers fatal LSP error (SP#1 gotcha).'
        );
    }
}
