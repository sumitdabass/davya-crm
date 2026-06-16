<?php

namespace App\Services\Rank;

class PredictorContext
{
    /**
     * @param  array<int,int>|null  $branchIds
     */
    public function __construct(
        public string $datasetToken,
        public int $rank,
        public string $region = 'delhi',
        public string $category = 'general',
        public ?string $subCategory = null,
        public ?string $gender = null,
        public ?int $courseId = null,
        public ?int $year = null,
        public ?array $branchIds = null,
    ) {}

    public function isGeneral(): bool
    {
        return mb_strtolower(trim($this->category)) === 'general';
    }

    public function isMale(): bool
    {
        return mb_strtolower((string) $this->gender) === 'male';
    }
}
