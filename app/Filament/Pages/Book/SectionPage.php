<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Asset;
use App\Models\Book\Attachment;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class SectionPage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/section/{section}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.section';

    /** @var Company */
    public $companyModel;

    /** @var FiscalYear */
    public $fyModel;

    /** @var Section */
    public $sectionModel;

    public ?int $uploadEntryId = null;

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

    /**
     * Bridges for buttons rendered inside Filament's modalContent partials.
     * wire:click directives inside an open Filament action modal don't get
     * re-bound by Livewire (the modal is teleported out of the component DOM),
     * so we fire global Livewire events from a plain onclick and re-mount the
     * action here. Used by partials/payment-list + partials/attachment-list.
     */
    #[On('book:open-edit-payment')]
    public function openEditPayment(int $id): void
    {
        $this->mountAction('editPayment', ['id' => $id]);
    }

    #[On('book:open-delete-payment')]
    public function openDeletePayment(int $id): void
    {
        $this->mountAction('deletePayment', ['id' => $id]);
    }

    #[On('book:open-delete-document')]
    public function openDeleteDocument(int $id): void
    {
        $this->mountAction('deleteDocument', ['id' => $id]);
    }

    public function getVisibleMoneyColumns(): array
    {
        return $this->sectionModel->visible_money_columns;
    }

    public function isAssetSection(): bool
    {
        return $this->sectionModel->kind === 'asset';
    }

    private function assetFormFields(): array
    {
        return [
            TextInput::make('original_value')
                ->label('Original value')
                ->numeric()
                ->required()
                ->minValue(1)
                ->prefix('₹')
                ->helperText('Purchase price / capitalised cost.'),
            TextInput::make('dep_percent')
                ->label('Depreciation % per year')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue(100)
                ->suffix('%')
                ->helperText('e.g. 20 for a 5-year straight-line asset.'),
            TextInput::make('dep_years')
                ->label('Useful life (years)')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(50)
                ->helperText('Used to cap accumulated depreciation.'),
            DatePicker::make('dep_started_at')
                ->label('Depreciation start date')
                ->required()
                ->helperText('When the asset was put into use. Prorated within the FY.'),
            Select::make('method')
                ->label('Method')
                ->required()
                ->default('straight_line')
                ->options([
                    'straight_line' => 'Straight-line (same % every year)',
                    'wdv' => 'Written-down value (declining balance)',
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        $cols = $this->getVisibleMoneyColumns();
        $isAsset = $this->isAssetSection();
        $form = [TextInput::make('title')->required()];

        if (in_array('salary', $cols, true)) {
            $form[] = TextInput::make('salary_amount')
                ->label($this->sectionModel->periodicAmountLabel())
                ->numeric()
                ->default(0)
                ->prefix('₹');
        }
        if (in_array('loan', $cols, true)) {
            $isTaken = $this->sectionModel->slug === 'loans_taken';
            $form[] = TextInput::make('loan_amount')
                ->label($isTaken ? 'Principal taken' : 'Principal lent')
                ->numeric()->default(0);
            $form[] = TextInput::make('interest_rate')
                ->label('Interest rate')
                ->placeholder($isTaken ? 'e.g. "8.5% pa" — bank rate' : 'e.g. "0% — interest-free"');
            $form[] = TextInput::make('emi_amount')
                ->label('Monthly EMI')
                ->numeric()
                ->minValue(0)
                ->prefix('₹')
                ->helperText('Equated monthly instalment. Leave blank if no fixed EMI.');
            $form[] = TextInput::make('tenure_months')
                ->label('Tenure (months)')
                ->numeric()
                ->minValue(1)
                ->maxValue(600)
                ->helperText('Total number of monthly EMIs. e.g. 60 for a 5-year loan.');
        }
        if ($isAsset) {
            $form = array_merge($form, $this->assetFormFields());
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
                ->action(function (array $data) use ($isAsset) {
                    if ($this->fyModel->is_closed) {
                        throw new \DomainException('FY is closed');
                    }
                    $entry = Entry::create([
                        'company_id' => $this->companyModel->id,
                        'fiscal_year_id' => $this->fyModel->id,
                        'section_id' => $this->sectionModel->id,
                        'title' => $data['title'],
                        'salary_amount' => $data['salary_amount'] ?? 0,
                        'loan_amount' => $data['loan_amount'] ?? 0,
                        'interest_rate' => $data['interest_rate'] ?? null,
                        'emi_amount' => $data['emi_amount'] ?? null,
                        'tenure_months' => $data['tenure_months'] ?? null,
                        'frequency' => $data['frequency'] ?? 'one_time',
                        'notes' => $data['notes'] ?? null,
                    ]);
                    if ($isAsset) {
                        Asset::create([
                            'entry_id' => $entry->id,
                            'original_value' => $data['original_value'],
                            'dep_percent' => $data['dep_percent'],
                            'dep_years' => $data['dep_years'],
                            'dep_started_at' => $data['dep_started_at'],
                            'method' => $data['method'] ?? 'straight_line',
                        ]);
                    }
                }),
        ];
    }

    public function editEntryAction(): Action
    {
        return Action::make('editEntry')
            ->label('Edit Entry')
            ->fillForm(function (array $arguments): array {
                $entry = Entry::findOrFail($arguments['id']);
                $base = [
                    'title' => $entry->title,
                    'salary_amount' => (float) $entry->salary_amount,
                    'loan_amount' => (float) $entry->loan_amount,
                    'interest_rate' => $entry->interest_rate,
                    'emi_amount' => $entry->emi_amount ? (float) $entry->emi_amount : null,
                    'tenure_months' => $entry->tenure_months,
                    'frequency' => $entry->frequency ?? 'one_time',
                    'notes' => $entry->notes,
                ];
                if ($this->isAssetSection()) {
                    $asset = Asset::where('entry_id', $entry->id)->first();
                    if ($asset) {
                        $base += [
                            'original_value' => (float) $asset->original_value,
                            'dep_percent' => (float) $asset->dep_percent,
                            'dep_years' => $asset->dep_years,
                            'dep_started_at' => $asset->dep_started_at?->toDateString(),
                            'method' => $asset->method,
                        ];
                    }
                }

                return $base;
            })
            ->form(function (): array {
                $cols = $this->getVisibleMoneyColumns();
                $isAsset = $this->isAssetSection();
                $form = [TextInput::make('title')->required()];

                if (in_array('salary', $cols, true)) {
                    $form[] = TextInput::make('salary_amount')->numeric()->default(0);
                }
                if (in_array('loan', $cols, true)) {
                    $form[] = TextInput::make('loan_amount')->numeric()->default(0);
                    $form[] = TextInput::make('interest_rate')
                        ->label('Interest rate')
                        ->placeholder('e.g. "8.5% pa" or "0% — interest-free"');
                    $form[] = TextInput::make('emi_amount')->label('Monthly EMI')->numeric()->minValue(0)->prefix('₹');
                    $form[] = TextInput::make('tenure_months')->label('Tenure (months)')->numeric()->minValue(1)->maxValue(600);
                }
                if ($isAsset) {
                    $form = array_merge($form, $this->assetFormFields());
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
                    'interest_rate' => $data['interest_rate'] ?? null,
                    'emi_amount' => $data['emi_amount'] ?? null,
                    'tenure_months' => $data['tenure_months'] ?? null,
                    'frequency' => $data['frequency'] ?? 'one_time',
                    'notes' => $data['notes'] ?? null,
                ]);
                if ($this->isAssetSection() && isset($data['original_value'])) {
                    Asset::updateOrCreate(
                        ['entry_id' => $entry->id],
                        [
                            'original_value' => $data['original_value'],
                            'dep_percent' => $data['dep_percent'],
                            'dep_years' => $data['dep_years'],
                            'dep_started_at' => $data['dep_started_at'],
                            'method' => $data['method'] ?? 'straight_line',
                        ]
                    );
                }
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
                Checkbox::make('zero_salary')
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
            ->mountUsing(function (array $arguments): void {
                $this->uploadEntryId = (int) $arguments['id'];
            })
            ->modalHeading(fn (array $arguments) => 'Upload documents for "'
                .Entry::find($arguments['id'])->title.'"')
            ->form([
                FileUpload::make('files')
                    ->label('Files')
                    ->multiple()
                    ->disk(config('books.attachments_disk'))
                    ->directory(fn () => 'books/'.$this->companyModel->slug.'/'.$this->fyModel->label
                        .'/'.$this->sectionModel->slug.'/'.$this->uploadEntryId)
                    ->preserveFilenames()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $entry = Entry::findOrFail($arguments['id']);
                $disk = Storage::disk(config('books.attachments_disk'));
                foreach ($data['files'] as $path) {
                    Attachment::create([
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
                .Entry::find($arguments['id'])->title.'"')
            ->modalContent(function (array $arguments): View {
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
        // No requiresConfirmation() — called from inside viewDocuments modal.
        // Confirmation is done via native confirm() in the blade button.
        return Action::make('deleteDocument')
            ->color('danger')
            ->action(function (array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $attachment = Attachment::findOrFail($arguments['id']);
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->delete();

                Notification::make()
                    ->title('Document deleted')
                    ->success()
                    ->send();
            });
    }

    public function addPaymentAction(): Action
    {
        return Action::make('addPayment')
            ->modalHeading(fn (array $arguments) => 'Record payment — '
                .Entry::find($arguments['id'])->title)
            ->fillForm(function (array $arguments): array {
                // Default direction is driven by the section's slug, not the
                // entry's amount fields — a Loans Given row with a yet-to-be-
                // recorded principal still needs payments to default to 'in'
                // (money coming back from the borrower).
                $defaultDirection = match ($this->sectionModel->slug) {
                    'loan', 'receipts' => 'in',
                    default => 'out',
                };

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
                DatePicker::make('occurred_on')
                    ->label('Date')
                    ->required(),
                TextInput::make('source')
                    ->label('Source')
                    ->placeholder('Who/what — free text, e.g. "Vendor X", "Client Y"'),
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
                EntryPayment::create([
                    'entry_id' => $entry->id,
                    'occurred_on' => $data['occurred_on'],
                    'amount' => $data['amount'],
                    'direction' => $data['direction'],
                    'mode' => $data['mode'],
                    'source' => $data['source'] ?? null,
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
                .Entry::find($arguments['id'])->title)
            // Default Filament modal width (~2xl/672px) couldn't fit the 8-col
            // payment table — Edit/Delete buttons overflowed off-screen.
            ->modalWidth('7xl')
            ->modalContent(function (array $arguments): View {
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
        // No requiresConfirmation() — this action is called from inside the
        // viewPayments modal, and Filament 3 doesn't stack a confirmation modal
        // on top of an open one. The blade button does a native confirm() prompt
        // before mounting this action; we just delete + flash a notification.
        return Action::make('deletePayment')
            ->color('danger')
            ->action(function (array $arguments): void {
                $payment = EntryPayment::findOrFail($arguments['id']);
                $entry = $payment->entry;
                if ($entry && $entry->fiscalYear?->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $payment->delete();

                Notification::make()
                    ->title('Payment deleted')
                    ->success()
                    ->send();
            });
    }

    public function editPaymentAction(): Action
    {
        return Action::make('editPayment')
            ->modalHeading(fn (array $arguments) => 'Edit payment')
            ->fillForm(function (array $arguments): array {
                $p = EntryPayment::findOrFail($arguments['id']);

                return [
                    'direction' => $p->direction,
                    'amount' => (float) $p->amount,
                    'mode' => $p->mode,
                    'occurred_on' => $p->occurred_on?->toDateString(),
                    'source' => $p->source,
                    'reference' => $p->reference,
                    'notes' => $p->notes,
                ];
            })
            ->form([
                Select::make('direction')
                    ->label('Direction')
                    ->required()
                    ->options([
                        'out' => 'Paid out (we paid them)',
                        'in' => 'Received back (they paid us)',
                    ]),
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
                DatePicker::make('occurred_on')
                    ->label('Date')
                    ->required(),
                TextInput::make('source')
                    ->label('Source')
                    ->placeholder('Who/what — free text'),
                TextInput::make('reference')
                    ->label('Reference')
                    ->placeholder('e.g. cheque no., UTR, txn id'),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $payment = EntryPayment::findOrFail($arguments['id']);
                $entry = $payment->entry;
                if ($entry && $entry->fiscalYear?->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                $payment->update([
                    'direction' => $data['direction'],
                    'amount' => $data['amount'],
                    'mode' => $data['mode'],
                    'occurred_on' => $data['occurred_on'],
                    'source' => $data['source'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
            });
    }
}
