<?php

namespace MyPinacle\Meta\Catalog;

/** Result of GET /{catalog}/check_batch_request_status?handle=... */
final class BatchStatus
{
    /**
     * @param array<string, list<string>> $errors id => messages ('' when Meta gave no id)
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly string $status, public readonly int $errorsTotal, public readonly array $errors, public readonly array $raw)
    {
    }

    /** @param array<string, mixed> $json */
    public static function fromJson(array $json): self
    {
        $row = (array) (($json['data'] ?? [])[0] ?? []);
        $errors = [];
        foreach ((array) ($row['errors'] ?? []) as $e) {
            $errors[(string) ($e['id'] ?? $e['retailer_id'] ?? '')][] = (string) ($e['message'] ?? 'error');
        }

        return new self(strtolower((string) ($row['status'] ?? 'unknown')), (int) ($row['errors_total_count'] ?? count($errors)), $errors, $json);
    }

    public function finished(): bool
    {
        return in_array($this->status, ['finished', 'complete', 'completed', 'error', 'failed', 'canceled', 'cancelled'], true);
    }

    public function failed(): bool
    {
        return in_array($this->status, ['error', 'failed'], true);
    }
}