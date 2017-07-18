<?php

namespace App;

use App\Log;
use Dotenv;

/**
* Environment
*/
class Environment
{
    
    public static function init($base_path) {
        define('BASE_PATH', realpath($base_path));

        $env_file = '.env';
        if (!file_exists(BASE_PATH.'/.env')) {
            if (file_exists(BASE_PATH.'/.env.example')) {
                $env_file = '.env.example';
            } else {
                throw new Exception(".env or .env.example file not found", 1);
            }
        }
        Dotenv::load(BASE_PATH, $env_file);

        Log::initMonolog();
    }

 
}
