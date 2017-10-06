<?php

namespace App;

use App\Cmd;
use App\Log;
use App\State;
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
        $node = $event['Node'];
        $check_id = md5($name.'@'.$node);
        $status = $event['Status'];
        $is_up = ($status == 'passing');

        $state = State::findOrCreate($check_id, [
            'name'            => $name,
            'consul_check_id' => $event['CheckID'],
        ]);

        if ($is_up) {
            $state->setStatus('up');
        } else {
            $note = ltrim($event['Notes']."\n".$event['Output']);
            $state->setStatus('down', $note);
        }
    }


    protected function lastState($check_id) {
        $state = $this->store->findByID($check_id);
        if ($state === null) { return null; }
        return $state;
    }


}
