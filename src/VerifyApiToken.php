<?php
/**
 *  Laravel-CreatedByPolicy (http://github.com/malhal/Laravel-CreatedByPolicy)
 *
 *  Created by Malcolm Hall on 9/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

class VerifyApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $guard = Auth::guard('api');
        $user = $guard->user();

        // Fixes the problem where Laravel assumes an invalid token means guest.
        if ((is_null($user) || is_null($user->getKey())) && !is_null($guard->getTokenForRequest())) {
            throw new AuthenticationException('Invalid token');
        }

        return $next($request);
    }
}
