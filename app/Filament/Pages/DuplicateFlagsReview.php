<?php

namespace App\Filament\Pages;

use App\Models\DuplicateFlag;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DuplicateFlagsReview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Duplicate review';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.duplicate-flags-review';

    protected static ?string $slug = 'duplicate-flags';

    protected static ?string $title = 'Duplicate leads review';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function getOpenFlags()
    {
        return DuplicateFlag::with(['studentA.owner', 'studentB.owner'])
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get();
    }

    public function getResolvedFlags()
    {
        return DuplicateFlag::with(['studentA', 'studentB', 'kept', 'resolvedBy'])
            ->whereNotNull('resolved_at')
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get();
    }

    public function resolveFlag(int $flagId, int $keepId): void
    {
        $flag = DuplicateFlag::find($flagId);
        if ($flag === null) {
            Notification::make()->danger()->title('Flag not found.')->send();
            return;
        }
        if ($flag->isResolved()) {
            Notification::make()->warning()->title('Already resolved.')->send();
            return;
        }
        if ($keepId !== $flag->student_a_id && $keepId !== $flag->student_b_id) {
            Notification::make()->danger()->title('Invalid keeper choice.')->send();
            return;
        }

        $loseId = $keepId === $flag->student_a_id ? $flag->student_b_id : $flag->student_a_id;

        DB::transaction(function () use ($flag, $keepId, $loseId) {
            $keep = Student::findOrFail($keepId);
            $lose = Student::findOrFail($loseId);

            Payment::where('student_id', $loseId)->update(['student_id' => $keepId]);
            StudentNote::where('student_id', $loseId)->update(['student_id' => $keepId]);
            RoundHistory::where('student_id', $loseId)->update(['student_id' => $keepId]);

            $keep->flagged_for_review = false;
            $keep->flag_reason = null;
            $keep->save();

            $lose->delete();

            $flag->resolved_at = now();
            $flag->resolved_by_user_id = auth()->id();
            $flag->kept_student_id = $keepId;
            $flag->save();
        });

        Notification::make()->success()->title('Resolved. Keeper kept; loser merged + deleted.')->send();
    }
}
