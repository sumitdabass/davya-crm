<div>
    @if ($isOpen && $payload)
        <div
            style="position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,0.45);"
            wire:click="close"
        ></div>
        <div
            style="position: fixed; top: 0; right: 0; bottom: 0; z-index: 9999; width: 100%; max-width: 36rem; background: white; box-shadow: -4px 0 12px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
        >
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #e5e7eb;">
                <h2 style="font-size: 15px; font-weight: 600; margin: 0; color: #111827;">
                    {{ $payload->title }} — {{ $rows->total() }} students
                </h2>
                <button
                    type="button"
                    wire:click="close"
                    aria-label="Close"
                    style="background: transparent; border: none; font-size: 20px; cursor: pointer; color: #6b7280; line-height: 1;"
                >&#x2715;</button>
            </div>

            <div style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name or phone"
                    style="flex: 1; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;"
                />
                <a
                    href="{{ route('admin.dashboard.drill-csv', ['cardId' => $cardId, 'search' => $search]) }}"
                    style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; text-decoration: none;"
                >&#x2193; CSV</a>
            </div>

            <div style="flex: 1; overflow-y: auto;">
                <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                    <thead style="background: #f9fafb; position: sticky; top: 0;">
                        <tr>
                            @foreach ($payload->columns as $col)
                                <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom: 1px solid #f3f4f6;"
                                onmouseover="this.style.background='#f9fafb';"
                                onmouseout="this.style.background='white';">
                                @foreach ($payload->columns as $col)
                                    <td style="padding: 8px 12px; color: #111827;">
                                        {{ \App\Dashboard\RowFormatter::format($row, $col['key']) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        @if ($rows->isEmpty())
                            <tr>
                                <td colspan="{{ count($payload->columns) }}" style="padding: 24px; text-align: center; color: #6b7280;">
                                    No rows match.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid #e5e7eb; background: white;">
                <div style="font-size: 12px; color: #6b7280;">
                    {{ $rows->links() }}
                </div>
                @if ($viewAllHref)
                    <a href="{{ $viewAllHref }}"
                       style="font-size: 13px; color: #2563eb; text-decoration: none;"
                       onmouseover="this.style.textDecoration='underline';"
                       onmouseout="this.style.textDecoration='none';">Open in full table &rarr;</a>
                @endif
            </div>
        </div>
    @endif
</div>
