<?php

namespace App;

use App\Cmd;
use App\ExternalChecks;
use App\Log;
use App\Store;
use DateTime;
use DateTimeZone;
use Exception;
use Maknz\Slack\Client;
use Mandrill;

/**
* Notifier
*/
class Notifier
{

    // wait 15 sec before sending a notification after something changed
    const MIN_CHANGED_DELAY = 15;
    
    // never notify twice within this time
    const MIN_NOTIFIED_DELAY = 15;

    // re-notify about down states after this amount of time
    const RENOTIFY_DELAY = 3600 * 4; // 4 hours

    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new Notifier();
        }
        return self::$INSTANCE;
    }

    public function checkAllStates() {
        $all_found_states_by_name = [];
        foreach ($this->store->findAllStateIDs() as $state_id) {
            $state = $this->store->findByID($state_id);
            if (!$state) { continue; }

            // update $all_found_states_by_name
            $all_found_states_by_name[$state->name] = $state;

            $this->notifyStateIfChanged($state, self::RENOTIFY_DELAY);
        }

        // find any missing required checks
        $names = $this->getRequiredCheckNames();
        foreach($names as $name) {
            $not_found_check_id = "notfound:".$name;
            $state = $this->store->findByID($not_found_check_id);
            if (!$state) {
                $state = $this->store->newState($not_found_check_id, 'up', 0);
                $state->name                    = $name." (Exists)";
                $state->last_notified_timestamp = 0;
                $state->timestamp               = 0;
                $state->last_notified_status    = null;
            }

            if (isset($all_found_states_by_name[$name])) {
                // already found - copy the state status
                $state->status = $all_found_states_by_name[$name]->status;
            } else {
                // not found - mark it down
                $state->status = 'down';
            }

            $this->notifyStateIfChanged($state, self::RENOTIFY_DELAY);
        }

        // do external notification checks (outside of consul)
        $external_checks = ExternalChecks::instance();
        $specs = $external_checks->getExternalCheckSpecs();
        foreach($specs as $spec) {
            $name = $spec['name'];
            $check_id = 'external:'.$spec['id'];
            $state = $this->store->findByID($check_id);
            if (!$state) {
                // new state
                $state = $this->store->newState($check_id, 'up', 0);
                $state->name                    = $name;
                $state->last_notified_timestamp = 0;
                $state->timestamp               = 0;
                $state->last_notified_status    = null;
            }

            list($status, $note) = $external_checks->runCheck($spec['id']);
            if ($note !== null) {
                Log::debug("$name note: $note");
            }
            $state->note = $note;
            $state->status = $status;

            $this->notifyStateIfChanged($state, self::RENOTIFY_DELAY);
        }
    }


    public function notify($status, $name, $state_change_timestamp, $check_id, $note=null) {
        $should_email = !!getenv('EMAIL_NOTIFICATIONS') AND getenv('EMAIL_NOTIFICATIONS') != 'false';

        $date = new DateTime($state_change_timestamp > 0 ? '@'.$state_change_timestamp : 'now', new DateTimeZone(env('TIMEZONE')));
        $date_string = $date->format('M. j, h:i:s A T');

        if ($should_email) {
            if ($status == 'up') {
                $this->email("Service UP: $name", "Service $name is now UP.\n\n".$date_string, $this->buildEmailRecipients($check_id));
            }
            if ($status == 'down') {
                $this->email("Service DOWN: $name", "Service $name is now DOWN.\n\n".($note?$note."\n\n":'').$date_string, $this->buildEmailRecipients($check_id));
            }
        }

        $should_slack = !!getenv('SLACK_NOTIFICATIONS') AND getenv('SLACK_NOTIFICATIONS') != 'false';
        if ($should_slack) {
            if ($status == 'up') {
                $this->slack($status, "$name", "Service $name is UP as of {$date_string}.");
            }
            if ($status == 'down') {
                $this->slack($status, "$name", "Service $name is DOWN as of {$date_string}.".($note ? "\n".$note : ''));
            }
        }

        print "Notify: ".'*** '.$name.' ('.$check_id.') ***'."\n"."    Service $name is now ".(strtoupper($status)).".".($note ? "\n    ".$note : '')."\n\n";

    }


    protected function buildEmailRecipients($check_id) {
        $recipients = [
            [
                'email' => getenv('EMAIL_RECIPIENT_EMAIL'),
                'name'  => getenv('EMAIL_RECIPIENT_NAME'),
                'type'  => 'to',
            ],
        ];

        if (getenv('EMAIL_CC_EMAILS')) {
            $emails = explode('|', getenv('EMAIL_CC_EMAILS'));
            $names = explode('|', getenv('EMAIL_CC_NAMES'));

            foreach($emails as $offset => $email) {
                $recipients[] = [
                    'email' => $email,
                    'name'  => isset($names[$offset]) ? $names[$offset] : '',
                    'type'  => 'cc',
                ];
            }
        }

        return $recipients;
    }

    protected function email($subject, $text, $recipients) {
        try {
                
            // [
            //     'email' => 'recipient.email@example.com',
            //     'name'  => 'Recipient Name',
            //     'type'  => 'to',
            // ]
            $mandrill = new Mandrill(getenv('MANDRILL_API_KEY'));
            $message = array(
                'text'       => $text,
                'subject'    => $subject,
                'from_email' => getenv('EMAIL_FROM_EMAIL'),
                'from_name'  => getenv('EMAIL_FROM_NAME'),
                'to'         => $recipients,
                'headers'    => array('Reply-To' => 'no-reply@tokenly.co'),
            );
            $results = $mandrill->messages->send($message, true);
            $result = (($results and is_array($results)) ? $results[0] : $results);
            if (!$result OR $result['status'] != 'sent') {
                throw new Exception("Failed to send email: ".json_encode($result, 192), 1);
            }

        } catch (Exception $e) {
            Log::logError($e);
        }

    }


    protected function slack($status, $subject, $text) {
        $client = $this->getSlackClient();
        $client
            ->from($status == 'up' ? getenv('SLACK_USERNAME_UP') : getenv('SLACK_USERNAME_DOWN'))
            ->withIcon($status == 'up' ? ':white_check_mark:' : ':exclamation:')
            ->send('*'.$subject.'*'."\n".$text);

    }

    protected function getSlackClient() {
        if (!isset($this->slack_client)) {
            $settings = [
                'username'   => getenv('SLACK_USERNAME_UP'),
                'channel'    => getenv('SLACK_CHANNEL'),
                'link_names' => true,
            ];
            echo "endpoint: ".getenv('SLACK_ENDPOINT')."\n";
            $this->slack_client = new Client(getenv('SLACK_ENDPOINT'), $settings);
        }
        return $this->slack_client;
    }


    protected function __construct() {
        $this->store = Store::instance();
    }


    protected function getRequiredCheckNames() {
        if (!isset($this->required_check_names)) {
            $this->required_check_names = [];

            $json_string = getenv('REQUIRED_SERVICE_CHECK_NAMES');
            if (strlen($json_string)) {
                $this->required_check_names = array_values(json_decode($json_string, true));
            }
            Log::debug('REQUIRED_SERVICE_CHECK_NAMES '.json_encode($json_string, 192));
            Log::debug('$this->required_check_names '.json_encode($this->required_check_names, 192));
        }
        return $this->required_check_names;
    }

    protected function notifyStateIfChanged($state, $renotify_down_delay=null) {
        $notified_delay       = isset($state->last_notified_timestamp) ? (time() - $state->last_notified_timestamp) : 86400;
        $last_changed_delay   = time() - (isset($state->timestamp) ? $state->timestamp : 0);
        $last_notified_status = isset($state->last_notified_status) ? $state->last_notified_status : null;

        // always wait for MIN_CHANGED_DELAY
        if ($last_changed_delay < self::MIN_CHANGED_DELAY) { return; }

        // always wait for MIN_NOTIFIED_DELAY
        if ($notified_delay < self::MIN_NOTIFIED_DELAY) { return; }

        $should_notify = true;
        if ($last_notified_status == $state->status) {
            // nothing changed
            $should_notify = false;

            // notify again if still down after $renotify_down_delay
            if ($renotify_down_delay !== null AND $state->status != 'up') {
                $time_since_last_notification = time() - $state->last_notified_timestamp;
                if ($time_since_last_notification >= $renotify_down_delay) {
                    $should_notify = true;
                }
            }
        }


        if ($should_notify) {
            // state changed

            // mark as notified
            $state->last_notified_timestamp = time();
            $state->last_notified_status = $state->status;
            $this->store->storeState($state);

            // notify
            switch ($state->status) {
                case 'up':
                    $this->notify('up', $state->name, $state->timestamp, $state->check_id);
                    break;
                
                default:
                    $this->notify('down', $state->name, $state->timestamp, $state->check_id, $state->note);
                    break;
            }
        }    
    }

}
