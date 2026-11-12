<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function __construct(private UserAccountService $userAccountService) {}

    public function forgot(Request $request): JsonResponse
    {
        $attributes = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->userAccountService->sendPasswordResetLink($attributes['email'], $request);

        // The same answer whether or not the address has an account.
        return response()->json(['message' => 'If this email address has a KontoTrack account, a link to choose a new password has been sent to it.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        $this->userAccountService->resetPassword($attributes, $request);

        return response()->json(['message' => 'Your password has been saved. You can sign in now.']);
    }

    public function change(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);
        /** @var User $user */
        $user = $request->user();
        $this->userAccountService->changePassword($user, $attributes['current_password'], $attributes['password'], $request);

        return response()->json(['message' => 'Your password has been changed. Other devices have been signed out.']);
    }
}
