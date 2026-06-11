<?php

namespace App\Today;

class SectionRegistry
{
    /**
     * Presentation descriptors for the Today checklist's list-card sections.
     * `icon` is a heroicon name; `urgent` flips the section to vermilion accent.
     *
     * @return array<string, array{label:string, icon:string, urgent:bool}>
     */
    public static function all(): array
    {
        return [
            'today_meetings' => ['label' => 'Meetings today',      'icon' => 'heroicon-o-calendar-days', 'urgent' => false],
            'payments_to_chase' => ['label' => 'Payments to chase',   'icon' => 'heroicon-o-credit-card',   'urgent' => true],
            'today_payments' => ['label' => 'Received today',       'icon' => 'heroicon-o-banknotes',     'urgent' => false],
            'stuck_leads' => ['label' => 'Stuck leads',          'icon' => 'heroicon-o-clock',         'urgent' => false],
            'seat_fee_pending' => ['label' => 'Seat-fee pending',     'icon' => 'heroicon-o-academic-cap',  'urgent' => true],
            're_entry_candidates' => ['label' => 'Re-entry candidates',  'icon' => 'heroicon-o-arrow-path',    'urgent' => false],
        ];
    }

    /** @return array{label:string, icon:string, urgent:bool}|null */
    public static function descriptor(string $cardId): ?array
    {
        return self::all()[$cardId] ?? null;
    }
}
