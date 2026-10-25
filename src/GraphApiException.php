<?php

namespace MyPinacle\Meta;

use Illuminate\Http\Client\Response;

/** Graph API error shared by every client in this SDK (catalog, messaging): code / subcode / HTTP status helpers. */
class GraphApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $metaCode = 0, public readonly int $httpStatus = 0, public readonly ?int $subcode = null)
    {
        parent::__construct($message, $metaCode);
    }

    /** Throws for a failed Graph response (or a body with "error"); otherwise returns the decoded JSON. */
    public static function check(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if ($response->failed() || isset($json['error'])) {
            $error = (array) ($json['error'] ?? []);
            throw new static(
                (string) ($error['message'] ?? ('HTTP ' . $response->status())),
                (int) ($error['code'] ?? 0),
                $response->status(),
                isset($error['error_subcode']) ? (int) $error['error_subcode'] : null
            );
        }

        return $json;
    }

    /** 80014 catalog batch, 4/17/32/613 app/user/page throttling, HTTP 429. */
    public function isRateLimit(): bool
    {
        // WhatsApp: 130429 throughput, 131056 pair rate limit, 131048 spam rate limit, 80007 WABA rate limit.
        return in_array($this->metaCode, [80014, 4, 17, 32, 613, 130429, 131056, 131048, 80007], true) || $this->httpStatus === 429;
    }

    /** 190 invalid / expired token, 200 / 10 permission missing (10 + subcode 2018278 = outside window, not a reconnect). */
    public function needsReconnect(): bool
    {
        if ($this->isOutsideWindow()) {
            return false;
        }

        return in_array($this->metaCode, [190, 200, 10], true);
    }

    /** WhatsApp: error codes 13xxxx (Cloud API). */
    public function isWhatsApp(): bool
    {
        return $this->metaCode >= 130000 && $this->metaCode < 140000;
    }

    public function isTransient(): bool
    {
        // WhatsApp 131000 / 131016 / 133004: something went wrong / service unavailable, retry.
        return $this->isRateLimit() || $this->httpStatus >= 500 || $this->httpStatus === 0 || in_array($this->metaCode, [1, 2, 131000, 131016, 133004], true);
    }

    /** Messaging: message sent outside the allowed window (needs a tag / human agent). */
    public function isOutsideWindow(): bool
    {
        // WhatsApp 131047: more than 24 hours since the customer last replied (re-engagement needs a template).
        return in_array($this->subcode, [2018278, 2018108], true) || ($this->metaCode === 10 && $this->subcode === 2018278) || $this->metaCode === 131047;
    }

    /** Messaging: the person can't receive messages (blocked the Page, deleted account): 551, subcode 1545041. */
    public function isRecipientUnavailable(): bool
    {
        // WhatsApp 131026: not a WhatsApp user / old app / can't receive; 131021 sending to yourself.
        return $this->metaCode === 551 || $this->subcode === 1545041 || in_array($this->metaCode, [131026, 131021], true);
    }
}