<?php
namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Spatie\Permission\Models\Role;

class AiAgentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Settings';
    protected static string $view = 'filament.pages.ai-agent-settings';
    protected static ?string $slug = 'ai-agent';
    protected static ?string $title = 'AI Agent';

    public array $rolesWithAgent = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->rolesWithAgent = Role::permission('use ai-agent')->pluck('name')->all();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            CheckboxList::make('rolesWithAgent')
                ->label('Roles allowed to use the AI agent')
                ->options(Role::pluck('name', 'name')->all()),
        ])->statePath('rolesWithAgent');
    }

    public function save(): void
    {
        $selected = collect($this->rolesWithAgent ?? [])->filter()->values()->all();

        foreach (Role::all() as $role) {
            if (in_array($role->name, $selected, true)) {
                $role->givePermissionTo('use ai-agent');
            } else {
                $role->revokePermissionTo('use ai-agent');
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save')->action('save')];
    }
}
