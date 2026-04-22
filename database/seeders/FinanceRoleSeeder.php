<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class FinanceRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('finance');
    }
}
