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

    /**
     * GET /{catalog_id}/external_event_sources: ids of the pixels / apps associated with the catalog.
     *
     * @return list<string>
     */
    public function eventSources(string $catalogId): array
    {
        $ids = [];
        $after = null;
        for ($page = 0; $page < 10; $page++) {
            $response = $this->http->timeout($this->timeout)->get(Graph::url(rawurlencode($catalogId) . '/external_event_sources'), array_filter([
                'access_token' => $this->accessToken,
                'fields' => 'id',
                'limit' => 100,
                'after' => $after,
            ], fn ($v) => $v !== null));
            $json = $this->json($response);
            foreach ((array) ($json['data'] ?? []) as $row) {
                if (isset($row['id']) && (string) $row['id'] !== '') {
                    $ids[] = (string) $row['id'];
                }
            }
            $after = $json['paging']['cursors']['after'] ?? null;
            if (!$after || empty($json['paging']['next'])) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * POST /{catalog_id}/external_event_sources with external_event_sources=["<pixel_id>", ...] (Meta: associate pixels /
     * apps with a catalog so catalog ads can match their events). Adds to the existing associations.
     *
     * @param list<string> $sourceIds
     */
    public function linkEventSources(string $catalogId, array $sourceIds): bool
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('strval', $sourceIds), fn ($id) => $id !== '')));
        $response = $this->http->asForm()->timeout($this->timeout)->post(Graph::url(rawurlencode($catalogId) . '/external_event_sources'), [
            'access_token' => $this->accessToken,
            'external_event_sources' => json_encode($sourceIds),
        ]);
        $json = $this->json($response);

        return !array_key_exists('success', $json) || (bool) $json['success'];
    }

    /**
     * Idempotent pixel link: reads the current associations first and only POSTs when the pixel is missing.
     *
     * @return 'already_linked'|'linked'
     */
    public function ensureEventSource(string $catalogId, string $pixelId): string
    {
        if (in_array($pixelId, $this->eventSources($catalogId), true)) {
            return 'already_linked';
        }
        if (!$this->linkEventSources($catalogId, [$pixelId])) {
            throw new CatalogApiException('Meta did not confirm the pixel link.');
        }

        return 'linked';
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