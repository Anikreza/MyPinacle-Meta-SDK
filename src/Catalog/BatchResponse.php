<?php

namespace MyPinacle\Meta\Catalog;

/** Result of POST /{catalog}/items_batch: the handle to poll and Meta's synchronous validation per item id. */
final class BatchResponse
{
    /**
     * @param list<string> $handles
     * @param array<string, list<string>> $errors id => messages
     * @param array<string, list<string>> $warnings id => messages
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly array $handles, public readonly array $errors, public readonly array $warnings, public readonly array $raw)
    {
    }

    /** @param array<string, mixed> $json */
    public static function fromJson(array $json): self
    {
        $errors = [];
        $warnings = [];
        foreach ((array) ($json['validation_status'] ?? []) as $row) {
            $id = (string) ($row['retailer_id'] ?? '');
            foreach ((array) ($row['errors'] ?? []) as $e) {
                $errors[$id][] = (string) ($e['message'] ?? 'error');
            }
            foreach ((array) ($row['warnings'] ?? []) as $w) {
                $warnings[$id][] = (string) ($w['message'] ?? 'warning');
            }
        }

        return new self(array_values(array_map('strval', (array) ($json['handles'] ?? []))), $errors, $warnings, $json);
    }

    public function handle(): ?string
    {
        return $this->handles[0] ?? null;
    }
}