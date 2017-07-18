<?php

namespace App;

use App\Cmd;
use App\Log;
use App\Store;
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

    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new Notifier();
        }
        return self::$INSTANCE;
    }

    public function checkAllStates() {
        foreach ($this->store->findAllStateIDs() as $state_id) {
            $state = $this->store->findByID($state_id);
            if (!$state) { continue; }

            $notified_delay       = isset($state->last_notified_timestamp) ? $state->last_notified_timestamp : 86400;
            $last_changed_delay   = time() - (isset($state->timestamp) ? $state->timestamp : 0);
            $last_notified_status = isset($state->last_notified_status) ? $state->last_notified_status : null;

            // always wait for MIN_CHANGED_DELAY
            if ($last_changed_delay < self::MIN_CHANGED_DELAY) { continue; }

            // always wait for MIN_NOTIFIED_DELAY
            if ($notified_delay < self::MIN_NOTIFIED_DELAY) { continue; }

            if ($last_notified_status == $state->status) {
                // nothing changed

                //  maybe re-notify in the future...

            } else {
                // state changed

                // mark as notified
                $state->last_notified_timestamp = time();
                $state->last_notified_status = $state->status;
                $this->store->storeState($state);

                // notify
                switch ($state->status) {
                    case 'up':
                        $this->notify('up', $state->name, $state->check_id);
                        break;
                    
                    default:
                        $this->notify('down', $state->name, $state->check_id, $state->note);
                        break;
                }
            }
        }
    }



    public function notify($status, $name, $check_id, $note=null) {
        $should_email = !!getenv('EMAIL_NOTIFICATIONS') AND getenv('EMAIL_NOTIFICATIONS') != 'false';
        if ($should_email) {
            if ($status == 'up') {
                $this->email("Service UP: $name", "Service $name is now UP.\n\n".date("Y-m-d H:i:s"), $this->buildEmailRecipients($check_id));
            }
            if ($status == 'down') {
                $this->email("Service DOWN: $name", "Service $name is now DOWN.\n\n".($note?$note."\n\n":'').date("Y-m-d H:i:s"), $this->buildEmailRecipients($check_id));
            }
        }

        $should_slack = !!getenv('SLACK_NOTIFICATIONS') AND getenv('SLACK_NOTIFICATIONS') != 'false';
        if ($should_slack) {
            if ($status == 'up') {
                $this->slack($status, "$name", "Service $name is now UP.");
            }
            if ($status == 'down') {
                $this->slack($status, "$name", "Service $name is now DOWN.".($note ? "\n".$note : ''));
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


}
