<?php

namespace App;

use Exception;

/**
 * ErrorLogChecker
 */
class ErrorLogChecker
{

    protected $last_check_ts = [];

    public function getCheckSpecs()
    {
        if (!env('ENABLE_ERROR_LOG_CHECKS', false)) {
            return [];
        }

        // these will be defined by env variables
        $prototype_spec = [
            'id' => '',
            'method' => 'error_count',
            'name' => '',
            'length' => 1800, // 30 minutes
            'threshold' => 5, // 5 errors or more causes an error
        ];

        $specs = [];

        $server_specs = json_decode(env('ERROR_LOG_CHECKS', '[]'), true);
        foreach ($server_specs as $server_spec) {
            $server_spec = array_merge($prototype_spec, $server_spec);
            // echo "\$server_spec: " . json_encode($server_spec, 192) . "\n";

            $specs[] = [
                'id' => 'error_log_' . $server_spec['id'],
                'method' => 'error_log',
                'name' => $server_spec['name'] . ' Errors',
                'params' => [
                    'delay' => 60,
                    'log_name' => $server_spec['log_name'],
                    'length' => $server_spec['length'],
                    'threshold' => $server_spec['threshold'],
                    'error_log_method' => $server_spec['method'],
                ],
            ];
        }

        return $specs;
    }

    public function runCheck($params, $spec)
    {
        $id = $spec['id'] . ':' . $params['error_log_method'];
        $delay = $params['delay'];
        if (!isset($this->last_check_ts[$id])) {
            // $this->last_check_ts[$id] = time() - $delay + 2;
            $this->last_check_ts[$id] = time() - $delay;
        }
        $should_check = (time() - $this->last_check_ts[$id]) >= $delay;
        if (!$should_check) {
            return [null, null];
        }

        $method_suffix = $params['error_log_method'];
        $method = 'runCheck_' . $method_suffix;
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method], $params, $spec);
        } else {
            // bad check
            throw new Exception("Bad method: $method", 1);
        }
    }

    public function runCheck_error_count($params, $spec)
    {
        $notes = [];
        $status = 'up';

        // echo "runCheck_error_count: " . json_encode($params, 192) . "\n";
        $error_count = $this->getErrorCount($params);
        // echo "\$error_count: " . json_encode($error_count, 192) . "\n";
        if ($error_count >= $params['threshold']) {
            $notes[] = "Found $error_count errors in the last ".round($params['length'] / 60)." minutes.";
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }

    // ------------------------------------------------------------------------

    protected function getErrorCount($params)
    {
        $url = env('ELASTIC_SEARCH_CONNECTION_STRING') . '/_search';

        $end_ms = round(microtime(true) * 1000);
        $start_ms = $end_ms - ($params['length'] * 1000);

        $data = [
            'query' => [
                'bool' => [
                    'filter' => [],
                    'must' => [
                        [
                            'query_string' => [
                                'analyze_wildcard' => true,
                                'default_field' => '*',
                                'query' => '@log_name:' . $params['log_name'] . ' AND level:error',
                            ],
                        ],
                        [
                            'range' => [
                                '@timestamp' => [
                                    'format' => 'epoch_millis',
                                    'gte' => $start_ms,
                                    'lte' => $end_ms,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [],
                    'should' => [],
                ],
            ],
        ];

        $json_string = json_encode($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $json_string);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        curl_close($curl);

        $response_data = json_decode($response, true);
        if (!$response_data) {
            throw new Exception("Failed to decode response data", 1);
        }

        // echo "\$response_data: ".json_encode($response_data, 192)."\n";
        return $response_data['hits']['total'];
    }
}

/*
$response_data: {
"took": 20,
"timed_out": false,
"_shards": {
"total": 239,
"successful": 239,
"skipped": 234,
"failed": 0
},
"hits": {
"total": 1,
"max_score": 7.9009967,
"hits": [
{
"_index": "fluentd-20180428",
"_type": "fluentd",
"_id": "4buXC2MB6moaDoFedXqK",
"_score": 7.9009967,
"_source": {
"level": "ERROR",
"message": "proc_open(): fork failed - Cannot allocate memory",
"mt": 1524907861124200,
"_tag": "applog.markets.prod",
"@timestamp": "2018-04-28T09:31:01.000000000+00:00",
"@log_name": "applog.markets.prod"
}
}
]
}
}
 */
