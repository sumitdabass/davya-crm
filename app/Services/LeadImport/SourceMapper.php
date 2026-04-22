<?php

namespace App\Services\LeadImport;

interface SourceMapper
{
    /** Canonical header order for the downloadable template. */
    public function expectedHeaders(): array;

    /** Map one raw row (keyed by the source's header names) into a LeadIntakeService payload. */
    public function map(array $row): array;

    /** Owner name to inject if the row doesn't carry one, or null to leave unset. */
    public function ownerHint(): ?string;
}
