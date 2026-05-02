<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $sumit = User::where('email', 'sumitdabass@gmail.com')->first();
        if ($sumit && ! $sumit->hasRole('super_admin')) {
            $sumit->assignRole($role);
        }
    }

    public function down(): void
    {
        // Don't remove the super_admin assignment from sumitdabass on rollback
        // — rollbacks should be recoverable, not lockout-inducing.
        $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->first();
        if ($role && $role->users()->doesntExist()) {
            $role->delete();
        }
    }
};
