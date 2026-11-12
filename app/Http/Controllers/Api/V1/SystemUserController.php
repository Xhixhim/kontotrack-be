<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSystemUserRequest;
use App\Http\Requests\UpdateSystemUserRequest;
use App\Http\Resources\Api\V1\SystemUserResource;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform accounts, managed by system administrators.
 */
class SystemUserController extends Controller
{
    public function __construct(private UserAccountService $userAccountService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->is_system_admin === true, 403);

        return SystemUserResource::collection($this->userAccountService->listUsers());
    }

    public function store(StoreSystemUserRequest $request): SystemUserResource
    {
        /** @var User $administrator */
        $administrator = $request->user();

        return new SystemUserResource($this->userAccountService->createUser($administrator, $request->validated(), $request));
    }

    public function update(UpdateSystemUserRequest $request, User $user): SystemUserResource
    {
        /** @var User $administrator */
        $administrator = $request->user();

        return new SystemUserResource($this->userAccountService->updateUser($administrator, $user, $request->validated(), $request));
    }
}
