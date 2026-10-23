<?php

namespace MyPinacle\Meta\Catalog;

/**
 * Turns "what the shop has now" + "what we last sent" into items_batch requests.
 * Only changed items are sent (payload hash), every request is idempotent (UPDATE by id with allow_upsert / DELETE by id).
 */
final class BatchPlanner
{
    /** Meta allows 5,000 records per call and recommends staying under 3,000. */
    public const CHUNK = 3000;

    /**
     * @param iterable<CatalogItem> $items
     * @param array<string, string|null> $knownHashes id => last hash sent (null = never confirmed)
     * @return list<CatalogItem>
     */
    public static function changed(iterable $items, array $knownHashes): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($knownHashes[$item->id] ?? null) !== $item->hash()) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<CatalogItem> $upserts
     * @param list<string> $deleteIds
     * @return list<array{method: string, data: array<string, mixed>}>
     */
    public static function requests(array $upserts, array $deleteIds = []): array
    {
        $requests = [];
        foreach ($upserts as $item) {
            $requests[] = ['method' => 'UPDATE', 'data' => $item->toData()];
        }
        foreach (array_unique($deleteIds) as $id) {
            $requests[] = ['method' => 'DELETE', 'data' => ['id' => (string) $id]];
        }

        return $requests;
    }

    /**
     * @param list<array{method: string, data: array<string, mixed>}> $requests
     * @return list<list<array{method: string, data: array<string, mixed>}>>
     */
    public static function chunks(array $requests, int $size = self::CHUNK): array
    {
        return $requests ? array_chunk($requests, max(1, min($size, 5000))) : [];
    }
}