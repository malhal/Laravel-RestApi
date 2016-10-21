<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *
 *  Created by Malcolm Hall on 26/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Exception;
use Symfony\Component\HttpFoundation\Response;

class BatchRestController extends RestController
{
    protected $atomic = true;

    public function batch(Request $request){
        $requestArrays = $request->json()->all();//'requests');
        if(!is_array($requestArrays) || Arr::isAssoc($requestArrays)){
            throw new \InvalidArgumentException('An array of requests must be supplied');
        }
        $rules = array();
        foreach ($requestArrays as $key => $value) {
            $rules[$key.'.method'] = 'required|in:POST,PUT,PATCH';
            $rules[$key.'.body'] = 'required|array';
            $rules[$key.'.path'] = 'required|string';
        }

        $this->validateJson($request, $rules);

        $responses = array();

        if($this->atomic) {
            DB::beginTransaction();
        }

        //$chainingRegex = '/^\${(\d*)\.(\w*)}$/';

        foreach ($requestArrays as $i => $requestArray) {

            // replace what the bound request and facade use
            $request->setMethod($requestArray['method']);
            $body = $requestArray['body'];

            // process chaining, e.g. allows in a second request venue_id : "$0.id" which takes the id from the first response.
            foreach($body as $key => $value){
                // only work on relation fields
                /*
                if(!Str::endsWith($key, '_id')) {
                    continue;
                }
                // check if is of pattern "$0.id"
                if(!preg_match($chainingRegex, $value, $matches)) {
                    continue;
                }
                // safety
                if(count($matches) != 3) {
                    continue;
                }

                // this is the response that has the result they want.
                $prevResponseIndex = $matches[1];
                $prevResponse = $responses[$prevResponseIndex];

                // check the response has a body, i.e. wasn't an error
                if (!array_has($prevResponse, 'body')) {
                    continue;
                }

                $prevBody = $prevResponse['body'];
                // this is the field within the response they want to reference.
                $fieldToChain = $matches[2];
                if (property_exists($prevBody, $fieldToChain)) {
                    $body[$key] = $prevBody->$fieldToChain;
                }
                */
                $body[$key] = preg_replace_callback(
                    '/(\$\(\$(\d*)\.(\w*)\))/', // e.g. $($1.id) means the id from the body response of index 1
                    function ($matches) use ($responses) {
                        return $responses[$matches[2]]['body']->$matches[3];
                    },
                    $value
                );
            }
            $request->replace($body);

            $relativePath = $requestArray['path'];

            // replace any params from previous responses in the batch.
            $relativePath = preg_replace_callback(
                '/(\$\((\d*)\.(\w*)\))/', // e.g. $($1.id) means the id from the body response of index 1
                function ($matches) use ($responses) {
                    return $responses[$matches[2]]['body']->$matches[3];
                },
                $relativePath
            );

            // replace any params using properties in this body.
            $relativePath = preg_replace_callback(
                '/(\$\((\w*)\))/', // e.g. $(password) means use the password property of the body of this request.
                function ($matches) use ($body) {
                    return $body[$matches[2]];
                },
                $relativePath
            );

            $path = dirname($request->path()).'/'.$relativePath;

            // create a request to dispatch
            $req = $request->create($path, $requestArray['method']);

            try {
                $response = Route::dispatch($req);
            }catch(Exception $e){ // MethodNotAllowedHttpException or NotFoundHttpException
                $response = resolve(ExceptionHandler::class)->render($req, $e);
            }

            // get the response or exception.
            $responseDict = ['body' => json_decode($response->getContent()), 'status' => $response->getStatusCode()];

            $responses[] = $responseDict;

            if(!$response->isSuccessful() && $this->atomic){
                $failedDependencyException = new FailedDependencyException('Skipped because atomic operation failed');
                $response = resolve(ExceptionHandler::class)->render($req, $failedDependencyException);

                for($j=$i+1; $j < count($requestArrays); $j++){
                    $responses[] = ['body' => json_decode($response->getContent()), 'status' => $response->getStatusCode()];;
                }
                DB::rollBack();
                break;
            }
        }

        // does nothing if no transaction or was rolled back.
        DB::commit();

        //return response(['responses' => $responses], Response::HTTP_MULTI_STATUS);
        return response($responses, Response::HTTP_MULTI_STATUS);

    }
}