<?php

namespace MyPinacle\Meta\WhatsApp;

use Illuminate\Http\Client\Factory as HttpFactory;
use MyPinacle\Meta\GraphApiException;
use MyPinacle\Tracking\Graph;

/**
 * WhatsApp Cloud API over the Graph API with a Business Manager System User token (whatsapp_business_messaging +
 * whatsapp_business_management). Graph version from MyPinacle\Tracking\Graph. The token goes to graph.facebook.com
 * only and is never logged. Errors throw GraphApiException (isOutsideWindow 131047, isRecipientUnavailable 131026,
 * isRateLimit 130429 / 131056 / 131048 ...).
 */
final class WhatsAppClient
{
    public const REQUIRED_PERMISSIONS = ['whatsapp_business_messaging', 'whatsapp_business_management', 'business_management'];

    /** WhatsApp text body limit and interactive limits. */
    public const TEXT_MAX = 4096;
    public const BODY_MAX = 1024;
    public const BUTTONS_MAX = 3;
    public const BUTTON_TITLE_MAX = 20;
    public const LIST_ROWS_MAX = 10;
    public const ROW_TITLE_MAX = 24;
    public const ROW_DESCRIPTION_MAX = 72;

    public function __construct(private readonly HttpFactory $http, private readonly string $token, private readonly int $timeout = 20)
    {
    }

    /**
     * POST /{phone_number_id}/messages with one message object (type text / interactive / image / template / product ...).
     * @return array{message_id: ?string, raw: array}
     */
    public function send(string $phoneNumberId, string $to, array $message): array
    {
        $json = $this->post($phoneNumberId . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to] + $message);

        return ['message_id' => isset($json['messages'][0]['id']) ? (string) $json['messages'][0]['id'] : null, 'raw' => $json];
    }

    /** Blue ticks for an inbound message (POST /{phone_number_id}/messages status=read). */
    public function markRead(string $phoneNumberId, string $messageId): bool
    {
        $json = $this->post($phoneNumberId . '/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId]);

        return (bool) ($json['success'] ?? false);
    }

    public static function text(string $body, bool $previewUrl = true): array
    {
        return ['type' => 'text', 'text' => ['body' => mb_substr($body, 0, self::TEXT_MAX), 'preview_url' => $previewUrl]];
    }

    /** Reply buttons: [[id, title], ...] max 3, titles cut to 20 characters. Optional image header. */
    public static function buttons(string $body, array $buttons, ?string $headerImage = null, ?string $footer = null): array
    {
        $interactive = ['type' => 'button', 'body' => ['text' => mb_substr($body, 0, self::BODY_MAX)], 'action' => ['buttons' => array_map(
            fn ($b) => ['type' => 'reply', 'reply' => ['id' => mb_substr((string) $b[0], 0, 256), 'title' => mb_substr((string) $b[1], 0, self::BUTTON_TITLE_MAX)]],
            array_slice(array_values($buttons), 0, self::BUTTONS_MAX)
        )]];
        if ($headerImage) {
            $interactive['header'] = ['type' => 'image', 'image' => ['link' => $headerImage]];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        }

        return ['type' => 'interactive', 'interactive' => $interactive];
    }

    /** List message: rows [[id, title, ?description], ...] max 10 in one section. */
    public static function listMessage(string $body, string $button, array $rows, string $sectionTitle = ''): array
    {
        $section = ['rows' => array_map(fn ($r) => array_filter([
            'id' => mb_substr((string) $r[0], 0, 200),
            'title' => mb_substr((string) $r[1], 0, self::ROW_TITLE_MAX),
            'description' => isset($r[2]) && $r[2] !== '' ? mb_substr((string) $r[2], 0, self::ROW_DESCRIPTION_MAX) : null,
        ], fn ($v) => $v !== null), array_slice(array_values($rows), 0, self::LIST_ROWS_MAX))];
        if ($sectionTitle !== '') {
            $section['title'] = mb_substr($sectionTitle, 0, 24);
        }

        return ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => mb_substr($body, 0, 4096)],
            'action' => ['button' => mb_substr($button, 0, 20), 'sections' => [$section]]]];
    }

    /** One URL button (cta_url) with an optional image header. */
    public static function ctaUrl(string $body, string $label, string $url, ?string $headerImage = null): array
    {
        $interactive = ['type' => 'cta_url', 'body' => ['text' => mb_substr($body, 0, self::BODY_MAX)],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($label, 0, 20), 'url' => $url]]];
        if ($headerImage) {
            $interactive['header'] = ['type' => 'image', 'image' => ['link' => $headerImage]];
        }

        return ['type' => 'interactive', 'interactive' => $interactive];
    }

    public static function image(string $link, ?string $caption = null): array
    {
        return ['type' => 'image', 'image' => array_filter(['link' => $link, 'caption' => $caption !== null ? mb_substr($caption, 0, 1024) : null])];
    }

    /** Single catalog product (catalog linked to the WABA; retailer id = catalog item id, see Catalog\ContentId). */
    public static function product(string $catalogId, string $retailerId, ?string $body = null): array
    {
        return ['type' => 'interactive', 'interactive' => array_filter(['type' => 'product', 'body' => $body ? ['text' => mb_substr($body, 0, self::BODY_MAX)] : null,
            'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $retailerId]])];
    }

    /** Multi-product message (max 30 items, one section). */
    public static function productList(string $catalogId, array $retailerIds, string $header, string $body, string $sectionTitle = 'Products'): array
    {
        return ['type' => 'interactive', 'interactive' => ['type' => 'product_list', 'header' => ['type' => 'text', 'text' => mb_substr($header, 0, 60)],
            'body' => ['text' => mb_substr($body, 0, self::BODY_MAX)], 'action' => ['catalog_id' => $catalogId,
                'sections' => [['title' => mb_substr($sectionTitle, 0, 24), 'product_items' => array_map(fn ($id) => ['product_retailer_id' => (string) $id], array_slice(array_values($retailerIds), 0, 30))]]]]];
    }

    /** Template message: body parameters in order ({{1}}, {{2}} ...). */
    public static function template(string $name, string $language, array $bodyParams = []): array
    {
        $t = ['name' => $name, 'language' => ['code' => $language]];
        if ($bodyParams) {
            $t['components'] = [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($bodyParams))]];
        }

        return ['type' => 'template', 'template' => $t];
    }

    /** GET /{phone_number_id}: display number, verified name, quality, messaging tier. */
    public function phoneNumber(string $phoneNumberId): array
    {
        return $this->get($phoneNumberId, ['fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,name_status']);
    }

    /** GET /{waba_id}/phone_numbers ("Find my numbers"). */
    public function phoneNumbers(string $wabaId): array
    {
        return array_values((array) ($this->get($wabaId . '/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier'])['data'] ?? []));
    }

    /** POST /{waba_id}/subscribed_apps: deliver this WABA's webhooks to our app. */
    public function subscribeApp(string $wabaId): bool
    {
        return (bool) ($this->post($wabaId . '/subscribed_apps', [])['success'] ?? false);
    }

    public function unsubscribeApp(string $wabaId): bool
    {
        $response = $this->http->timeout($this->timeout)->delete(Graph::url($wabaId . '/subscribed_apps') . '?access_token=' . rawurlencode($this->token));

        return (bool) (GraphApiException::check($response)['success'] ?? true);
    }

    /** GET /{waba_id}/message_templates (all pages, max $max). */
    public function templates(string $wabaId, int $max = 500): array
    {
        $out = [];
        $after = null;
        do {
            $json = $this->get($wabaId . '/message_templates', array_filter(['fields' => 'id,name,language,status,category,components,rejected_reason', 'limit' => 100, 'after' => $after]));
            foreach ((array) ($json['data'] ?? []) as $t) {
                $out[] = $t;
            }
            $after = $json['paging']['cursors']['after'] ?? null;
        } while ($after && isset($json['paging']['next']) && count($out) < $max);

        return $out;
    }

    /** POST /{waba_id}/message_templates: {name, language, category, components}. Returns {id, status, category}. */
    public function createTemplate(string $wabaId, array $template): array
    {
        return $this->post($wabaId . '/message_templates', $template);
    }

    /** @return list<string> */
    public function grantedPermissions(): array
    {
        $out = [];
        foreach ((array) ($this->get('me/permissions')['data'] ?? []) as $row) {
            if (($row['status'] ?? '') === 'granted' && isset($row['permission'])) {
                $out[] = (string) $row['permission'];
            }
        }

        return $out;
    }

    private function post(string $path, array $body): array
    {
        $response = $this->http->asJson()->timeout($this->timeout)
            ->post(Graph::url($path) . '?access_token=' . rawurlencode($this->token), $body);

        return GraphApiException::check($response);
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->http->timeout($this->timeout)->get(Graph::url($path), $query + ['access_token' => $this->token]);

        return GraphApiException::check($response);
    }
}
