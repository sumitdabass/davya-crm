<?php

namespace App\Rank;

class RankDataset
{
    /** @var array<string, array{label:string, codes:array<int,string>, btech_only:bool}> */
    private const MAP = [
        'ipu' => ['label' => 'IPU',  'codes' => ['IPU'], 'btech_only' => false],
        'dtu' => ['label' => 'DTU',  'codes' => ['JAC'], 'btech_only' => true],
    ];

    /** @return array<int,string> */
    public static function tokens(): array
    {
        return array_keys(self::MAP);
    }

    /** @return array<int,string> */
    public static function universityCodes(string $token): array
    {
        return self::MAP[$token]['codes'] ?? [];
    }

    public static function label(string $token): string
    {
        return self::MAP[$token]['label'] ?? strtoupper($token);
    }

    public static function courseFixedToBtech(string $token): bool
    {
        return self::MAP[$token]['btech_only'] ?? false;
    }
}
