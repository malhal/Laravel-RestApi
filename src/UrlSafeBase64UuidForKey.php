<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *  UrlSafeBase64UuidForKey.php
 *
 *  Created by Malcolm Hall on 6/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

/**
 * Created by PhpStorm.
 * User: mh
 * Date: 03/09/2016
 * Time: 21:33
 */

namespace Malhal\RestApi;

use Ramsey\Uuid\Uuid;

trait UrlSafeBase64UuidForKey
{
    /**
     * Boot the Uuid trait for the model.
     *
     * @return void
     */
    public static function bootUrlSafeBase64UuidForKey()
    {
        static::creating(function ($model) {
            $model->setAttribute($model->getKeyName(), $model->newUuid());
        });
    }

    // disables laravel trying to use getLastInsertID.
    public function getIncrementing(){
        return false;
    }

    // returns a UUID encoded in base64 made url safe by replacing '+' with '/', and '_' with '-', and '=' removed.
    public static function urlSafeBase64Uuid(){
        $hex = Uuid::uuid4()->getHex();
        $bin = pack('H*', $hex);
        $encoded = rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        return $encoded;
    }

    protected function newUuid(){
        return static::urlSafeBase64Uuid();
    }

}