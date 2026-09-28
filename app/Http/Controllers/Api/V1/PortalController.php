<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    /**
     * Get the authenticated employee's profile.
     */
    public function profile()
    {
        $employee = auth()->user();
        $employee->load(['office', 'position', 'applications']);

        return new EmployeeResource($employee);
    }

    /**
     * Update the authenticated employee's profile.
     * Only allows self-editable fields.
     */
    public function updateProfile(Request $request)
    {
        $employee = auth()->user();

        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'civil_status' => ['sometimes', 'nullable', 'string', 'in:single,married,widowed,separated,divorced'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'block_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'building_floor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'house_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:50'],
            'office_id' => ['sometimes', 'nullable', 'integer', 'exists:offices,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'date_employed' => ['sometimes', 'nullable', 'date'],
        ]);

        $employee->update($validated);
        $employee->load(['office', 'position', 'applications']);

        return new EmployeeResource($employee);
    }

    /**
     * Get the authenticated employee's application access.
     */
    public function applications()
    {
        $employee = auth()->user();
        $applications = $employee->applications()->where('is_active', true)->get()->map(function ($app) {
            return [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'description' => $app->description,
                'role' => $app->pivot->role,
            ];
        });

        return response()->json(['data' => $applications]);
    }
}
