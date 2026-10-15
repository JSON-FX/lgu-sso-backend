<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'initials' => $this->initials,
            'avatar_version' => $this->avatar_version,
            'avatar_endpoint' => $this->avatar_version ? '/api/v1/sso/directory/'.$this->uuid.'/avatar' : null,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'age' => $this->age,
            'civil_status' => $this->civil_status?->value,
            'region' => $this->region,
            'province' => $this->province,
            'city' => $this->city,
            'barangay' => $this->barangay,
            'residence' => $this->residence,
            'block_number' => $this->block_number,
            'building_floor' => $this->building_floor,
            'house_number' => $this->house_number,
            'nationality' => $this->nationality,
            'email' => $this->email,
            'username' => $this->username,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'office' => $this->whenLoaded('office', fn () => new OfficeResource($this->office)),
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'title' => $this->position->title,
            ]),
            'date_employed' => $this->date_employed?->format('Y-m-d'),
            'date_terminated' => $this->date_terminated?->format('Y-m-d'),
            'applications' => $this->whenLoaded('applications', fn () => $this->applications->map(fn ($app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'role' => $app->pivot->role,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
