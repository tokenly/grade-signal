<?php

namespace App;

use RedBeanPHP\R as R;
use App\Log;
use Exception;

/**
* Store
*/
class Store
{

    public static function databaseExists() {
        $db_path = BASE_PATH.'/data/data.db';
        return file_exists($db_path);
    }

    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new Store();
        }
        return self::$INSTANCE;
    }


    public function findOrCreateState($check_id, $create_vars) {
        $state = $this->findByID($check_id);
        if (!$state) {
            $state = $this->newState($check_id, $create_vars);
        }
        return $state;
    }

    public function newState($check_id, $create_vars) {
        $state = R::dispense('state');

        $state->check_id  = $check_id;
        foreach($create_vars as $key => $value) {
            $state->{$key} = $value;
        }

        R::store($state);
        return $state;
    }

    public function storeState($state) {
        R::store($state);
    }

    public function findAllStates() {
        $states = R::getAll('SELECT * FROM `state` ORDER BY `timestamp`'); 
        return R::convertToBeans('state', $states);
    }
    public function findAllStateIDs() {
        $states = R::getAll('SELECT check_id FROM `state` ORDER BY `timestamp`'); 
        $out = [];
        foreach($states as $state) {
            $out[] = $state['check_id'];
        }
        return $out;
    }
    public function findByID($check_id) {
        return R::findOne( 'state', 'check_id = ?', [ $check_id ] );
    }
    public function deleteState($state) {
        R::trash($state); 
    }

    public function destroyDatabase() {
        R::close();
        $db_path = BASE_PATH.'/data/data.db';
        unlink($db_path);
        R::$toolboxes = [];

        $this->initDB();
    }


    protected function __construct() {
        $this->initDB();
    }

    protected function initDB() {
        $db_path = BASE_PATH.'/data/data.db';

        try {
            R::setup('sqlite:'.$db_path);
        } catch (Exception $e) {
            Log::warn('failed to setup DB at '.$db_path." ".$e->getMessage());
        }
    }


}

