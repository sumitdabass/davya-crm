<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillSumitSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function writeCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leads_') . '.csv';
        file_put_contents($path, $body);
        return $path;
    }

    public function test_imports_unique_rows_with_sumit_as_owner(): void
    {
        $sumit = \App\Models\User::where('email', 'sumit@davya.local')->first();

        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            "9000001001,BCA,Alice\n" .
            "9000001002,BBA,Bob\n"
        );

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        $this->assertSame(0, $exit);

        $this->assertDatabaseHas('students', ['phone' => '9000001001', 'name' => 'Alice', 'owner_id' => $sumit->id]);
        $this->assertDatabaseHas('students', ['phone' => '9000001002', 'name' => 'Bob', 'owner_id' => $sumit->id]);
    }

    public function test_in_memory_dedup_keeps_first_of_bounce_duplicates(): void
    {
        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            "9000002001,BCA,First\n" .
            "9000002001,BCA,Second\n" .
            "9000002001,BCA,Third\n"
        );

        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);

        $this->assertSame(1, Student::where('phone', '9000002001')->count());
        $this->assertSame('First', Student::where('phone', '9000002001')->value('name'));
    }

    public function test_rejects_rows_missing_phone_or_course(): void
    {
        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            ",BCA,NoPhone\n" .
            "9000003001,,NoCourse\n" .
            "9000003002,BCA,Good\n"
        );

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, Student::count());
        $this->assertDatabaseHas('students', ['phone' => '9000003002']);
    }

    public function test_is_idempotent_when_rerun(): void
    {
        $path = $this->writeCsv("Phone,Course,Name\n9000004001,BCA,Alice\n");

        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);

        $this->assertSame(1, Student::where('phone', '9000004001')->count());
    }

    public function test_dry_run_inserts_nothing(): void
    {
        $path = $this->writeCsv("Phone,Course,Name\n9000005001,BCA,Alice\n");

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path, '--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(0, Student::count());
    }
}
