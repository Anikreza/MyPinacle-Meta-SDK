<?php

namespace MyPinacle\Meta\Catalog;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use MyPinacle\Tracking\Graph;

/**
 * Meta Catalog Batch API client. Graph version comes from MyPinacle\Tracking\Graph (one version for every Meta call).
 * The token is sent as a POST form field / query param to graph.facebook.com only, never logged.
 */
final class CatalogBatchClient
{
    public function __construct(private readonly HttpFactory $http, private readonly string $accessToken, private readonly int $timeout = 60)
    {
    }

    /**
     * POST /{catalog_id}/items_batch (item_type=PRODUCT_ITEM, allow_upsert=true).
     *
     * @param list<array{method: string, data: array<string, mixed>}> $requests
     */
    public function send(string $catalogId, array $requests): BatchResponse
    {
        $response = $this->http->asForm()->timeout($this->timeout)->post(Graph::url(rawurlencode($catalogId) . '/items_batch'), [
            'access_token' => $this->accessToken,
            'item_type' => 'PRODUCT_ITEM',
            'allow_upsert' => 'true',
            'requests' => json_encode($requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return BatchResponse::fromJson($this->json($response));
    }

    /** GET /{catalog_id}/check_batch_request_status?handle=... */
    public function status(string $catalogId, string $handle): BatchStatus
    {
        $response = $this->http->timeout($this->timeout)->get(Graph::url(rawurlencode($catalogId) . '/check_batch_request_status'), [
            'access_token' => $this->accessToken,
            'handle' => $handle,
            'load_ids_of_invalid_requests' => 'true',
        ]);

        return BatchStatus::fromJson($this->json($response));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if ($response->failed() || isset($json['error'])) {
            $error = (array) ($json['error'] ?? []);
            throw new CatalogApiException(
                (string) ($error['message'] ?? ('HTTP ' . $response->status())),
                (int) ($error['code'] ?? 0),
                $response->status(),
                isset($error['error_subcode']) ? (int) $error['error_subcode'] : null
            );
        }

        return $json;
    }
}