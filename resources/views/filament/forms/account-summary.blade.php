@php
    /** @var \App\Models\Student|null $record */
    $record = $getRecord();
    if (! $record) {
        return;
    }

    $payments = \App\Models\Payment::where('student_id', $record->id)
        ->orderByDesc('received_at')
        ->limit(10)
        ->get();

    $notes = \App\Models\StudentNote::where('student_id', $record->id)
        ->with('author:id,name')
        ->orderByDesc('created_at')
        ->limit(10)
        ->get();

    $timeline = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', \App\Models\Student::class)
        ->where('subject_id', $record->id)
        ->with('causer:id,name')
        ->latest()
        ->limit(15)
        ->get();

    $sectionStyle = 'border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; padding: 12px 14px; margin-top: 12px;';
    $headerStyle = 'font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;';
    $emptyStyle = 'color: #9ca3af; font-size: 12px; text-align: center; padding: 12px;';
@endphp

<div>
    {{-- Payments --}}
    <div style="{{ $sectionStyle }}">
        <div style="{{ $headerStyle }}">
            <span>Payments</span>
            <span style="color: #6b7280; font-weight: 500; font-size: 11px;">{{ count($payments) }} recent</span>
        </div>
        @if ($payments->isEmpty())
            <div style="{{ $emptyStyle }}">No payments yet — use "+ New Payment" above.</div>
        @else
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <thead style="color: #6b7280;">
                    <tr style="border-bottom: 1px solid #f3f4f6; text-align: left;">
                        <th style="padding: 6px 4px; font-weight: 600;">When</th>
                        <th style="padding: 6px 4px; font-weight: 600;">Type</th>
                        <th style="padding: 6px 4px; font-weight: 600; text-align: right;">Amount</th>
                        <th style="padding: 6px 4px; font-weight: 600;">Mode</th>
                    </tr>
                </thead>
                <tbody class="davya-tl">
                    @foreach ($payments as $p)
                        <tr class="ev pay" style="border-bottom: 1px solid #f9fafb;">
                            <td style="padding: 6px 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;"><span class="pt"></span>{{ $p->received_at?->format('d M Y · H:i') }}</td>
                            <td style="padding: 6px 4px; text-transform: capitalize;">{{ $p->type }}</td>
                            <td class="am" style="padding: 6px 4px; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; {{ $p->amount < 0 ? 'color: #d97706;' : '' }}">₹{{ number_format(abs($p->amount), 0, '.', ',') }}</td>
                            <td style="padding: 6px 4px; text-transform: uppercase; color: #6b7280;">{{ $p->mode ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Notes --}}
    <div style="{{ $sectionStyle }}">
        <div style="{{ $headerStyle }}">
            <span>Notes</span>
            <span style="color: #6b7280; font-weight: 500; font-size: 11px;">{{ count($notes) }} recent</span>
        </div>
        @if ($notes->isEmpty())
            <div style="{{ $emptyStyle }}">No notes yet — use "+ New Note" above.</div>
        @else
            <div class="davya-tl" style="display: flex; flex-direction: column; gap: 8px;">
                @foreach ($notes as $n)
                    <div class="ev" style="border-left: 3px solid #d1d5db; padding: 6px 10px; background: #fafafa; border-radius: 0 4px 4px 0;">
                        <span class="pt"></span>
                        <div class="by" style="font-size: 11px; color: #6b7280; margin-bottom: 2px;">
                            {{ $n->author?->name ?? '—' }} · {{ $n->created_at?->diffForHumans() }}
                        </div>
                        <div style="font-size: 13px; color: #111827; white-space: pre-wrap;">{{ $n->body }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Timeline --}}
    <div style="{{ $sectionStyle }}">
        <div style="{{ $headerStyle }}">
            <span>Timeline</span>
            <span style="color: #6b7280; font-weight: 500; font-size: 11px;">{{ count($timeline) }} recent</span>
        </div>
        @if ($timeline->isEmpty())
            <div style="{{ $emptyStyle }}">No activity recorded yet.</div>
        @else
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <thead style="color: #6b7280;">
                    <tr style="border-bottom: 1px solid #f3f4f6; text-align: left;">
                        <th style="padding: 6px 4px; font-weight: 600;">When</th>
                        <th style="padding: 6px 4px; font-weight: 600;">Who</th>
                        <th style="padding: 6px 4px; font-weight: 600;">What</th>
                    </tr>
                </thead>
                <tbody class="davya-tl">
                    @foreach ($timeline as $a)
                        <tr class="ev" style="border-bottom: 1px solid #f9fafb;">
                            <td style="padding: 6px 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap;"><span class="pt"></span>{{ $a->created_at?->format('d M · H:i') }}</td>
                            <td class="by" style="padding: 6px 4px; color: #6b7280;">{{ $a->causer?->name ?? '—' }}</td>
                            <td style="padding: 6px 4px;">{{ $a->description }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
