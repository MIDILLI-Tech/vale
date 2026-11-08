<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;

final class ProductTest extends TestCase
{
    // ── Constructor & basic getters ─────────────────────────────────

    public function testConstructorSetsNameAndSlug(): void
    {
        $product = new Product('Test Product', new Slug('test-product'));

        $this->assertSame('Test Product', $product->getName());
        $this->assertSame('test-product', $product->getSlug()->value);
    }

    public function testDefaultsAfterConstruction(): void
    {
        $product = new Product('Widget', new Slug('widget'));

        $this->assertSame('en', $product->getLanguage());
        $this->assertNull($product->getTranslationOf());
        $this->assertNull($product->getSku());
        $this->assertNull($product->getDescription());
        $this->assertNull($product->getShortDescription());
        $this->assertSame(0.0, $product->getPrice());
        $this->assertNull($product->getSalePrice());
        $this->assertSame(0, $product->getStockQuantity());
        $this->assertSame('in_stock', $product->getStockStatus());
        $this->assertNull($product->getWeight());
        $this->assertNull($product->getDimensions());
        $this->assertNull($product->getFeaturedImageId());
        $this->assertSame('draft', $product->getStatus());
        $this->assertNull($product->getPublishedAt());
        $this->assertSame('use_global', $product->getCommentStatus());
        $this->assertSame('use_global', $product->getVoteStatus());
        $this->assertSame('simple', $product->getType());
        $this->assertSame('use_global', $product->getShippingClass());
        $this->assertNull($product->getShippingCost());
        $this->assertSame('use_global', $product->getVatMode());
        $this->assertNull($product->getVatRate());
        $this->assertSame('none', $product->getDownloadSource());
        $this->assertNull($product->getDownloadFileId());
        $this->assertNull($product->getDownloadUrl());
        $this->assertTrue($product->isDownloadUrlProxy());
        $this->assertSame(0, $product->getDownloadLimit());
        $this->assertSame(0, $product->getDownloadExpiryDays());
        $this->assertNull($product->getMetaTitle());
        $this->assertNull($product->getMetaDescription());
        $this->assertSame(0, $product->getTotalSold());
        $this->assertSame([], $product->getShippingCountryOverrides());
        $this->assertSame([], $product->getVatCountryOverrides());
        $this->assertSame([], $product->getUpsellIds());
        $this->assertSame([], $product->getCrossSellIds());
        $this->assertNull($product->getLength());
        $this->assertNull($product->getWidth());
        $this->assertNull($product->getHeight());
        $this->assertSame([], $product->getCategoryIds());
        $this->assertNull($product->getId());
        $this->assertNull($product->getCreatedAt());
        $this->assertNull($product->getUpdatedAt());
    }

    public function testGmcDefaults(): void
    {
        $product = new Product('Item', new Slug('item'));

        $this->assertNull($product->getGtin());
        $this->assertNull($product->getMpn());
        $this->assertNull($product->getBrand());
        $this->assertNull($product->getGoogleProductCategory());
        $this->assertSame('new', $product->getCondition());
        $this->assertNull($product->getGender());
        $this->assertNull($product->getAgeGroup());
        $this->assertNull($product->getColor());
        $this->assertNull($product->getSize());
        $this->assertNull($product->getMaterial());
        $this->assertNull($product->getPattern());
        $this->assertNull($product->getCustomLabel0());
        $this->assertNull($product->getCustomLabel1());
        $this->assertNull($product->getCustomLabel2());
        $this->assertNull($product->getCustomLabel3());
        $this->assertNull($product->getCustomLabel4());
        $this->assertFalse($product->isGmcExclude());
    }

    // ── Setters & getters round-trip ────────────────────────────────

    public function testSetName(): void
    {
        $product = new Product('Old', new Slug('old'));
        $product->setName('New');
        $this->assertSame('New', $product->getName());
    }

    public function testSetLanguage(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setLanguage('de');
        $this->assertSame('de', $product->getLanguage());
    }

    public function testSetTranslationOf(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setTranslationOf(42);
        $this->assertSame(42, $product->getTranslationOf());
        $product->setTranslationOf(null);
        $this->assertNull($product->getTranslationOf());
    }

    public function testSetSlug(): void
    {
        $product = new Product('P', new Slug('old'));
        $product->setSlug(new Slug('new-slug'));
        $this->assertSame('new-slug', $product->getSlug()->value);
    }

    public function testSetSku(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSku('SKU-001');
        $this->assertSame('SKU-001', $product->getSku());
        $product->setSku(null);
        $this->assertNull($product->getSku());
    }

    public function testSetDescriptionAndShortDescription(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setDescription('Full desc');
        $this->assertSame('Full desc', $product->getDescription());
        $product->setShortDescription('Short');
        $this->assertSame('Short', $product->getShortDescription());
    }

    public function testSetPrice(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setPrice(29.99);
        $this->assertSame(29.99, $product->getPrice());
    }

    public function testSetSalePrice(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(19.99);
        $this->assertSame(19.99, $product->getSalePrice());
        $product->setSalePrice(null);
        $this->assertNull($product->getSalePrice());
    }

    public function testSetSalePriceStartAndEnd(): void
    {
        $product = new Product('P', new Slug('p'));
        $start   = new \DateTimeImmutable('2025-01-01');
        $end     = new \DateTimeImmutable('2025-12-31');
        $product->setSalePriceStart($start);
        $product->setSalePriceEnd($end);
        $this->assertSame($start, $product->getSalePriceStart());
        $this->assertSame($end, $product->getSalePriceEnd());
    }

    public function testSetStockQuantityAndStatus(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setStockQuantity(50);
        $this->assertSame(50, $product->getStockQuantity());
        $product->setStockStatus('out_of_stock');
        $this->assertSame('out_of_stock', $product->getStockStatus());
    }

    public function testSetWeightAndDimensions(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setWeight(2.5);
        $this->assertSame(2.5, $product->getWeight());
        $product->setDimensions('10x20x30');
        $this->assertSame('10x20x30', $product->getDimensions());
    }

    public function testSetFeaturedImageId(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setFeaturedImageId(7);
        $this->assertSame(7, $product->getFeaturedImageId());
        $product->setFeaturedImageId(null);
        $this->assertNull($product->getFeaturedImageId());
    }

    public function testSetStatus(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setStatus('published');
        $this->assertSame('published', $product->getStatus());
    }

    public function testSetCommentStatusAndVoteStatus(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setCommentStatus('enabled');
        $this->assertSame('enabled', $product->getCommentStatus());
        $product->setVoteStatus('disabled');
        $this->assertSame('disabled', $product->getVoteStatus());
    }

    public function testSetType(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setType('variable');
        $this->assertSame('variable', $product->getType());
    }

    public function testSetShippingClassAndCost(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setShippingClass('free');
        $this->assertSame('free', $product->getShippingClass());
        $product->setShippingCost(5.99);
        $this->assertSame(5.99, $product->getShippingCost());
    }

    public function testSetVatModeAndRate(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setVatMode('custom');
        $this->assertSame('custom', $product->getVatMode());
        $product->setVatRate(19.0);
        $this->assertSame(19.0, $product->getVatRate());
    }

    public function testSetDownloadFields(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setDownloadSource('internal');
        $this->assertSame('internal', $product->getDownloadSource());
        $product->setDownloadFileId(5);
        $this->assertSame(5, $product->getDownloadFileId());
        $product->setDownloadUrl('https://example.com/file.zip');
        $this->assertSame('https://example.com/file.zip', $product->getDownloadUrl());
        $product->setDownloadUrlProxy(false);
        $this->assertFalse($product->isDownloadUrlProxy());
        $product->setDownloadLimit(10);
        $this->assertSame(10, $product->getDownloadLimit());
        $product->setDownloadExpiryDays(30);
        $this->assertSame(30, $product->getDownloadExpiryDays());
    }

    public function testSetMetaTitleAndDescription(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setMetaTitle('SEO Title');
        $this->assertSame('SEO Title', $product->getMetaTitle());
        $product->setMetaDescription('SEO Desc');
        $this->assertSame('SEO Desc', $product->getMetaDescription());
    }

    public function testSetTotalSold(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setTotalSold(100);
        $this->assertSame(100, $product->getTotalSold());
    }

    public function testSetShippingCountryOverrides(): void
    {
        $product   = new Product('P', new Slug('p'));
        $overrides = [['country' => 'DE', 'cost' => 5.0]];
        $product->setShippingCountryOverrides($overrides);
        $this->assertSame($overrides, $product->getShippingCountryOverrides());
    }

    public function testSetVatCountryOverrides(): void
    {
        $product   = new Product('P', new Slug('p'));
        $overrides = [['country' => 'AT', 'rate' => 20.0]];
        $product->setVatCountryOverrides($overrides);
        $this->assertSame($overrides, $product->getVatCountryOverrides());
    }

    public function testSetUpsellIdsAndCrossSellIds(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setUpsellIds([1, 2, 3]);
        $this->assertSame([1, 2, 3], $product->getUpsellIds());
        $product->setCrossSellIds([4, 5]);
        $this->assertSame([4, 5], $product->getCrossSellIds());
    }

    public function testSetLengthWidthHeight(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setLength(10.5);
        $product->setWidth(20.0);
        $product->setHeight(5.5);
        $this->assertSame(10.5, $product->getLength());
        $this->assertSame(20.0, $product->getWidth());
        $this->assertSame(5.5, $product->getHeight());
    }

    public function testSetCategoryIds(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setCategoryIds([10, 20]);
        $this->assertSame([10, 20], $product->getCategoryIds());
    }

    // ── GMC setters ─────────────────────────────────────────────────

    public function testGmcSetters(): void
    {
        $product = new Product('P', new Slug('p'));

        $product->setGtin('123456789');
        $this->assertSame('123456789', $product->getGtin());
        $product->setMpn('MPN-1');
        $this->assertSame('MPN-1', $product->getMpn());
        $product->setBrand('BrandCo');
        $this->assertSame('BrandCo', $product->getBrand());
        $product->setGoogleProductCategory('Electronics');
        $this->assertSame('Electronics', $product->getGoogleProductCategory());
        $product->setCondition('refurbished');
        $this->assertSame('refurbished', $product->getCondition());
        $product->setGender('male');
        $this->assertSame('male', $product->getGender());
        $product->setAgeGroup('adult');
        $this->assertSame('adult', $product->getAgeGroup());
        $product->setColor('red');
        $this->assertSame('red', $product->getColor());
        $product->setSize('L');
        $this->assertSame('L', $product->getSize());
        $product->setMaterial('cotton');
        $this->assertSame('cotton', $product->getMaterial());
        $product->setPattern('striped');
        $this->assertSame('striped', $product->getPattern());
        $product->setCustomLabel0('cl0');
        $this->assertSame('cl0', $product->getCustomLabel0());
        $product->setCustomLabel1('cl1');
        $this->assertSame('cl1', $product->getCustomLabel1());
        $product->setCustomLabel2('cl2');
        $this->assertSame('cl2', $product->getCustomLabel2());
        $product->setCustomLabel3('cl3');
        $this->assertSame('cl3', $product->getCustomLabel3());
        $product->setCustomLabel4('cl4');
        $this->assertSame('cl4', $product->getCustomLabel4());
        $product->setGmcExclude(true);
        $this->assertTrue($product->isGmcExclude());
    }

    // ── Business logic methods ──────────────────────────────────────

    public function testIsSaleActiveReturnsFalseWhenNoSalePrice(): void
    {
        $product = new Product('P', new Slug('p'));
        $this->assertFalse($product->isSaleActive());
    }

    public function testIsSaleActiveReturnsTrueWhenSalePriceSetNoDates(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $this->assertTrue($product->isSaleActive());
    }

    public function testIsSaleActiveReturnsFalseBeforeStart(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $product->setSalePriceStart(new \DateTimeImmutable('2030-01-01'));

        $now = new \DateTimeImmutable('2025-06-15');
        $this->assertFalse($product->isSaleActive($now));
    }

    public function testIsSaleActiveReturnsTrueBetweenStartAndEnd(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $product->setSalePriceStart(new \DateTimeImmutable('2025-01-01'));
        $product->setSalePriceEnd(new \DateTimeImmutable('2025-12-31'));

        $now = new \DateTimeImmutable('2025-06-15');
        $this->assertTrue($product->isSaleActive($now));
    }

    public function testIsSaleActiveReturnsFalseAfterEnd(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $product->setSalePriceEnd(new \DateTimeImmutable('2025-01-01'));

        $now = new \DateTimeImmutable('2025-06-15');
        $this->assertFalse($product->isSaleActive($now));
    }

    public function testIsSaleActiveOnlyStart(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $product->setSalePriceStart(new \DateTimeImmutable('2025-01-01'));

        $now = new \DateTimeImmutable('2025-06-15');
        $this->assertTrue($product->isSaleActive($now));
    }

    public function testIsSaleActiveOnlyEnd(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $product->setSalePriceEnd(new \DateTimeImmutable('2026-01-01'));

        $now = new \DateTimeImmutable('2025-06-15');
        $this->assertTrue($product->isSaleActive($now));
    }

    public function testIsSaleActiveExactlyAtEndReturnsFalse(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setSalePrice(10.0);
        $end = new \DateTimeImmutable('2025-06-15 12:00:00');
        $product->setSalePriceEnd($end);

        $this->assertFalse($product->isSaleActive($end));
    }

    public function testGetEffectivePriceReturnsSalePriceWhenActive(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setPrice(50.0);
        $product->setSalePrice(30.0);
        $this->assertSame(30.0, $product->getEffectivePrice());
    }

    public function testGetEffectivePriceReturnsRegularPriceWhenSaleInactive(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setPrice(50.0);
        $this->assertSame(50.0, $product->getEffectivePrice());
    }

    public function testIsInStock(): void
    {
        $product = new Product('P', new Slug('p'));

        $product->setStockStatus('in_stock');
        $this->assertTrue($product->isInStock());

        $product->setStockStatus('on_backorder');
        $this->assertTrue($product->isInStock());

        $product->setStockStatus('out_of_stock');
        $this->assertFalse($product->isInStock());
    }

    public function testIsStockTracked(): void
    {
        $product = new Product('P', new Slug('p'));

        $product->setStockStatus('in_stock');
        $product->setStockQuantity(10);
        $this->assertTrue($product->isStockTracked());

        $product->setStockQuantity(0);
        $this->assertFalse($product->isStockTracked());

        $product->setStockStatus('out_of_stock');
        $product->setStockQuantity(10);
        $this->assertFalse($product->isStockTracked());
    }

    public function testIsDigital(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setShippingClass('digital');
        $this->assertTrue($product->isDigital());
        $product->setShippingClass('use_global');
        $this->assertFalse($product->isDigital());
    }

    public function testIsPublished(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setStatus('published');
        $this->assertTrue($product->isPublished());
        $product->setStatus('draft');
        $this->assertFalse($product->isPublished());
    }

    public function testIsUnlisted(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setStatus('unlisted');
        $this->assertTrue($product->isUnlisted());
        $product->setStatus('published');
        $this->assertFalse($product->isUnlisted());
    }

    public function testIsScheduled(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setStatus('scheduled');
        $this->assertTrue($product->isScheduled());
        $product->setStatus('draft');
        $this->assertFalse($product->isScheduled());
    }

    public function testIsCommentsOpen(): void
    {
        $product = new Product('P', new Slug('p'));

        $product->setCommentStatus('use_global');
        $this->assertTrue($product->isCommentsOpen());
        $product->setCommentStatus('enabled');
        $this->assertTrue($product->isCommentsOpen());
        $product->setCommentStatus('disabled');
        $this->assertFalse($product->isCommentsOpen());
    }

    public function testIsVoteEnabled(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->setVoteStatus('enabled');
        $this->assertTrue($product->isVoteEnabled());
        $product->setVoteStatus('use_global');
        $this->assertFalse($product->isVoteEnabled());
    }

    // ── publish / trash / schedule ──────────────────────────────────

    public function testPublishSetsStatusAndPublishedAt(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->publish();

        $this->assertSame('published', $product->getStatus());
        $this->assertNotNull($product->getPublishedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $product->getPublishedAt());
    }

    public function testPublishDoesNotOverwriteExistingPublishedAt(): void
    {
        $product  = new Product('P', new Slug('p'));
        $existing = new \DateTimeImmutable('2020-01-01');
        $product->setStatus('published');
        $product->setId(1);
        $product->publish();

        $this->assertSame('published', $product->getStatus());
        $this->assertNotNull($product->getPublishedAt());
    }

    public function testTrashSetsStatus(): void
    {
        $product = new Product('P', new Slug('p'));
        $product->publish();
        $product->trash();

        $this->assertSame('trash', $product->getStatus());
    }

    public function testScheduleSetsStatusAndPublishedAt(): void
    {
        $product = new Product('P', new Slug('p'));
        $at      = new \DateTimeImmutable('2025-12-25');
        $product->schedule($at);

        $this->assertSame('scheduled', $product->getStatus());
        $this->assertSame($at, $product->getPublishedAt());
    }

    // ── fromRow ─────────────────────────────────────────────────────

    public function testFromRowMinimal(): void
    {
        $product = Product::fromRow([
            'id'   => 1,
            'name' => 'Test',
            'slug' => 'test',
        ]);

        $this->assertSame(1, $product->getId());
        $this->assertSame('Test', $product->getName());
        $this->assertSame('test', $product->getSlug()->value);
        $this->assertSame('en', $product->getLanguage());
        $this->assertSame('draft', $product->getStatus());
        $this->assertSame('simple', $product->getType());
        $this->assertSame('new', $product->getCondition());
        $this->assertSame(0.0, $product->getPrice());
    }

    public function testFromRowFull(): void
    {
        $row = [
            'id'                          => 99,
            'name'                        => 'Full Product',
            'slug'                        => 'full-product',
            'language'                    => 'de',
            'translation_of'              => 10,
            'sku'                         => 'SKU-99',
            'description'                 => 'A description',
            'short_description'           => 'Short desc',
            'price'                       => 29.99,
            'sale_price'                  => 19.99,
            'sale_price_start'            => '2025-06-01',
            'sale_price_end'              => '2025-06-30',
            'stock_quantity'              => 50,
            'stock_status'                => 'in_stock',
            'weight'                      => 1.5,
            'dimensions'                  => '10x20x30',
            'featured_image_id'           => 7,
            'status'                      => 'published',
            'published_at'                => '2025-01-15 10:00:00',
            'comment_status'              => 'enabled',
            'vote_status'                 => 'disabled',
            'type'                        => 'variable',
            'shipping_class'              => 'free',
            'shipping_cost'               => 5.0,
            'vat_mode'                    => 'custom',
            'vat_rate'                    => 19.0,
            'download_source'             => 'internal',
            'download_file_id'            => 12,
            'download_url'                => 'https://example.com/dl',
            'download_url_proxy'          => 0,
            'download_limit'              => 5,
            'download_expiry_days'        => 30,
            'meta_title'                  => 'SEO',
            'meta_description'            => 'SEO desc',
            'total_sold'                  => 100,
            'gtin'                        => '12345',
            'mpn'                         => 'MPN-99',
            'brand'                       => 'TestBrand',
            'google_product_category'     => 'Electronics',
            'condition'                   => 'used',
            'gender'                      => 'male',
            'age_group'                   => 'adult',
            'color'                       => 'red',
            'size'                        => 'XL',
            'material'                    => 'cotton',
            'pattern'                     => 'striped',
            'custom_label_0'              => 'cl0',
            'custom_label_1'              => 'cl1',
            'custom_label_2'              => 'cl2',
            'custom_label_3'              => 'cl3',
            'custom_label_4'              => 'cl4',
            'gmc_exclude'                 => 1,
            'upsell_ids'                  => '[1,2,3]',
            'cross_sell_ids'              => '[4,5]',
            'length'                      => 10.0,
            'width'                       => 20.0,
            'height'                      => 30.0,
            'shipping_country_overrides'  => '[{"country":"DE","cost":5}]',
            'vat_country_overrides'       => '[{"country":"AT","rate":20}]',
            'created_at'                  => '2025-01-01 00:00:00',
            'updated_at'                  => '2025-06-15 12:00:00',
        ];

        $product = Product::fromRow($row, [10, 20]);

        $this->assertSame(99, $product->getId());
        $this->assertSame('Full Product', $product->getName());
        $this->assertSame('full-product', $product->getSlug()->value);
        $this->assertSame('de', $product->getLanguage());
        $this->assertSame(10, $product->getTranslationOf());
        $this->assertSame('SKU-99', $product->getSku());
        $this->assertSame('A description', $product->getDescription());
        $this->assertSame('Short desc', $product->getShortDescription());
        $this->assertSame(29.99, $product->getPrice());
        $this->assertSame(19.99, $product->getSalePrice());
        $this->assertSame('2025-06-01', $product->getSalePriceStart()->format('Y-m-d'));
        $this->assertSame('2025-06-30', $product->getSalePriceEnd()->format('Y-m-d'));
        $this->assertSame(50, $product->getStockQuantity());
        $this->assertSame('in_stock', $product->getStockStatus());
        $this->assertSame(1.5, $product->getWeight());
        $this->assertSame('10x20x30', $product->getDimensions());
        $this->assertSame(7, $product->getFeaturedImageId());
        $this->assertSame('published', $product->getStatus());
        $this->assertSame('2025-01-15', $product->getPublishedAt()->format('Y-m-d'));
        $this->assertSame('enabled', $product->getCommentStatus());
        $this->assertSame('disabled', $product->getVoteStatus());
        $this->assertSame('variable', $product->getType());
        $this->assertSame('free', $product->getShippingClass());
        $this->assertSame(5.0, $product->getShippingCost());
        $this->assertSame('custom', $product->getVatMode());
        $this->assertSame(19.0, $product->getVatRate());
        $this->assertSame('internal', $product->getDownloadSource());
        $this->assertSame(12, $product->getDownloadFileId());
        $this->assertSame('https://example.com/dl', $product->getDownloadUrl());
        $this->assertFalse($product->isDownloadUrlProxy());
        $this->assertSame(5, $product->getDownloadLimit());
        $this->assertSame(30, $product->getDownloadExpiryDays());
        $this->assertSame('SEO', $product->getMetaTitle());
        $this->assertSame('SEO desc', $product->getMetaDescription());
        $this->assertSame(100, $product->getTotalSold());
        $this->assertSame('12345', $product->getGtin());
        $this->assertSame('MPN-99', $product->getMpn());
        $this->assertSame('TestBrand', $product->getBrand());
        $this->assertSame('Electronics', $product->getGoogleProductCategory());
        $this->assertSame('used', $product->getCondition());
        $this->assertSame('male', $product->getGender());
        $this->assertSame('adult', $product->getAgeGroup());
        $this->assertSame('red', $product->getColor());
        $this->assertSame('XL', $product->getSize());
        $this->assertSame('cotton', $product->getMaterial());
        $this->assertSame('striped', $product->getPattern());
        $this->assertSame('cl0', $product->getCustomLabel0());
        $this->assertSame('cl1', $product->getCustomLabel1());
        $this->assertSame('cl2', $product->getCustomLabel2());
        $this->assertSame('cl3', $product->getCustomLabel3());
        $this->assertSame('cl4', $product->getCustomLabel4());
        $this->assertTrue($product->isGmcExclude());
        $this->assertSame([1, 2, 3], $product->getUpsellIds());
        $this->assertSame([4, 5], $product->getCrossSellIds());
        $this->assertSame(10.0, $product->getLength());
        $this->assertSame(20.0, $product->getWidth());
        $this->assertSame(30.0, $product->getHeight());
        $this->assertSame([['country' => 'DE', 'cost' => 5]], $product->getShippingCountryOverrides());
        $this->assertSame([['country' => 'AT', 'rate' => 20]], $product->getVatCountryOverrides());
        $this->assertSame('2025-01-01', $product->getCreatedAt()->format('Y-m-d'));
        $this->assertSame('2025-06-15', $product->getUpdatedAt()->format('Y-m-d'));
        $this->assertSame([10, 20], $product->getCategoryIds());
    }

    public function testFromRowWithNullOptionalFields(): void
    {
        $product = Product::fromRow([
            'id'             => 2,
            'name'           => 'Minimal',
            'slug'           => 'minimal',
            'sale_price'     => null,
            'weight'         => null,
            'length'         => null,
            'upsell_ids'     => null,
            'cross_sell_ids' => 'invalid-json',
        ]);

        $this->assertNull($product->getSalePrice());
        $this->assertNull($product->getWeight());
        $this->assertNull($product->getLength());
        $this->assertSame([], $product->getUpsellIds());
        $this->assertSame([], $product->getCrossSellIds());
    }

    public function testFromRowWithEmptyStringOptionalFields(): void
    {
        $product = Product::fromRow([
            'id'                         => 3,
            'name'                       => 'WithEmpty',
            'slug'                       => 'with-empty',
            'shipping_country_overrides' => '',
            'vat_country_overrides'      => '',
        ]);

        $this->assertSame([], $product->getShippingCountryOverrides());
        $this->assertSame([], $product->getVatCountryOverrides());
    }

    public function testFromRowWithCategoryIds(): void
    {
        $product = Product::fromRow([
            'id'   => 5,
            'name' => 'Cat Product',
            'slug' => 'cat-product',
        ], [10, 20, 30]);

        $this->assertSame([10, 20, 30], $product->getCategoryIds());
    }
}
