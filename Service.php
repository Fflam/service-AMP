<?php

namespace App\Services\AMP;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Services\ServiceInterface;
use App\Models\Package;
use App\Models\Order;

class Service implements ServiceInterface
{
    /**
     * Unique key used to store settings 
     * for this service.
     * 
     * @return string
     */
    public static $key = 'amp'; 

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
    
    /**
     * Returns the meta data about this Server/Service
     *
     * @return object
     */
    public static function metaData(): object
    {
        return (object)
        [
          'display_name' => 'AMP CubeCoders',
          'author' => 'WemX',
          'version' => '1.0.0',
          'wemx_version' => ['dev', '>=1.8.0'],
        ];
    }

    /**
     * Define the default configuration values required to setup this service
     * i.e host, api key, or other values. Use Laravel validation rules for
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setConfig(): array
    {
        // Check if the URL ends with a slash
        $doesNotEndWithSlash = function ($attribute, $value, $fail) {
            if (preg_match('/\/$/', $value)) {
                return $fail('AMP Panel URL must not end with a slash "/".');
            }
        };

        self::api('POST', '/ADSModule/GetDeploymentTemplates', ['test']);

        return [
            [
                "col" => "col-12",
                "key" => "amp::hostname",
                "name" => "Hostname",
                "description" => "Hostname of the AMP instance",
                "type" => "url",
                "rules" => ['required', 'active_url', $doesNotEndWithSlash], // laravel validation rules
            ],
            [
                "key" => "amp::username",
                "name" => "Username",
                "description" => "Username of an administrator on AMP Panel",
                "type" => "text",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "encrypted::amp::password",
                "name" => "User Password",
                "description" => "Password of an administrator on AMP Panel",
                "type" => "password",
                "rules" => ['required'], // laravel validation rules
            ],
        ];
    }

    /**
     * Define the default package configuration values required when creatig
     * new packages. i.e maximum ram usage, allowed databases and backups etc.
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setPackageConfig(Package $package): array
    {
        return [];
    }

    /**
     * Define the checkout config that is required at checkout and is fillable by
     * the client. Its important to properly sanatize all inputted data with rules
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setCheckoutConfig(Package $package): array
    {
        return [];
    }

    /**
     * Define buttons shown at order management page
     *
     * @return array
     */
    public static function setServiceButtons(Order $order): array
    {
        return [];    
    }

    /**
     * Init connection with API
    */
    public static function api($method, $endpoint, $data = [])
    {
        // retrieve the session ID
        $sessionID = Cache::get('AMP::SessionID');
        if(!$sessionID) {
            $session = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post(settings('amp::hostname'). "/API/Core/Login", [
                'username' => settings('amp::username'),
                'password' => settings('encrypted::amp::password'),
                'token' => '',
                'rememberMe' => false,
            ]);

            if($session->failed())
            {
                throw new \Exception("[AMP] Failed to retrieve session ID. Ensure the API details and hostname are valid.");
            }

            $sessionID = $session['sessionID'];
            if(!isset($session['sessionID']))
            {
                throw new \Exception("[AMP] Failed to retrieve session ID. Ensure the API details and hostname are valid.");
            }

            Cache::put('AMP::SessionID', $sessionID, 240);
        }

        // define the URL and data
        $url = settings('amp::hostname'). "/API{$endpoint}";
        $data['SESSIONID'] = $sessionID;

        // make the request
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->$method($url, $data);


        if($response->failed())
        {
            dd($response, $response->json(), $url);

            if($response->unauthorized() OR $response->forbidden()) {
                throw new \Exception("[AMP] This action is unauthorized! Confirm that API token has the right permissions");
            }

            // dd($response);
            if($response->serverError()) {
                throw new \Exception("[AMP] Internal Server Error: {$response->status()}");
            }

            throw new \Exception("[AMP] Failed to connect to the API. Ensure the API details and hostname are valid.");
        }

        dd($response, $response->json(), $url);


        return $response;
    }

    /**
     * This function is responsible for creating an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
     */
    public function create(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for upgrading or downgrading
     * an instance of this service. This method is optional
     * If your service doesn't support upgrading, remove this method.
     * 
     * Optional
     * @return void
    */
    public function upgrade(Package $oldPackage, Package $newPackage)
    {
        return [];
    }

    /**
     * This function is responsible for suspending an instance of the
     * service. This method is called when a order is expired or
     * suspended by an admin
     * 
     * @return void
    */
    public function suspend(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for unsuspending an instance of the
     * service. This method is called when a order is activated or
     * unsuspended by an admin
     * 
     * @return void
    */
    public function unsuspend(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        return [];
    }

}
