<div wire:key="student-peek-drawer">
    @if ($isOpen && $this->student)
        @php $s = $this->student; @endphp
        <div style="position: fixed; inset: 0; z-index: 55;">
            <div style="position: absolute; inset: 0; background: rgba(17,24,39,.25);" wire:click="close"></div>
            <aside style="position: absolute; top: 0; right: 0; bottom: 0; width: 560px; max-width: calc(100vw - 40px); background: var(--surface); box-shadow: var(--elev-drawer); overflow-y: auto; display: flex; flex-direction: column;">

                <div style="padding: 16px 18px; border-bottom: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <div style="font-size: var(--fs-18); font-weight: 800; color: var(--text);">{{ $s->name }}</div>
                            <div style="font-size: var(--fs-11); color: var(--text-sub); margin-top: 2px;">
                                {{ $s->phone }} · {{ $s->course ?? '—' }}@if($s->current_round) · Round {{ $s->current_round }}@endif
                            </div>
                        </div>
                        <button wire:click="close" style="background: 0; border: 0; color: var(--text-muted); font-size: 18px; cursor: pointer;">✕</button>
                    </div>

                    @if ($s->owner)
                        <div style="margin-top: 10px;">
                            <span class="davya-owner-pill">
                                <span class="av" style="background: {{ \App\Support\AvatarColor::forUserId($s->owner->id) }};">{{ \App\Support\AvatarColor::initials($s->owner->name) }}</span>
                                {{ $s->owner->name }}
                            </span>
                        </div>
                    @endif

                    @php $choices = $this->choicePredictions; @endphp
                    @if (! empty($choices))
                        <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 8px;">
                            <div style="font-size: var(--fs-10); color: var(--text-sub); font-weight: 600; letter-spacing: .05em; text-transform: uppercase;">
                                Admission likelihood · rank {{ number_format((int) $s->rank) }}{{ $s->category ? ' · '.$s->category : '' }}
                            </div>
                            @foreach ($choices as $c)
                                @php
                                    $barColor = match ($c['bucket']) {
                                        'safe'     => 'var(--success)',
                                        'probable' => 'var(--warning)',
                                        default    => '#ef4444',
                                    };
                                @endphp
                                <div>
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: var(--fs-12);">
                                        <span style="color: var(--text);">
                                            <span style="color: var(--text-sub); font-weight: 600;">Choice {{ $c['rank'] }}</span>
                                            · {{ $c['college'] }}
                                            <span style="color: var(--text-sub);">— {{ $c['branch'] }}</span>
                                        </span>
                                        <span style="font-weight: 700; color: {{ $barColor }}; font-variant-numeric: tabular-nums;">{{ $c['probability_pct'] }}%</span>
                                    </div>
                                    <div style="height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin-top: 4px;">
                                        <div style="height: 100%; background: {{ $barColor }}; width: {{ $c['probability_pct'] }}%;"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        @php
                            try {
                                $stages = \App\Models\Stage::orderBy('display_order')->get();
                            } catch (\Throwable $e) {
                                $stages = collect();
                            }
                            $currentIndex = $stages->isNotEmpty() ? $stages->search(fn ($st) => $st->id === $s->stage_id) : false;
                        @endphp
                        @if ($stages->isNotEmpty())
                            <div style="display: flex; gap: 4px; margin-top: 14px; align-items: center;">
                                @foreach ($stages as $i => $st)
                                    <div style="flex: 1; height: 6px; border-radius: 3px; background: {{ $currentIndex !== false && $i < $currentIndex ? 'var(--success)' : ($i === $currentIndex ? 'var(--warning)' : 'var(--border)') }};"></div>
                                @endforeach
                                <span style="font-size: var(--fs-10); color: var(--text-sub); margin-left: 8px; font-weight: 600;">{{ $currentIndex !== false ? $currentIndex + 1 : 0 }} / {{ $stages->count() }}</span>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="davya-drawer-tabs" style="display: flex; gap: 18px; padding: 0 18px; border-bottom: 1px solid var(--border); font-size: var(--fs-12);">
                    @foreach (['overview','payments','notes','meetings','activity'] as $t)
                        <button wire:click="switchTab('{{ $t }}')"
                                style="padding: 10px 0; background: 0; border: 0; cursor: pointer; color: {{ $activeTab === $t ? 'var(--brand-700)' : 'var(--text-sub)' }}; font-weight: {{ $activeTab === $t ? 700 : 500 }}; border-bottom: 2px solid {{ $activeTab === $t ? 'var(--brand-600)' : 'transparent' }}; margin-bottom: -1px; text-transform: capitalize;">
                            {{ $t }}
                        </button>
                    @endforeach
                </div>

                <div style="flex: 1; padding: 14px 18px; overflow-y: auto;">
                    @if ($activeTab === 'overview')
                        @livewire('drawer.overview-tab', ['studentId' => $studentId], key('ov-'.$studentId))
                    @elseif ($activeTab === 'payments')
                        @livewire('drawer.payments-tab', ['studentId' => $studentId], key('pm-'.$studentId))
                    @elseif ($activeTab === 'notes')
                        @livewire('drawer.notes-tab', ['studentId' => $studentId], key('nt-'.$studentId))
                    @elseif ($activeTab === 'meetings')
                        @livewire('drawer.meetings-tab', ['studentId' => $studentId], key('mt-'.$studentId))
                    @elseif ($activeTab === 'activity')
                        @livewire('drawer.activity-tab', ['studentId' => $studentId], key('ac-'.$studentId))
                    @endif
                </div>

                <div class="davya-drawer-footer" style="position: sticky; bottom: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 10px 18px; display: flex; justify-content: space-between; align-items: center;">
                    <a href="/admin/students/{{ $s->id }}/edit"
                       style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); border: 1px solid var(--brand-600); background: var(--brand-600); color: white; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;"
                       onmouseover="this.style.background='var(--brand-700)'; this.style.borderColor='var(--brand-700)';"
                       onmouseout="this.style.background='var(--brand-600)'; this.style.borderColor='var(--brand-600)';">
                        Update Information
                    </a>
                    <div style="display: flex; gap: 6px;">
                        <button wire:click="switchTab('notes')" style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer;">+ Note</button>
                        <button wire:click="switchTab('payments')" style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer;">+ Payment</button>
                    </div>
                </div>

            </aside>
        </div>
    @endif
</div>
