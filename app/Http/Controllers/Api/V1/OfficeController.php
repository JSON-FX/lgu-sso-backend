<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Office\SaveOfficeRequest;
use App\Http\Resources\OfficeResource;
use App\Models\AuditLog;
use App\Models\Office;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfficeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['include_inactive' => ['sometimes', 'boolean']]);

        return OfficeResource::collection(Office::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get());
    }

    public function active(): AnonymousResourceCollection
    {
        return OfficeResource::collection(Office::query()->where('is_active', true)->orderBy('name')->get());
    }

    public function store(SaveOfficeRequest $request): JsonResponse
    {
        try {
            $office = (new Office)->getConnection()->transaction(function () use ($request): Office {
                $office = Office::create([...$request->validated(), 'is_active' => true]);
                AuditLog::log('office_created', $request->user('api'), metadata: [
                    'office_id' => $office->id,
                    'changes' => $office->only(['name', 'abbreviation', 'is_active']),
                ]);

                return $office;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $request->validate($request->rules(), $request->messages());
            throw $exception;
        }

        return response()->json(['data' => new OfficeResource($office)], 201);
    }

    public function show(Office $office): JsonResponse
    {
        return response()->json(['data' => new OfficeResource($office)]);
    }

    public function update(SaveOfficeRequest $request, Office $office): JsonResponse
    {
        try {
            $office = $office->getConnection()->transaction(function () use ($request, $office): Office {
                $office = Office::query()->lockForUpdate()->findOrFail($office->id);
                $office->fill($request->validated());
                $changes = $office->getDirty();
                $office->save();
                AuditLog::log('office_updated', $request->user('api'), metadata: [
                    'office_id' => $office->id,
                    'changes' => $changes,
                ]);

                return $office;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $request->validate($request->rules(), $request->messages());
            throw $exception;
        }

        return response()->json(['data' => new OfficeResource($office)]);
    }
}
