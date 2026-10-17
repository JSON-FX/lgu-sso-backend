<?php

namespace App\Support;

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class ApplicationSessionAuthorization
{
    /** @return list<array{status: int, employee: ?Employee, role: ?AppRole}> */
    public function decide(Application $application, array $tokens): array
    {
        $subjects = [];
        $hashes = [];
        foreach ($tokens as $index => $token) {
            try {
                // jwt-auth's manager/factory are shared within one request; claims must not leak between items.
                JWTAuth::factory()->emptyClaims()->customClaims([])->setRefreshFlow(false);
                JWTAuth::manager()->setRefreshFlow(false);
                $subject = JWTAuth::setToken($token)->getPayload()->get('sub');
                if ((is_int($subject) || (is_string($subject) && ctype_digit($subject)))
                    && filter_var($subject, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                    $subjects[$index] = (int) $subject;
                    $hashes[$index] = hash('sha256', $token);
                }
            } catch (JWTException) {
                // Invalid, expired and blacklisted items are independent denials.
            } finally {
                JWTAuth::factory()->emptyClaims()->customClaims([])->setRefreshFlow(false);
                JWTAuth::unsetToken();
            }
        }

        $employees = Employee::whereIn('id', array_values($subjects))->get()->keyBy('id');
        $sessions = OAuthToken::whereIn('access_token', array_values($hashes))
            ->whereNull('revoked_at')->get()->keyBy('access_token');
        $grants = DB::table('employee_application')->where('application_id', $application->id)
            ->whereIn('employee_id', array_values($subjects))->get()->keyBy('employee_id');

        $decisions = [];
        foreach ($tokens as $index => $token) {
            $employee = $employees->get($subjects[$index] ?? null);
            $session = $sessions->get($hashes[$index] ?? null);
            $grant = $employee ? $grants->get($employee->id) : null;
            $status = ! $employee || ! $employee->is_active || ! $session || $session->employee_id !== $employee->id
                ? 401 : (($session->application_id !== $application->id || $employee->must_change_password || ! $grant) ? 403 : 200);
            $decisions[] = [
                'status' => $status,
                'employee' => $status === 200 ? $employee : null,
                'role' => $status === 200 ? AppRole::from($grant->role) : null,
            ];
        }

        return $decisions;
    }
}
