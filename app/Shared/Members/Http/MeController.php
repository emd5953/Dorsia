<?php

declare(strict_types=1);

namespace App\Shared\Members\Http;

use App\Shared\Members\Member;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): MemberResource
    {
        /** @var Member $member */
        $member = $request->user();

        return new MemberResource($member);
    }
}
