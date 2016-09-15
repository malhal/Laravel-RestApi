<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *
 *  Created by Malcolm Hall on 6/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Ramsey\Uuid\Uuid;

trait UuidForKey
{
    /**
     * Boot the Uuid trait for the model.
     *
     * @return void
     */
    public static function bootUuidForKey()
    {
        static::creating(function ($model) {
            // skip if already has a key set.
            if(!is_null($model->getKey())){
                return;
            }
            // couldn't use this because would require a case sensitive id column.
            //$uuid = rtrim(strtr(base64_encode(Uuid::uuid4()->getBytes()), '+/', '-_'), '='); // e.g. 3V_4npGbQEKzMNJyGxGEpw
            $uuid = Uuid::uuid4()->toString();
            $model->setAttribute($model->getKeyName(), $uuid);
        });
    }

    // disables laravel trying to use getLastInsertID.
    public function getIncrementing(){
        return false;
    }

}