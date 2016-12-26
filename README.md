# Laravel-RestApi
A controller and handler that lets you easily build a REST API in Laravel that uses [fine grained CRUD resources](https://www.thoughtworks.com/insights/blog/rest-api-design-resource-modeling). It also provides consistent error responses using Laravel's built-in exception handling.

First in your model you want to use be sure to add a $fillable param with all the fields you would like to populate via the API methods, e.g.

    protected $fillable = ['name', 'formattedAddress', 'latitude', 'longitude'];

Note this is important because it is also used to hide these fields from output when not needed. E.g. when a record is created we do not want to send the data back down to client. It would be much more complicated to reflect these fields from the database so we simply make use of this property.

Then, make a controller subclass of RestController, e.g. VenueController:

    use Malhal\RestApi\RestController;
    
    class VenueController extends RestController
    {
    
    }

Finally, in your api.php routes file add:

    Route::resource('venue', 'VenueController',  ['except' => [
        'create', 'edit'
    ]]);

Now you can POST to api/venue to create a venue, PUT to replace it, or PATCH to update it. You can also use GET to query or api/venue/1 to get an individial record.

As an added bonus, to support batch updates add:

    $this->post('batch', '\Malhal\RestApi\RestController@batch');
    
And post a JSON with a requests array like this:

    {
        "atomic" : true,
        "requests":
        [
            {
                "url" : "api/venue/1000",
                "method" : "PUT",
                "body" : {
                    "name" : "Test Venue"
                    }
            },
            {
                "url" : "api/password",
                "method" : "POST",
                "body" : {
                    "password" : "12345679",
                    "venue_id" : 1000
                    }
            }
        ]
    }

And set the atomic flag to make it rollback if a request fails.

If you want to add validation then simply override a method and change the query if necessary, e.g.

    class PasswordController extends RestController
    {
        public function index(Request $request)
        {
            $this->validateQuery($request, [
                'venue_id' => 'required|integer'
            ]);
            return $this->newModel()->where('venue_id', $request->get('venue_id'))->get();
        }
    
        public function store(Request $request){
            $this->validateJson($request, [
                'venue_id' => 'required|integer'
            ]);
            return parent::store($request);
        }
    }

Now if a password is posted and is missing a venue_id then the following error is returned by the RestHandler:

    {
      "error": "QueryException",
      "driverCode": "1364",
      "reason": "Field 'name' doesn't have a default value",
      "code": "HY000"
    }

## Installation

[PHP](https://php.net) 5.6.4+ and [Laravel](http://laravel.com) 5.3+ are required.

To get the latest version of Laravel-RestApi, simply require the project using [Composer](https://getcomposer.org):

```bash
$ composer require malhal/laravel-restapi dev-master
```
