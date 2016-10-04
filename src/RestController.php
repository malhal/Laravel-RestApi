<?php
/**
 *  Laravel-RestApi (http://github.com/malhal/Laravel-RestApi)
 *  RestController.php
 *
 *  Created by Malcolm Hall on 2/9/2016.
 *  Copyright © 2016 Malcolm Hall. All rights reserved.
 */

namespace Malhal\RestApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RestController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * If the requests should be authorized using the Model's policy.
     *
     * @var bool
     */
    protected $authorizeRequests = true;

    /**
     * The request rules for createModel. The Model's routeKeyName will be removed automatically.
     *
     * @var array
     */
    protected $createRules = [];

    /**
     * The request rules replaceModel, if not set the getter falls back to createRules.
     *
     * @var array
     */
    protected $replaceRules;

    /**
     * The request rules for modifyModel.
     *
     * @var array
     */
    protected $modifyRules = [];

    protected $modelClass;

    protected function removeRouteKeyNameFromArray($array){
        if(isset($array)) {
            $routeKeyName = $this->newModel()->getRouteKeyName(); // defaults to 'id'
            if (array_key_exists($routeKeyName, $array)) {
                unset($array[$routeKeyName]);
            }
        }
        return $array;
    }

    protected function getCreateRules(){

        return $this->removeRouteKeyNameFromArray($this->createRules);
    }

    protected function getReplaceRules(){
        return $this->removeRouteKeyNameFromArray($this->replaceRules) ?: $this->getCreateRules();
    }

    protected function getModifyRules(){
        return $this->removeRouteKeyNameFromArray($this->modifyRules);
    }

    protected function getModelClass(){
        return $this->modelClass;
    }

    protected function getAuthorizeRequests(){
        return $this->authorizeRequests;
    }

    public function __construct()
    {
        // Switch to our handler that converts exceptions to JSON.
        \App::singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            RestHandler::class
        );

        // If an api_token was set this exceptions if it was invalid, rather than assuming guest.
        $this->middleware(VerifyApiToken::class);

        // get the user if it was already set, e.g. a guest user by WorldServiceProvider
        $user = Auth::user();

        // switch to token guard
        Auth::shouldUse('api');

        // if we dont have a user and we had one with the previous guard then set the user.
        if(!Auth::check()){
            if(!is_null($user)) {
                Auth::setUser($user);
            }
        }
    }

    /**
     * The model used for the controller, this default implementation determines the model from name of this controller.
     *
     * @var \Illuminate\Database\Eloquent\Model  $model
     */
    protected function newModel(){
        $modelClass = $this->getModelClass();
        return new $modelClass;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $model = $this->newModel();
        if($this->getAuthorizeRequests()){
            $this->authorize('read', $model);
        }
        return $this->restList($request, $model->newQuery());
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
        return DB::transaction(function () use ($request) {
            $model = $this->newModel();
            if ($this->getAuthorizeRequests()) {
                $this->authorize('create', $model);
            }
            $this->validateJson($request, $this->getCreateRules());
            return $this->restCreate($request, $model);
        });
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        // todo: move this into a custom model binding middleware.
        $model = $this->newModel();

        if($this->getAuthorizeRequests()){
            $this->authorize('read', $model);
        }
        return $this->restView($request, $model->where($model->getRouteKeyName(), $id)->firstOrFail());
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
            if($this->getAuthorizeRequests()){
                try {
                    $this->authorize('read', $newModel);
                }catch(AuthorizationException $e){
                    // Hide the fact they don't have read access since they did a write operation
                    // Also hide if the model wasn't found for security reasons.
                    throw new ServiceUnavailableHttpException(null, "Failure updating");
                }
            }
            $query = $newModel->newQuery();

            if($request->method() == Request::METHOD_PATCH) {
                $model = $query->where($newModel->getRouteKeyName(), $id)->firstOrFail();

                if($this->getAuthorizeRequests()) {
                    $this->authorize('write', $model);
                }
                $this->validateJson($request, $this->getModifyRules());

                return $this->restModify($request, $model);
            }

            // PUT
            $model = $query->where($newModel->getRouteKeyName(), $id)->first();

            if(is_null($model)){
                $model = $newModel;
                if($this->getAuthorizeRequests()){
                    $this->authorize('create', $model);
                }
                $this->validateJson($request, $this->getCreateRules());
                $model->setAttribute($model->getRouteKeyName(), $id);
                return $this->restCreate($request, $model);
            }

            if($this->getAuthorizeRequests()){
                $this->authorize('write', $model);
            }
            $this->validateJson($request, $this->getReplaceRules());
            return $this->restReplace($request, $model);
        });
    }

    protected function restList(Request $request, $query){

        return $query->get();
    }

    protected function restView(Request $request, $model){
        return $model;
    }

    /**
     * Creates a new model via a POST or PUT when didn't previously exist, replaced param is false
     * and 'create' auth is checked and 201 returned.
     * If PUT and did previously exist replace is true and 'update' auth is checked and 200 returned.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  bool  $replace
     * @return \Illuminate\Http\Response
     */
    protected function restCreate(Request $request, $model){
        $model->fill($request->json()->all());
        $model->save();
        return response($model->makeHidden($model->getFillable()), Response::HTTP_CREATED);
    }

    protected function restReplace(Request $request, $model){
        foreach($model->getFillable() as $fillable){
            // prevent nulling the key they used to reference the record.
            if($fillable == $model->getRouteKeyName()){
                continue;
            }
            $model->setAttribute($fillable, null);
        }

        $model->update($request->json()->all());

        return response($model->makeHidden($model->getFillable()));
    }

    protected function restModify(Request $request, $model){
        $model->update($request->json()->all());

        return response($model->makeHidden($model->getFillable()));
    }

    protected function restDelete( Request $request, $model){

        $model->delete();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $newModel = $this->newModel();
        if($this->getAuthorizeRequests()){
            $this->authorize('write', $newModel);
        }
        DB::transaction(function () use ($id, $newModel) {
            $model = $newModel->where($newModel->getRouteKeyName(), $id)->first();
            if (!is_null($model)) {
                $this->restDelete($model);
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

    protected function denyIfGuest(Request $request, $message = null){
        $user = $request->getUser();

        if (is_null($user) || is_null($user->getKey())){
            throw new AuthenticationException($message);
        }
    }


}