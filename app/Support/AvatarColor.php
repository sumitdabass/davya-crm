<?php

namespace App\Support;

class AvatarColor
{
    /**
     * Stable palette for owner/avatar circles. 8 colours — roughly balanced.
     */
    private const PALETTE = [
        '#10B981', // emerald
        '#8B5CF6', // violet
        '#F59E0B', // amber
        '#3B82F6', // blue
        '#EF4444', // red
        '#06B6D4', // cyan
        '#EC4899', // pink
        '#6366F1', // indigo
    ];

    public static function forUserId(int $userId): string
    {
        return self::PALETTE[$userId % count(self::PALETTE)];
    }

    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }
}
