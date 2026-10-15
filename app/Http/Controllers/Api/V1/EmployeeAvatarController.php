<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Services\EmployeeAvatars;
use Illuminate\Http\Request;

class EmployeeAvatarController extends Controller
{
    public function store(Request $request, EmployeeAvatars $avatars): EmployeeResource
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp']]);

        return new EmployeeResource($avatars->replace($request->route('employee') ?? $request->user('api'), $request->user('api'), $request->file('photo')));
    }

    public function destroy(Request $request, EmployeeAvatars $avatars): EmployeeResource
    {
        return new EmployeeResource($avatars->remove($request->route('employee') ?? $request->user('api'), $request->user('api')));
    }

    public function show(Request $request, EmployeeAvatars $avatars)
    {
        return $avatars->response($request->route('employee') ?? $request->user('api'));
    }
}
