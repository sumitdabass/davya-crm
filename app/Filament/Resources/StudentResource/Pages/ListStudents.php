<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\Payment;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('kanban_view')
                ->label('Kanban')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->url('/admin/kanban')
                ->visible(fn () => config('davyas.visual_v2')),
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
            Actions\CreateAction::make(),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $filename = 'students-'.Carbon::now('Asia/Kolkata')->format('Y-m-d-His').'.csv';

        $query = static::getResource()::getEloquentQuery()->with('owner:id,name');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'ID', 'Phone', 'Name', 'Father', 'Owner', 'Stage',
                'Deal', 'Received', 'Pending',
                'Plan', 'Exam', '12th Marks', 'Category', 'Course',
                '1st Choice', '2nd Choice', '3rd Choice',
                'Final College', 'Final Course', 'Admission Date',
                'Created', 'Updated',
            ]);

            $paymentsByStudent = Payment::query()
                ->selectRaw('student_id, SUM(amount) as total')
                ->groupBy('student_id')
                ->pluck('total', 'student_id');

            $query->orderBy('id')->chunk(500, function ($students) use ($out, $paymentsByStudent): void {
                foreach ($students as $s) {
                    $received = (float) ($paymentsByStudent[$s->id] ?? 0);
                    $pending  = max(0, (float) ($s->deal_amount ?? 0) - $received);
                    fputcsv($out, [
                        $s->id,
                        $s->phone,
                        $s->name,
                        $s->father_name,
                        $s->owner?->name,
                        $s->stage,
                        $s->deal_amount,
                        $received,
                        $pending,
                        $s->plan,
                        $s->exam_appeared,
                        $s->twelfth_marks,
                        $s->category,
                        $s->course,
                        $s->preference_r1,
                        $s->preference_r2,
                        $s->preference_r3,
                        $s->final_college,
                        $s->final_course,
                        optional($s->admission_date)->format('Y-m-d'),
                        optional($s->created_at)->format('Y-m-d H:i'),
                        optional($s->updated_at)->format('Y-m-d H:i'),
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
