<?php
namespace App\Filament\Pages;

use App\Models\AiConversation;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class AiConversations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Reports';
    protected static string $view = 'filament.pages.ai-conversations';
    protected static ?string $slug = 'ai-conversations';
    protected static ?string $title = 'AI Conversations';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('use ai-agent') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AiConversation::query()
                    ->with('user:id,name')
                    ->withCount('messages')
                    ->when(
                        !auth()->user()?->hasAnyRole(['admin', 'super_admin']),
                        fn ($q) => $q->where('user_id', auth()->id()),
                    )
                    ->latest('last_message_at'),
            )
            ->columns([
                TextColumn::make('title')->limit(60)->searchable(),
                TextColumn::make('user.name')->label('User')->toggleable(),
                TextColumn::make('messages_count')->label('Msgs')->numeric(),
                TextColumn::make('last_message_at')->dateTime()->since()->sortable(),
            ]);
    }
}
