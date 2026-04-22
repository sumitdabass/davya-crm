<?php

namespace App\Services\LeadImport;

class ImportAction
{
    public const CREATE = 'create';
    public const MERGE  = 'merge';   // demote existing, insert new
    public const FLAG   = 'flag';    // head-vs-head conflict
    public const REJECT = 'reject';  // duplicate, same/lower tier

    public function __construct(
        public readonly string $action,
        public readonly array $mappedPayload,
        public readonly ?int $existingStudentId = null,
        public readonly ?string $reason = null,
        public readonly ?int $rowNumber = null,
    ) {}

    public static function create(array $payload, ?int $rowNumber = null): self
    {
        return new self(self::CREATE, $payload, rowNumber: $rowNumber);
    }

    public static function merge(array $payload, int $existingId, ?int $rowNumber = null): self
    {
        return new self(self::MERGE, $payload, existingStudentId: $existingId, rowNumber: $rowNumber);
    }

    public static function flag(array $payload, int $existingId, ?int $rowNumber = null): self
    {
        return new self(self::FLAG, $payload, existingStudentId: $existingId, rowNumber: $rowNumber);
    }

    public static function reject(array $payload, string $reason, ?int $existingId = null, ?int $rowNumber = null): self
    {
        return new self(self::REJECT, $payload, existingStudentId: $existingId, reason: $reason, rowNumber: $rowNumber);
    }
}
