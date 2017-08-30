<?php

namespace App;

use App\Cmd;
use App\Log;
use App\Store;
use Exception;
use Mandrill;
use Tokenly\QuotebotClient\Client as QuotebotClient;
use Tokenly\QuotebotClient\Mock\MemoryCacheStore;

/**
* ExternalChecks
*/
class ExternalChecks
{

    // wait 15 sec before sending a notification after something changed
    const MIN_CHANGED_DELAY = 15;
    
    // never notify twice within this time
    const MIN_NOTIFIED_DELAY = 15;

    static $INSTANCE;

    public static function instance() {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new ExternalChecks();
        }
        return self::$INSTANCE;
    }

    public function getExternalCheckSpecs() {
        return [
            [
                'id'   => 'quotebot_freshness',
                'name' => 'Quotebot Freshness',
            ],
        ];
    }

    // returns [$status, $note]
    public function runCheck($check_id) {
        $method = 'runCheck_'.$check_id;
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method]);
        } else {
            // bad check
            throw new Exception("Bad check id: $check_id", 1);
        }
    }

    public function runCheck_quotebot_freshness() {
        $stale_seconds = env('QUOTEBOT_FRESHNESS_TTL', 1800); // 30 minutes default

        $success = true;
        $notes = [];
        $quotebot_client = new QuotebotClient(env('QUOTEBOT_CONNECTION_URL'), env('QUOTEBOT_API_TOKEN'), new MemoryCacheStore());
        $loaded_quote_data = $quotebot_client->loadRawQuotesData();
        foreach($loaded_quote_data['quotes'] as $loaded_quote) {
            $quote_timestamp = isset($loaded_quote['time']) ? strtotime($loaded_quote['time']) : 0;
            $seconds_old = time() - $quote_timestamp;
            if ($seconds_old >= $stale_seconds) {
                $notes[] = "Quote from provider {$loaded_quote['source']} for pair {$loaded_quote['pair']} was $seconds_old old.";
                $success = false;
            }
        }

        if ($success) {
            return ['up', null];
        }

        return ['down', implode("\n", $notes)];
    }


}
