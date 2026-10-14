<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfficeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $offices = Office::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return OfficeResource::collection($offices);
    }

    public function show(Office $office): JsonResponse
    {
        return response()->json([
            'data' => new OfficeResource($office),
        ]);
    }
}
