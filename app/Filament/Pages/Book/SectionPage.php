<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class SectionPage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/section/{section}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.section';

    /** @var \App\Models\Book\Company */
    public $companyModel;

    /** @var \App\Models\Book\FiscalYear */
    public $fyModel;

    /** @var \App\Models\Book\Section */
    public $sectionModel;

    public function mount(string $company, string $fy, string $section): void
    {
        abort_unless(config('books.enabled'), 404);
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->companyModel = Company::where('slug', $company)->firstOrFail();
        $this->fyModel = FiscalYear::where('company_id', $this->companyModel->id)
            ->where('label', $fy)
            ->firstOrFail();
        $this->sectionModel = Section::where('company_id', $this->companyModel->id)
            ->where('slug', $section)
            ->firstOrFail();
    }

    public function getEntries()
    {
        return Entry::where('section_id', $this->sectionModel->id)
            ->where('fiscal_year_id', $this->fyModel->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getVisibleMoneyColumns(): array
    {
        return $this->sectionModel->visible_money_columns;
    }

    protected function getHeaderActions(): array
    {
        $cols = $this->getVisibleMoneyColumns();
        $form = [TextInput::make('title')->required()];

        if (in_array('salary', $cols, true)) {
            $form[] = TextInput::make('salary_amount')->numeric()->default(0);
        }
        if (in_array('loan', $cols, true)) {
            $form[] = TextInput::make('loan_amount')->numeric()->default(0);
        }
        $form[] = Select::make('frequency')
            ->label('Frequency')
            ->options(Entry::FREQUENCIES)
            ->default('one_time')
            ->required()
            ->helperText('How often does this amount apply? Daily/Monthly/etc. multiplies for the annual total.');
        $form[] = Textarea::make('notes')->rows(2);

        return [
            Action::make('createEntry')
                ->label('+ Add Row')
                ->form($form)
                ->action(function (array $data) {
                    if ($this->fyModel->is_closed) {
                        throw new \DomainException('FY is closed');
                    }
                    Entry::create([
                        'company_id' => $this->companyModel->id,
                        'fiscal_year_id' => $this->fyModel->id,
                        'section_id' => $this->sectionModel->id,
                        'title' => $data['title'],
                        'salary_amount' => $data['salary_amount'] ?? 0,
                        'loan_amount' => $data['loan_amount'] ?? 0,
                        'frequency' => $data['frequency'] ?? 'one_time',
                        'notes' => $data['notes'] ?? null,
                    ]);
                }),
        ];
    }

    public function editEntryAction(): Action
    {
        return Action::make('editEntry')
            ->label('Edit Entry')
            ->fillForm(function (array $arguments): array {
                $entry = Entry::findOrFail($arguments['id']);

                return [
                    'title' => $entry->title,
                    'salary_amount' => (float) $entry->salary_amount,
                    'loan_amount' => (float) $entry->loan_amount,
                    'frequency' => $entry->frequency ?? 'one_time',
                    'notes' => $entry->notes,
                ];
            })
            ->form(function (): array {
                $cols = $this->getVisibleMoneyColumns();
                $form = [TextInput::make('title')->required()];

                if (in_array('salary', $cols, true)) {
                    $form[] = TextInput::make('salary_amount')->numeric()->default(0);
                }
                if (in_array('loan', $cols, true)) {
                    $form[] = TextInput::make('loan_amount')->numeric()->default(0);
                }
                $form[] = Select::make('frequency')
                    ->label('Frequency')
                    ->options(Entry::FREQUENCIES)
                    ->default('one_time')
                    ->required()
                    ->helperText('How often does this amount apply? Daily/Monthly/etc. multiplies for the annual total.');
                $form[] = Textarea::make('notes')->rows(2);

                return $form;
            })
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $entry = Entry::findOrFail($arguments['id']);
                $entry->update([
                    'title' => $data['title'],
                    'salary_amount' => $data['salary_amount'] ?? 0,
                    'loan_amount' => $data['loan_amount'] ?? 0,
                    'frequency' => $data['frequency'] ?? 'one_time',
                    'notes' => $data['notes'] ?? null,
                ]);
            });
    }

    public function reclassifyAsLoanAction(): Action
    {
        return Action::make('reclassifyAsLoan')
            ->label('Convert to Loan')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->fillForm(function (array $arguments): array {
                $entry = Entry::findOrFail($arguments['id']);

                return [
                    'loan_amount' => (float) $entry->salary_amount + (float) $entry->loan_amount,
                    'zero_salary' => true,
                ];
            })
            ->modalHeading(function (array $arguments): string {
                $entry = Entry::find($arguments['id']);

                return 'Convert "'.($entry?->title ?? 'entry').'" to Loan';
            })
            ->modalDescription('Moves this entry into the loan book. Useful when a salary advance should be treated as a recoverable loan instead.')
            ->form([
                TextInput::make('loan_amount')
                    ->label('Loan amount')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                \Filament\Forms\Components\Checkbox::make('zero_salary')
                    ->label('Zero out the salary column (recommended)')
                    ->default(true),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $entry = Entry::findOrFail($arguments['id']);
                $entry->update([
                    'loan_amount' => $data['loan_amount'],
                    'salary_amount' => ($data['zero_salary'] ?? true) ? 0 : $entry->salary_amount,
                ]);
            });
    }

    public function deleteEntryAction(): Action
    {
        return Action::make('deleteEntry')
            ->label('Delete Entry')
            ->requiresConfirmation()
            ->modalHeading('Delete entry')
            ->modalDescription(function (array $arguments): string {
                $entry = Entry::find($arguments['id']);

                return 'Delete "'.($entry?->title ?? 'entry').'"? This cannot be undone.';
            })
            ->color('danger')
            ->action(function (array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                Entry::findOrFail($arguments['id'])->delete();
            });
    }

    public function uploadDocumentsAction(): Action
    {
        return Action::make('uploadDocuments')
            ->label('+ Documents')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading(fn (array $arguments) => 'Upload documents for "'
                .\App\Models\Book\Entry::find($arguments['id'])->title.'"')
            ->form([
                \Filament\Forms\Components\FileUpload::make('files')
                    ->label('Files')
                    ->multiple()
                    ->disk(config('books.attachments_disk'))
                    ->directory(fn (array $arguments) => 'books/'.$this->companyModel->slug.'/'.$this->fyModel->label
                        .'/'.$this->sectionModel->slug.'/'.$arguments['id'])
                    ->preserveFilenames()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $entry = Entry::findOrFail($arguments['id']);
                $disk = \Illuminate\Support\Facades\Storage::disk(config('books.attachments_disk'));
                foreach ($data['files'] as $path) {
                    \App\Models\Book\Attachment::create([
                        'attachable_type' => $entry::class,
                        'attachable_id' => $entry->id,
                        'disk' => config('books.attachments_disk'),
                        'path' => $path,
                        'original_name' => basename($path),
                        'mime' => $disk->mimeType($path) ?: null,
                        'size' => $disk->size($path) ?: null,
                        'uploaded_by' => auth()->id(),
                    ]);
                }
            });
    }

    public function viewDocumentsAction(): Action
    {
        return Action::make('viewDocuments')
            ->label('View')
            ->modalHeading(fn (array $arguments) => 'Documents for "'
                .\App\Models\Book\Entry::find($arguments['id'])->title.'"')
            ->modalContent(function (array $arguments): \Illuminate\Contracts\View\View {
                $entry = Entry::findOrFail($arguments['id']);
                $attachments = $entry->attachments()->latest('uploaded_at')->get();

                return view('filament.pages.book.partials.attachment-list', [
                    'attachments' => $attachments,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function deleteDocumentAction(): Action
    {
        return Action::make('deleteDocument')
            ->requiresConfirmation()
            ->color('danger')
            ->action(function (array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $attachment = \App\Models\Book\Attachment::findOrFail($arguments['id']);
                \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->delete();
            });
    }

    public function addPaymentAction(): Action
    {
        return Action::make('addPayment')
            ->modalHeading(fn (array $arguments) => 'Record payment — '
                .\App\Models\Book\Entry::find($arguments['id'])->title)
            ->fillForm(function (array $arguments): array {
                $entry = \App\Models\Book\Entry::findOrFail($arguments['id']);
                $defaultDirection = ((float) $entry->loan_amount > 0 && (float) $entry->salary_amount == 0)
                    ? 'in' : 'out';

                return [
                    'direction' => $defaultDirection,
                    'mode' => 'bank',
                    'occurred_on' => now()->toDateString(),
                ];
            })
            ->form([
                Select::make('direction')
                    ->label('Direction')
                    ->required()
                    ->options([
                        'out' => 'Paid out (we paid them)',
                        'in' => 'Received back (they paid us)',
                    ])
                    ->helperText('"In" reduces loan_outstanding; "out" reduces salary balance.'),
                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix('₹'),
                Select::make('mode')
                    ->required()
                    ->options([
                        'cash' => 'Cash',
                        'bank' => 'Bank transfer',
                        'upi' => 'UPI',
                        'cheque' => 'Cheque',
                        'other' => 'Other',
                    ]),
                \Filament\Forms\Components\DatePicker::make('occurred_on')
                    ->label('Date')
                    ->required(),
                TextInput::make('reference')
                    ->label('Reference')
                    ->placeholder('e.g. cheque no., UTR, txn id'),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $entry = Entry::findOrFail($arguments['id']);
                \App\Models\Book\EntryPayment::create([
                    'entry_id' => $entry->id,
                    'occurred_on' => $data['occurred_on'],
                    'amount' => $data['amount'],
                    'direction' => $data['direction'],
                    'mode' => $data['mode'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            });
    }

    public function viewPaymentsAction(): Action
    {
        return Action::make('viewPayments')
            ->modalHeading(fn (array $arguments) => 'Payments — '
                .\App\Models\Book\Entry::find($arguments['id'])->title)
            ->modalContent(function (array $arguments): \Illuminate\Contracts\View\View {
                $entry = Entry::findOrFail($arguments['id']);

                return view('filament.pages.book.partials.payment-list', [
                    'entry' => $entry,
                    'payments' => $entry->payments()->latest('occurred_on')->latest('id')->get(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function deletePaymentAction(): Action
    {
        return Action::make('deletePayment')
            ->requiresConfirmation()
            ->color('danger')
            ->action(function (array $arguments): void {
                $payment = \App\Models\Book\EntryPayment::findOrFail($arguments['id']);
                $entry = $payment->entry;
                if ($entry && $entry->fiscalYear?->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $payment->delete();
            });
    }

    public function editPaymentAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('editPayment')
            ->modalHeading(fn (array $arguments) => 'Edit payment')
            ->fillForm(function (array $arguments): array {
                $p = \App\Models\Book\EntryPayment::findOrFail($arguments['id']);

                return [
                    'direction' => $p->direction,
                    'amount' => (float) $p->amount,
                    'mode' => $p->mode,
                    'occurred_on' => $p->occurred_on?->toDateString(),
                    'reference' => $p->reference,
                    'notes' => $p->notes,
                ];
            })
            ->form([
                \Filament\Forms\Components\Select::make('direction')
                    ->label('Direction')
                    ->required()
                    ->options([
                        'out' => 'Paid out (we paid them)',
                        'in' => 'Received back (they paid us)',
                    ]),
                \Filament\Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix('₹'),
                \Filament\Forms\Components\Select::make('mode')
                    ->required()
                    ->options([
                        'cash' => 'Cash',
                        'bank' => 'Bank transfer',
                        'upi' => 'UPI',
                        'cheque' => 'Cheque',
                        'other' => 'Other',
                    ]),
                \Filament\Forms\Components\DatePicker::make('occurred_on')
                    ->label('Date')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('reference')
                    ->label('Reference')
                    ->placeholder('e.g. cheque no., UTR, txn id'),
                \Filament\Forms\Components\Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $payment = \App\Models\Book\EntryPayment::findOrFail($arguments['id']);
                $entry = $payment->entry;
                if ($entry && $entry->fiscalYear?->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $payment->update([
                    'direction' => $data['direction'],
                    'amount' => $data['amount'],
                    'mode' => $data['mode'],
                    'occurred_on' => $data['occurred_on'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
            });
    }
}
