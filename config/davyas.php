<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Visual Refresh (v2)
    |--------------------------------------------------------------------------
    |
    | Master toggle for the 2026-04-24 visual refresh. When false the panel
    | renders the legacy look (no tokens.css, no top bar, no command palette,
    | no peek drawer, legacy kanban + legacy page styles). When true the
    | whole refresh lights up at once.
    |
    | Spec: docs/superpowers/specs/2026-04-24-visual-refresh-design.md
    */
    'visual_v2' => env('DAVYAS_VISUAL_V2', false),
];
