<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            UsersSeeder::class,
            FinanceRoleSeeder::class,
            \Database\Seeders\Rank\RankRoleSeeder::class,
            \Database\Seeders\Rank\RankReferenceDataSeeder::class,
            \Database\Seeders\Rank\SumitSuperAdminSeeder::class,
        ]);
    }
}
