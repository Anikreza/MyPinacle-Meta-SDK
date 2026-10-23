<?php

namespace MyPinacle\Meta\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use MyPinacle\Meta\Catalog\CatalogApiException;
use MyPinacle\Meta\Catalog\CatalogBatchClient;
use MyPinacle\Tracking\Graph;
use PHPUnit\Framework\TestCase;

final class CatalogBatchClientTest extends TestCase
{
    public function test_send_posts_items_batch_and_parses_validation(): void
    {
        $http = new Factory();
        $http->fake([
            'graph.facebook.com/*/items_batch' => $http->response([
                'handles' => ['H1'],
                'validation_status' => [['retailer_id' => '1-v2', 'errors' => [['message' => 'Missing image']], 'warnings' => [['message' => 'Unrecognised field']]]],
            ]),
        ]);

        $res = (new CatalogBatchClient($http, 'TOKEN'))->send('123', [['method' => 'UPDATE', 'data' => ['id' => '1-v2']]]);

        $this->assertSame('H1', $res->handle());
        $this->assertSame(['1-v2' => ['Missing image']], $res->errors);
        $this->assertSame(['1-v2' => ['Unrecognised field']], $res->warnings);
        $http->assertSent(function (Request $r) {
            return $r->url() === Graph::url('123/items_batch')
                && $r['item_type'] === 'PRODUCT_ITEM'
                && $r['allow_upsert'] === 'true'
                && json_decode($r['requests'], true)[0]['data']['id'] === '1-v2';
        });
    }

    public function test_status_parses_finished_and_errors(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['data' => [['status' => 'finished', 'errors_total_count' => 1, 'errors' => [['id' => '1-v2', 'message' => 'Bad price']]]]])]);

        $status = (new CatalogBatchClient($http, 'TOKEN'))->status('123', 'H1');

        $this->assertTrue($status->finished());
        $this->assertFalse($status->failed());
        $this->assertSame(['1-v2' => ['Bad price']], $status->errors);
        $http->assertSent(fn (Request $r) => str_contains($r->url(), '/check_batch_request_status') && str_contains($r->url(), 'handle=H1'));
    }

    public function test_rate_limit_and_token_errors_are_classified(): void
    {
        $http = new Factory();
        $http->fakeSequence()
            ->push(['error' => ['message' => 'too many calls', 'code' => 80014]], 400)
            ->push(['error' => ['message' => 'Session expired', 'code' => 190]], 400);
        $client = new CatalogBatchClient($http, 'TOKEN');

        try {
            $client->send('123', []);
            $this->fail('expected exception');
        } catch (CatalogApiException $e) {
            $this->assertTrue($e->isRateLimit());
            $this->assertTrue($e->isTransient());
            $this->assertFalse($e->needsReconnect());
        }
        try {
            $client->send('123', []);
            $this->fail('expected exception');
        } catch (CatalogApiException $e) {
            $this->assertTrue($e->needsReconnect());
            $this->assertFalse($e->isTransient());
            $this->assertStringNotContainsString('TOKEN', $e->getMessage());
        }
    }
}