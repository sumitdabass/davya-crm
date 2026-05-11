<x-filament-panels::page>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Companies</div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px;">
            @forelse ($this->getCompanies() as $c)
                @php
                    $latestFy = \App\Models\Book\FiscalYear::where('company_id',$c->id)->orderByDesc('start_date')->first();
                @endphp
                <a href="{{ url('/admin/books/'.$c->slug) }}"
                   style="display:block; padding:16px; background:var(--surface); border:1px solid var(--border); border-radius:8px; box-shadow:var(--elev-1); text-decoration:none; color:inherit;"
                   onmouseover="this.style.borderColor='var(--brand-600)'; this.style.boxShadow='var(--elev-2)';"
                   onmouseout="this.style.borderColor='var(--border)'; this.style.boxShadow='var(--elev-1)';">
                    <div style="font-family:var(--font-display); font-weight:600; font-size:18px; letter-spacing:-0.015em; color:var(--text);">{{ $c->name }}</div>
                    <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">{{ $c->slug }}</div>
                    <div style="display:flex; gap:8px; margin-top:10px;">
                        <span class="davya-books-badge davya-books-badge--brand">{{ $c->currency }}</span>
                        @if ($latestFy)
                            <span class="davya-books-badge">FY {{ $latestFy->label }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <div style="grid-column:1/-1; text-align:center; padding:48px 0; color:var(--text-sub);">
                    No companies yet — click <strong>+ New Company</strong> above.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
