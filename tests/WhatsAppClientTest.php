<?php

namespace MyPinacle\Meta\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use MyPinacle\Meta\GraphApiException;
use MyPinacle\Meta\WhatsApp\WhatsAppClient;
use MyPinacle\Tracking\Graph;
use PHPUnit\Framework\TestCase;

final class WhatsAppClientTest extends TestCase
{
    public function test_send_text_buttons_list_and_template(): void
    {
        $http = new Factory();
        $http->fake(['graph.facebook.com/*' => $http->response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.X']]])]);
        $c = new WhatsAppClient($http, 'SYS');

        $this->assertSame('wamid.X', $c->send('PN1', '8801711000000', WhatsAppClient::text('হ্যালো'))['message_id']);
        $c->send('PN1', '88017', WhatsAppClient::buttons('Pick', [['A', 'A very very long button title'], ['B', 'B'], ['C', 'C'], ['D', 'D']]));
        $c->send('PN1', '88017', WhatsAppClient::listMessage('Pick', 'Options', array_map(fn ($i) => ['R' . $i, 'Row ' . $i], range(1, 12))));
        $c->send('PN1', '88017', WhatsAppClient::template('order_shipped', 'bn', ['#SL-1', 'Pathao']));

        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('PN1/messages')) && $r['messaging_product'] === 'whatsapp'
            && $r['to'] === '8801711000000' && $r['text']['body'] === 'হ্যালো');
        $http->assertSent(fn (Request $r) => ($r['interactive']['type'] ?? '') === 'button' && count($r['interactive']['action']['buttons']) === 3
            && mb_strlen($r['interactive']['action']['buttons'][0]['reply']['title']) === 20);
        $http->assertSent(fn (Request $r) => ($r['interactive']['type'] ?? '') === 'list' && count($r['interactive']['action']['sections'][0]['rows']) === 10);
        $http->assertSent(fn (Request $r) => ($r['type'] ?? '') === 'template' && $r['template']['components'][0]['parameters'][1]['text'] === 'Pathao');
    }

    public function test_whatsapp_error_codes(): void
    {
        $this->assertTrue((new GraphApiException('x', 131047, 400))->isOutsideWindow());
        $this->assertTrue((new GraphApiException('x', 131026, 400))->isRecipientUnavailable());
        $this->assertTrue((new GraphApiException('x', 130429, 400))->isRateLimit());
        $this->assertTrue((new GraphApiException('x', 131056, 400))->isTransient());
        $this->assertFalse((new GraphApiException('x', 131047, 400))->needsReconnect());
    }

    public function test_numbers_templates_subscribe(): void
    {
        $http = new Factory();
        $http->fake(fn (Request $r) => $r->method() === 'GET'
            ? $http->response(['data' => [['id' => 'T1', 'name' => 'order_shipped', 'status' => 'APPROVED']]])
            : $http->response(['success' => true, 'id' => 'T2', 'status' => 'PENDING']));
        $c = new WhatsAppClient($http, 'SYS');
        $this->assertSame('T1', $c->phoneNumbers('W1')[0]['id']);
        $this->assertSame('APPROVED', $c->templates('W1')[0]['status']);
        $this->assertTrue($c->subscribeApp('W1'));
        $this->assertSame('T2', $c->createTemplate('W1', ['name' => 'x'])['id']);
        $http->assertSent(fn (Request $r) => str_starts_with($r->url(), Graph::url('W1/subscribed_apps')));
    }
}
