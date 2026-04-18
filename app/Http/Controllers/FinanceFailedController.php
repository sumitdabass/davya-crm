<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFailedExtractionRequest;
use App\Models\FailedExtraction;
use Illuminate\Http\JsonResponse;

class FinanceFailedController extends Controller
{
    public function store(StoreFailedExtractionRequest $request): JsonResponse
    {
        $row = FailedExtraction::create($request->validated());
        return response()->json(['id' => $row->id], 201);
    }
}
