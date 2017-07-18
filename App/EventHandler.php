<?php

namespace App;

use App\Cmd;
use App\Log;
use App\Store;
use Exception;
use Maknz\Slack\Client;
use Mandrill;

/**
* EventHandler
*/
class EventHandler
{


    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new EventHandler();
        }
        return self::$INSTANCE;
    }


    public function handleEvent($event) {
        $service_id = $event['ServiceID'];
        if (!strlen($service_id)) { return; }

        $name = $event['Name'];
        $check_id = $event['CheckID'];
        $status = $event['Status'];
        $is_up = ($status == 'passing');

        Log::debug("$name: ".($is_up?'UP':'DOWN')."");
        $last_state = $this->lastState($check_id);
        $last_state_status = ($last_state ? $last_state->status : null);

        Log::debug("$name: \$last_state = $last_state_status ".($last_state ? $last_state->timestamp : null));

        if ($is_up) {
            if ($last_state_status == 'up') { return; }

            // save state
            $this->changeStatus('up', $check_id, $event['Name']);
        } else {
            // down
            if ($last_state_status == 'down') { return; }

            // save state
            $note = ltrim($event['Notes']."\n".$event['Output']);
            $this->changeStatus('down', $check_id, $event['Name'], $note);

        }
    }


    protected function lastState($check_id) {
        $state = $this->store->findByID($check_id);
        if ($state === null) { return null; }
        return $state;
    }

    protected function changeStatus($status, $check_id, $name, $note=null) {
        print "Now ".(strtoupper($status))." $check_id\n";
        $state = $this->store->findOrCreateState($check_id);
        $state->name             = $name;
        $state->status           = $status;
        $state->timestamp        = time();
        $state->{"last_$status"} = time();
        $state->note = ($note === null ? '' : $note);
        $this->store->storeState($state);
    }

    protected function __construct() {
        $this->store = Store::instance();
    }


}
