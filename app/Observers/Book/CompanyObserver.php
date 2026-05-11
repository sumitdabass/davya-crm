<?php

namespace App\Observers\Book;

use App\Books\Services\BuiltInFieldsSeeder;
use App\Models\Book\Company;

class CompanyObserver
{
    public function created(Company $company): void
    {
        (new BuiltInFieldsSeeder())->seed($company);
    }
}
