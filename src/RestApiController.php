<?php
/**
 * Created by PhpStorm.
 * User: mh
 * Date: 21/08/2016
 * Time: 14:50
 */

namespace RestApi;

use App\Exceptions\Handler;
use App\Exceptions\RestApiHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RestApiController extends \App\Http\Controllers\BaseController
{

    public function __construct()
    {
        \App::singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            RestApiHandler::class
        );
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
        return $this->newModel()->findOrFail($id);
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
            $model = $newModel->newQuery()->find($id);

            // the resource does not already need to exist for it to be replaced.
            if(is_null($model)){
                $model = $newModel;
                $keyName = $model->getKeyName();
                $model->$keyName = $id;
                return $this->createModel($request, $model);
            }

            return $this->updateModel($request, $model);
        });
    }

    protected function updateModel(Request $request, $model)
    {
        if ($request->method() == Request::METHOD_PUT) {
            // clear all attributes
            foreach($model->getFillable() as $fillable){
                $model->$fillable = null;

            }
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
        }

        $this->validateJson($request, $rules);

        $responses = array();

        DB::beginTransaction();

        foreach ($requestArrays as $i => $requestArray) {

            // replace what the bound request and facade use
            $request->setMethod($requestArray['method']);
            $request->replace($requestArray['body']);

            // create a request to dispatch
            $req = $request->create($requestArray['url'], $requestArray['method']);

            $response = \Route::dispatch($req);

            $responseArray = ['body' => json_decode($response->getContent()), 'status' => $response->getStatusCode()];

            $responses[] = $responseArray;

            if(!$response->isSuccessful() && $atomic){
                /*
                    $status = Response::HTTP_FAILED_DEPENDENCY;
                    $reason = Response::$statusTexts[$status];
                    // error the remaining

                    for($j=$i+1; $j < count($requestArrays); $j++){
                        $responses[] = ['status' => $status,
                            'body' => ['error' => 'DependencyException', 'reason' => 'Skipped because atomic operation failed']];
                    }
                */
                DB::rollBack();
                break;
            }
        }

        DB::commit();

        return ['responses' => $responses];

    }
}