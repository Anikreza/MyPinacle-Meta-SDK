<?php

namespace MyPinacle\Meta\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use MyPinacle\Meta\Catalog\CatalogApiException;
use MyPinacle\Meta\GraphApiException;
use MyPinacle\Meta\Messaging\SendClient;
use MyPinacle\Tracking\Graph;
use PHPUnit\Framework\TestCase;

final class SendClientTest extends TestCase
{
    public function test_send_text_with_quick_replies(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['recipient_id' => 'PSID', 'message_id' => 'm_1'])]);

        $out = (new SendClient($http, 'PAGE_TOKEN'))->sendText('PAGE', 'PSID', 'হ্যালো', [['content_type' => 'text', 'title' => 'Price', 'payload' => 'PRICE']]);

        $this->assertSame('m_1', $out['message_id']);
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('PAGE/messages'))
            && str_contains($r->url(), 'access_token=PAGE_TOKEN')
            && $r['recipient'] === ['id' => 'PSID'] && $r['messaging_type'] === 'RESPONSE'
            && $r['message']['text'] === 'হ্যালো' && $r['message']['quick_replies'][0]['payload'] === 'PRICE');
    }

    public function test_private_reply_uses_comment_id(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['recipient_id' => 'X', 'message_id' => 'm_2'])]);
        (new SendClient($http, 'T'))->privateReply('PAGE', 'C1', ['text' => 'hi']);
        $http->assertSent(fn (Request $r) => $r['recipient'] === ['comment_id' => 'C1']);
    }

    public function test_comment_reply_and_hide_per_platform(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['id' => 'r', 'success' => true])]);
        $c = new SendClient($http, 'T');
        $c->replyToComment('C1', 'ok');
        $c->replyToComment('C2', 'ok', 'instagram');
        $c->hideComment('C3');
        $c->hideComment('C4', true, 'instagram');
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('C1/comments')));
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('C2/replies')));
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('C3') . '?') && $r['is_hidden'] === true);
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('C4') . '?') && $r['hide'] === true);
    }

    public function test_subscribe_and_pages(): void
    {
        $http = new Factory();
        $http->fake(function (Request $r) use ($http) {
            return $r->method() === 'GET'
                ? $http->response(['data' => [['id' => 'P1', 'name' => 'Shop', 'access_token' => 'PT']]])
                : $http->response(['success' => true]);
        });
        $c = new SendClient($http, 'SYS');
        $this->assertTrue($c->subscribeApp('P1'));
        $this->assertSame('P1', $c->pages()[0]['id']);
        $http->assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r['subscribed_fields'], 'messages,messaging_postbacks'));
    }

    public function test_errors_are_classified(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['error' => ['message' => 'outside window', 'code' => 10, 'error_subcode' => 2018278]], 400)]);
        try {
            (new SendClient($http, 'T'))->sendText('P', 'U', 'x');
            $this->fail('expected exception');
        } catch (GraphApiException $e) {
            $this->assertTrue($e->isOutsideWindow());
            $this->assertFalse($e->needsReconnect());
            $this->assertFalse($e->isTransient());
        }
    }

    public function test_catalog_exception_is_a_graph_exception(): void
    {
        $this->assertTrue(is_subclass_of(CatalogApiException::class, GraphApiException::class));
        $e = new CatalogApiException('x', 190, 400);
        $this->assertTrue($e->needsReconnect());
    }
}