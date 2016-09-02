<?php

namespace RestApi;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RestApiHandler extends \App\Exceptions\Handler
{

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        $error = ['error' => class_basename($exception)];
        $message = $exception->getMessage();
        $code = $exception->getCode();

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
        }
        else if($exception instanceof ValidationException){
            $error['validation'] = $exception->validator->errors()->getMessages();
            $statusCode = 422;
        }
        else if($exception instanceof QueryException){
            //var_export($exception);
            // hide the sql and bindings if not in debug.
            if(!config('app.debug')) {
                //$message = $exception->getPrevious()->getMessage();
            }
            if($code == 23000){
                $statusCode = 409;
            }
            else{
                $statusCode = 400;
            }

            if($exception->getPrevious() instanceof \PDOException) {
                $error['driverCode'] = $exception->errorInfo[1];
                $message = $exception->errorInfo[2];
            }
        }
        else if($exception instanceof AuthorizationException){
            $statusCode = 403;
        }else{
            $statusCode = 500;
        }
        // only add the reason if there is one.
        if(!empty($message)) {
            $error['reason'] = $message;
        }
        if(!empty($code)) {
            $error['code'] = $code;
        }
        return response()->json($error, $statusCode);
    }
}
