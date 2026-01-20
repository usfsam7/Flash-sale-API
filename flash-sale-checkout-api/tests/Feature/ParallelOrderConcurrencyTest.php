<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Symfony\Component\Process\Process;
use App\Models\Product;
use App\Models\Hold;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ParallelOrderConcurrencyTest extends TestCase
{
    public function test_two_parallel_requests_create_only_one_order()
    {
        // create a temp sqlite db file that both server and test will use
        $dbFile = sys_get_temp_dir().'/laravel_test_concurrency_'.uniqid().'.sqlite';
        if (file_exists($dbFile)) {
            @unlink($dbFile);
        }
        touch($dbFile);

        // ensure test process uses the temp DB
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$dbFile);
        putenv('APP_ENV=testing');

        // run migrations into the temp DB
        Artisan::call('migrate', ['--force' => true]);

        // create product + hold (data visible to server process since same sqlite file)
        $product = Product::create(['name' => 'p', 'price' => 10, 'stock' => 10]);
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(10),
            'released' => false,
            'used' => false,
        ]);

        // Allow reusing an already-running server for faster/local debugging.
        $skipServer = (bool) getenv('PAR_TEST_SKIP_SERVER');
        $baseUri = getenv('PAR_TEST_BASE') ?: 'http://127.0.0.1:9512';

        $server = null;
        if (! $skipServer) {
            // start a local server bound to our app with environment pointing at the same sqlite file
            $server = new Process(
                ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port=9512'],
                null, // cwd
                [
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => $dbFile,
                    'APP_ENV' => 'testing',
                ],
                null, // input
                120 // timeout
            );
            $server->start();
        }

        // wait for server to boot (longer wait)
        $started = false;
        $tries = 0;
        while ($tries++ < 120) {
            try {
                $r = @file_get_contents(rtrim($baseUri, '/') . '/');
                $started = true;
                break;
            } catch (\Throwable $e) {
                usleep(200_000);
            }
        }

        if (! $started) {
            $serverOut = sys_get_temp_dir().'/parallel_server_out_'.uniqid().'.log';
            if ($server) {
                file_put_contents($serverOut, "OUT:\n".$server->getOutput()."\nERR:\n".$server->getErrorOutput());
            }
            $this->fail('Server failed to start in time. Server log: '.$serverOut);
        }

        // run two concurrent requests against /api/orders using Guzzle async
        $client = new Client(['base_uri' => rtrim($baseUri, '/'), 'http_errors' => false]);

        $promises = [
            $client->postAsync('/api/orders', ['json' => ['hold_id' => $hold->id]]),
            $client->postAsync('/api/orders', ['json' => ['hold_id' => $hold->id]]),
        ];

        $responses = Utils::unwrap($promises);

        // collect statuses and bodies
        $codes = array_map(fn($r) => $r->getStatusCode(), $responses);
        $bodies = array_map(fn($r) => (string)$r->getBody(), $responses);

        // save responses / server output for debugging if needed
        $outDir = sys_get_temp_dir();
        $respA = $outDir.'/parallel_resp_a_'.uniqid().'.json';
        $respB = $outDir.'/parallel_resp_b_'.uniqid().'.json';
        file_put_contents($respA, $bodies[0] ?? '');
        file_put_contents($respB, $bodies[1] ?? '');
        $serverOut = $outDir.'/parallel_server_out_'.uniqid().'.log';
        file_put_contents($serverOut, "OUT:\n".$server->getOutput()."\nERR:\n".$server->getErrorOutput());

        // wait up to 5s for server to commit order(s)
        $found = false;
        $tries = 0;
        while ($tries++ < 25) {
            if ($this->getConnection()->table('orders')->count() > 0) {
                $found = true;
                break;
            }
            usleep(200_000);
        }

        $this->assertTrue($found, "No order was created by the server. Responses saved to {$respA}, {$respB}. Server log: {$serverOut}. HTTP codes: ".json_encode($codes));

        // verify only one order exists for the hold and hold marked used
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'used' => true]);

        // stop server (only if we started it here) and cleanup
        if ($server) {
            $server->stop(1);
        }
        if (file_exists($dbFile)) {
            @unlink($dbFile);
        }
    }
}
