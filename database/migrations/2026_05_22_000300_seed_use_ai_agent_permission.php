<?php
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration {
    public function up(): void {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'use ai-agent', 'guard_name' => 'web']);
    }
    public function down(): void {
        Permission::where('name', 'use ai-agent')->delete();
    }
};
