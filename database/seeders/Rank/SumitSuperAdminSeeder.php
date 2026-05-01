<?php

namespace Database\Seeders\Rank;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SumitSuperAdminSeeder extends Seeder
{
    private const EMAIL = 'sumitdabass@gmail.com';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Sumit Dabas',
                'password' => Hash::make('Sumit@Rank2026!'),
                'is_active' => true,
                'must_change_password' => true,
            ],
        );

        if (! $user->wasRecentlyCreated) {
            $user->is_active = true;
            $user->save();
        }

        $admin = Role::where('name', 'admin')->first();
        if ($admin && ! $user->hasRole('admin')) {
            $user->assignRole($admin);
        }

        $rankAdmin = Role::where('name', 'rank-admin')->first();
        if ($rankAdmin && ! $user->hasRole('rank-admin')) {
            $user->assignRole($rankAdmin);
        }

        if ($user->wasRecentlyCreated) {
            $this->command?->warn('Created '.self::EMAIL.' with temporary password "Sumit@Rank2026!" — must change on first login.');
        } else {
            $this->command?->info(self::EMAIL.' updated with admin + rank-admin roles.');
        }
    }
}
