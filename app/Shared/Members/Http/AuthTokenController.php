<?php

declare(strict_types=1);

namespace App\Shared\Members\Http;

use App\Shared\Members\Exceptions\InvalidCredentialsException;
use App\Shared\Members\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class AuthTokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $member = Member::query()->where('email', $credentials['email'])->first();

        if ($member === null || ! Hash::check($credentials['password'], $member->password)) {
            throw new InvalidCredentialsException;
        }

        $token = $member->createToken($credentials['device_name'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'member' => (new MemberResource($member))->resolve($request),
            ],
        ], 201);
    }
}
