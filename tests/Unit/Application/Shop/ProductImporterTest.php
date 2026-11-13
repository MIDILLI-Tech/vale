<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ProductImporter;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\ImageProcessor;

final class ProductImporterTest extends TestCase
{
    private $dbMock;
    private $slugServiceMock;
    private $importer;

    protected function setUp(): void
    {
        $this->dbMock          = $this->createStub(Connection::class);
        $this->slugServiceMock = $this->createStub(SlugService::class);

        $this->importer = new ProductImporter(
            $this->dbMock,
            $this->slugServiceMock,
            new ImageProcessor(),
            [
                'thumbnails' => [
                    'sizes' => [
                        'small'  => ['width' => 150,  'height' => 150],
                        'medium' => ['width' => 300,  'height' => 300],
                        'large'  => ['width' => 1024, 'height' => 1024],
                    ],
                ],
            ],
        );
    }

    private function createTempFile(string $content): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'import_test');
        file_put_contents($temp, $content);
        return $temp;
    }

    public function testImportShopifySimpleProduct(): void
    {
        $csvContent = "Handle,Title,Body (HTML),Status,Published,Variant SKU,Variant Price,Variant Compare At Price,Variant Inventory Qty,Type\n" .
                      "test-product,\"Test Product\",\"Great description\",active,true,TEST-SKU,19.99,,10,Electronics\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('test-product-1'));

        $insertedProducts = [];
        $insertedPivot    = [];

        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedPivot) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_to_categories') {
                    $insertedPivot[] = $data;
                }
                return 1;
            });

        $updatedProducts = [];
        $this->dbMock->method('update')
            ->willReturnCallback(function ($table, $data, $criteria) use (&$updatedProducts) {
                if ($table === 'vl_products') {
                    $updatedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importShopify($filePath, false);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(0, $res['skipped']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('Test Product', $insertedProducts[0]['name']);
        $this->assertSame('simple', $insertedProducts[0]['type']);

        $this->assertCount(1, $updatedProducts);
        $this->assertSame('TEST-SKU', $updatedProducts[0]['sku']);
        $this->assertEquals(19.99, $updatedProducts[0]['price']);
        $this->assertSame(10, $updatedProducts[0]['stock_quantity']);
    }

    public function testImportShopifyVariableProduct(): void
    {
        $csvContent = "Handle,Title,Body (HTML),Status,Published,Variant SKU,Variant Price,Variant Compare At Price,Variant Inventory Qty,Type,Option1 Name,Option1 Value\n" .
                      "var-product,\"Var Product\",\"Body\",active,true,VAR-S,15.00,20.00,5,Clothing,Size,S\n" .
                      "var-product,\"Var Product\",\"Body\",active,true,VAR-M,18.00,,8,Clothing,Size,M\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('var-product'));

        $this->dbMock->method('lastInsertId')
            ->willReturn('101');

        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedVariants) {
                if ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $updatedProducts = [];
        $this->dbMock->method('update')
            ->willReturnCallback(function ($table, $data, $criteria) use (&$updatedProducts) {
                if ($table === 'vl_products') {
                    $updatedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importShopify($filePath, false);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(2, $res['variants']);

        $this->assertCount(2, $insertedVariants);
        $this->assertSame('VAR-S', $insertedVariants[0]['sku']);
        $this->assertSame('VAR-M', $insertedVariants[1]['sku']);

        $this->assertCount(1, $updatedProducts);
        $this->assertEquals(15.00, $updatedProducts[0]['price']);
        $this->assertSame(13, $updatedProducts[0]['stock_quantity']);
    }

    public function testImportEtsySimpleProduct(): void
    {
        $csvContent = "Title,Description,Price,Quantity,SKU,State,Section\n" .
                      "\"Etsy Item\",\"Handmade item\",25.50,3,ETSY-SKU,active,Art\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('etsy-item'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('Etsy Item', $insertedProducts[0]['name']);
        $this->assertEquals(25.50, $insertedProducts[0]['price']);
        $this->assertSame('ETSY-SKU', $insertedProducts[0]['sku']);
        $this->assertSame(3, $insertedProducts[0]['stock_quantity']);
    }

    public function testImportEbayVariableProduct(): void
    {
        $csvContent = "Item number,Title,Variation details,Custom label (SKU),Available quantity,Format,Currency,Start price,eBay category 1 name\n" .
                      "\"297927595587\",2 Stück USB Kabel 2-Meter,Adapter=Ja;Nein,,\"115\",\"FIXED_PRICE\",\"EUR\",2.49,\"Kabel & Adapter\"\n" .
                      "\"297927595587\",2 Stück USB Kabel 2-Meter,Adapter=Ja,mdl-usb2mc-WA,\"62\",\"FIXED_PRICE\",\"EUR\",2.85,\"Kabel & Adapter\"\n" .
                      "\"297927595587\",2 Stück USB Kabel 2-Meter,Adapter=Nein,mdl-usb2mc-OA,\"53\",\"FIXED_PRICE\",\"EUR\",2.49,\"Kabel & Adapter\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('ebay-usb-cable'));

        $this->dbMock->method('lastInsertId')
            ->willReturn('202');

        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedVariants) {
                if ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $updatedProducts = [];
        $this->dbMock->method('update')
            ->willReturnCallback(function ($table, $data, $criteria) use (&$updatedProducts) {
                if ($table === 'vl_products') {
                    $updatedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEbay($filePath, false);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(2, $res['variants']);

        $this->assertCount(2, $insertedVariants);
        $this->assertSame('mdl-usb2mc-WA', $insertedVariants[0]['sku']);
        $this->assertSame('mdl-usb2mc-OA', $insertedVariants[1]['sku']);

        $this->assertCount(1, $updatedProducts);
        $this->assertEquals(2.49, $updatedProducts[0]['price']);
        $this->assertSame(115, $updatedProducts[0]['stock_quantity']);
    }

    public function testImportAmazonInventoryReport(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\tasin1\n" .
                      "AMZN-101\t\"Amazon Product\"\t49.99\t120\tB000123456\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('amazon-product'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($filePath);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('Amazon Product', $insertedProducts[0]['name']);
        $this->assertSame('AMZN-101', $insertedProducts[0]['sku']);
        $this->assertSame('B000123456', $insertedProducts[0]['gtin']);
        $this->assertEquals(49.99, $insertedProducts[0]['price']);
        $this->assertSame(120, $insertedProducts[0]['stock_quantity']);
    }

    public function testParseXlsmReadsMultisheetAndHeadersCorrectly(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'test_excel') . '.xlsm';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        // workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                    '<workbook xmlns="http://schemas.openxmlformats.org/workbookvision/2006/main">' .
                    '<sheets>' .
                    '<sheet name="Info" sheetId="1"/>' .
                    '<sheet name="Vorlage" sheetId="2"/>' .
                    '</sheets>' .
                    '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // sharedStrings.xml
        $sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                        '<sst uniqueCount="4" count="4">' .
                        '<si><t>Status</t></si>' .
                        '<si><t>Title</t></si>' .
                        '<si><t>SKU</t></si>' .
                        '<si><t>Aktiv</t></si>' .
                        '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);

        // worksheets/sheet2.xml
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                 '<worksheet>' .
                 '<sheetData>' .
                 // Row 1: Instructions row
                 '<row r="1">' .
                 '<c r="A1" t="inlineStr"><v>Instructions...</v></c>' .
                 '</row>' .
                 // Row 2: Headers row (shared strings: index 0 = Status, index 1 = Title, index 2 = SKU)
                 '<row r="2">' .
                 '<c r="A2" t="s"><v>0</v></c>' .
                 '<c r="B2" t="s"><v>1</v></c>' .
                 '<c r="C2" t="s"><v>2</v></c>' .
                 '</row>' .
                 // Row 3: Data row (index 3 = Aktiv)
                 '<row r="3">' .
                 '<c r="A3" t="s"><v>3</v></c>' .
                 '<c r="B3" t="inlineStr"><v>Men Sweater</v></c>' .
                 '<c r="C3" t="inlineStr"><v>SWEATER-1</v></c>' .
                 '</row>' .
                 '</sheetData>' .
                 '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
        $zip->close();

        // Expect slug generation for product
        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('men-sweater'));

        $inserted = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$inserted) {
                if ($table === 'vl_products') {
                    $inserted[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($zipPath);

        unlink($zipPath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertCount(1, $inserted);
        $this->assertSame('Men Sweater', $inserted[0]['name']);
        $this->assertSame('SWEATER-1', $inserted[0]['sku']);
    }

    public function testImportAmazonTemplateXlsmSkipsMetadataAndImportsParentChild(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'test_amazon') . '.xlsm';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        // workbook.xml — "Vorlage" sheet uses sheetId 9 but maps (via rels) to sheet5.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"' .
                    ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
                    '<sheets>' .
                    '<sheet name="Anleitungen" sheetId="1" r:id="rId1"/>' .
                    '<sheet name="Vorlage" sheetId="9" r:id="rId2"/>' .
                    '</sheets>' .
                    '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet5.xml"/>' .
                '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet><sheetData></sheetData></worksheet>'
        );

        // Vorlage sheet: frozen pane ySplit=6 → real data starts at row 7.
        $inline = static fn (string $ref, string $value): string =>
            "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>{$value}</t></is></c>";
        $num    = static fn (string $ref, string $value): string =>
            "<c r=\"{$ref}\"><v>{$value}</v></c>";

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                 '<worksheet>' .
                 '<sheetViews><sheetView tabSelected="1" workbookViewId="0">' .
                 '<pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/>' .
                 '</sheetView></sheetViews>' .
                 '<sheetData>' .
                 // Row 4: label headers
                 '<row r="4">' . $inline('A4', 'Status') . $inline('B4', 'SKU') . $inline('C4', 'Artikelname') .
                 $inline('D4', 'Abstammungsgrad') . $inline('E4', 'Variationsthemabezeichnung') .
                 $inline('F4', 'Satzname (Set-Name)') . $inline('G4', 'Menge (DE)') .
                 $inline('H4', 'Listenpreis (UVP)') . $inline('I4', 'URL des Hauptbildes') . '</row>' .
                 // Row 5: Amazon attribute codes — must NOT be imported as a product
                 '<row r="5">' . $inline('A5', '::listing_status') . $inline('B5', 'contribution_sku#1.value') .
                 $inline('C5', 'item_name[marketplace_id=A1PA6795UKMFR9][language_tag=de_DE]#1.value') .
                 $inline('D5', 'parentage_level[marketplace_id=A1PA6795UKMFR9]#1.value') .
                 $inline('E5', 'variation_theme#1.name') .
                 $inline('F5', 'set_name[marketplace_id=A1PA6795UKMFR9][language_tag=de_DE]#1.value') .
                 $inline('G5', 'quantity#1.value') .
                 $inline('H5', 'uvp_list_price[marketplace_id=A1PA6795UKMFR9]#1.value') .
                 $inline('I5', 'main_image_url#1.value') . '</row>' .
                 // Row 6: Amazon example row — must NOT be imported
                 '<row r="6">' . $inline('A6', 'Aktiv') . $inline('B6', 'ABC123') .
                 $inline('C6', 'Geräuschunterdrückende kabellose Kopfhörer Sony QuietComfort') .
                 $inline('D6', 'Eltern') . '</row>' .
                 // Row 7: child variation
                 '<row r="7">' . $inline('A7', 'Aktiv') . $inline('B7', 'MFD128-U3A') .
                 $inline('C7', 'USB Stick 128GB USB 3.0 (mit Adapter)') . $inline('E7', 'SET_NAME') .
                 $inline('F7', 'mit USB-C Adapter') . $num('G7', '74') . $num('H7', '14.59') .
                 $inline('I7', 'https://m.media-amazon.com/images/I/51mVGZIjjwL.jpg') . '</row>' .
                 // Row 8: parent
                 '<row r="8">' . $inline('A8', 'Aktiv') . $inline('B8', 'MFD128-U3OA') .
                 $inline('C8', 'USB Stick 128GB USB 3.0 (ohne Adapter)') . $inline('D8', 'Eltern') .
                 $inline('E8', 'SET_NAME') . $num('G8', '141') . $num('H8', '14.59') .
                 $inline('I8', 'https://m.media-amazon.com/images/I/51f+r9rluuL.jpg') . '</row>' .
                 '</sheetData>' .
                 '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet5.xml', $sheet);
        $zip->close();

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('usb-stick-128gb'));

        $this->dbMock->method('lastInsertId')->willReturn('301');

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $updatedProducts = [];
        $this->dbMock->method('update')
            ->willReturnCallback(function ($table, $data, $criteria) use (&$updatedProducts) {
                if ($table === 'vl_products') {
                    $updatedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($zipPath);

        unlink($zipPath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(2, $res['variants']);
        $this->assertSame(0, $res['skipped']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('USB Stick 128GB USB 3.0 (ohne Adapter)', $insertedProducts[0]['name']);
        $this->assertSame('MFD128-U3OA', $insertedProducts[0]['sku']);
        $this->assertSame('variable', $insertedProducts[0]['type']);

        $this->assertCount(2, $insertedVariants);
        // Parent row itself becomes a variation (its own SKU/price/stock are sellable).
        // Its theme attribute is derived from the title's parenthetical option.
        $this->assertSame('MFD128-U3OA', $insertedVariants[0]['sku']);
        $this->assertSame(['set_name' => 'ohne Adapter'], json_decode($insertedVariants[0]['attributes'], true));
        $this->assertEquals(14.59, $insertedVariants[0]['price']);
        $this->assertSame(141, $insertedVariants[0]['stock_quantity']);
        // Child variation with its theme attribute.
        $this->assertSame('MFD128-U3A', $insertedVariants[1]['sku']);
        $this->assertSame(['set_name' => 'mit USB-C Adapter'], json_decode($insertedVariants[1]['attributes'], true));
        $this->assertEquals(14.59, $insertedVariants[1]['price']);
        $this->assertSame(74, $insertedVariants[1]['stock_quantity']);

        $this->assertCount(1, $updatedProducts);
        $this->assertEquals(14.59, $updatedProducts[0]['price']);
        $this->assertSame(215, $updatedProducts[0]['stock_quantity']);
    }

    public function testImportShopifyDryRunRollsBackTransaction(): void
    {
        $csvContent = "Handle,Title,Body (HTML),Status,Published,Variant SKU,Variant Price,Variant Compare At Price,Variant Inventory Qty,Type\n" .
                      "test-product,\"Test Product\",\"Great description\",active,true,TEST-SKU,19.99,,10,Electronics\n";

        $filePath = $this->createTempFile($csvContent);

        $dbMock = $this->createMock(Connection::class);
        $dbMock->expects($this->once())->method('beginTransaction');
        $dbMock->method('isTransactionActive')->willReturn(true);
        $dbMock->expects($this->once())->method('rollBack');
        $dbMock->expects($this->never())->method('commit');
        $dbMock->method('fetchOne')->willReturn(false);
        $dbMock->method('insert')->willReturn(1);
        $dbMock->method('update')->willReturn(1);

        $slugMock = $this->createStub(SlugService::class);
        $slugMock->method('generate')->willReturn(new Slug('test-product-1'));

        $importer = new ProductImporter($dbMock, $slugMock, new ImageProcessor(), [
            'thumbnails' => ['sizes' => ['small' => ['width' => 150, 'height' => 150]]],
        ]);

        $res = $importer->importShopify($filePath, false, true);

        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['dry_run']);
        $this->assertSame(1, $res['imported']);
    }

    // ── Empty / missing file edge cases ────────────────────────────

    public function testImportShopifyEmptyFileReturnsFailure(): void
    {
        $filePath = $this->createTempFile('');

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertNotEmpty($res['errors']);
    }

    public function testImportShopifyMissingFileReturnsFailure(): void
    {
        $res = $this->importer->importShopify('/nonexistent/path/file.csv', false);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportEtsyEmptyFileReturnsFailure(): void
    {
        $filePath = $this->createTempFile('');

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportEtsyMissingFileReturnsFailure(): void
    {
        $res = $this->importer->importEtsy('/nonexistent/path/file.csv', false);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportEbayEmptyFileReturnsFailure(): void
    {
        $filePath = $this->createTempFile('');

        $res = $this->importer->importEbay($filePath, false);
        unlink($filePath);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportEbayMissingFileReturnsFailure(): void
    {
        $res = $this->importer->importEbay('/nonexistent/path/file.csv', false);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportAmazonEmptyFileReturnsFailure(): void
    {
        $filePath = $this->createTempFile('');

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportAmazonMissingFileReturnsFailure(): void
    {
        $res = $this->importer->importAmazon('/nonexistent/path/file.csv');

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    // ── Shopify edge cases ─────────────────────────────────────────

    public function testImportShopifySkipsRowsWithoutHandle(): void
    {
        $csvContent = "Handle,Title,Type\n" .
                      ",No Handle Product,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(0, $res['skipped']);
    }

    public function testImportShopifySkipsProductsWithEmptyTitle(): void
    {
        $csvContent = "Handle,Title,Type\n" .
                      "has-no-title,,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    public function testImportShopifySkipsDuplicateByHandle(): void
    {
        $csvContent = "Handle,Title,Type\n" .
                      "dup-handle,Dup Product,simple\n" .
                      "dup-handle,Dup Product 2,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('dup-handle'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertCount(1, $insertedProducts);
    }

    // ── Etsy edge cases ────────────────────────────────────────────

    public function testImportEtsySkipsRowsWithEmptyTitle(): void
    {
        $csvContent = "Title,Description,Price,Quantity,SKU,State\n" .
                      ",\"No title\",10.00,1,SKU-1,active\n";

        $filePath = $this->createTempFile($csvContent);

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    public function testImportEtsyInsertsAllUniqueTitles(): void
    {
        $csvContent = "Title,Description,Price,Quantity,SKU,State\n" .
                      "First Title,\"Desc\",10.00,1,SKU-A,active\n" .
                      "Second Title,\"Desc 2\",20.00,2,SKU-B,active\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('first-title'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['imported']);
        $this->assertCount(2, $insertedProducts);
    }

    public function testImportEtsyVariableKeepsSinglePriceAndNewlines(): void
    {
        $csvContent = "TITEL,BESCHREIBUNG,PREIS,STÜCKZAHL,\"VARIATIONSTYP 1\",\"VARIATIONSNAME 1\",\"VARIATIONSWERT 1\"\n" .
                      "\"Cocktail Kerze\",\"Zeile eins\nZeile zwei\",26.45,75,\"Selbstgewählte Eigenschaft\",Style,\"Pina Colada mit Gel,Espresso Martini\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('cocktail-kerze'));

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['variants']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('variable', $insertedProducts[0]['type']);
        $this->assertEquals(26.45, $insertedProducts[0]['price']);
        $this->assertStringContainsString("\n", $insertedProducts[0]['description']);

        $this->assertCount(1, $insertedVariants);
        $this->assertEquals(26.45, $insertedVariants[0]['price']);
        $attributes = json_decode((string) $insertedVariants[0]['attributes'], true);
        $this->assertSame('Pina Colada mit Gel,Espresso Martini', $attributes['Style']);
    }

    // ── Ebay edge cases ────────────────────────────────────────────

    public function testImportEbaySkipsRowsWithoutTitle(): void
    {
        $csvContent = "Item number,Title,Available quantity,Start price\n" .
                      "\"12345\",,\"10\",5.99\n";

        $filePath = $this->createTempFile($csvContent);

        $res = $this->importer->importEbay($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    public function testImportEbaySkipsDuplicateByItemNumber(): void
    {
        $csvContent = "Item number,Title,Available quantity,Start price\n" .
                      "\"12345\",\"Item A\",\"5\",9.99\n" .
                      "\"12345\",\"Item B\",\"3\",7.99\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('item-a'));

        $this->dbMock->method('fetchOne')->willReturn(false);

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEbay($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertCount(1, $insertedProducts);
    }

    // ── Amazon edge cases ──────────────────────────────────────────

    public function testImportAmazonSkipsRowsWithoutTitle(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\n" .
                      "AMZN-SKU\t\t49.99\t10\n";

        $filePath = $this->createTempFile($csvContent);

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    public function testImportAmazonSkipsDuplicateBySku(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\n" .
                      "DUP-SKU\t\"Product A\"\t10.00\t5\n" .
                      "DUP-SKU\t\"Product B\"\t20.00\t3\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('product-a'));

        $this->dbMock->method('fetchOne')->willReturnOnConsecutiveCalls(false, '1');

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['skipped']);
        $this->assertCount(1, $insertedProducts);
    }

    public function testImportAmazonSimpleWithActiveStatusMapsToPublished(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\tstatus\n" .
                      "SKU-ACTIVE\t\"Active Product\"\t29.99\t5\taktiv\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('active-product'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('published', $insertedProducts[0]['status']);
    }

    public function testImportAmazonSimpleWithInactiveStatusMapsToDraft(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\tstatus\n" .
                      "SKU-INACTIVE\t\"Inactive Product\"\t29.99\t5\tinaktiv\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('inactive-product'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('draft', $insertedProducts[0]['status']);
    }

    public function testImportAmazonZeroStockMapsToOutOfStock(): void
    {
        $csvContent = "seller-sku\titem-name\tprice\tquantity\n" .
                      "SKU-ZERO\t\"Zero Stock\"\t9.99\t0\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('zero-stock'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importAmazon($filePath);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $insertedProducts[0]['stock_quantity']);
        $this->assertSame('out_of_stock', $insertedProducts[0]['stock_status']);
    }

    // ── Semicolon & tab delimiter sniffing ─────────────────────────

    public function testImportShopifyWithSemicolonDelimiter(): void
    {
        $csvContent = "Handle;Title;Type\n" .
                      "semi-product;\"Semicolon Product\";simple\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('semi-product'));

        $this->dbMock->method('insert')->willReturn(1);

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
    }

    public function testImportEtsyWithSemicolonDelimiter(): void
    {
        $csvContent = "Title;Description;Price;Quantity;SKU;State\n" .
                      "\"Semi Etsy\";\"Handmade\";15.50;2;SEMI-SKU;active\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('semi-etsy'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('Semi Etsy', $insertedProducts[0]['name']);
    }

    public function testImportEtsyGermanExportImportsProductAndVariations(): void
    {
        $csvContent = "\"TITEL\",\"BESCHREIBUNG\",\"PREIS\",\"WÄHRUNGSCODE\",\"STÜCKZAHL\",\"TAGS\",\"BILD1\",\"BILD2\"," .
                      "\"VARIATIONSTYP 1\",\"VARIATIONSNAME 1\",\"VARIATIONSWERT 1\",\"VARIATIONSTYP 2\",\"VARIATIONSNAME 2\"," .
                      "\"VARIATIONSWERT 2\",\"BESTANDSEINHEIT\"\n" .
                      "\"Dekorative Kerzen\",\"Handgemachte Kerzen\",\"22,92\",\"EUR\",\"559\",\"kerzen\"," .
                      "\"https://i.etsystatic.com/1.jpg\",\"https://i.etsystatic.com/2.jpg\"," .
                      "\"Selbstgewählte Eigenschaft\",\"Style\",\"Pfingstrose,Alpaka\",\"Farbe\",\"Hauptfarbe\",\"Weiß,Braun\",\"\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('dekorative-kerzen'));

        $this->dbMock->method('lastInsertId')->willReturn('77');

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['variants']);
        $this->assertSame(0, $res['skipped']);

        $this->assertCount(1, $insertedProducts);
        $this->assertSame('Dekorative Kerzen', $insertedProducts[0]['name']);
        $this->assertSame('Handgemachte Kerzen', $insertedProducts[0]['description']);
        $this->assertEquals(22.92, $insertedProducts[0]['price']);
        $this->assertSame(559, $insertedProducts[0]['stock_quantity']);
        $this->assertSame('published', $insertedProducts[0]['status']);
        $this->assertSame('variable', $insertedProducts[0]['type']);

        $this->assertCount(1, $insertedVariants);
        $this->assertSame([
            'Style'      => 'Pfingstrose,Alpaka',
            'Hauptfarbe' => 'Weiß,Braun',
        ], json_decode((string) $insertedVariants[0]['attributes'], true));
        $this->assertSame(559, $insertedVariants[0]['stock_quantity']);
        $this->assertEquals(22.92, $insertedVariants[0]['price']);
    }

    public function testImportEtsyNumberedVariationColumns(): void
    {
        $csvContent = "\"TITEL\",\"BESCHREIBUNG\",\"PREIS\",\"STÜCKZAHL\"," .
                      "\"VARIATION 1 TYPE\",\"VARIATION 1 NAME\",\"VARIATION 1 VALUES\"," .
                      "\"VARIATION 2 TYPE\",\"VARIATION 2 NAME\",\"VARIATION 2 VALUES\"\n" .
                      "\"Dekorative Kerzen\",\"Handgemachte Kerzen\",\"22,92\",\"559\"," .
                      "\"Selbstgewählte Eigenschaft\",\"Style\",\"Romantisches Paar,Pfingstrose\"," .
                      "\"Farbe\",\"Hauptfarbe\",\"Weiß,Braun\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('dekorative-kerzen'));

        $this->dbMock->method('lastInsertId')->willReturn('78');

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['variants']);
        $this->assertSame('variable', $insertedProducts[0]['type']);

        $this->assertCount(1, $insertedVariants);
        $this->assertSame([
            'Style'      => 'Romantisches Paar,Pfingstrose',
            'Hauptfarbe' => 'Weiß,Braun',
        ], json_decode((string) $insertedVariants[0]['attributes'], true));
    }

    public function testImportEtsyUsesVariationLabelsFromFile(): void
    {
        $csvContent = "\"TITEL\",\"PREIS\",\"STÜCKZAHL\",\"VARIATIONSTYP 1\",\"VARIATIONSNAME 1\",\"VARIATIONSWERT 1\"\n" .
                      "\"Kerze\",\"4.90\",\"3\",\"Selbstgewählte Eigenschaft\",\"kamil\",\"rot,blau,schwarz\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('kerze'));

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['variants']);

        $this->assertCount(1, $insertedVariants);
        $this->assertSame(
            ['kamil' => 'rot,blau,schwarz'],
            json_decode((string) $insertedVariants[0]['attributes'], true),
        );
        $this->assertSame('variable', $insertedProducts[0]['type']);
    }

    public function testImportEtsyUppercaseEnglishHeaders(): void
    {
        $csvContent = "TITLE,DESCRIPTION,PRICE,QUANTITY,SKU,STATE,IMAGE1\n" .
                      "\"Upper Case\",\"Desc\",10.50,4,UPPER-1,active,https://i.etsystatic.com/x.jpg\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('upper-case'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('Upper Case', $insertedProducts[0]['name']);
        $this->assertEquals(10.50, $insertedProducts[0]['price']);
        $this->assertSame(4, $insertedProducts[0]['stock_quantity']);
        $this->assertSame('UPPER-1', $insertedProducts[0]['sku']);
        $this->assertSame('published', $insertedProducts[0]['status']);
        $this->assertSame('simple', $insertedProducts[0]['type']);
    }

    public function testImportEtsyDefaultsToPublishedWithoutStateColumn(): void
    {
        $csvContent = "Title,Description,Price,Quantity\n" .
                      "\"No State\",\"Desc\",9.99,2\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('no-state'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('published', $insertedProducts[0]['status']);
    }

    public function testImportEtsyInactiveStateMapsToDraft(): void
    {
        $csvContent = "Title,Description,Price,Quantity,State\n" .
                      "\"Archived Item\",\"Desc\",9.99,2,inactive\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('archived-item'));

        $insertedProducts = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                }
                return 1;
            });

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame('draft', $insertedProducts[0]['status']);
    }

    public function testImportEtsySkipsAlreadyImportedListing(): void
    {
        $csvContent = "\"TITEL\",\"PREIS\",\"STÜCKZAHL\"\n" .
                      "\"Dekorative Kerzen\",\"22.92\",\"5\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->dbMock->method('fetchOne')->willReturn('42');

        $res = $this->importer->importEtsy($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    // ── importProducts method ──────────────────────────────────────

    public function testImportProductsSimpleNewProduct(): void
    {
        $csvContent = "Name,SKU,Price,Stock Quantity,Status,Type\n" .
                      "Test Product,TP-001,29.99,10,published,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('unique')
            ->willReturn(new Slug('test-product'));

        $this->dbMock->method('fetchOne')->willReturn(false);
        $this->dbMock->method('lastInsertId')->willReturn('500');
        $this->dbMock->method('insert')->willReturn(1);

        $res = $this->importer->importProducts($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(0, $res['skipped']);
    }

    public function testImportProductsSkipsExistingByDefault(): void
    {
        $csvContent = "Name,SKU,Price,Stock Quantity,Status,Type\n" .
                      "Existing,EX-001,10.00,5,published,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('unique')
            ->willReturn(new Slug('existing'));

        $this->dbMock->method('fetchOne')->willReturn(42);

        $res = $this->importer->importProducts($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
    }

    public function testImportProductsUpdatesExistingWhenFlagSet(): void
    {
        $csvContent = "Name,SKU,Price,Stock Quantity,Status,Type\n" .
                      "Updated,UP-001,15.00,20,published,simple\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('unique')
            ->willReturn(new Slug('updated'));

        $this->dbMock->method('fetchOne')->willReturn(42);
        $this->dbMock->method('update')->willReturn(1);

        $res = $this->importer->importProducts($filePath, false, false, true);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(0, $res['skipped']);
    }

    public function testImportProductsSkipsEmptyName(): void
    {
        $csvContent = "Name,SKU,Price\n" .
                      ",SKU-001,10.00\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('unique')
            ->willReturn(new Slug('placeholder'));

        $res = $this->importer->importProducts($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(1, $res['skipped']);
        $this->assertNotEmpty($res['errors']);
    }

    public function testImportProductsWithVariationsJson(): void
    {
        $varsJson   = '[{"sku":"VPRO-S","price":24.99,"stock_quantity":5},{"sku":"VPRO-L","price":34.99,"stock_quantity":3}]';
        $csvContent = "Name,Slug,SKU,Price,Stock Quantity,Status,Type,Variations\n" .
                      "Var Product,var-prod,VPRO-001,29.99,10,published,variable,\"" . str_replace('"', '""', $varsJson) . "\"\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('unique')
            ->willReturn(new Slug('var-prod'));

        $this->dbMock->method('fetchOne')->willReturn(false);
        $this->dbMock->method('lastInsertId')->willReturnOnConsecutiveCalls('600', '601', '602');
        $this->dbMock->method('insert')->willReturn(1);

        $res = $this->importer->importProducts($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(2, $res['variants']);
    }

    public function testImportProductsEmptyFileReturnsFailure(): void
    {
        $filePath = $this->createTempFile('');

        $res = $this->importer->importProducts($filePath, false);
        unlink($filePath);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportProductsMissingFileReturnsFailure(): void
    {
        $res = $this->importer->importProducts('/nonexistent/file.csv', false);

        $this->assertFalse($res['success']);
        $this->assertSame(0, $res['imported']);
    }

    public function testImportProductsDryRunRollsBack(): void
    {
        $csvContent = "Name,SKU,Price\n" .
                      "Dry Run Product,DR-001,19.99\n";

        $filePath = $this->createTempFile($csvContent);

        $slugMock = $this->createStub(SlugService::class);
        $slugMock->method('unique')->willReturn(new Slug('dry-run-product'));

        $dbMock = $this->createMock(Connection::class);
        $dbMock->expects($this->once())->method('beginTransaction');
        $dbMock->method('isTransactionActive')->willReturn(true);
        $dbMock->expects($this->once())->method('rollBack');
        $dbMock->method('fetchOne')->willReturn(false);
        $dbMock->method('lastInsertId')->willReturn('700');
        $dbMock->method('insert')->willReturn(1);

        $importer = new ProductImporter($dbMock, $slugMock, new ImageProcessor(), [
            'thumbnails' => ['sizes' => ['small' => ['width' => 150, 'height' => 150]]],
        ]);

        $res = $importer->importProducts($filePath, false, true);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['dry_run']);
        $this->assertSame(1, $res['imported']);
    }

    // ── Shopify filter/strip/quantity handling ──────────────────────

    public function testImportShopifySkipsGiftCards(): void
    {
        $csvContent = "Handle,Title,Type\n" .
                      "gift-card,Gift Card,gift_card\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('gift-card'));

        $this->dbMock->method('fetchOne')->willReturn(false);
        $this->dbMock->method('insert')->willReturn(1);

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['imported']);
    }

    public function testImportShopifyMultipleVariantsGroupsCorrectly(): void
    {
        $csvContent = "Handle,Title,Type,Option1 Name,Option1 Value,Variant SKU,Variant Price,Variant Inventory Qty\n" .
                      "multi-prod,Multi Product,clothing,Color,Red,MULTI-R,12.00,7\n" .
                      "multi-prod,Multi Product,clothing,Color,Blue,MULTI-B,12.00,3\n" .
                      "other-prod,Other Product,accessories,,,,Other SKU,8.00,5\n";

        $filePath = $this->createTempFile($csvContent);

        $this->slugServiceMock->method('generate')
            ->willReturn(new Slug('multi-prod'));

        $this->dbMock->method('lastInsertId')->willReturn('800');

        $insertedProducts = [];
        $insertedVariants = [];
        $this->dbMock->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedProducts, &$insertedVariants) {
                if ($table === 'vl_products') {
                    $insertedProducts[] = $data;
                } elseif ($table === 'vl_product_variations') {
                    $insertedVariants[] = $data;
                }
                return 1;
            });

        $this->dbMock->method('update')->willReturn(1);

        $res = $this->importer->importShopify($filePath, false);
        unlink($filePath);

        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['imported']);
        $this->assertCount(2, $insertedVariants);
    }
}
