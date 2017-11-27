# Encrypted API - Laravel server
Create encrypted API communication between Laravel applications in a breeze. Request and response data is transmitted securely using a two-way cipher,
everything is checksummed to prevent modification (MITM attacks) in any way.

This package is meant to be used in your Laravel server application. It handles both receiving the request (verifying and decrypting)
and sending a request / response (encrypting and signing), the whole implementation is seamless and based only on a middleware.

The middleware transparently modifies the incoming request and replaces the request data with decrypted values, so you can use your own
`FormRequest`s, validation or any other code that you would normally use with a standard request (for example `$request->input('foo')`
inside controllers and so on).

You can extend the middleware to satisfy any specific needs you might have (for example multiple clients communicating securely each with it's own set
of shared secrets).

The called API routes should be served using HTTPS for extra security, but this is not a requirement.

This package authenticates the calling client, since no other caller knows the shared secrets. This ensures your API is securely called only
from applications under your or approved 3rd party control, even if the API routes themselves are publicly open to the internet.

# Installation
```
composer require kbs1/encrypted-api-server-laravel
```
The package is now installed. If you are using laravel version &lt; 5.5, add the following line in your `config/app.php` providers section:
```
Kbs1\EncryptedApiServerLaravel\Providers\EncryptedApiServiceProvider::class,
```

# Configuration
By default, the package supports encrypted communication with exactly one client, with one pair of shared secrets. First publish the config using
`php artisan vendor:publish --tag=encrypted-api` and set the appropriate `secret1` and `secret2` values (minimum 32 bytes in length).

For your convenience, `php artisan encrypted-api:secrets:generate` command is included to generate suitable shared secrets. Pass the `--save` option to
automatically publish the config if it hasn't been published already, and modify the config file in-place with new shared secrets. After executing the
command with the `--save` option, you can view the generated secrets by opening `config/encrypted_api.php`.

# Operation
Upon successful request decryption, all request data (except files) are replaced as if the request came in unencrypted. This includes the following `Request`
`Bag`s: `query`, `server`, `request`, `cookies`, `headers`. Request `content` is also overwritten, so if you are for example accepting XML in your service,
you can call `$request->getContent()` as you normally would to obtain XML data.

If request `Content-Type` was json, you can access the JSON request body as normal using `$request->json()`.

All native PHP variables are overwritten as well, so `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER` and `$_COOKIE` will contain expected values.
`php://input` is not overwritten (as this is not possible), so this stream will always contain the encrypted request.

Encrypted files transmission (using `multipart/form-data`) is not supported. If your service relies on files being posted via `multipart/form-data`,
you will have to modify your service and transmit the files as for example standard base64-encoded parameters.

Received encrypted requests always contain only basic headers required for encrypted JSON transmission (`Content-Type: application/json` and so on).
All of your other headers, including your `Content-Type`, `Cookie`, custom headers such as `X-Api-Auth: pwd123` and so on are always transmitted
securely (encrypted), not present in sent request. After decryption on the server side, original headers will be reconstructed as mentioned above.

This means you do not have to modify your service at all, and use cookies and custom headers as before, only now they will be transmitted encrypted.

PHP and Laravel's `Request` reconstruction works the same way as PHP would natively parse input data, using [parse_str](http://php.net/manual/en/function.parse-str.php).
This ensures that for example [array cookies](http://php.net/manual/en/function.setcookie.php) work as expected,
together with [input name mangling](http://php.net/manual/en/language.variables.external.php#81080), magic quotes gpc and so on,
so that your cookie or form variable `a.x=7` will still be present as `$request->input('a_x')` as it normally would if the request came in unencrypted.

Implementation also checks that the current request URI and query string match originally called route and query string. This ensures
no one can steal the request mid-way, and send it to another endpoint on your service.

Protection against replay attacks is also implemented, see "Replay attacks" section below.

# Usage
Once the package is installed, it automatically registers the `kbs1.encryptedApi-v1` middleware alias.
You can use this alias in any routes you would like to secure using this package.

```
Route::group(['prefix' => '/api', 'middleware' => ['kbs1.encryptedApi-v1']], function () {
	Route::post('/users/disable', 'App\Http\Controllers\Api\UsersController@disable')->name('myApp.api.users.disable');
	...
});
```
Above example automatically secures the route group using this package, any calls to the group must now be sent only using authenticated client application.
Default middleware implementation uses shared secrets defined in `config/encrypted_api.php`.

You can easily support multiple calling clients with secrets stored for example in a database. Extend the
`Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApi` class and implement your own `getSharedSecrets()` method:
```
class ClientApi extends \Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApi
{
	protected function getSharedSecrets($request)
	{
		$client = \App\Clients\ClientRepository::findByUuid($request->route('clientUuid'));
		return ['secret1' => $client->secret1, 'secret2' => $client->secret2];
	}
}
```
In the above example, the route group might look like this:
```
Route::group(['prefix' => '/api/{clientUuid}', 'middleware' => ['clientApi']], function () {
	Route::post('/users/disable', 'App\Http\Controllers\Api\Clients\UsersController@disable')->name('myApp.api.clients.users.disable');
	...
});
```

## A note on route URIs and query string parameters
The only parameter that should be passed unencrypted is the `clientUuid` or any other client identifier, as it is used to load shared secrets for
communication with said client. Only usable unencrypted parts of the request for this purpose are the request URI and query string.

Do not pass other sensitive information as a part of request URI or query string. While the request URI and query string are ensured not to be modified
after the request was made, passing any sensitive data as part of the request URI or query string defeats the security offered by this package.

Request URIs and query strings are often logged in HTTP server access logs, causing sensitive data leaks in this scenario. MITM sensitive data disclosure
attacks are also possible when passing sensitive data as a part of the request URI or query string when the service is called via plain HTTP.

## IP whitelists
If you want to ensure API calls from a certain client come only from whitelisted IPv4 addresses, you can set appropriate `ipv4_whitelist` array in
`config/encrypted_api.php`. To provide your own whitelist based on `clientUuid` or any other client identifier (if you have multiple calling clients),
override `getAllowedIps` method in your own route middleware class:
```
class ClientApi extends \Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApi
{
	protected function getAllowedIps($request)
	{
		$client = \App\Clients\ClientRepository::findByUuid($request->route('clientUuid'));
		return [$client->ipv4];
	}
}
```

# Replay attacks
This implementation protects using simple replay attacks, as each signed request and response has it's unique identifier, and is only valid for 10 seconds.
Implementation automatically stores each received identifier in the last 10 seconds on the server side, and discards any processing when encountering
already processed request identifier.

# Clients
Client implementations ([PHP](https://github.com/kbs1/encrypted-api-client-php), [NodeJS](https://github.com/kbs1/encrypted-api-client-nodejs)) provide
a convenient way to call your Encrypted API service. See the client repository for a detailed guide and description on how to use said client.
