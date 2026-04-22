<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class SumitWebsiteMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'];
    }

    public function map(array $row): array
    {
        return [
            'phone'      => $this->cleanPhone($row['Phone'] ?? null),
            'name'       => $this->clean($row['Name'] ?? null),
            'email'      => $this->clean($row['Email'] ?? null),
            'course'     => $this->clean($row['Course'] ?? null),
            'rank'       => $this->clean($row['Rank'] ?? null),
            'state'      => $this->clean($row['State'] ?? null),
            'remarks'    => $this->clean($row['Message'] ?? null),
            'owner_name' => 'Sumit',
            'source'     => 'Sheet:Sumit-website',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Sumit';
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
