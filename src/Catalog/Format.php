<?php

namespace MyPinacle\Meta\Catalog;

/** Value formatting rules from Meta's catalog field spec (items_batch, PRODUCT_ITEM). */
final class Format
{
    public const TITLE_MAX = 100;        // items_batch PRODUCT_ITEM title max size
    public const DESCRIPTION_MAX = 5000; // items_batch PRODUCT_ITEM description max size
    public const LABEL_MAX = 100;

    /** "350.00 BDT" (period decimal, ISO 4217 code, no symbol). */
    public static function price(float $amount, string $currency): string
    {
        return number_format(round($amount, 2), 2, '.', '') . ' ' . strtoupper(trim($currency));
    }

    public static function availability(float|int|null $quantity): string
    {
        return (float) $quantity > 0 ? 'in stock' : 'out of stock';
    }

    /** HTML -> plain text, entities decoded, whitespace collapsed, cut on a character boundary (Bangla safe). */
    public static function plainText(?string $html, int $max): string
    {
        $text = (string) $html;
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = trim($text);

        return self::cut($text, $max);
    }

    public static function cut(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
    }

    /** "2026-10-01T00:00+0600/2026-10-31T23:59+0600" or null when either end is missing. */
    public static function saleWindow(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
    {
        if (!$start || !$end) {
            return null;
        }

        return $start->format('Y-m-d\TH:iO') . '/' . $end->format('Y-m-d\TH:iO');
    }
}