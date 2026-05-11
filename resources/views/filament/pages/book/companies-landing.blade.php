<x-filament-panels::page>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Companies</div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px;">
            @forelse ($this->getCompanies() as $c)
                @php
                    $latestFy = \App\Models\Book\FiscalYear::where('company_id',$c->id)->orderByDesc('start_date')->first();
                @endphp
                @if ($latestFy)
                    <div style="position:relative;">
                        <a href="{{ url('/admin/books/'.$c->slug.'/'.$latestFy->label) }}"
                           style="display:block; padding:16px; background:var(--surface); border:1px solid var(--border); border-radius:8px; box-shadow:var(--elev-1); text-decoration:none; color:inherit;"
                           onmouseover="this.style.borderColor='var(--brand-600)'; this.style.boxShadow='var(--elev-2)';"
                           onmouseout="this.style.borderColor='var(--border)'; this.style.boxShadow='var(--elev-1)';">
                            <div style="font-family:var(--font-display); font-weight:600; font-size:18px; letter-spacing:-0.015em; color:var(--text); padding-right:36px;">{{ $c->name }}</div>
                            <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">{{ $c->slug }}</div>
                            <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
                                <span class="davya-books-badge davya-books-badge--brand">{{ $c->currency }}</span>
                                <span class="davya-books-badge">FY {{ $latestFy->label }}{{ $latestFy->is_closed ? ' · closed' : '' }}</span>
                            </div>
                        </a>
                        <button type="button"
                                wire:click="mountAction('editCompany', { id: {{ $c->id }} })"
                                title="Edit company"
                                style="position:absolute; top:10px; right:10px; background:transparent; border:0; padding:4px 6px; border-radius:4px; color:var(--text-muted); cursor:pointer; font-size:12px;"
                                onmouseover="this.style.background='var(--border-muted)'; this.style.color='var(--text);';"
                                onmouseout="this.style.background='transparent'; this.style.color='var(--text-muted);';">✎</button>
                    </div>
                @else
                    <div style="padding:16px; background:var(--surface); border:1px dashed var(--border); border-radius:8px; box-shadow:var(--elev-1); color:var(--text-sub);">
                        <div style="font-family:var(--font-display); font-weight:600; font-size:18px; letter-spacing:-0.015em; color:var(--text);">{{ $c->name }}</div>
                        <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">{{ $c->slug }}</div>
                        <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
                            <span class="davya-books-badge davya-books-badge--brand">{{ $c->currency }}</span>
                            <span class="davya-books-badge davya-books-badge--warning">No FY yet</span>
                        </div>
                        <div style="display:flex; gap:8px; margin-top:12px;">
                            <button type="button"
                                    wire:click="mountAction('createFirstFy', { company_id: {{ $c->id }}, company_slug: '{{ $c->slug }}' })"
                                    style="padding:6px 12px; background:var(--brand-600); color:white; border:0; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer;"
                                    onmouseover="this.style.background='var(--brand-700)';"
                                    onmouseout="this.style.background='var(--brand-600)';">+ Create first FY</button>
                            <button type="button"
                                    wire:click="mountAction('editCompany', { id: {{ $c->id }} })"
                                    style="padding:6px 12px; background:transparent; border:1px solid var(--border); color:var(--text-sub); border-radius:6px; font-size:12px; cursor:pointer;"
                                    onmouseover="this.style.borderColor='var(--brand-600)'; this.style.color='var(--brand-700);';"
                                    onmouseout="this.style.borderColor='var(--border)'; this.style.color='var(--text-sub);';">Edit</button>
                        </div>
                    </div>
                @endif
            @empty
                <div style="grid-column:1/-1; text-align:center; padding:48px 0; color:var(--text-sub);">
                    No companies yet — click <strong>+ New Company</strong> above.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
