<?php

namespace App\Services\LeadImport;

class ImportPreview
{
    /**
     * @param array<int, ImportAction> $actions
     */
    public function __construct(
        public readonly string $source,
        public readonly array $actions,
    ) {}

    /** @return array<int, ImportAction> */
    public function byAction(string $action): array
    {
        return array_values(array_filter($this->actions, fn (ImportAction $a) => $a->action === $action));
    }

    public function countBy(string $action): int
    {
        return count($this->byAction($action));
    }

    public function rowCount(): int
    {
        return count($this->actions);
    }
}
