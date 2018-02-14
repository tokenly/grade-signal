<?php

namespace App;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
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

    public static function instance()
    {
        if (self::$INSTANCE === null) {
            self::$INSTANCE = new ExternalChecks();
        }
        return self::$INSTANCE;
    }

    public function getExternalCheckSpecs()
    {
        if (!isset($this->external_check_specs)) {
            $this->external_check_specs = [
                [
                    'id' => 'quotebot_freshness',
                    'method' => 'quotebot_freshness',
                    'name' => 'Quotebot Freshness',
                    'params' => [],
                ],
            ];

            $env_specs = json_decode(env('RABBIT_QUEUE_CHECKS'), true);
            if ($env_specs) {
                $this->external_check_specs = array_merge($this->external_check_specs, $env_specs);
            }
        }
        return $this->external_check_specs;
    }

    // returns [$status, $note]
    public function runCheck($spec)
    {
        $method_suffix = $spec['method'];
        $method = 'runCheck_' . $method_suffix;
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method], $spec['params']);
        } else {
            // bad check
            throw new Exception("Bad method: $method_suffix", 1);
        }
    }

    public function runCheck_quotebot_freshness($parameters)
    {
        $stale_seconds = env('QUOTEBOT_FRESHNESS_TTL', 1800); // 30 minutes default

        $success = true;
        $notes = [];
        $quotebot_client = new QuotebotClient(env('QUOTEBOT_CONNECTION_URL'), env('QUOTEBOT_API_TOKEN'), new MemoryCacheStore());
        $loaded_quote_data = $quotebot_client->loadRawQuotesData();
        foreach ($loaded_quote_data['quotes'] as $loaded_quote) {
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

    public function runCheck_rabbmitmq_queue_rate($parameters)
    {
        $notes = [];
        $status = 'up';

        $query = [
            'msg_rates_age' => $parameters['age'],
            'msg_rates_incr' => $parameters['age'] / 4,
        ];
        $result = $this->callRabbitmqAPI('queues/'.urlencode($parameters['vhost']).'/'.$parameters['queue'], $query);
        // echo "\$result: ".json_encode($result, 192)."\n";

        // get_no_ack_details
        $stats_to_check = ['ack','get_no_ack'];
        foreach($stats_to_check as $stat_name) {
            $checks = $parameters[$stat_name];
            $rate = $result['message_stats'][$stat_name.'_details']['avg_rate'];
            if (isset($checks['min']) AND $rate < $checks['min']) {
                $notes[] = "$stat_name was $rate. Needs to be {$checks['min']}";
                $status = 'down';
            }
            if (isset($checks['max']) AND $rate > $checks['max']) {
                $notes[] = "$stat_name was $rate.  Must be less than {$checks['max']}";
                $status = 'down';
            }
        }

        if (isset($parameters['messages'])) {
            $msg_checks = $parameters['messages'];
            $msg_count = $result['messages'];
            if (isset($msg_checks['min']) AND $msg_count < $msg_checks['min']) {
                $notes[] = "messages was $msg_count. Needs to be {$msg_checks['min']}";
                $status = 'down';
            }
            if (isset($msg_checks['max']) AND $msg_count > $msg_checks['max']) {
                $notes[] = "messages was $msg_count.  Must be less than {$msg_checks['max']}";
                $status = 'down';
            }
        }
        
        return [$status, implode("\n", $notes)];
    }

    // http://rabbitmq.lb.stagesvc.tokenly.co/api/queues/chainscout/bitcoinTestnet_tx?lengths_age=600&lengths_incr=5&msg_rates_age=600&msg_rates_incr=5&data_rates_age=600&data_rates_incr=5

    protected function callRabbitmqAPI($path, $query=[]) {
        $api_path = '/api/'.$path;

        $client = new GuzzleClient();

        $options = [
            'auth' => [env('RABBITMQ_LOGIN'), env('RABBITMQ_PASSWORD')],
        ];

        $request = new \GuzzleHttp\Psr7\Request('GET', env('RABBITMQ_API_URL').$api_path);
        $request = \GuzzleHttp\Psr7\modify_request($request, ['query' => http_build_query($query, null, '&', PHP_QUERY_RFC3986)]);

        // send request
        try {
            // echo "\$request: ".\GuzzleHttp\Psr7\str($request)."\n";
            $response = $client->send($request, $options);
        } catch (RequestException $e) {
            if ($response = $e->getResponse()) {
                // interpret the response and error message
                $code = $response->getStatusCode();
                try {
                    $json = json_decode($response->getBody(), true);
                } catch (Exception $parse_json_exception) {
                    // could not parse json
                    $json = null;
                }
                if ($json and isset($json['message'])) {
                    throw new Exception($json['message'], $code);
                }
            }

            // if no response, then just throw the original exception
            throw $e;
        }

        // echo "\$response: ".\GuzzleHttp\Psr7\str($response)."\n";

        $code = $response->getStatusCode();
        if ($code == 204) {
            // empty content
            return [];
        }

        $json = json_decode($response->getBody(), true);
        if (!is_array($json)) { throw new Exception("Unexpected response", 1); }

        return $json;
    }

}
