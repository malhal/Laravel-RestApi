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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

class UserRestController extends RestController
{
    use AuthenticatesUsers;

    protected $authorizeRequests = true;

    protected $createRules = [
        //'name' => 'required|max:255',
        'email' => 'required|email|max:255|unique:users',
        'password' => 'required|min:6'
    ];

    public function getModelClass()
    {
        return config('auth.providers.users.model');
    }

    protected function restCreate(Request $request, $model)
    {
        $password = $request->json()->get('password');
        if (!is_null($password)) {
            $request->json()->set('password', bcrypt($password));
            $model->setAttribute('api_token', Str::random(60));
            $model->makeVisible(['api_token']);
            Auth::setUser($model);
        }
        return parent::restCreate($request, $model);
    }

    protected function guard()
    {
        return Auth::guard('web');
    }

    public function username()
    {
        return $this->newModel()->getKeyName();
    }

    // had to reimplement this method to remove the call to session.
    protected function sendLoginResponse(Request $request)
    {
        $this->clearLoginAttempts($request);

        Auth::setUser($this->guard()->user());
    }

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

    public function show(Request $request, $id)
    {
        if($request->has('password')) {
            // allow userID from route to be used in authentication.
            $request->query->set($this->newModel()->getKeyName(), $id);
            $this->login($request);
        }
        return parent::show($request, $id);
    }

    protected function restView(Request $request, $model){
        if(Auth::user() == $model){
            $model->makeVisible(['api_token']);
        }
        return $model;
    }

    public function index(Request $request)
    {
        $this->missingMethod();
    }
}
