<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class InstallAppWidget extends Widget
{
    protected static string $view = 'filament.widgets.install-app-widget';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 0;
}
