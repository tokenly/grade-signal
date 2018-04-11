<?php

namespace App;

use Ethereum\Ethereum;
use Exception;
use Nbobtc\Command\Command;
use Nbobtc\Http\Client as BitcoindHttpClient;
use Tokenly\APIClient\TokenlyAPI;
use Tokenly\CounterpartyClient\CounterpartyClient;
use Tokenly\SubstationBlockchains\Bitcoin\Synchronizer\IndexdClient;
use Tokenly\SubstationBlockchains\Support\Pool\ConnectionPool;

/**
 * CryptoServerChecker
 */
class CryptoServerChecker
{

    protected $last_check_ts = [];

    public function getCheckSpecs() {
        if (!env('ENABLE_CRYPTO_SERVER_CHECKS', false)) {
            return [];
        }

        $server_specs = [
            [
                'id' => 'bitcoin',
                'method' => 'bitcoin',
                'name' => 'Bitcoin',
                'chain' => 'bitcoin',
            ],
            [
                'id' => 'bitcoin_testnet',
                'method' => 'bitcoin',
                'name' => 'Bitcoin Testnet',
                'chain' => 'bitcoinTestnet',
            ],

            [
                'id' => 'indexd',
                'method' => 'indexd',
                'name' => 'Indexd',
                'chain' => 'bitcoin',
            ],
            [
                'id' => 'indexd_testnet',
                'method' => 'indexd',
                'name' => 'Indexd Testnet',
                'chain' => 'bitcoinTestnet',
            ],

            [
                'id' => 'counterparty',
                'method' => 'counterparty',
                'name' => 'Counterparty',
                'chain' => 'counterparty',
            ],
            [
                'id' => 'counterparty_testnet',
                'method' => 'counterparty',
                'name' => 'Counterparty Testnet',
                'chain' => 'counterpartyTestnet',
            ],

            [
                'id' => 'counterparty_lite',
                'method' => 'counterparty_lite',
                'name' => 'Counterparty Lite',
                'chain' => 'counterparty',
            ],
            [
                'id' => 'counterparty_lite_testnet',
                'method' => 'counterparty_lite',
                'name' => 'Counterparty Lite Testnet',
                'chain' => 'counterpartyTestnet',
            ],

            [
                'id' => 'ethereum',
                'method' => 'ethereum',
                'name' => 'Ethereum',
                'chain' => 'ethereum',
            ],
            [
                'id' => 'ethereum_testnet',
                'method' => 'ethereum',
                'name' => 'Ethereum Testnet',
                'chain' => 'ethereumTestnet',
            ],
            [
                'id' => 'ethereum_indexd',
                'method' => 'ethereum_indexd',
                'name' => 'Ethereum Indexd',
                'chain' => 'ethereum',
            ],
            [
                'id' => 'ethereum_indexd_testnet',
                'method' => 'ethereum_indexd',
                'name' => 'Ethereum Indexd Testnet',
                'chain' => 'ethereumTestnet',
            ],
        ];

        $specs = [];

        foreach($server_specs as $server_spec) {
            $specs[] = [
                'id' => 'crypto_server_'.$server_spec['id'],
                'method' => 'crypto_server',
                'name' => $server_spec['name'].' Server',
                'params' => [
                    'delay' => 30,
                    'server' => $server_spec['id'],
                    'chain' => $server_spec['chain'],
                    'crypto_check_method' => $server_spec['method'],
                ],
            ];
        }

        return $specs;
    }

    public function runCheck($params, $spec)
    {
        $id = $spec['id'].':'.$params['chain'];
        $delay = $params['delay'];
        if (!isset($this->last_check_ts[$id])) {
            // $this->last_check_ts[$id] = time() - $delay + 2;
            $this->last_check_ts[$id] = time() - $delay;
        }
        $should_check = (time() - $this->last_check_ts[$id]) >= $delay;
        if (!$should_check) {
            return [null, null];
        }

        $method_suffix = $params['crypto_check_method'];
        $method = 'runCheck_' . $method_suffix;
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method], $params, $spec);
        } else {
            // bad check
            throw new Exception("Bad method: $method", 1);
        }
    }

    public function runCheck_bitcoin($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'bitcoin') {
            $bitcoind_pool = $this->buildBitcoindClientPool(
                env('BITCOIND_CONNECTION_STRING', 'http://localhost:8332'),
                env('BITCOIND_RPC_USER', null),
                env('BITCOIND_RPC_PASSWORD', null)
            );
        } else if ($params['chain'] == 'bitcoinTestnet') {
            $bitcoind_pool = $this->buildBitcoindClientPool(
                env('BITCOIND_TESTNET_CONNECTION_STRING', 'http://localhost:18332'),
                env('BITCOIND_TESTNET_RPC_USER', null),
                env('BITCOIND_TESTNET_RPC_PASSWORD', null)
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        $highest_block_count = 0;
        foreach ($bitcoind_pool->getConnections() as $offset => $bitcoind_client) {
            $command = new Command('getblockcount');
            $response = $bitcoind_client->sendCommand($command);
            if ($response->getStatusCode() == '200') {
                $body = $response->getBody();
                $body->rewind();
                $response_data = json_decode($body->getContents(), true);
                $block_count = $response_data['result'];
                $highest_block_count = max($highest_block_count, $block_count);
                if ($highest_block_count - $block_count >= 2) {
                    $notes[] = "{$spec['name']} ({$offset}) was 2 blocks away from tip.";
                }
                if ($block_count <= 0) {
                    $notes[] = "{$spec['name']} ({$offset}) returned 0 block height.";
                }
            } else {
                // failed
                $notes[] = "{$spec['name']} ({$offset}) returned status code ".$response->getStatusCode();
            }
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }

    public function runCheck_indexd($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'bitcoin') {
            $indexd_pool = $this->buildIndexdPool(
                env('INDEXD_CONNECTION_STRING', null)
            );
        } else if ($params['chain'] == 'bitcoinTestnet') {
            $indexd_pool = $this->buildIndexdPool(
                env('INDEXD_TESTNET_CONNECTION_STRING', null)
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        $highest_block_count = 0;
        foreach ($indexd_pool->getConnections() as $offset => $indexd_client) {
            try {
                $response = $indexd_client->getStatus();
                if (!$response['ready']) {
                    $notes[] = "{$spec['name']} ({$offset}) was not ready.";
                }
            } catch (Exception $e) {
                $notes[] = "{$spec['name']} ({$offset}) returned: ".$e->getMessage().".";
            }
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }


    public function runCheck_counterparty($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'counterparty') {
            $counterparty_pool = $this->buildCounterpartyClientPool(
                env('COUNTERPARTY_CONNECTION_STRING', 'http://127.0.0.1:4000'),
                env('COUNTERPARTY_RPC_USER', null),
                env('COUNTERPARTY_RPC_PASSWORD', null)
            );
        } else if ($params['chain'] == 'counterpartyTestnet') {
            $counterparty_pool = $this->buildCounterpartyClientPool(
                env('COUNTERPARTY_TESTNET_CONNECTION_STRING', 'http://127.0.0.1:14000'),
                env('COUNTERPARTY_TESTNET_RPC_USER', null),
                env('COUNTERPARTY_TESTNET_RPC_PASSWORD', null)
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        $highest_block_count = 0;
        foreach ($counterparty_pool->getConnections() as $offset => $counterparty_client) {
            try {
                $response = $counterparty_client->get_running_info();
                if (!$response['server_ready']) {
                    $notes[] = "{$spec['name']} ({$offset}) was not ready.";
                }
                if (!$response['db_caught_up']) {
                    $notes[] = "{$spec['name']} ({$offset}) was not caught up.";
                }

            } catch (Exception $e) {
                $notes[] = "{$spec['name']} ({$offset}) returned: ".$e->getMessage().".";
            }
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }

    public function runCheck_counterparty_lite($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'counterparty') {
            $counterparty_lite_pool = $this->buildCounterpartyLiteClientPool(
                env('COUNTERPARTY_LITE_CONNECTION_STRING', 'http://127.0.0.1:4001')
            );
        } else if ($params['chain'] == 'counterpartyTestnet') {
            $counterparty_lite_pool = $this->buildCounterpartyLiteClientPool(
                env('COUNTERPARTY_LITE_TESTNET_CONNECTION_STRING', 'http://127.0.0.1:14001')
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        $highest_block_count = 0;
        foreach ($counterparty_lite_pool->getConnections() as $offset => $counterparty_lite_client) {
            try {
                $response = $counterparty_lite_client->getPublic('/latestblock');
                if (!$response['height'] OR $response['height'] < 1) {
                    $notes[] = "{$spec['name']} ({$offset}) height was too low.";
                }
            } catch (Exception $e) {
                $notes[] = "{$spec['name']} ({$offset}) returned: ".$e->getMessage().".";
            }
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }

    public function runCheck_ethereum($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'ethereum') {
            $thereum = $this->buildEthereumClient(
                env('ETHEREUM_CONNECTION_STRING', 'http://localhost:8545')
            );
        } else if ($params['chain'] == 'ethereumTestnet') {
            $thereum = $this->buildEthereumClient(
                env('ETHEREUM_TESTNET_CONNECTION_STRING', 'http://localhost:8545')
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        try {
            $block_number = $thereum->eth_blockNumber()->val();
            if (!$block_number) {
                $notes[] = "{$spec['name']} block number not found.";
            }
        } catch (Exception $e) {
            $notes[] = "{$spec['name']} returned: ".$e->getMessage().".";
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }

    public function runCheck_ethereum_indexd($params, $spec) {
        $notes = [];
        $status = 'up';

        if ($params['chain'] == 'ethereum') {
            $ethereum_indexd_client = $this->buildEthereumIndexdClient(
                env('ETHEREUM_INDEXD_CONNECTION_STRING', 'http://localhost:19545')
            );
        } else if ($params['chain'] == 'ethereumTestnet') {
            $ethereum_indexd_client = $this->buildEthereumIndexdClient(
                env('ETHEREUM_INDEXD_TESTNET_CONNECTION_STRING', 'http://localhost:19545')
            );
        } else {
            throw new Exception("Unknown chain {$params['chain']}", 1);
        }

        try {
            $response = $ethereum_indexd_client->getPublic('/info');
            if (!$response['ready']) {
                $notes[] = "{$spec['name']} was not ready.";
            }
        } catch (Exception $e) {
            $notes[] = "{$spec['name']} returned: ".$e->getMessage().".";
        }

        if (!empty($notes)) {
            $status = 'down';
        }

        return [$status, implode("\n", $notes)];
    }


    // ------------------------------------------------------------------------
    
    protected function buildBitcoindClientPool($BITCOIND_CONNECTION_STRING, $BITCOIND_RPC_USER, $BITCOIND_RPC_PASSWORD)
    {
        $bitcoind_connection_strings = explode(',', $BITCOIND_CONNECTION_STRING);
        $bitcoind_rpc_user_strings = explode(',', $BITCOIND_RPC_USER);
        $bitcoind_rpc_password_strings = explode(',', $BITCOIND_RPC_PASSWORD);

        $clients = [];
        foreach($bitcoind_connection_strings as $offset => $bitcoind_connection_string) {
            if (!strlen($bitcoind_connection_string)) {
                continue;
            }

            $url_pieces = parse_url($bitcoind_connection_strings[$offset]);
            $rpc_user = urlencode($bitcoind_rpc_user_strings[$offset] ?? $bitcoind_rpc_user_strings[0] );
            $rpc_password = urlencode($bitcoind_rpc_password_strings[$offset] ?? $bitcoind_rpc_password_strings[0]);

            $connection_string = "{$url_pieces['scheme']}://{$rpc_user}:{$rpc_password}@{$url_pieces['host']}:{$url_pieces['port']}";

            $client = new BitcoindHttpClient($connection_string);
            $clients[] = $client;
        }

        return ConnectionPool::withConnections($clients);
    }

    protected function buildIndexdPool($INDEXD_CONNECTION_STRING)
    {
        if (!$INDEXD_CONNECTION_STRING) {
            throw new Exception("Indexd connection string was not defined", 1);
        }

        $base_urls = explode(',', $INDEXD_CONNECTION_STRING);

        $clients = [];
        foreach($base_urls as $base_url) {
            $clients[] = new IndexdClient($base_url);
        }

        return ConnectionPool::withConnections($clients);
    }

    protected function buildCounterpartyClientPool($COUNTERPARTY_CONNECTION_STRING, $COUNTERPARTY_RPC_USER, $COUNTERPARTY_RPC_PASSWORD)
    {
        $counterparty_connection_strings = explode(',', $COUNTERPARTY_CONNECTION_STRING);
        $counterparty_rpc_user_strings = explode(',', $COUNTERPARTY_RPC_USER);
        $counterparty_rpc_password_strings = explode(',', $COUNTERPARTY_RPC_PASSWORD);

        $clients = [];
        foreach($counterparty_connection_strings as $offset => $counterparty_connection_string) {
            if (!strlen($counterparty_connection_string)) {
                continue;
            }

            $uri = $counterparty_connection_strings[$offset];
            $rpc_user = $counterparty_rpc_user_strings[$offset] ?? $counterparty_rpc_user_strings[0] ;
            $rpc_password = $counterparty_rpc_password_strings[$offset] ?? $counterparty_rpc_password_strings[0];

            $clients[] = new CounterpartyClient($uri, $rpc_user, $rpc_password);
        }

        return ConnectionPool::withConnections($clients);
    }

    protected function buildCounterpartyLiteClientPool($COUNTERPARTY_LITE_CONNECTION_STRING)
    {
        $counterparty_connection_strings = explode(',', $COUNTERPARTY_LITE_CONNECTION_STRING);

        $clients = [];
        foreach($counterparty_connection_strings as $offset => $counterparty_connection_string) {
            if (!strlen($counterparty_connection_string)) {
                continue;
            }

            $uri = $counterparty_connection_strings[$offset];

            $clients[] = new TokenlyAPI($uri);
        }

        return ConnectionPool::withConnections($clients);
    }

    protected function buildEthereumClient($connection_string)
    {
        $url_pieces = parse_url($connection_string);

        $connection_string = "{$url_pieces['scheme']}://{$url_pieces['host']}:{$url_pieces['port']}";
        return new Ethereum($connection_string);
    }

    protected function buildEthereumIndexdClient($connection_string)
    {
        return new TokenlyAPI($connection_string);
    }
}
