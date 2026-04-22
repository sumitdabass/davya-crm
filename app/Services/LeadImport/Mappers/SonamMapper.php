<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class SonamMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Date', 'Ph no', 'Course', 'Rank', 'D/OD', 'enquiry', 'connected to.'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->cleanPhone($row['Ph no'] ?? null),
            'course'        => $this->clean($row['Course'] ?? null),
            'rank'          => $this->clean($row['Rank'] ?? null),
            'category'      => $this->clean($row['D/OD'] ?? null),
            'remarks'       => $this->clean($row['enquiry'] ?? null),
            'referrer_name' => $this->clean($row['connected to.'] ?? null),
            'owner_name'    => 'Sonam',
            'source'        => 'Sheet:Sonam',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Sonam';
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
