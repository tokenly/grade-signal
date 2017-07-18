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


    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new Store();
        }
        return self::$INSTANCE;
    }


    public function findOrCreateState($check_id) {
        $state = $this->findByID($check_id);
        if (!$state) {
            $state = $this->newState($check_id);
        }
        return $state;
    }

    public function newState($check_id, $status='up', $timestamp=null) {
        $state = R::dispense('state');

        $state->check_id  = $check_id;
        $state->status    = $status;
        $state->timestamp = ($timestamp === null ? time() : $timestamp);

        $check_id = R::store( $state );
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



    protected function __construct() {
        $db_path = BASE_PATH.'/data/data.db';

        try {
            R::setup('sqlite:'.$db_path);
        } catch (Exception $e) {
            Log::warn('failed to setup DB at '.$db_path)            ;
        }
    }



}

