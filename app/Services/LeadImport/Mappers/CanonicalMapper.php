<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class CanonicalMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['phone', 'name', 'course', 'rank', 'state', 'referrer_name', 'remarks', 'source'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->clean($row['phone'] ?? null),
            'name'          => $this->clean($row['name'] ?? null),
            'course'        => $this->clean($row['course'] ?? null),
            'rank'          => $this->clean($row['rank'] ?? null),
            'state'         => $this->clean($row['state'] ?? null),
            'referrer_name' => $this->clean($row['referrer_name'] ?? null),
            'remarks'       => $this->clean($row['remarks'] ?? null),
            'source'        => $this->clean($row['source'] ?? null),
            'owner_name'    => null,
        ];
    }

    public function ownerHint(): ?string
    {
        return null;
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
