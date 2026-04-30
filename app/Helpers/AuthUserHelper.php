<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class AuthUserHelper
{
    public static function getUser(Request $request)
    {
        $userRaw = $request->attributes->get('auth_user');
        // return (object) collect($userRaw)->all();
        return (object) collect($userRaw['user'])->all();
    }
}
