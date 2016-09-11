<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *  RestApiController.php
 *
 *  Created by Malcolm Hall on 2/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RestApiController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $authorize;

    public function __construct()
    {
        \App::singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            RestApiHandler::class
        );

        $this->middleware(VerifyApiToken::class);
    }

    protected function newModel(){
        $className = 'App\\' . substr(class_basename($this), 0, -10);
        return new $className;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return $this->newModel()->get();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    //public function create()
    //{}

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        return $this->createModel($request, $this->newModel());
    }

    protected function createModel(Request $request, $model){
        if($this->authorize){
            $this->authorize('create', $model);
        }

        $model->fill($request->json()->all());
        $model->save();
        return response($model->makeHidden($model->getFillable()), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $model = $this->newModel();
        if($this->authorize){
            $this->authorize('show', $model);
        }
        return $model->findOrFail($id);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    //public function edit($id)
    //{}

    /**
     * Update the specified resource in storage.
     * PATCH exceptions if the resource is not found.
     * PUT returns 201 if it creates a new resource otherwise 200.
     * Because PUT does a delete, all cascade related records will be deleted too.
     * If the same update is done again then the timestamp is not updated, even if it is a different user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {

            $newModel = $this->newModel();
            $query = $newModel->newQuery();

            if($request->method() == Request::METHOD_PATCH) {
                $model = $query->findOrFail($id);
                return $this->updateModel($request, $model);
            }

            // PUT
            $model = $query->find($id);

            if(is_null($model)){
                $model = $newModel;
                $model->setAttribute($model->getKeyName(), $id);
                return $this->createModel($request, $model);
            }

            return $this->replaceModel($request, $model);
        });
    }

    protected function replaceModel(Request $request, $model){
        // clear all attributes
        foreach($model->getFillable() as $fillable){
            $model->$fillable = null;
        }
        return $this->updateModel($request, $model);
    }

    protected function updateModel(Request $request, $model)
    {
        if($this->authorize){
            $this->authorize('update', $model);
        }

        $model->fill($request->json()->all());

        $model->save();

        return response($model->makeHidden($model->getFillable()));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $model = $this->newModel()->find($id);
            if (!is_null($model)) {
                $model->delete();
            }
        });
    }

    public function validateJson(Request $request, array $rules, array $messages = [], array $customAttributes = [])
    {
        $validator = $this->getValidationFactory()->make($request->json()->all(), $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            $this->throwValidationException($request, $validator);
        }
    }

    public function validateQuery(Request $request, array $rules, array $messages = [], array $customAttributes = [])
    {
        $validator = $this->getValidationFactory()->make($request->query->all(), $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            $this->throwValidationException($request, $validator);
        }
    }

    public function batch(Request $request){
        $rules = [
            'requests' => 'required|array',
            'atomic' => 'boolean'
        ];
        $this->validateJson($request, $rules);

        $requestArrays = $request->json('requests');
        $atomic = $request->json('atomic');

        foreach ($requestArrays as $key => $value) {
            $rules['requests.'.$key.'.method'] = 'required|in:POST,PUT,PATCH';
            $rules['requests.'.$key.'.body'] = 'required|array';
            $rules['requests.'.$key.'.path'] = 'required';
        }

        $this->validateJson($request, $rules);

        $responses = array();

        if($atomic) {
            DB::beginTransaction();
        }

        foreach ($requestArrays as $i => $requestArray) {

            // replace what the bound request and facade use
            $request->setMethod($requestArray['method']);
            $request->replace($requestArray['body']);
            $path = dirname($request->path()).'/'.$requestArray['path'];
            // create a request to dispatch
            $req = $request->create($path, $requestArray['method']);

            try {
                $response = \Route::dispatch($req);
            }catch(NotFoundHttpException $e){
                $response = resolve(ExceptionHandler::class)->render($req, $e);
            }

            $responseDict = ['body' => json_decode($response->getContent()), 'status' => $response->getStatusCode()];

            $responses[] = $responseDict;

            if(!$response->isSuccessful() && $atomic){
                $failedDependencyException = new FailedDependencyException('Skipped because atomic operation failed');
                $response = resolve(ExceptionHandler::class)->render($req, $failedDependencyException);

                for($j=$i+1; $j < count($requestArrays); $j++){
                    $responses[] = ['body' => json_decode($response->getContent()), 'status' => $response->getStatusCode()];;
                }
                /*
                    $status = Response::HTTP_FAILED_DEPENDENCY;
                    $reason = Response::$statusTexts[$status];
                    // error the remaining

                    for($j=$i+1; $j < count($requestArrays); $j++){
                        $responses[] = ['status' => $status,
                            'body' => ['error' => 'DependencyException', 'reason' => ]];
                    }
                */
                DB::rollBack();
                return response(['responses' => $responses], Response::HTTP_BAD_REQUEST);
            }
        }

        DB::commit();

        return response(['responses' => $responses]);

    }
}