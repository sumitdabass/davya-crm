<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\IpuPredict;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpuPredictPageTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['sqlite', 'ranks'];

    protected function setUp(): void
    {
        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @test */
    public function ipu_predict_access_follows_ipu_role(): void
    {
        $this->seed(RankRoleSeeder::class);

        $ipuUser = User::factory()->create();
        $ipuUser->assignRole('rank-ipu-predict');
        $this->actingAs($ipuUser);
        $this->assertTrue(IpuPredict::canAccess());

        $dtuUser = User::factory()->create();
        $dtuUser->assignRole('rank-dtu-predict');
        $this->actingAs($dtuUser);
        $this->assertFalse(IpuPredict::canAccess());
    }

    /** @test */
    public function ipu_predict_uses_ipu_dataset_token(): void
    {
        $this->assertSame('ipu', (new \ReflectionMethod(IpuPredict::class, 'datasetToken'))
            ->invoke(new IpuPredict));
    }
}
