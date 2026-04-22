<?php

namespace App\Filament\Pages;

use App\Services\LeadImport\ImportAction;
use App\Services\LeadImport\ImportPreview;
use App\Services\LeadImport\LeadImportService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;

class LeadImport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Lead import';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $slug = 'lead-import';
    protected static ?string $title = 'Bulk lead import';
    protected static string $view = 'filament.pages.lead-import';

    public string $step = 'input';   // input | preview | done
    public string $source = 'sonam';
    public string $paste = '';
    /** @var UploadedFile|null */
    public $upload = null;
    public ?string $parseError = null;

    // Preview state (serialized across requests by Livewire)
    public int $previewCreateCount = 0;
    public int $previewMergeCount = 0;
    public int $previewFlagCount = 0;
    public int $previewRejectCount = 0;
    public array $previewRows = [];          // [{action, row_number, phone, reason, ...}]

    // Done state
    public ?int $committedBatchId = null;
    public int $committedCreateCount = 0;
    public int $committedMergeCount = 0;
    public int $committedFlagCount = 0;
    public int $committedRejectCount = 0;
    public ?string $rejectionsUrl = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function runPreview(LeadImportService $svc): void
    {
        $this->parseError = null;
        $input = $this->upload instanceof UploadedFile ? $this->upload : $this->paste;
        if ((is_string($input) && trim($input) === '') && !$this->upload) {
            $this->parseError = 'Paste rows or upload a file before previewing.';
            return;
        }
        try {
            $preview = $svc->preview($this->source, $input);
        } catch (\Throwable $e) {
            $this->parseError = $e->getMessage();
            return;
        }
        $this->storePreview($preview);
        $this->step = 'preview';
    }

    public function backToInput(): void
    {
        $this->step = 'input';
    }

    public function commitPreview(LeadImportService $svc): void
    {
        $preview = $this->rehydratePreview();
        $batch = $svc->commit($preview, auth()->user());

        $this->committedBatchId     = $batch->id;
        $this->committedCreateCount = $batch->created_count;
        $this->committedMergeCount  = $batch->merged_count;
        $this->committedFlagCount   = $batch->flagged_count;
        $this->committedRejectCount = $batch->rejected_count;
        $this->rejectionsUrl = $batch->rejections_csv_path
            ? \Illuminate\Support\Facades\URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id])
            : null;
        $this->step = 'done';

        Notification::make()->success()->title("Imported batch #{$batch->id}")->send();
    }

    public function resetForm(): void
    {
        $this->step = 'input';
        $this->paste = '';
        $this->upload = null;
        $this->parseError = null;
        $this->previewRows = [];
        $this->previewCreateCount = 0;
        $this->previewMergeCount = 0;
        $this->previewFlagCount = 0;
        $this->previewRejectCount = 0;
    }

    private function storePreview(ImportPreview $preview): void
    {
        $this->previewCreateCount = $preview->countBy(ImportAction::CREATE);
        $this->previewMergeCount  = $preview->countBy(ImportAction::MERGE);
        $this->previewFlagCount   = $preview->countBy(ImportAction::FLAG);
        $this->previewRejectCount = $preview->countBy(ImportAction::REJECT);
        $this->previewRows = array_map(fn (ImportAction $a) => [
            'action' => $a->action,
            'row_number' => $a->rowNumber,
            'phone' => $a->mappedPayload['phone'] ?? null,
            'course' => $a->mappedPayload['course'] ?? null,
            'name' => $a->mappedPayload['name'] ?? null,
            'reason' => $a->reason,
            'existing_student_id' => $a->existingStudentId,
            'mapped' => $a->mappedPayload,
        ], $preview->actions);
    }

    private function rehydratePreview(): ImportPreview
    {
        $actions = [];
        foreach ($this->previewRows as $row) {
            $actions[] = new ImportAction(
                action: $row['action'],
                mappedPayload: $row['mapped'],
                existingStudentId: $row['existing_student_id'] ?? null,
                reason: $row['reason'] ?? null,
                rowNumber: $row['row_number'] ?? null,
            );
        }
        return new ImportPreview($this->source, $actions);
    }
}
