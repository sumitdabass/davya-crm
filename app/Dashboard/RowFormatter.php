<?php

namespace App\Dashboard;

use App\Models\Meeting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class RowFormatter
{
    public static function format(Model $row, string $key): string
    {
        return match ($key) {
            'name' => (string) ($row->name ?? '—'),
            'phone' => (string) ($row->phone ?? '—'),
            'course' => (string) ($row->course ?? '—'),
            'final_college' => (string) ($row->final_college ?? '—'),
            'lead_source' => (string) ($row->lead_source ?? '—'),
            'owner_name' => (string) ($row->owner?->name ?? '—'),
            'student_name' => $row instanceof Meeting
                ? (string) ($row->student?->name ?? '—')
                : (string) ($row->name ?? '—'),
            'created_at_time' => $row->created_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—',
            'updated_at_time' => $row->updated_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—',
            'held_at_time' => $row instanceof Meeting
                ? ($row->held_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—')
                : '—',
            'days_in_stage' => $row instanceof Student && $row->updated_at
                ? (string) (int) $row->updated_at->diffInDays(now())
                : '—',
            default => '—',
        };
    }
}
