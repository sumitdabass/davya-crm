<?php

namespace App\Enums;

enum PipelineStage: string
{
    case LeadCaptured = 'Lead Captured';
    case MeetingScheduled = 'Meeting Scheduled';
    case MeetingDone = 'Meeting Done';
    case AdvanceReceived = 'Advance Received';
    case Mq = 'MQ';
    case Round1 = 'Round 1';
    case Round2 = 'Round 2';
    case Round3 = 'Round 3';
    case Sliding = 'Sliding';
    case Offline = 'Offline';
    case SeatAllotted = 'Seat Allotted';
    case Closed = 'Closed';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<string,string> value => value (for Filament Select options). */
    public static function options(): array
    {
        return array_combine(self::values(), self::values());
    }

    /** @return self[] */
    public static function roundStages(): array
    {
        return [self::Round1, self::Round2, self::Round3, self::Sliding, self::Offline];
    }

    public static function fromRoundName(string $roundName): ?self
    {
        return match ($roundName) {
            'Online_R1', 'S2_R1' => self::Round1,
            'Online_R2' => self::Round2,
            'Online_R3', 'S2_R3' => self::Round3,
            'Online_Sliding', 'Online_Reporting' => self::Sliding,
            'Offline_R1', 'Offline_R2' => self::Offline,
            default => null,
        };
    }
}
