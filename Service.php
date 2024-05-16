<?php

namespace App\Services\AMP;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Services\ServiceInterface;
use Illuminate\Http\Request;
use App\Models\Settings;
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
        $templates = Service::api('/ADSModule/GetDeploymentTemplates', [])->collect()->mapWithKeys(function ($item) {
            if(!isset($item['Id']) OR !isset($item['Name'])) {
                throw new \Exception("Could not retrieve a list of deployable templates, create a template on AMP first.");
            }
            
            return [$item['Id'] => $item['Name']];
        });
        
        return [
            [
                "col" => "col-12",
                "key" => "template",
                "name" => "Template ",
                "description" => "Select the template to deploy for this package",
                "type" => "select",
                "options" => $templates->toArray(),
                "save_on_change" => true,
                "rules" => ['required'],
            ],
            [
                "col" => "col-12",
                "key" => "post_create_action",
                "name" => "Post Create Action ",
                "description" => "Choose what the application does inside the instance",
                "type" => "select",
                "options" => [
                    0 => 'Do Nothing',
                    1 => 'Update Once',
                    2 => 'Update Always',
                    3 => 'Update and Start Once',
                    4 => 'Update and Start Always',
                    5 => 'Start Always',
                ],
                "save_on_change" => true,
                "rules" => ['required'],
            ]
        ];
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
        return [
            [
                "name" => "Login to Panel",
                "color" => "primary",
                "href" => settings('amp::hostname'),
                "target" => "_blank", // optional
            ],
        ];    
    }

    /**
     * Change the AMP password
     */
    public function changePassword(Order $order, string $newPassword)
    {
        try {
            $ampUser = $order->getExternalUser();

            $response = Service::api('/Core/ResetUserPassword', [
                'Username' => $ampUser->username,
                'NewPassword' => $newPassword,
            ]);

            if($response->failed())
            {
                throw new \Exception("AMP failed to reset password. Please try again.");
            }

            $order->updateExternalPassword($newPassword);
        } catch (\Exception $error) {
            return redirect()->back()->withError("Something went wrong, please try again.");
        }

        return redirect()->back()->withSuccess("Password has been changed");
    }

    /**
     * This function is responsible for creating an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
     */
    public function create(array $data = [])
    {
        // define the order, user and package
        $order = $this->order;
        $user = $order->user;
        $package = $order->package;

        // define user data
        $externalId = 'WMX'.$order->id;
        $username = $user->username . rand(1, 1000);
        $password = str_random(12);

        // if AMP user already exists, set the username
        $externalUser = $order->getExternalUser();
        if($externalUser) {
            $username = $externalUser->username;
        }

        $server = Service::api('/ADSModule/DeployTemplate', [
            'TemplateID' => $package->data('template'),
            'NewUsername' => $username, 
            'NewPassword' => $password,
            'NewEmail' => $user->email,
            'Tag' => $externalId,
            'FriendlyName' => $package->name,
            'Secret' => 'secretwemx'. $order->id,
            'PostCreate' => $package->data('post_create_action', 0),
            'RequiredTags' => [],
            'ExtraProvisionSettings' => [],
        ]);

        if($server->failed())
        {
            throw new \Exception("[AMP] Failed to create instance");
        }

        $order->setExternalId((string) $externalId);

        if(!$externalUser) {
            // create the external user
            $order->createExternalUser([
                'username' => $username,
                'password' => $password,
            ]);

            // finally, lets email the user their login details
            $user->email([
                'subject' => 'Game Panel Account',
                'content' => "Your account has been created on the game panel. You can login using the following details: <br><br> Username: {$username} <br> Password: {$password}",
                'button' => [
                    'name' => 'Game Panel',
                    'url' => settings('amp::hostname'),
                ],
            ]);
        }
    }

    /**
     * Handle the callback from the AMP server
    */
    public function callback(Request $request)
    {
        ErrorLog('amp:callback', json_encode($request->all()));
        return response()->json(['success' => true], 200);
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
        $order = $this->order;
        $server = Service::api('/ADSModule/SetInstanceSuspended', [
            'InstanceName' => $order->external_id,
            'Suspended' => true,
        ]);
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
        $order = $this->order;
        $server = Service::api('/ADSModule/SetInstanceSuspended', [
            'InstanceName' => $order->external_id,
            'Suspended' => false,
        ]);
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        $order = $this->order;
        $server = Service::api('/ADSModule/DeleteInstance', [
            'InstanceName' => $order->external_id,
        ]);
    }

    /**
     * Init connection with API
    */
    public static function api($endpoint, $data = [])
    {
        // retrieve the session ID
        $method = 'post';
        $sessionID = Cache::get('AMP::SessionID');
        if(!$sessionID) {
            $session = Http::withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])->post(settings('amp::hostname'). "/API/Core/Login", 
            [
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
            if(!isset($sessionID))
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
            if($response->unauthorized() OR $response->forbidden()) {
                throw new \Exception("[AMP] This action is unauthorized! Confirm that API token has the right permissions");
            }

            if($response->serverError()) {
                throw new \Exception("[AMP] Internal Server Error: {$response->status()}");
            }

            throw new \Exception("[AMP] Failed to connect to the API. Ensure the API details and hostname are valid.");
        }

        return $response;
    }

    /**
     * Test API connection
    */
    public static function testConnection()
    {
        try {
            // try to get list of packages through API request
            $templates = Service::api('/ADSModule/GetDeploymentTemplates', [])->collect()->mapWithKeys(function ($item) {
                if(!isset($item['Id']) OR !isset($item['Name'])) {
                    throw new \Exception("Could not retrieve a list of deployable templates, create a template on AMP first.");
                }

                return [$item['Id'] => $item['Name']];
            });
        } catch(\Exception $error) {
            // if try-catch fails, return the error with details
            return redirect()->back()->withError("Failed to connect to AMP. <br><br>[AMP] {$error->getMessage()}");
        }

        // if no errors are logged, return a success message
        return redirect()->back()->withSuccess("Successfully connected with AMP");
    }
}
