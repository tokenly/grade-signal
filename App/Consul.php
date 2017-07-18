<?php

namespace App;

use App\Cmd;
use App\Log;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
* Consul
*/
class Consul
{


    static $INSTANCE;

    protected $guzzle_client = null;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new Consul();
        }
        return self::$INSTANCE;
    }


    public function healthUp($container_name) {
        $this->setKeyValue("health/$container_name", 1);
    }
    public function healthDown($container_name) {
        $this->deleteKey("health/$container_name");
    }




    public function setKeyValue($key, $value) {
        $this->guzzle_client->put('/v1/kv/'.urlencode($key), ['body' => (string)$value]);
    }

    public function deleteKey($key) {
        $this->guzzle_client->delete('/v1/kv/'.urlencode($key));
    }

    public function getKeyValue($key) {
        try {
            $response = $this->guzzle_client->get('/v1/kv/'.urlencode($key));
        } catch (ClientException $e) {
            $e_respone = $e->getResponse();
            if ($e_respone->getStatusCode() == 404) {
                return null;
            }
            throw $e;
        }

        // decode this...
        $response_data = json_decode($response->getBody(), true);
        return base64_decode($response_data[0]['Value']);

    }


    protected function __construct() {
        $this->guzzle_client = new Client(['base_url' => getenv('CONSUL_HOST')]);
    }



}

