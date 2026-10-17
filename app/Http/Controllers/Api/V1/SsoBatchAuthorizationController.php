<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sso\AuthorizeBatchRequest;
use App\Models\AuditLog;
use App\Support\ApplicationSessionAuthorization;
use App\Support\SsoWorkBudget;

class SsoBatchAuthorizationController extends Controller
{
    public function __invoke(AuthorizeBatchRequest $request, ApplicationSessionAuthorization $authorization, SsoWorkBudget $budget): \Illuminate\Http\JsonResponse
    {
        $application = $request->attributes->get('application');
        abort_unless($application->uuid === config('sso.batch.application_uuid'), 404);
        $items = $request->validated('items');
        $budget->charge('batch-global', count($items), (int) config('sso.batch.global_items_per_minute'));
        $budget->charge('batch-'.$application->id, count($items), (int) config('sso.batch.items_per_minute'));
        $decisions = $authorization->decide($application, array_column($items, 'token'));
        $data = [];
        $audit = [];
        foreach ($decisions as $index => $decision) {
            $result = ['id' => $items[$index]['id'], 'authorized' => $decision['status'] === 200];
            $employee = $decision['employee'];
            if ($employee) {
                $result['role'] = $decision['role']->value;
                $result['employee'] = ['uuid' => $employee->uuid, 'full_name' => $employee->full_name, 'email' => $employee->email];
            } else {
                $result['status'] = $decision['status'];
            }
            $data[] = $result;
            $audit[] = [
                'employee_id' => $employee?->id,
                'application_id' => $application->id,
                'action' => 'app_authorize_batch_item',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => json_encode(['method' => 'POST', 'path' => $request->path(), 'item_index' => $index, 'item_count' => count($items), 'status_code' => $decision['status']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ];
        }
        AuditLog::insert($audit);

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
