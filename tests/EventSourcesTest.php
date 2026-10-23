<?php

namespace MyPinacle\Meta\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use MyPinacle\Meta\Catalog\CatalogApiException;
use MyPinacle\Meta\Catalog\CatalogBatchClient;
use MyPinacle\Tracking\Graph;
use PHPUnit\Framework\TestCase;

final class EventSourcesTest extends TestCase
{
    public function test_links_pixel_when_missing(): void
    {
        $http = new Factory();
        $http->fake(function (Request $r) use ($http) {
            return $r->method() === 'GET'
                ? $http->response(['data' => [['id' => '999']]])
                : $http->response(['success' => true]);
        });

        $this->assertSame('linked', (new CatalogBatchClient($http, 'TOKEN'))->ensureEventSource('123', '555'));
        $http->assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === Graph::url('123/external_event_sources')
            && $r['external_event_sources'] === '["555"]' && $r['access_token'] === 'TOKEN');
    }

    public function test_already_linked_does_not_post(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['data' => [['id' => '555']]])]);

        $this->assertSame('already_linked', (new CatalogBatchClient($http, 'TOKEN'))->ensureEventSource('123', '555'));
        $http->assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $http->assertSent(fn (Request $r) => str_contains($r->url(), '/123/external_event_sources') && str_contains($r->url(), 'fields=id'));
    }

    public function test_follows_paging(): void
    {
        $http = new Factory();
        $http->fakeSequence()
            ->push(['data' => [['id' => '1']], 'paging' => ['cursors' => ['after' => 'A'], 'next' => 'https://graph.facebook.com/next']])
            ->push(['data' => [['id' => '555']]]);

        $this->assertSame(['1', '555'], (new CatalogBatchClient($http, 'TOKEN'))->eventSources('123'));
    }

    public function test_permission_error_is_classified(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['error' => ['message' => '(#200) Requires business_management', 'code' => 200]], 403)]);

        try {
            (new CatalogBatchClient($http, 'TOKEN'))->ensureEventSource('123', '555');
            $this->fail('expected exception');
        } catch (CatalogApiException $e) {
            $this->assertTrue($e->needsReconnect());
            $this->assertStringContainsString('business_management', $e->getMessage());
        }
    }
}