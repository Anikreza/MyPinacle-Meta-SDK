<?php

namespace MyPinacle\Meta\Tests;

use MyPinacle\Meta\Catalog\BatchPlanner;
use MyPinacle\Meta\Catalog\CatalogItem;
use MyPinacle\Meta\Catalog\ContentId;
use MyPinacle\Meta\Catalog\Format;
use PHPUnit\Framework\TestCase;

final class CatalogItemTest extends TestCase
{
    private function item(array $o = []): CatalogItem
    {
        return new CatalogItem(...array_merge([
            'id' => '1010-v12-c3',
            'title' => 'Binne Balance formula 1 kg packet',
            'description' => '<p>Complete <b>bird</b> food&nbsp;mix.</p><ul><li>1 kg</li></ul>',
            'price' => 350.0,
            'currency' => 'bdt',
            'quantity' => 7,
            'link' => 'https://shop.test/products/binne?variant=12&color=3',
            'imageLink' => 'https://shop.test/meta-catalog/img/ab12/bird.jpg',
            'brand' => 'Binne',
            'itemGroupId' => '1010',
        ], $o));
    }

    public function test_content_ids(): void
    {
        $this->assertSame('1010', ContentId::item(1010));
        $this->assertSame('1010-v12', ContentId::item(1010, 12));
        $this->assertSame('1010-c3', ContentId::item(1010, null, 3));
        $this->assertSame('1010-v12-c3', ContentId::item('1010', '12', '3'));
        $this->assertSame('1010', ContentId::item(1010, 0, 0));
        $this->assertSame('1010', ContentId::group(1010));
        $this->assertSame(['product_id' => 1010, 'variant_id' => 12, 'color_id' => 3], ContentId::parse('1010-v12-c3'));
        $this->assertSame(['product_id' => 7, 'variant_id' => null, 'color_id' => 3], ContentId::parse('7-c3'));
        $this->assertNull(ContentId::parse('abc'));
    }

    public function test_formats_required_fields(): void
    {
        $d = $this->item()->toData();
        $this->assertSame('350.00 BDT', $d['price']);
        $this->assertSame('in stock', $d['availability']);
        $this->assertSame(7, $d['quantity_to_sell_on_facebook']);
        $this->assertSame('new', $d['condition']);
        $this->assertSame('1010', $d['item_group_id']);
        $this->assertSame("Complete bird food mix.\n1 kg", $d['description']);
        $this->assertArrayNotHasKey('sale_price', $d);
    }

    public function test_out_of_stock_and_sale_price_rules(): void
    {
        $d = $this->item(['quantity' => 0, 'salePrice' => 300.0, 'saleWindow' => '2026-10-01T00:00+0600/2026-10-31T23:59+0600'])->toData();
        $this->assertSame('out of stock', $d['availability']);
        $this->assertSame(0, $d['quantity_to_sell_on_facebook']);
        $this->assertSame('300.00 BDT', $d['sale_price']);
        $this->assertSame('2026-10-01T00:00+0600/2026-10-31T23:59+0600', $d['sale_price_effective_date']);
        // A "sale" that is not lower than the price is dropped (Meta rejects it).
        $this->assertArrayNotHasKey('sale_price', $this->item(['salePrice' => 350.0])->toData());
    }

    public function test_bangla_text_is_cut_on_characters_and_description_differs_from_title(): void
    {
        $title = str_repeat('পাখির খাবার ', 20);
        $d = $this->item(['title' => $title, 'description' => ''])->toData();
        $this->assertSame(Format::TITLE_MAX, mb_strlen($d['title'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($d['title'], 'UTF-8'));
        $this->assertNotSame($d['title'], $d['description']);
    }

    public function test_hash_changes_only_with_payload(): void
    {
        $a = $this->item();
        $this->assertSame($a->hash(), $this->item()->hash());
        $this->assertNotSame($a->hash(), $this->item(['quantity' => 0])->hash());
        $this->assertNotSame($a->hash(), $this->item(['price' => 351.0])->hash());
    }

    public function test_planner_sends_only_changes_and_chunks(): void
    {
        $a = $this->item();
        $b = $this->item(['id' => '1010-v13', 'quantity' => 0]);
        $changed = BatchPlanner::changed([$a, $b], [$a->id => $a->hash(), $b->id => 'old']);
        $this->assertSame([$b], $changed);

        $requests = BatchPlanner::requests($changed, ['999', '999']);
        $this->assertSame('UPDATE', $requests[0]['method']);
        $this->assertSame(['method' => 'DELETE', 'data' => ['id' => '999']], $requests[1]);
        $this->assertCount(2, $requests);

        $this->assertCount(3, BatchPlanner::chunks(array_fill(0, 6001, ['method' => 'DELETE', 'data' => ['id' => 'x']])));
        $this->assertSame([], BatchPlanner::chunks([]));
    }

    public function test_videos_are_sent_as_url_objects_only_when_present(): void
    {
        $plain = $this->item();
        $this->assertArrayNotHasKey('video', $plain->toData());
        $this->assertSame($plain->hash(), $this->item(['videos' => []])->hash());

        $with = $this->item(['videos' => ['https://shop.test/videos/products/a.mp4', 'https://shop.test/videos/products/a.mp4', 'https://cdn.test/b.mov']]);
        $this->assertSame([['url' => 'https://shop.test/videos/products/a.mp4'], ['url' => 'https://cdn.test/b.mov']], $with->toData()['video']);
        $this->assertNotSame($plain->hash(), $with->hash());
    }
}