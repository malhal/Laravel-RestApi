<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *
 *  Created by Malcolm Hall on 25/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Illuminate\Support\Facades\Router;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait RouteKeyNameFromQuery
{
    // Define this in model to denote the allowed natural keys for use in the route.
    // protected $validRouteKeyNames = ['key'];

    public function getRouteKeyName(){
        $routeKeyName = resolve('router')->getCurrentRequest()->query('route_key_name'); // this way works with batch
        if(is_null($routeKeyName)) {
            return $this->getKeyName();
        }
        if(!in_array($routeKeyName, $this->getValidRouteKeyNames())){
            throw new BadRequestHttpException('invalid route_key_name');
        }
        return $routeKeyName;
    }

    public function getValidRouteKeyNames()
    {
        return property_exists($this, 'validRouteKeyNames') ? $this->validRouteKeyNames : [];
    }
}