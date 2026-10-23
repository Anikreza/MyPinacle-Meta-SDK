<?php

namespace MyPinacle\Meta\Catalog;

/**
 * One Meta catalog product item (item_type=PRODUCT_ITEM), already resolved by the app (price, stock, urls).
 * toData() is exactly what goes into an items_batch UPDATE request; hash() is how we detect changes.
 */
final class CatalogItem
{
    /**
     * @param list<string> $additionalImages
     * @param list<string> $internalLabels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly float $price,
        public readonly string $currency,
        public readonly float|int $quantity,
        public readonly string $link,
        public readonly string $imageLink,
        public readonly string $brand,
        public readonly ?string $itemGroupId = null,
        public readonly ?float $salePrice = null,
        public readonly ?string $saleWindow = null,
        public readonly string $condition = 'new',
        public readonly array $additionalImages = [],
        public readonly ?string $googleProductCategory = null,
        public readonly ?string $productType = null,
        public readonly ?string $color = null,
        public readonly ?string $size = null,
        public readonly array $internalLabels = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toData(): array
    {
        $title = Format::cut($this->title, Format::TITLE_MAX);
        $description = Format::plainText($this->description, Format::DESCRIPTION_MAX);
        if ($description === '' || $description === $title) {
            // Meta requires a description that differs from the title.
            $description = Format::cut($title . ($this->brand !== '' ? ' - ' . $this->brand : ''), Format::DESCRIPTION_MAX);
        }

        $data = [
            'id' => $this->id,
            'title' => $title,
            'description' => $description,
            'availability' => Format::availability($this->quantity),
            'quantity_to_sell_on_facebook' => max(0, (int) floor((float) $this->quantity)),
            'condition' => $this->condition,
            'price' => Format::price($this->price, $this->currency),
            'link' => $this->link,
            'image_link' => $this->imageLink,
            'brand' => Format::cut($this->brand, Format::LABEL_MAX),
        ];

        if ($this->itemGroupId !== null && $this->itemGroupId !== '') {
            $data['item_group_id'] = $this->itemGroupId;
        }
        if ($this->salePrice !== null && $this->salePrice > 0 && round($this->salePrice, 2) < round($this->price, 2)) {
            $data['sale_price'] = Format::price($this->salePrice, $this->currency);
            if ($this->saleWindow) {
                $data['sale_price_effective_date'] = $this->saleWindow;
            }
        }
        if ($this->additionalImages) {
            $data['additional_image_link'] = array_values(array_slice(array_unique($this->additionalImages), 0, 20));
        }
        foreach ([
            'google_product_category' => $this->googleProductCategory,
            'product_type' => $this->productType,
            'color' => $this->color,
            'size' => $this->size,
        ] as $field => $value) {
            if ($value !== null && trim($value) !== '') {
                $data[$field] = Format::cut($value, $field === 'product_type' ? 750 : Format::LABEL_MAX);
            }
        }
        if ($this->internalLabels) {
            // internal_label: filterable in product sets, no policy re-review on change (unlike custom_label_*).
            $data['internal_label'] = array_values(array_unique(array_map(fn ($l) => Format::cut((string) $l, 110), $this->internalLabels)));
        }

        return $data;
    }

    public function hash(): string
    {
        $data = $this->toData();
        ksort($data);

        return sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}