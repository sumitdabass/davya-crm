<?php

namespace App\Rank;

class RankDataset
{
    /** @var array<string, array{label:string, codes:array<int,string>, btech_only:bool, category_dimension:bool, per_institute_year:bool}> */
    private const MAP = [
        // IPU's legacy cutoff source carries only region + shift — no category/sub_category.
        // IPU institutes share one counselling cycle -> dataset-wide latest year.
        'ipu' => ['label' => 'IPU',  'codes' => ['IPU'], 'btech_only' => false, 'category_dimension' => false, 'per_institute_year' => false],
        // JAC institutes publish round cutoffs independently -> latest year per institute.
        'dtu' => ['label' => 'DTU',  'codes' => ['JAC'], 'btech_only' => true,  'category_dimension' => true,  'per_institute_year' => true],
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

    /** Whether this dataset's cutoffs are broken down by category / sub_category. */
    public static function hasCategoryDimension(string $token): bool
    {
        return self::MAP[$token]['category_dimension'] ?? false;
    }

    /**
     * Whether the predictor resolves the latest year per institute (true) or a
     * single dataset-wide latest year (false) when no year is requested.
     */
    public static function usesPerInstituteYear(string $token): bool
    {
        return self::MAP[$token]['per_institute_year'] ?? false;
    }
}
