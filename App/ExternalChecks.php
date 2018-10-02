<?php

namespace App;

use App\CryptoServerChecker;
use App\ErrorLogChecker;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Tokenly\TokenmapClient\Mock\MemoryCacheStore as TokenmapMemoryCacheStore;
use Tokenly\TokenmapClient\TokenmapClient;

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

    // save the last failure/success timestamp
    protected $fail_timestamps = [];

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
                    'id' => 'tokenmap_freshness',
                    'method' => 'tokenmap_freshness',
                    'name' => 'Tokenmap Freshness',
                    'params' => [],
                ],
            ];

            $env_specs = json_decode(env('RABBIT_QUEUE_CHECKS'), true);
            if ($env_specs) {
                $this->external_check_specs = array_merge($this->external_check_specs, $env_specs);
            }

            $crypto_server_checker = new CryptoServerChecker();
            $this->external_check_specs = array_merge($this->external_check_specs, $crypto_server_checker->getCheckSpecs());

            $error_log_checker = new ErrorLogChecker();
            $this->external_check_specs = array_merge($this->external_check_specs, $error_log_checker->getCheckSpecs());
        }
        return $this->external_check_specs;
    }

    // returns [$status, $note]
    public function runCheck($spec)
    {
        $method_suffix = $spec['method'];
        $method = 'runCheck_' . $method_suffix;
        if (method_exists($this, $method)) {
            try {
                return call_user_func([$this, $method], $spec['params'], $spec);
            } catch (Exception $e) {
                $status = 'down';
                $note = $e->getMessage();
                return [$status, $note];
            }
        } else {
            // bad check
            throw new Exception("Bad method: $method", 1);
        }
    }

    public function runCheck_tokenmap_freshness($parameters)
    {
        $stale_seconds = env('TOKENMAP_FRESHNESS_TTL', 1800); // 30 minutes default

        $success = true;
        $notes = [];
        $tokenmap_client = new TokenmapClient(env('TOKENMAP_CONNECTION_URL'), new TokenmapMemoryCacheStore());
        $loaded_quote_data = $tokenmap_client->loadAllQuotesData();
        foreach ($loaded_quote_data['quotes'] as $loaded_quote) {
            $quote_timestamp = isset($loaded_quote['time']) ? strtotime($loaded_quote['time']) : 0;
            $seconds_old = time() - $quote_timestamp;
            if ($seconds_old >= $stale_seconds) {
                $notes[] = "Tokenmap Quote from provider {$loaded_quote['source']} for pair {$loaded_quote['pair']} was $seconds_old seconds old.";
                $success = false;
            }
        }

        if ($success) {
            return ['up', null];
        }

        return ['down', implode("\n", $notes)];
    }

    public function runCheck_rabbmitmq_queue_rate($parameters, $check_spec)
    {
        $notes = [];
        $status = 'up';

        $query = [
            'msg_rates_age' => $parameters['age'],
            'msg_rates_incr' => $parameters['age'] / 4,
        ];
        $result = $this->callRabbitmqAPI('queues/' . urlencode($parameters['vhost']) . '/' . $parameters['queue'], $query);
        // echo "\$result: ".json_encode($result, 192)."\n";

        // get_no_ack_details
        $stats_to_check = ['ack', 'get_no_ack'];
        foreach ($stats_to_check as $stat_name) {
            $checks = $parameters[$stat_name] ?? [];
            $rate = $result['message_stats'][$stat_name . '_details']['avg_rate'];
            if (isset($checks['min']) and $rate < $checks['min']) {
                $notes[] = "$stat_name was $rate. Needs to be {$checks['min']}";
                $status = 'down';
            }
            if (isset($checks['max']) and $rate > $checks['max']) {
                $notes[] = "$stat_name was $rate.  Must be less than {$checks['max']}";
                $status = 'down';
            }
        }

        if (isset($parameters['messages'])) {
            $msg_checks = $parameters['messages'];
            $msg_count = $result['messages'];

            $min_passed = isset($msg_checks['min']) ? ($msg_count >= $msg_checks['min']) : true;
            $max_passed = isset($msg_checks['max']) ? ($msg_count <= $msg_checks['max']) : true;

            $min_passed = $this->modifyCheckDuration($check_spec['id'] . ':min', ($msg_checks['min_duration'] ?? 0), $min_passed);
            $max_passed = $this->modifyCheckDuration($check_spec['id'] . ':max', ($msg_checks['max_duration'] ?? 0), $max_passed);

            if (!$min_passed) {
                $notes[] = "messages was $msg_count. Needs to be {$msg_checks['min']}";
                $status = 'down';
            }
            if (!$max_passed) {
                $notes[] = "messages was $msg_count.  Must be less than {$msg_checks['max']}";
                $status = 'down';
            }
        }

        return [$status, implode("\n", $notes)];
    }

    protected function runCheck_crypto_server($params, $spec)
    {
        $crypto_server_checker = new CryptoServerChecker();
        return $crypto_server_checker->runCheck($params, $spec);
    }

    protected function runCheck_error_log($params, $spec)
    {
        $error_log_checker = new ErrorLogChecker();
        return $error_log_checker->runCheck($params, $spec);
    }

    protected function modifyCheckDuration($check_id, $required_fail_duration, $is_up)
    {
        if ($is_up) {
            unset($this->fail_timestamps[$check_id]);
            return true;
        } else {
            if (isset($this->fail_timestamps[$check_id])) {
                $last_failure = $this->fail_timestamps[$check_id];
            } else {
                // not set yet - set it now
                $last_failure = time();
                $this->fail_timestamps[$check_id] = $last_failure;
            }

            $duration = time() - $last_failure;

            $has_failed_long_enough = ($duration >= $required_fail_duration);
            if ($has_failed_long_enough) {
                return false;
            } else {
                return true;
            }
        }
    }

    // http://rabbitmq.lb.stagesvc.tokenly.co/api/queues/chainscout/bitcoinTestnet_tx?lengths_age=600&lengths_incr=5&msg_rates_age=600&msg_rates_incr=5&data_rates_age=600&data_rates_incr=5

    protected function callRabbitmqAPI($path, $query = [])
    {
        $api_path = '/api/' . $path;

        $client = new GuzzleClient();

        $options = [
            'auth' => [env('RABBITMQ_LOGIN'), env('RABBITMQ_PASSWORD')],
        ];

        $request = new \GuzzleHttp\Psr7\Request('GET', env('RABBITMQ_API_URL') . $api_path);
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
        if (!is_array($json)) {throw new Exception("Unexpected response", 1);}

        return $json;
    }

}
