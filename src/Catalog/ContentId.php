<?php

namespace MyPinacle\Meta\Catalog;

/**
 * The one place catalog item ids (Meta `id`, a.k.a. retailer id / pixel content id) are built.
 *
 *   simple product            -> "{product}"            (unchanged from the ids the pixel already sends)
 *   variant                   -> "{product}-v{variant}"
 *   color                     -> "{product}-c{color}"
 *   variant + color           -> "{product}-v{variant}-c{color}"
 *   item_group_id (variants)  -> "{product}"
 *
 * Ids never use product_warehouse row ids: those differ per warehouse, these are stable across stores and renames.
 */
final class ContentId
{
    public static function item(int|string $productId, int|string|null $variantId = null, int|string|null $colorId = null): string
    {
        $id = (string) (int) $productId;
        if ((int) $variantId > 0) {
            $id .= '-v' . (int) $variantId;
        }
        if ((int) $colorId > 0) {
            $id .= '-c' . (int) $colorId;
        }

        return $id;
    }

    public static function group(int|string $productId): string
    {
        return (string) (int) $productId;
    }

    /** @return array{product_id: int, variant_id: ?int, color_id: ?int}|null */
    public static function parse(string $id): ?array
    {
        if (!preg_match('/^(\d+)(?:-v(\d+))?(?:-c(\d+))?$/', $id, $m)) {
            return null;
        }

        return [
            'product_id' => (int) $m[1],
            'variant_id' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null,
            'color_id' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
        ];
    }
}