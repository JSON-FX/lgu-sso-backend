<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Position\SavePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\AuditLog;
use App\Models\Position;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['include_inactive' => ['sometimes', 'boolean']]);

        return PositionResource::collection(Position::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('title')->get());
    }

    public function active(): AnonymousResourceCollection
    {
        return PositionResource::collection(Position::query()->where('is_active', true)->orderBy('title')->get());
    }

    public function store(SavePositionRequest $request): JsonResponse
    {
        try {
            $position = (new Position)->getConnection()->transaction(function () use ($request): Position {
                $position = Position::create([...$request->validated(), 'is_active' => true]);
                AuditLog::log('position_created', $request->user('api'), metadata: [
                    'position_id' => $position->id,
                    'changes' => $position->only(['title', 'is_active']),
                ]);

                return $position;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $request->validate($request->rules(), $request->messages());
            throw $exception;
        }

        return response()->json(['data' => new PositionResource($position)], 201);
    }

    public function show(Position $position): JsonResponse
    {
        return response()->json(['data' => new PositionResource($position)]);
    }

    public function update(SavePositionRequest $request, Position $position): JsonResponse
    {
        try {
            $position = $position->getConnection()->transaction(function () use ($request, $position): Position {
                $position = Position::query()->lockForUpdate()->findOrFail($position->id);
                $position->fill($request->validated());
                $changes = $position->getDirty();
                $position->save();
                AuditLog::log('position_updated', $request->user('api'), metadata: [
                    'position_id' => $position->id,
                    'changes' => $changes,
                ]);

                return $position;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $request->validate($request->rules(), $request->messages());
            throw $exception;
        }

        return response()->json(['data' => new PositionResource($position)]);
    }
}
