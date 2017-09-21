<?php

namespace App;

use App\Store;
use App\Log;
use DateTime;
use DateTimeZone;
use Exception;

/**
* Store
*/
class State
{

    protected $state_record = null;

    public static function new($check_id, $create_vars) {
        return new self(Store::instance()->newState($check_id, $create_vars));
    }

    public static function findOrCreate($check_id, $create_vars) {
        return new self(Store::instance()->findOrCreateState($check_id, $create_vars));
    }

    public static function findByID($check_id) {
        $state_record = Store::instance()->findByID($check_id);
        if (!$state_record) { return null; }

        return new self($state_record);
    }

    // ------------------------------------------------------------------------

    public function setStatus($status, $note=null, $timestamp=null) {
        $update_vars = [];
        if ($this->state_record->status == $status) {
            // status not changed
        } else {
            // status changed
            $update_vars = [
                'status'    => $status,
                'timestamp' => $timestamp !== null ? $timestamp : time(),
                'note'      => ($note === null ? '' : $note),
            ];
        }
        if ($update_vars) {
            $this->update($update_vars);
            Log::debug("{$this->name} updated with ".json_encode($update_vars)." at ".$this->formatTimestamp('timestamp'));
        }
    }

    public function markAsNotified() {
        $update_vars = [
            'last_notified_timestamp' => time(),
            'last_notified_status'    => $this->state_record->status,
        ];
        $this->update($update_vars);
        Log::debug("{$this->name} markAsNotified with ".json_encode($update_vars)."");
    }

    public function formatTimestamp($timestamp_field='timestamp') {
        $timestamp = $this->state_record->{$timestamp_field};
        $date = new DateTime($timestamp > 0 ? ('@'.$timestamp) : ('@'.time()));
        $date->setTimezone(new DateTimeZone(env('TIMEZONE')));
        return $date->format('M. j, g:i:s A T');
    }
    
    public function __get($var) {
        return isset($this->state_record->{$var}) ? $this->state_record->{$var} : null;
    }

    public function __isset($var) {
        return isset($this->state_record->{$var});
    }

    public function update($update_vars) {
        foreach($update_vars as $_k => $_v) {
            $this->state_record->{$_k} = $_v;
        }
        Store::instance()->storeState($this->state_record);
    }

    // ------------------------------------------------------------------------
    
    protected function __construct($state_record) {
        $this->state_record = $state_record;
    }

}

