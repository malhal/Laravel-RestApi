<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *
 *  Created by Malcolm Hall on 14/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/*
 * This class is a resource controller that also allows registering in via the create method by hasing the password
 * and returning the api_token to authenticate future requests.
 * It also supports logging in via the login method by taking a password param and if matches then includes the api_token
 * in the response. Add a post route to the login method.
 */
class UserRestController extends RestController
{
    use AuthenticatesUsers; // provides the login method.

    public function getModelClass()
    {
        return config('auth.providers.users.model');
    }

    // Users cannot be queried for security reasons.
    public function index(Request $request)
    {
        $this->missingMethod();
    }

    // for user registration.
    protected function restCreate(Request $request, $model)
    {
        $password = $request->json()->get('password');
        if (!is_null($password)) {
            $request->json()->set('password', bcrypt($password));
        }
        $model->setAttribute('api_token', Str::random(60));
        $model->makeVisible(['api_token']);
        Auth::setUser($model); // log in so that any model events have access to this user.

        return parent::restCreate($request, $model);
    }

    // this is the guard used by AuthenticatesUsers
    protected function guard()
    {
        return Auth::guard('web');
    }

    // had to re-implement this method to remove the call to session.
    protected function sendLoginResponse(Request $request)
    {
        $this->clearLoginAttempts($request);

        //Auth::setUser($this->guard()->user()); // not needed yet

        return $this->guard()->user()->makeVisible(['api_token']);
    }

    // had to re-implement method from ThrottlesLogins
    protected function sendLockoutResponse(Request $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        $message = Lang::get('auth.throttle', ['seconds' => $seconds]); // e.g. Too many login attempts. Please try again in 60 seconds.

        throw new TooManyRequestsHttpException($seconds, $message);
    }

    protected function sendFailedLoginResponse(Request $request)
    {
        throw new AuthenticationException(Lang::get('auth.failed'));
    }

}
