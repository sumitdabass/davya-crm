<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $randomPw = fn () => Hash::make(bin2hex(random_bytes(8)));

        $sumit = User::updateOrCreate(
            ['email' => 'sumit@davya.local'],
            ['name' => 'Sumit', 'password' => $randomPw(),
             'is_freelancer' => false, 'is_active' => true, 'must_change_password' => true]
        );
        $sonam = User::updateOrCreate(
            ['email' => 'sonam@davya.local'],
            ['name' => 'Sonam', 'password' => $randomPw(),
             'is_freelancer' => false, 'is_active' => true, 'must_change_password' => true]
        );
        $nikhil = User::updateOrCreate(
            ['email' => 'nikhil@davya.local'],
            ['name' => 'Nikhil', 'password' => $randomPw(),
             'is_freelancer' => false, 'is_active' => true, 'must_change_password' => true]
        );

        User::updateOrCreate(
            ['email' => 'nisha@davya.local'],
            ['name' => 'Nisha', 'password' => $randomPw(),
             'team_head_id' => $nikhil->id, 'is_freelancer' => false,
             'is_active' => true, 'must_change_password' => true]
        );
        User::updateOrCreate(
            ['email' => 'poonam@davya.local'],
            ['name' => 'Poonam', 'password' => $randomPw(),
             'team_head_id' => $sonam->id, 'is_freelancer' => false,
             'is_active' => true, 'must_change_password' => true]
        );
        User::updateOrCreate(
            ['email' => 'neetu@davya.local'],
            ['name' => 'Neetu', 'password' => $randomPw(),
             'team_head_id' => $sonam->id, 'is_freelancer' => false,
             'is_active' => true, 'must_change_password' => true]
        );

        User::updateOrCreate(
            ['email' => 'kapil@davya.local'],
            ['name' => 'Kapil', 'password' => $randomPw(),
             'team_head_id' => $sumit->id, 'is_freelancer' => true,
             'is_active' => true, 'must_change_password' => true]
        );

        $sumit->syncRoles(['admin', 'head']);
        $sonam->syncRoles(['head']);
        $nikhil->syncRoles(['head']);
        User::whereIn('email', ['nisha@davya.local', 'poonam@davya.local', 'neetu@davya.local'])
            ->get()->each(fn ($u) => $u->syncRoles(['member']));
        User::where('email', 'kapil@davya.local')->first()->syncRoles(['freelancer']);
    }
}
