<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sso\ReferenceDirectoryRequest;
use App\Models\Office;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SsoReferenceDirectoryController extends Controller
{
    private const OFFICE_FIELDS = ['id', 'name', 'abbreviation', 'is_active'];

    private const POSITION_FIELDS = ['id', 'title', 'is_active'];

    public function offices(ReferenceDirectoryRequest $request): JsonResponse
    {
        return $this->listing($request, Office::query(), ['name', 'abbreviation'], self::OFFICE_FIELDS);
    }

    public function office(string $id): JsonResponse
    {
        $office = Office::query()->select(self::OFFICE_FIELDS)->find($id);

        return $office
            ? response()->json(['data' => $office])
            : response()->json(['message' => 'Office not found.'], 404);
    }

    public function positions(ReferenceDirectoryRequest $request): JsonResponse
    {
        return $this->listing($request, Position::query(), ['title'], self::POSITION_FIELDS);
    }

    public function position(string $id): JsonResponse
    {
        $position = Position::query()->select(self::POSITION_FIELDS)->find($id);

        return $position
            ? response()->json(['data' => $position])
            : response()->json(['message' => 'Position not found.'], 404);
    }

    private function listing(ReferenceDirectoryRequest $request, Builder $query, array $searchFields, array $fields): JsonResponse
    {
        $input = $request->validated();
        $query->where('is_active', true);
        foreach (preg_split('/\s+/u', trim($input['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->where(function (Builder $query) use ($searchFields, $pattern): void {
                foreach ($searchFields as $field) {
                    $query->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        $page = $query->orderBy($searchFields[0])->orderBy('id')
            ->simplePaginate((int) ($input['per_page'] ?? 50), $fields, 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'has_more' => $page->hasMorePages()],
        ]);
    }
}
