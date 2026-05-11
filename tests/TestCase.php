<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force the worktree's bootstrap/app.php so Laravel resolves
     * `base_path()`, `resource_path()`, etc. against this worktree
     * rather than the main repo. Composer's autoload_psr4 already
     * points `App\\` at the worktree, but Laravel infers its base
     * path from the ClassLoader location — which lives in the main
     * repo's `vendor/` (shared via symlink). Without this override
     * the test sees worktree classes but main-repo views/storage.
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
