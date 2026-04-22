<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class NikhilMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->cleanPhone($row['Phone'] ?? null),
            'name'          => $this->clean($row['Name'] ?? null),
            'course'        => $this->clean($row['Course'] ?? null),
            'rank'          => $this->clean($row['Rank'] ?? null),
            'state'         => $this->clean($row['State'] ?? null),
            'referrer_name' => $this->clean($row['Referrer'] ?? null),
            'remarks'       => $this->clean($row['Remarks'] ?? null),
            'owner_name'    => 'Nikhil',
            'source'        => 'Sheet:Nikhil',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Nikhil';
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/', ' ', trim($v));
        return $v === '' ? null : $v;
    }

    private function cleanPhone(?string $v): ?string
    {
        if ($v === null) return null;
        $digits = preg_replace('/\D+/', '', $v);
        return $digits === '' ? null : $digits;
    }
}
