<?php

namespace App\Dashboard;

use Illuminate\Database\Eloquent\Builder;

final class DrillDownPayload
{
    /**
     * @param  array<int, array{key:string, label:string}>  $columns
     */
    public function __construct(
        public readonly string $title,
        public readonly Builder $query,
        public readonly array $columns,
        public readonly string $csvFilenamePrefix,
        public readonly ?string $viewAllHref = null,
    ) {}
}
