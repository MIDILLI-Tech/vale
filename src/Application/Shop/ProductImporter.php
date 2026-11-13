<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use Doctrine\DBAL\Connection;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Payment\HttpTransport;

final class ProductImporter
{
    private array $downloadedImages = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SlugService $slugService,
        private readonly ImageProcessor $imageProcessor,
        private readonly array $mediaConfig = [],
    ) {
    }

    /**
     * Parses a CSV or tab-delimited file into an array of associative arrays.
     * Sniffs the first 15 lines to auto-detect the delimiter correctly even with instruction headers,
     * and dynamically searches for the actual column headers row.
     */
    private function parseCsv(string $filePath): array
    {
        $rows = [];
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        // Sniff delimiter across multiple lines
        $delimiter = ',';
        if (($handle = fopen($filePath, 'r')) !== false) {
            $commaCount = 0;
            $semiCount  = 0;
            $tabCount   = 0;

            for ($i = 0; $i < 15; $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $commaCount += substr_count($line, ',');
                $semiCount  += substr_count($line, ';');
                $tabCount   += substr_count($line, "\t");
            }
            fclose($handle);

            if ($tabCount > $commaCount && $tabCount > $semiCount) {
                $delimiter = "\t";
            } elseif ($semiCount > $commaCount && $semiCount > $tabCount) {
                $delimiter = ';';
            }
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = null;

            while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                // Skip empty rows
                if (empty($data) || (count($data) === 1 && trim((string)$data[0]) === '')) {
                    continue;
                }

                // If headers are not found yet, detect if this is a header row
                if ($headers === null) {
                    if ($this->isHeaderRow($data)) {
                        // Clean UTF-8 BOM if present on the first header
                        if (isset($data[0])) {
                            $data[0] = preg_replace('/[\x{FEFF}\x{FFFE}]/u', '', $data[0]);
                        }
                        $headers = array_map('trim', $data);
                    }
                    continue;
                }

                // Pad data row if shorter than headers
                if (count($data) < count($headers)) {
                    $data = array_pad($data, count($headers), '');
                }

                $row    = array_combine(array_slice($headers, 0, count($data)), array_slice($data, 0, count($headers)));
                $rows[] = $row;
            }
            fclose($handle);
        }
        return $rows;
    }

    /**
     * Helper to clean XML namespaces for standardizing SimpleXML element access.
     */
    private function cleanXmlNamespaces(string $xmlRaw): string
    {
        // Remove namespace declarations
        $clean = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlRaw);

        // Convert namespaced attributes like r:id="..." or x:id="..." to id="..."
        $clean = preg_replace('/[a-zA-Z0-9]+:([a-zA-Z0-9]+)\s*=\s*"/i', '$1="', $clean);

        // Convert namespaced elements like <x:sheet> to <sheet>
        $clean = preg_replace('/<\s*\/?[a-zA-Z0-9]+:([a-zA-Z0-9]+)/i', '<$1', $clean);

        return $clean;
    }

    /**
     * High-performance, memory-efficient XLSX/XLSM reader using standard ZipArchive and SimpleXML.
     */
    private function parseXlsm(string $filePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        // 1. Read Shared Strings to map string indices to cell values
        $sharedStrings = [];
        $stringsXmlRaw = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXmlRaw) {
            $cleanXml = $this->cleanXmlNamespaces($stringsXmlRaw);
            $xml      = @simplexml_load_string($cleanXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } else {
                        foreach ($si->r as $r) {
                            if (isset($r->t)) {
                                $text .= (string) $r->t;
                            }
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // 2. Read Workbook relationships to map r:id to actual filenames
        $relationships = [];
        $relsXmlRaw    = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXmlRaw) {
            $cleanXml = $this->cleanXmlNamespaces($relsXmlRaw);
            $relsXml  = @simplexml_load_string($cleanXml);
            if ($relsXml) {
                foreach ($relsXml->Relationship as $rel) {
                    $id = (string) $rel['id']; // note: cleaned r:id to id
                    if ($id === '') {
                        $id = (string) $rel['Id'];
                    }
                    $target             = (string) $rel['Target'];
                    $relationships[$id] = $target;
                }
            }
        }

        // 3. Read Workbook to find the target sheet (e.g. "Vorlage", "Template", "Products")
        $targetSheetPath = null;
        $firstSheetPath  = null;
        $workbookXmlRaw  = $zip->getFromName('xl/workbook.xml');
        if ($workbookXmlRaw) {
            $cleanXml    = $this->cleanXmlNamespaces($workbookXmlRaw);
            $workbookXml = @simplexml_load_string($cleanXml);
            if ($workbookXml && isset($workbookXml->sheets->sheet)) {
                foreach ($workbookXml->sheets->sheet as $sheet) {
                    $name = strtolower((string) $sheet['name']);

                    // Retrieve relationship r:id attribute safely
                    $rId = (string) ($sheet['id'] ?? $sheet['Id'] ?? '');

                    // Resolve the sheet file path
                    $relPath = $relationships[$rId] ?? '';
                    if ($relPath === '') {
                        $sheetId = (int) $sheet['sheetId'];
                        $relPath = "worksheets/sheet{$sheetId}.xml";
                    }

                    $fullPath = str_starts_with($relPath, 'xl/') ? $relPath : 'xl/' . $relPath;

                    if ($firstSheetPath === null) {
                        $firstSheetPath = $fullPath;
                    }

                    if (
                        $name === 'vorlage'            ||
                        $name === 'template'           ||
                        str_contains($name, 'product') ||
                        str_contains($name, 'inventory')
                    ) {
                        $targetSheetPath = $fullPath;
                        break;
                    }
                }
            }
        }

        $sheetPath = $targetSheetPath ?? $firstSheetPath ?? 'xl/worksheets/sheet1.xml';

        // Load the target sheet XML
        $sheetXmlRaw = $zip->getFromName($sheetPath);
        if (!$sheetXmlRaw && $sheetPath !== 'xl/worksheets/sheet1.xml') {
            // Fallback to sheet1.xml if resolved target sheet file was not found
            $sheetXmlRaw = $zip->getFromName('xl/worksheets/sheet1.xml');
        }

        if (!$sheetXmlRaw) {
            $zip->close();
            return [];
        }

        $cleanXml = $this->cleanXmlNamespaces($sheetXmlRaw);
        $sheetXml = @simplexml_load_string($cleanXml);
        if (!$sheetXml) {
            $zip->close();
            return [];
        }

        // Detect the first data row from the frozen pane (top rows are template metadata).
        $firstDataRow = null;
        if (isset($sheetXml->sheetViews->sheetView)) {
            foreach ($sheetXml->sheetViews->sheetView as $view) {
                $ySplit = isset($view->pane) ? (int) ($view->pane['ySplit'] ?? 0) : 0;
                if ($ySplit > 0) {
                    $firstDataRow = $ySplit + 1;
                    break;
                }
                $topLeft = (string) ($view['topLeftCell'] ?? '');
                if ($topLeft !== '' && preg_match('/\d+/', $topLeft, $m)) {
                    $firstDataRow = (int) $m[0];
                    break;
                }
            }
        }

        $rows           = [];
        $headerRowFound = false;
        $codeRowFound   = false;
        $headers        = [];
        $codeKeys       = [];
        $rowNum         = 0;

        foreach ($sheetXml->sheetData->row as $row) {
            $rAttr  = (string) $row['r'];
            $rowNum = $rAttr !== '' ? (int) $rAttr : $rowNum + 1;

            $rowData = [];
            foreach ($row->c as $cell) {
                $r = (string) $cell['r']; // e.g. "A1", "B1"
                preg_match('/^[A-Z]+/', $r, $match);
                $colLetter = $match[0] ?? '';

                $t   = (string) $cell['t'];
                $v   = (string) $cell->v;
                $val = '';

                if ($t === 's') {
                    $idx = (int) $v;
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($t === 'inlineStr' || isset($cell->is->t)) {
                    $val = (string) ($cell->is->t ?? '');
                    if ($val === '') {
                        $val = $v;
                    }
                } else {
                    $val = $v;
                }

                $rowData[$colLetter] = trim($val);
            }

            if (empty($rowData)) {
                continue;
            }

            // Detect if this row is the header (label) row.
            if (!$headerRowFound && $this->isHeaderRow($rowData)) {
                $headers        = $rowData;
                $headerRowFound = true;
                continue; // the header row itself must never become a data row
            }

            // Rows above the frozen pane are template metadata (labels/codes/example row).
            if ($firstDataRow !== null && $rowNum < $firstDataRow) {
                if (!$codeRowFound && $this->isAttributeCodeRow($rowData)) {
                    $codeKeys     = $this->buildCodeKeys($rowData);
                    $codeRowFound = true;
                }
                continue;
            }

            if (!$headerRowFound) {
                continue;
            }

            $mappedRow = [];
            foreach ($rowData as $colLetter => $val) {
                $headerName = $headers[$colLetter] ?? '';
                if ($headerName === '') {
                    continue;
                }
                $mappedRow[$headerName] = $val;

                $codeKey = $codeKeys[$colLetter] ?? '';
                if ($codeKey !== '' && $codeKey !== strtolower($headerName)) {
                    $mappedRow[$codeKey] = $val;
                }
            }

            // Safety: skip a template code row that leaked past the pane boundary.
            if ($this->isAttributeCodeRow($rowData)) {
                continue;
            }

            $rows[] = $mappedRow;
        }

        $zip->close();
        return $rows;
    }

    /**
     * Recognises the header row of the supported marketplace exports
     * (Shopify, eBay, Amazon, Etsy, generic) in both the English and the
     * localised (e.g. German) column labels.
     */
    private function isHeaderRow(array $rowData): bool
    {
        $knownLabels = [
            'sku', 'seller-sku', 'contribution_sku',
            'title', 'titel',
            'beschreibung',
            'preis',
            'stückzahl', 'stuckzahl',
            'artikelname', 'item-name',
            'handle',
            'item number', 'itemnumber',
        ];

        foreach ($rowData as $val) {
            if (in_array(mb_strtolower(trim((string) $val)), $knownLabels, true)) {
                return true;
            }
        }

        return false;
    }

    private function isAttributeCodeRow(array $rowData): bool
    {
        foreach ($rowData as $val) {
            if (preg_match('/#\d+\.|\[[a-z0-9_]+_id=|^::/', $val) === 1) {
                return true;
            }
        }
        return false;
    }

    private function buildCodeKeys(array $rowData): array
    {
        $keys = [];
        foreach ($rowData as $colLetter => $code) {
            $key = $this->normalizeAttributeKey($code);
            if ($key !== '') {
                $keys[$colLetter] = $key;
            }
        }
        return $keys;
    }

    private function normalizeAttributeKey(string $code): string
    {
        $key = trim($code);
        $key = preg_replace('/#.*$/', '', $key);
        $key = preg_replace('/\[.*$/', '', $key);
        $key = strtolower(ltrim($key, ':'));

        if ($key === '' || str_contains($key, ' ') || str_contains($key, '/')) {
            return '';
        }
        return $key;
    }

    /**
     * Parses the given file path based on its actual magic bytes / file signature (ZIP/Excel or CSV).
     */
    private function parseFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        // Detect if the file is a ZIP/OfficeOpenXML file (XLSM, XLSX, ZIP) using magic bytes
        $isZip = false;
        if (($handle = fopen($filePath, 'rb')) !== false) {
            $bytes = fread($handle, 4);
            fclose($handle);
            if ($bytes === "PK\x03\x04") {
                $isZip = true;
            }
        }

        if ($isZip) {
            return $this->parseXlsm($filePath);
        }

        return $this->parseCsv($filePath);
    }

    /**
     * Helper to validate that a URL points to a safe public IP address (SSRF mitigation).
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host   = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $ips = @gethostbynamel($host);
        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper to detect loopback, private, and link-local IP addresses.
     */
    private function isPrivateIp(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return true; // block invalid IPs
        }

        return (
            ($ipLong & 0xff000000) === 0x7f000000 || // 127.0.0.0/8
            ($ipLong & 0xff000000) === 0x0a000000 || // 10.0.0.0/8
            ($ipLong & 0xfff00000) === 0xac100000 || // 172.16.0.0/12
            ($ipLong & 0xffff0000) === 0xc0a80000 || // 192.168.0.0/16
            ($ipLong & 0xffff0000) === 0xa9fe0000    // 169.254.0.0/16
        );
    }

    /**
     * Helper to download an image from a URL and save it to media library.
     */
    private function downloadImage(string $url): ?int
    {
        $url = trim($url);
        if (empty($url)) {
            return null;
        }

        // Cache downloaded URLs in the same run to prevent duplicate downloads
        if (isset($this->downloadedImages[$url])) {
            return $this->downloadedImages[$url];
        }

        // Mitigate SSRF
        if (!$this->isSafeUrl($url)) {
            return null;
        }

        // Reuse an existing media entry for the same source file (Amazon image URLs
        // carry the unique filename). Avoids duplicate rows / unique-path collisions
        // on re-imports.
        $originalName = basename($url);
        $existingId   = $this->db->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE original_name = ?',
            [$originalName],
        );
        if ($existingId) {
            $existingId = (int) $existingId;
            $this->ensureThumbnailsForMedia($existingId);
            $this->downloadedImages[$url] = $existingId;
            return $existingId;
        }

        $uploadsDir = base_path('storage/uploads');
        $parsedUrl  = parse_url($url);
        $path       = $parsedUrl['path'] ?? '';
        $filename   = basename($path);

        if (empty($filename)) {
            $filename = 'imported_' . uniqid() . '.jpg';
        }

        // Check if year/month organization folders are enabled in admin settings
        $useFoldersVal = $this->db->fetchOne("SELECT " . $this->db->quoteIdentifier('value') . " FROM " . VL_DB_PREFIX . "settings WHERE " . $this->db->quoteIdentifier('key') . " = 'media_use_year_month_folders'");
        $useFolders    = ($useFoldersVal === false || $useFoldersVal === '1');

        if ($useFolders) {
            $subDir       = date('Y/m');
            $targetDir    = $uploadsDir . '/' . $subDir;
            $relativePath = $subDir . '/' . $filename;
            $destPath     = $targetDir . '/' . $filename;
        } else {
            $subDir       = '';
            $targetDir    = $uploadsDir;
            $relativePath = $filename;
            $destPath     = $uploadsDir . '/' . $filename;
        }

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // Use HttpTransport for downloads as per architectural guidelines
        $res = HttpTransport::request('GET', $url);
        if (!$res['ok'] || empty($res['body'])) {
            return null;
        }

        if (@file_put_contents($destPath, $res['body']) === false) {
            return null;
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($destPath) ?: 'image/jpeg';

        // Enforce strict image whitelist to prevent executable upload/RCE
        $safeMimes = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
        ];

        if (!isset($safeMimes[$mimeType])) {
            @unlink($destPath);
            return null;
        }

        // SVG from a remote feed is attacker-controlled markup served
        // same-origin — sanitize before it ever lands in the media library
        // (same sanitizer as the admin upload path). Failure rejects the
        // image rather than storing raw SVG (fail-closed).
        if ($mimeType === 'image/svg+xml') {
            try {
                $this->sanitizeDownloadedSvg($destPath);
            } catch (\RuntimeException) {
                @unlink($destPath);
                return null;
            }
        }

        // Match filename extension to true safe mime type
        $safeExt     = $safeMimes[$mimeType];
        $originalExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($originalExt !== $safeExt) {
            $newFilename  = pathinfo($filename, PATHINFO_FILENAME) . '.' . $safeExt;
            $newDestPath  = $targetDir . '/' . $newFilename;
            @rename($destPath, $newDestPath);
            $destPath     = $newDestPath;
            $filename     = $newFilename;
            $relativePath = ($useFolders ? date('Y/m') . '/' : '') . $filename;
        }

        $size       = filesize($destPath);
        $thumbnails = $this->generateThumbnailsForFile($destPath, $subDir);

        try {
            $this->db->insert(VL_DB_PREFIX . 'media', [
                'user_id'       => 1, // Default admin user ID
                'filename'      => $filename,
                'original_name' => basename($url),
                'mime_type'     => $mimeType,
                'size'          => $size,
                'path'          => $relativePath,
                'disk'          => 'local',
                'thumbnails'    => $thumbnails === [] ? null : json_encode($thumbnails),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $mediaId                      = (int) $this->db->lastInsertId();
            $this->downloadedImages[$url] = $mediaId;
            return $mediaId;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sanitizes a freshly downloaded SVG in place (same sanitizer as the
     * admin upload path). Public for testability; failure rejects the file
     * (caller deletes it — fail-closed).
     *
     * @throws \RuntimeException when sanitization fails (caller deletes the file).
     */
    public function sanitizeDownloadedSvg(string $destPath): void
    {
        $this->imageProcessor->sanitizeSvg($destPath, $destPath);
    }

    /**
     * Generates small/medium/large thumbnails next to the source file and
     * returns their relative paths keyed by size. Skips sizes the image is
     * already small enough for, so processing stays cheap (no CPU churn).
     *
     * @return array<string, string>
     */
    private function generateThumbnailsForFile(string $destPath, string $subDir): array
    {
        $sizes = $this->mediaConfig['thumbnails']['sizes'] ?? [];
        if ($sizes === [] || !is_file($destPath)) {
            return [];
        }

        try {
            $thumbs = $this->imageProcessor->generateThumbnails(
                $destPath,
                dirname($destPath),
                basename($destPath),
                $sizes,
            );
        } catch (\RuntimeException) {
            return [];
        }

        $relative = [];
        foreach ($thumbs as $sizeName => $thumbFile) {
            $relative[$sizeName] = ($subDir !== '' ? $subDir . '/' : '') . $thumbFile;
        }
        return $relative;
    }

    /**
     * Backfills thumbnails on a media row that predates thumbnail generation
     * (e.g. an image downloaded by an earlier import and re-used on re-import).
     */
    private function ensureThumbnailsForMedia(int $mediaId): void
    {
        $row = $this->db->fetchAssociative(
            'SELECT path, thumbnails FROM ' . VL_DB_PREFIX . 'media WHERE id = ?',
            [$mediaId],
        );
        if ($row === false || !empty($row['thumbnails'])) {
            return;
        }

        $path = (string) $row['path'];
        if ($path === '') {
            return;
        }

        $file = base_path('storage/uploads') . '/' . $path;
        if (!is_file($file)) {
            return;
        }

        $thumbnails = $this->generateThumbnailsForFile($file, dirname($path));
        if ($thumbnails !== []) {
            $this->db->update(
                VL_DB_PREFIX . 'media',
                ['thumbnails' => json_encode($thumbnails)],
                ['id'         => $mediaId],
            );
        }
    }

    /**
     * Resolves category ID by name, creating it if it doesn't exist.
     */
    private function resolveCategory(string $categoryName): ?int
    {
        $categoryName = trim($categoryName);
        if (empty($categoryName)) {
            return null;
        }

        $table = VL_DB_PREFIX . 'product_categories';
        try {
            $existing = $this->db->fetchOne("SELECT id FROM {$table} WHERE name = ?", [$categoryName]);
            if ($existing) {
                return (int) $existing;
            }

            $slug = (string) $this->slugService->generate($categoryName, $table);
            $this->db->insert($table, [
                'name'       => $categoryName,
                'slug'       => $slug,
                'language'   => 'en',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Dry-run: run inside a transaction and roll back so counts stay accurate without persisting.
     */
    private function beginDryRun(bool $dryRun, bool $importMedia): bool
    {
        if (!$dryRun) {
            return $importMedia;
        }

        $this->db->beginTransaction();

        return false;
    }

    /** @param array<string, mixed> $result */
    private function finishDryRun(bool $dryRun, array $result): array
    {
        if ($dryRun) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $result['dry_run'] = true;
        }

        return $result;
    }

    private function abortDryRun(bool $dryRun): void
    {
        if ($dryRun && $this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
    }

    /**
     * Shopify Product CSV/Excel Importer
     */
    public function importShopify(string $filePath, bool $importMedia, bool $dryRun = false): array
    {
        $rows = $this->parseFile($filePath);
        if (empty($rows)) {
            return ['success' => false, 'imported' => 0, 'variants' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable']];
        }

        $importMedia = $this->beginDryRun($dryRun, $importMedia);

        try {
            $grouped = [];
            foreach ($rows as $row) {
                $handle = $row['Handle'] ?? '';
                if ($handle === '') {
                    continue;
                }
                $grouped[$handle][] = $row;
            }

            $importedCount = 0;
            $variantsCount = 0;
            $skippedCount  = 0;

            foreach ($grouped as $handle => $productRows) {
                $firstRow = $productRows[0];
                $title    = $firstRow['Title'] ?? '';
                if ($title === '') {
                    $skippedCount++;
                    continue;
                }

                // Check if product already exists to avoid duplication
                // (match by Shopify handle OR the slug generated from the title,
                //  since slugified titles can differ from the raw handle)
                $existing = $this->db->fetchOne(
                    "SELECT id FROM " . VL_DB_PREFIX . "products WHERE slug IN (?, ?) LIMIT 1",
                    [(string) Slug::fromString($handle), (string) Slug::fromString($title)]
                );
                if ($existing) {
                    $skippedCount++;
                    continue;
                }

                // Determine if variable or simple
                $isVariable = false;
                $options    = [];
                foreach ($productRows as $row) {
                    $opt1Name = trim($row['Option1 Name'] ?? '');
                    $opt1Val  = trim($row['Option1 Value'] ?? '');
                    if ($opt1Name !== '' && $opt1Name !== 'Title' && $opt1Val !== 'Default Title' && $opt1Val !== '') {
                        $isVariable = true;
                        break;
                    }
                }

                // Resolve Category
                $categoryName = $firstRow['Type'] ?? $firstRow['Product Category'] ?? '';
                $categoryId   = $this->resolveCategory($categoryName);

                // Handle image downloading
                $featuredImageId = null;
                $galleryImageIds = [];
                if ($importMedia) {
                    // Collect unique image URLs
                    $imageUrls = [];
                    foreach ($productRows as $row) {
                        $imgSrc = trim($row['Image Src'] ?? '');
                        if ($imgSrc !== '') {
                            $imageUrls[] = $imgSrc;
                        }
                        $varImg = trim($row['Variant Image'] ?? '');
                        if ($varImg !== '') {
                            $imageUrls[] = $varImg;
                        }
                    }
                    $imageUrls = array_values(array_unique($imageUrls));

                    foreach ($imageUrls as $idx => $url) {
                        $mediaId = $this->downloadImage($url);
                        if ($mediaId !== null) {
                            if ($featuredImageId === null) {
                                $featuredImageId = $mediaId;
                            } else {
                                $galleryImageIds[] = $mediaId;
                            }
                        }
                    }
                }

                $description      = $firstRow['Body (HTML)'] ?? '';
                $status           = (strtolower($firstRow['Status'] ?? '') === 'active' || strtolower($firstRow['Published'] ?? '') === 'true') ? 'published' : 'draft';
                $uniqueSlug       = (string) $this->slugService->generate($title, VL_DB_PREFIX . 'products');

                $productType = $isVariable ? 'variable' : 'simple';

                // Insert Parent Product
                $this->db->insert(VL_DB_PREFIX . 'products', [
                    'name'              => $title,
                    'slug'              => $uniqueSlug,
                    'language'          => 'en',
                    'description'       => $description,
                    'short_description' => '',
                    'price'             => 0.00,
                    'sale_price'        => null,
                    'stock_quantity'    => 0,
                    'stock_status'      => 'out_of_stock',
                    'weight'            => null,
                    'type'              => $productType,
                    'status'            => $status,
                    'featured_image_id' => $featuredImageId,
                    'created_at'        => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);

                $productId = (int) $this->db->lastInsertId();

                // Link Category
                if ($categoryId !== null) {
                    $this->db->insert(VL_DB_PREFIX . 'product_to_categories', [
                        'product_id'  => $productId,
                        'category_id' => $categoryId,
                    ]);
                }

                // Link Gallery Images
                foreach ($galleryImageIds as $idx => $mediaId) {
                    $this->db->insert(VL_DB_PREFIX . 'product_images', [
                        'product_id' => $productId,
                        'media_id'   => $mediaId,
                        'sort_order' => $idx,
                    ]);
                }

                // Insert Variants or Simple Details
                if ($isVariable) {
                    $totalStock  = 0;
                    $lowestPrice = null;

                    // Shopify fills Option N Name only on the first variant row;
                    // later rows carry just the Option N Value, so inherit the
                    // product-level names.
                    $optionNames = [1 => '', 2 => '', 3 => ''];
                    foreach ($productRows as $row) {
                        for ($i = 1; $i <= 3; $i++) {
                            $name = trim($row["Option{$i} Name"] ?? '');
                            if ($optionNames[$i] === '' && $name !== '' && $name !== 'Title') {
                                $optionNames[$i] = $name;
                            }
                        }
                    }

                    foreach ($productRows as $row) {
                        $opt1Name = trim($row['Option1 Name'] ?? '');
                        $opt1Name = ($opt1Name === '' || $opt1Name === 'Title') ? $optionNames[1] : $opt1Name;
                        $opt1Val  = trim($row['Option1 Value'] ?? '');
                        $opt2Name = trim($row['Option2 Name'] ?? '');
                        $opt2Name = ($opt2Name === '' || $opt2Name === 'Title') ? $optionNames[2] : $opt2Name;
                        $opt2Val  = trim($row['Option2 Value'] ?? '');
                        $opt3Name = trim($row['Option3 Name'] ?? '');
                        $opt3Name = ($opt3Name === '' || $opt3Name === 'Title') ? $optionNames[3] : $opt3Name;
                        $opt3Val  = trim($row['Option3 Value'] ?? '');

                        $attributes = [];
                        if ($opt1Name !== '' && $opt1Val !== '') {
                            $attributes[strtolower($opt1Name)] = $opt1Val;
                        }
                        if ($opt2Name !== '' && $opt2Val !== '') {
                            $attributes[strtolower($opt2Name)] = $opt2Val;
                        }
                        if ($opt3Name !== '' && $opt3Val !== '') {
                            $attributes[strtolower($opt3Name)] = $opt3Val;
                        }

                        if (empty($attributes)) {
                            continue;
                        }

                        $sku    = $row['Variant SKU'] ?? null;
                        $grams  = trim($row['Variant Grams'] ?? '');
                        $weight = ($grams !== '') ? (float) $grams / 1000 : null;

                        $rawPrice   = (float) ($row['Variant Price'] ?? 0);
                        $compareAt  = trim($row['Variant Compare At Price'] ?? '');
                        $price      = $rawPrice;
                        $salePrice  = null;

                        if ($compareAt !== '' && (float) $compareAt > $rawPrice) {
                            $price     = (float) $compareAt;
                            $salePrice = $rawPrice;
                        }

                        $stock = (int) ($row['Variant Inventory Qty'] ?? 0);
                        $totalStock += $stock;

                        if ($lowestPrice === null || $rawPrice < $lowestPrice) {
                            $lowestPrice = $rawPrice;
                        }

                        // Download specific variant image if requested
                        $varImgId = null;
                        if ($importMedia && !empty($row['Variant Image'])) {
                            $varImgId = $this->downloadImage($row['Variant Image']);
                        }

                        $this->db->insert(VL_DB_PREFIX . 'product_variations', [
                            'product_id'     => $productId,
                            'attributes'     => json_encode($attributes),
                            'sku'            => $sku ?: null,
                            'price'          => $price,
                            'sale_price'     => $salePrice,
                            'stock_quantity' => $stock,
                            'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                            'weight'         => $weight,
                            'image_id'       => $varImgId,
                        ]);

                        $variantsCount++;
                    }

                    // Update parent with aggregates
                    $this->db->update(VL_DB_PREFIX . 'products', [
                        'price'          => $lowestPrice ?? 0.00,
                        'stock_quantity' => $totalStock,
                        'stock_status'   => ($totalStock > 0) ? 'in_stock' : 'out_of_stock',
                    ], ['id' => $productId]);
                } else {
                    // Simple Product
                    $firstRow = $productRows[0];
                    $sku      = $firstRow['Variant SKU'] ?? null;
                    $grams    = trim($firstRow['Variant Grams'] ?? '');
                    $weight   = ($grams !== '') ? (float) $grams / 1000 : null;

                    $rawPrice   = (float) ($firstRow['Variant Price'] ?? 0);
                    $compareAt  = trim($firstRow['Variant Compare At Price'] ?? '');
                    $price      = $rawPrice;
                    $salePrice  = null;

                    if ($compareAt !== '' && (float) $compareAt > $rawPrice) {
                        $price     = (float) $compareAt;
                        $salePrice = $rawPrice;
                    }

                    $stock = (int) ($firstRow['Variant Inventory Qty'] ?? 0);

                    $this->db->update(VL_DB_PREFIX . 'products', [
                        'sku'            => $sku ?: null,
                        'price'          => $price,
                        'sale_price'     => $salePrice,
                        'stock_quantity' => $stock,
                        'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                        'weight'         => $weight,
                    ], ['id' => $productId]);
                }

                $importedCount++;
            }

            return $this->finishDryRun($dryRun, [
                'success'  => true,
                'imported' => $importedCount,
                'variants' => $variantsCount,
                'skipped'  => $skippedCount,
                'errors'   => [],
            ]);
        } catch (\Throwable $e) {
            $this->abortDryRun($dryRun);
            throw $e;
        }
    }

    /**
     * Etsy Listings CSV/Excel Importer
     *
     * Accepts both the English and the localised (e.g. German) Etsy listing
     * exports — column labels are matched case-insensitively against English
     * and German aliases (TITLE/TITEL, PRICE/PREIS, IMAGE1/BILD1, ...).
     *
     * Etsy exports the selectable variation values as a comma-separated list on
     * a single row (not one row per combination), which matches the storefront
     * convention of turning comma-separated attribute values into dropdowns.
     * Listings with variations therefore become one variable product carrying a
     * single variation row whose attributes keep Etsy's option lists verbatim.
     *
     * Etsy listings exports carry a single price per listing (PRICE/PREIS
     * column); there is no per-variation price in the file. All imported
     * variations therefore inherit the listing price — adjust per-variation
     * prices in the admin panel after import when they differ (e.g. one scent
     * or style costs more than the base listing price).
     */
    public function importEtsy(string $filePath, bool $importMedia, bool $dryRun = false): array
    {
        $rows = $this->parseFile($filePath);
        if (empty($rows)) {
            return ['success' => false, 'imported' => 0, 'variants' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable']];
        }

        $importMedia = $this->beginDryRun($dryRun, $importMedia);

        try {
            $importedCount = 0;
            $variantsCount = 0;
            $skippedCount  = 0;

            foreach ($rows as $row) {
                $row   = $this->lowercaseRow($row);
                $title = $this->readRowValue($row, ['titel', 'title']);
                if ($title === '') {
                    $skippedCount++;
                    continue;
                }

                // Skip listings that were already imported (Etsy exports carry no
                // SKU column, so the slug derived from the title is the natural key)
                $existing = $this->db->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE slug = ? LIMIT 1',
                    [(string) Slug::fromString($title)],
                );
                if ($existing) {
                    $skippedCount++;
                    continue;
                }

                $slug = (string) $this->slugService->generate($title, VL_DB_PREFIX . 'products');

                // Find Section / Category
                $categoryId = $this->resolveCategory($this->readRowValue($row, ['section', 'abschnitt', 'kategorie']));

                // Handle image downloading (BILD1..BILD10 / IMAGE1..IMAGE10)
                $featuredImageId = null;
                $galleryImageIds = [];
                if ($importMedia) {
                    for ($i = 1; $i <= 10; $i++) {
                        $imgUrl = $this->readRowValue($row, ["bild{$i}", "image{$i}"]);
                        if ($imgUrl === '') {
                            continue;
                        }
                        $mediaId = $this->downloadImage($imgUrl);
                        if ($mediaId === null) {
                            continue;
                        }
                        if ($featuredImageId === null) {
                            $featuredImageId = $mediaId;
                        } else {
                            $galleryImageIds[] = $mediaId;
                        }
                    }
                }

                $description = $this->readRowValue($row, ['beschreibung', 'description']);
                $sku         = $this->readRowValue($row, ['sku', 'seller-sku']);
                $price       = $this->toFloat($this->readRowValue($row, ['preis', 'price']));
                $stock       = (int) $this->toFloat($this->readRowValue($row, ['stückzahl', 'stuckzahl', 'quantity', 'menge']));

                // The listings download carries no state column; imported listings
                // are treated as published unless an explicit non-active state is set.
                $state  = strtolower($this->readRowValue($row, ['state']));
                $status = ($state === '' || $state === 'active') ? 'published' : 'draft';

                $attributes = $this->readEtsyVariationAttributes($row);

                try {
                    $this->db->insert(VL_DB_PREFIX . 'products', [
                        'name'              => $title,
                        'slug'              => $slug,
                        'language'          => 'en',
                        'sku'               => $sku !== '' ? $sku : null,
                        'description'       => $description,
                        'short_description' => '',
                        'price'             => $price,
                        'sale_price'        => null,
                        'stock_quantity'    => $stock,
                        'stock_status'      => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                        'status'            => $status,
                        'featured_image_id' => $featuredImageId,
                        'type'              => $attributes === [] ? 'simple' : 'variable',
                        'created_at'        => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);

                    $productId = (int) $this->db->lastInsertId();

                    if ($categoryId !== null) {
                        $this->db->insert(VL_DB_PREFIX . 'product_to_categories', [
                            'product_id'  => $productId,
                            'category_id' => $categoryId,
                        ]);
                    }

                    foreach ($galleryImageIds as $idx => $mediaId) {
                        $this->db->insert(VL_DB_PREFIX . 'product_images', [
                            'product_id' => $productId,
                            'media_id'   => $mediaId,
                            'sort_order' => $idx,
                        ]);
                    }

                    if ($attributes !== []) {
                        $this->db->insert(VL_DB_PREFIX . 'product_variations', [
                            'product_id'     => $productId,
                            'attributes'     => json_encode($attributes),
                            'sku'            => $sku !== '' ? $sku : null,
                            'price'          => $price,
                            'sale_price'     => null,
                            'stock_quantity' => $stock,
                            'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                            'image_id'       => $featuredImageId,
                        ]);
                        $variantsCount++;
                    }

                    $importedCount++;
                } catch (\Exception $e) {
                    $skippedCount++;
                }
            }

            return $this->finishDryRun($dryRun, [
                'success'  => true,
                'imported' => $importedCount,
                'variants' => $variantsCount,
                'skipped'  => $skippedCount,
                'errors'   => [],
            ]);
        } catch (\Throwable $e) {
            $this->abortDryRun($dryRun);
            throw $e;
        }
    }

    /**
     * Reads Etsy's "VARIATIONSTYP / VARIATIONSNAME / VARIATIONSWERT" (English:
     * "VARIATION TYPE / NAME / VALUE") column triples into a single attribute
     * map. Both the number of slots and the attribute labels are discovered from
     * the file, so any custom variation (Farbe, Size, kamil, ...) is supported.
     *
     * Two export layouts are in the wild and both put the slot number in a
     * different place:
     *   - older German export: VARIATIONSTYP 1 / VARIATIONSNAME 1 / VARIATIONSWERT 1
     *   - current export:      VARIATION 1 TYPE / VARIATION 1 NAME / VARIATION 1 VALUES
     * The slot regex therefore accepts the number before or after the word, and
     * the value aliases include the plural "values"/"werte" spellings.
     */
    private function readEtsyVariationAttributes(array $row): array
    {
        $slots = [];
        foreach (array_keys($row) as $key) {
            $key = (string) $key;
            $matched = preg_match('/^(?:variationstyp|variation type)\s*(\d+)$/', $key, $matches) === 1
                || preg_match('/^variation\s+(\d+)\s+(?:type|typ)$/', $key, $matches) === 1;
            if ($matched) {
                $slots[] = (int) $matches[1];
            }
        }
        $slots = array_values(array_unique($slots));
        sort($slots);

        $attributes = [];
        foreach ($slots as $slot) {
            $type = $this->readRowValue($row, [
                "variationstyp {$slot}", "variationstyp{$slot}",
                "variation type {$slot}", "variation type{$slot}",
                "variation {$slot} type", "variation {$slot} typ",
            ]);
            $name = $this->readRowValue($row, [
                "variationsname {$slot}", "variationsname{$slot}",
                "variation name {$slot}", "variation name{$slot}",
                "variation {$slot} name",
            ]);
            $value = $this->readRowValue($row, [
                "variationswert {$slot}", "variationswert{$slot}",
                "variationswerte {$slot}", "variationswerte{$slot}",
                "variation value {$slot}", "variation value{$slot}",
                "variation {$slot} value", "variation {$slot} values",
            ]);
            if ($value === '') {
                continue;
            }

            $label = $name !== '' ? $name : $type;
            if ($label === '') {
                $label = 'Option ' . $slot;
            }
            if (isset($attributes[$label])) {
                $label = ($type !== '' && !isset($attributes[$type])) ? $type : $label . ' ' . $slot;
            }

            $attributes[$label] = $value;
        }

        return $attributes;
    }

    /**
     * eBay Listings CSV/Excel Importer
     */
    public function importEbay(string $filePath, bool $importMedia, bool $dryRun = false): array
    {
        $rows = $this->parseFile($filePath);
        if (empty($rows)) {
            return ['success' => false, 'imported' => 0, 'variants' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable']];
        }

        $importMedia = $this->beginDryRun($dryRun, $importMedia);

        try {
            $grouped = [];
            foreach ($rows as $row) {
                $itemId = $row['Item number'] ?? $row['ItemNumber'] ?? $row['Item ID'] ?? $row['ItemID'] ?? $row['Title'] ?? $row['Item Title'] ?? '';
                if ($itemId === '') {
                    continue;
                }
                $grouped[$itemId][] = $row;
            }

            $importedCount = 0;
            $variantsCount = 0;
            $skippedCount  = 0;

            foreach ($grouped as $itemId => $productRows) {
                // Find parent row
                $parentRow = null;
                $childRows = [];

                foreach ($productRows as $row) {
                    $sku     = $row['Custom label (SKU)'] ?? $row['CustomLabel'] ?? $row['SKU'] ?? '';
                    $details = $row['Variation details']  ?? $row['VariationDetails'] ?? '';

                    // If SKU is empty, or details has a semicolon, it's the parent row!
                    if ($sku === '' || str_contains($details, ';')) {
                        $parentRow = $row;
                    } else {
                        $childRows[] = $row;
                    }
                }

                if ($parentRow === null) {
                    $parentRow = $productRows[0];
                    if (count($productRows) > 1) {
                        $childRows = array_slice($productRows, 1);
                    }
                }

                $title = $parentRow['Title'] ?? $parentRow['Item Title'] ?? $parentRow['ItemTitle'] ?? '';
                if ($title === '') {
                    $skippedCount++;
                    continue;
                }

                $isVariable = !empty($childRows);
                $slug       = (string) $this->slugService->generate($title, VL_DB_PREFIX . 'products');

                // Find Section / Category
                $categoryName = $parentRow['eBay category 1 name'] ?? $parentRow['CategoryName'] ?? $parentRow['Category Name'] ?? '';
                $categoryId   = $this->resolveCategory($categoryName);

                // Handle images from PicURL/ImageURL/Pic URLs/Image URL columns
                $featuredImageId = null;
                $galleryImageIds = [];
                if ($importMedia) {
                    $picUrlsRaw = $parentRow['PicURL'] ?? $parentRow['ImageURL'] ?? $parentRow['Pic URLs'] ?? $parentRow['Image URL'] ?? '';
                    $urls       = [];
                    if (!empty($picUrlsRaw)) {
                        $urls = preg_split('/[|;,\s]+/', $picUrlsRaw);
                    }

                    foreach ($urls as $url) {
                        $url = trim($url);
                        if ($url === '') {
                            continue;
                        }
                        $mediaId = $this->downloadImage($url);
                        if ($mediaId !== null) {
                            if ($featuredImageId === null) {
                                $featuredImageId = $mediaId;
                            } else {
                                $galleryImageIds[] = $mediaId;
                            }
                        }
                    }
                }

                $description = $parentRow['Description'] ?? '';
                $status      = 'published'; // eBay active listings are published

                try {
                    $this->db->insert(VL_DB_PREFIX . 'products', [
                        'name'              => $title,
                        'slug'              => $slug,
                        'language'          => 'en',
                        'description'       => $description,
                        'short_description' => '',
                        'price'             => 0.00,
                        'sale_price'        => null,
                        'stock_quantity'    => 0,
                        'stock_status'      => 'out_of_stock',
                        'status'            => $status,
                        'featured_image_id' => $featuredImageId,
                        'type'              => $isVariable ? 'variable' : 'simple',
                        'created_at'        => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);

                    $productId = (int) $this->db->lastInsertId();

                    if ($categoryId !== null) {
                        $this->db->insert(VL_DB_PREFIX . 'product_to_categories', [
                            'product_id'  => $productId,
                            'category_id' => $categoryId,
                        ]);
                    }

                    foreach ($galleryImageIds as $idx => $mediaId) {
                        $this->db->insert(VL_DB_PREFIX . 'product_images', [
                            'product_id' => $productId,
                            'media_id'   => $mediaId,
                            'sort_order' => $idx,
                        ]);
                    }

                    if ($isVariable) {
                        $totalStock  = 0;
                        $lowestPrice = null;

                        foreach ($childRows as $row) {
                            $details    = $row['Variation details'] ?? $row['VariationDetails'] ?? '';
                            $attributes = [];
                            if (!empty($details)) {
                                $pairs = preg_split('/[;|]+/', $details);
                                foreach ($pairs as $pair) {
                                    $parts = explode('=', $pair, 2);
                                    if (count($parts) === 2) {
                                        $attributes[strtolower(trim($parts[0]))] = trim($parts[1]);
                                    }
                                }
                            }

                            if (empty($attributes)) {
                                continue;
                            }

                            $sku   = $row['Custom label (SKU)'] ?? $row['CustomLabel'] ?? $row['SKU'] ?? null;
                            $price = (float) ($row['Start price'] ?? $row['Start Price'] ?? $row['Price'] ?? 0);
                            $stock = (int) ($row['Available quantity'] ?? $row['AvailableQuantity'] ?? $row['QuantityAvailable'] ?? $row['Quantity'] ?? 0);

                            $totalStock += $stock;
                            if ($lowestPrice === null || $price < $lowestPrice) {
                                $lowestPrice = $price;
                            }

                            $this->db->insert(VL_DB_PREFIX . 'product_variations', [
                                'product_id'     => $productId,
                                'attributes'     => json_encode($attributes),
                                'sku'            => $sku ?: null,
                                'price'          => $price,
                                'sale_price'     => null,
                                'stock_quantity' => $stock,
                                'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                            ]);

                            $variantsCount++;
                        }

                        // Update parent aggregates
                        $this->db->update(VL_DB_PREFIX . 'products', [
                            'price'          => $lowestPrice ?? 0.00,
                            'stock_quantity' => $totalStock,
                            'stock_status'   => ($totalStock > 0) ? 'in_stock' : 'out_of_stock',
                        ], ['id' => $productId]);
                    } else {
                        // Simple product
                        $sku   = $parentRow['Custom label (SKU)'] ?? $parentRow['CustomLabel'] ?? $parentRow['SKU'] ?? null;
                        $price = (float) ($parentRow['Start price'] ?? $parentRow['Start Price'] ?? $parentRow['Price'] ?? 0);
                        $stock = (int) ($parentRow['Available quantity'] ?? $parentRow['AvailableQuantity'] ?? $parentRow['QuantityAvailable'] ?? $parentRow['Quantity'] ?? 0);

                        $this->db->update(VL_DB_PREFIX . 'products', [
                            'sku'            => $sku ?: null,
                            'price'          => $price,
                            'stock_quantity' => $stock,
                            'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                        ], ['id' => $productId]);
                    }

                    $importedCount++;
                } catch (\Exception $e) {
                    $skippedCount++;
                }
            }

            return $this->finishDryRun($dryRun, [
                'success'  => true,
                'imported' => $importedCount,
                'variants' => $variantsCount,
                'skipped'  => $skippedCount,
                'errors'   => [],
            ]);
        } catch (\Throwable $e) {
            $this->abortDryRun($dryRun);
            throw $e;
        }
    }

    /**
     * Amazon Inventory Report Importer
     *
     * Supports simple listings as well as parent/child variations. Parentage is
     * detected universally (Eltern/Parent -> parent, Kind/Child -> child) so the
     * import is not bound to a single marketplace language.
     */
    public function importAmazon(string $filePath, bool $importMedia = false, bool $dryRun = false): array
    {
        $rows = $this->parseFile($filePath);
        if (empty($rows)) {
            return ['success' => false, 'imported' => 0, 'variants' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable']];
        }

        $importMedia = $this->beginDryRun($dryRun, $importMedia);

        try {
            $rows = array_map(fn (array $row) => $this->lowercaseRow($row), $rows);

            $importedCount = 0;
            $variantsCount = 0;
            $skippedCount  = 0;
            $errors        = [];

            // Parent/child classification (universal, language-independent).
            $hasParent = false;
            foreach ($rows as $row) {
                $parentage = strtolower($this->readRowValue($row, ['parentage_level', 'abstammungsgrad', 'parentage']));
                if ($parentage === 'eltern' || $parentage === 'parent') {
                    $hasParent = true;
                    break;
                }
            }

            if ($hasParent) {
                $parentRows = [];
                $childRows  = [];
                foreach ($rows as $row) {
                    $parentage = strtolower($this->readRowValue($row, ['parentage_level', 'abstammungsgrad', 'parentage']));
                    if ($parentage === 'eltern' || $parentage === 'parent') {
                        $parentRows[] = $row;
                    } else {
                        $childRows[] = $row;
                    }
                }

                $singleParent = count($parentRows) === 1;
                foreach ($parentRows as $parentRow) {
                    $parentSku = strtolower($this->readRowValue($parentRow, ['sku', 'contribution_sku', 'seller-sku']));

                    $childrenForParent = [];
                    foreach ($childRows as $childRow) {
                        $linkedParentSku = $this->readRowValue($childRow, ['child_parent_sku_relationship', 'parent_sku', 'übergeordnete sku', 'ubergeordnete sku']);
                        if ($linkedParentSku !== '') {
                            if ($parentSku !== '' && strcasecmp($linkedParentSku, $parentSku) === 0) {
                                $childrenForParent[] = $childRow;
                            }
                        } elseif ($singleParent) {
                            $childrenForParent[] = $childRow;
                        }
                    }

                    $result = $this->importAmazonParent($parentRow, $childrenForParent, $importMedia);
                    $importedCount += $result['imported'];
                    $variantsCount += $result['variants'];
                    $skippedCount  += $result['skipped'];
                    foreach ($result['errors'] as $error) {
                        $errors[] = $error;
                    }
                }
            } else {
                foreach ($rows as $row) {
                    $result = $this->importAmazonSimple($row, $importMedia);
                    $importedCount += $result['imported'];
                    $skippedCount  += $result['skipped'];
                    foreach ($result['errors'] as $error) {
                        $errors[] = $error;
                    }
                }
            }

            return $this->finishDryRun($dryRun, [
                'success'  => true,
                'imported' => $importedCount,
                'variants' => $variantsCount,
                'skipped'  => $skippedCount,
                'errors'   => $errors,
            ]);
        } catch (\Throwable $e) {
            $this->abortDryRun($dryRun);
            throw $e;
        }
    }

    private function importAmazonSimple(array $row, bool $importMedia): array
    {
        $sku   = $this->readRowValue($row, ['sku', 'contribution_sku', 'seller-sku']);
        $title = $this->readRowValue($row, ['artikelname', 'titel', 'item_name', 'item-name', 'title']);

        if ($title === '') {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => []];
        }

        if ($sku !== '' && $this->amazonProductExists($sku)) {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => []];
        }

        $price       = $this->readAmazonPrice($row);
        $quantity    = $this->readAmazonQuantity($row);
        $asin        = $this->readRowValue($row, ['produkt-id', 'asin1', 'asin']);
        $description = $this->readRowValue($row, ['beschreibung des produkts', 'beschreibung', 'description', 'item-description']);
        $status      = $this->readAmazonStatus($row);
        $imageId     = $importMedia ? $this->readAmazonImage($row) : null;

        $slug = (string) $this->slugService->generate($title, VL_DB_PREFIX . 'products');

        try {
            $this->db->insert(VL_DB_PREFIX . 'products', [
                'name'              => $title,
                'slug'              => $slug,
                'language'          => 'en',
                'sku'               => $sku  !== '' ? $sku : null,
                'gtin'              => $asin !== '' ? $asin : null,
                'description'       => $description,
                'short_description' => '',
                'price'             => $price,
                'sale_price'        => null,
                'stock_quantity'    => $quantity,
                'stock_status'      => ($quantity > 0) ? 'in_stock' : 'out_of_stock',
                'status'            => $status,
                'featured_image_id' => $imageId,
                'type'              => 'simple',
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            return ['imported' => 1, 'variants' => 0, 'skipped' => 0, 'errors' => []];
        } catch (\Exception $e) {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => ["DB insert error for SKU '" . ($sku ?: 'unknown') . "': " . $e->getMessage()]];
        }
    }

    private function importAmazonParent(array $parentRow, array $childRows, bool $importMedia): array
    {
        $sku   = $this->readRowValue($parentRow, ['sku', 'contribution_sku', 'seller-sku']);
        $title = $this->readRowValue($parentRow, ['artikelname', 'titel', 'item_name', 'item-name', 'title']);

        if ($title === '') {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => []];
        }

        if ($sku !== '' && $this->amazonProductExists($sku)) {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => []];
        }

        $description = $this->readRowValue($parentRow, ['beschreibung des produkts', 'beschreibung', 'description', 'item-description']);
        $asin        = $this->readRowValue($parentRow, ['produkt-id', 'asin1', 'asin']);
        $status      = $this->readAmazonStatus($parentRow);
        $imageId     = $importMedia ? $this->readAmazonImage($parentRow) : null;

        // Variation attributes derive from the variation theme (e.g. SET_NAME -> set_name).
        $theme         = strtoupper($this->readRowValue($parentRow, ['variation_theme', 'variationsthemebezeichnung', 'variation theme']));
        $attributeKeys = $theme !== '' ? array_map('strtolower', explode('/', $theme)) : [];

        $slug = (string) $this->slugService->generate($title, VL_DB_PREFIX . 'products');

        try {
            $this->db->insert(VL_DB_PREFIX . 'products', [
                'name'              => $title,
                'slug'              => $slug,
                'language'          => 'en',
                'sku'               => $sku  !== '' ? $sku : null,
                'gtin'              => $asin !== '' ? $asin : null,
                'description'       => $description,
                'short_description' => '',
                'price'             => 0.00,
                'sale_price'        => null,
                'stock_quantity'    => 0,
                'stock_status'      => 'out_of_stock',
                'status'            => $status,
                'featured_image_id' => $imageId,
                'type'              => 'variable',
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            $productId = (int) $this->db->lastInsertId();
        } catch (\Exception $e) {
            return ['imported' => 0, 'variants' => 0, 'skipped' => 1, 'errors' => ["DB insert error for SKU '" . ($sku ?: 'unknown') . "': " . $e->getMessage()]];
        }

        $totalStock    = 0;
        $lowestPrice   = null;
        $variantsCount = 0;

        // The parent row may itself carry a sellable listing (own SKU/price/stock),
        // so it becomes a variation too. Pure containers (no own qty/price) stay as-is.
        $variationRows = $childRows;
        if ($this->readAmazonQuantity($parentRow) > 0 || $this->readAmazonPrice($parentRow) > 0) {
            $variationRows = array_merge([$parentRow], $variationRows);
        }

        foreach ($variationRows as $variationRow) {
            $attributes = [];
            foreach ($attributeKeys as $attrKey) {
                $value = $this->readRowValue($variationRow, [$attrKey]);
                if ($value === '') {
                    // The variation theme option is often omitted on the parent row
                    // (e.g. the "ohne USB-C Adapter" base variant). Fall back to the
                    // option shown in the product title, e.g. "... (ohne USB-C Adapter)".
                    $title = $this->readRowValue($variationRow, ['artikelname', 'titel', 'item_name', 'item-name', 'title']);
                    $value = $this->extractTitleOption($title);
                }
                if ($value !== '') {
                    $attributes[$attrKey] = $value;
                }
            }

            $sku   = $this->readRowValue($variationRow, ['sku', 'contribution_sku', 'seller-sku']);
            $price = $this->readAmazonPrice($variationRow);
            $stock = $this->readAmazonQuantity($variationRow);
            $image = $importMedia ? $this->readAmazonImage($variationRow) : null;

            $totalStock += $stock;
            if ($lowestPrice === null || $price < $lowestPrice) {
                $lowestPrice = $price;
            }

            try {
                $this->db->insert(VL_DB_PREFIX . 'product_variations', [
                    'product_id'     => $productId,
                    'attributes'     => json_encode($attributes),
                    'sku'            => $sku !== '' ? $sku : null,
                    'price'          => $price,
                    'sale_price'     => null,
                    'stock_quantity' => $stock,
                    'stock_status'   => ($stock > 0) ? 'in_stock' : 'out_of_stock',
                    'image_id'       => $image,
                ]);
                $variantsCount++;
            } catch (\Exception $e) {
                // Skip one broken variation, keep the rest.
            }
        }

        $this->db->update(VL_DB_PREFIX . 'products', [
            'price'          => $lowestPrice ?? 0.00,
            'stock_quantity' => $totalStock,
            'stock_status'   => ($totalStock > 0) ? 'in_stock' : 'out_of_stock',
        ], ['id' => $productId]);

        return ['imported' => 1, 'variants' => $variantsCount, 'skipped' => 0, 'errors' => []];
    }

    /**
     * Lower-cases and trims the column labels of a parsed row so lookups are
     * case-insensitive (marketplace exports differ between "Title"/"TITLE").
     */
    private function lowercaseRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[mb_strtolower(trim((string) $key))] = trim((string) $value);
        }
        return $normalized;
    }

    /**
     * Returns the first non-empty value found under any of the given column
     * aliases. The row must already be normalised with lowercaseRow().
     */
    private function readRowValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $key = mb_strtolower($key);
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    private function readAmazonPrice(array $row): float
    {
        $keys = [
            'ihr preis eur (bei amazon verkaufen, de)',
            'listenpreis mit steuern',
            'listenpreis (uvp)',
            'angebotspreis eur (bei amazon verkaufen, de)',
            'price',
            'start preis',
            'start price',
        ];
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                $price = (float) $row[$key];
                if ($price > 0) {
                    return $price;
                }
            }
        }
        return 0.00;
    }

    private function readAmazonQuantity(array $row): int
    {
        $keys = ['menge (de)', 'menge', 'quantity', 'available quantity', 'availablequantity'];
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (int) $row[$key];
            }
        }
        return 0;
    }

    private function readAmazonStatus(array $row): string
    {
        $statusRaw = strtolower($this->readRowValue($row, ['status', 'listing_status']));
        return ($statusRaw === 'aktiv' || $statusRaw === 'active') ? 'published' : 'draft';
    }

    private function readAmazonImage(array $row): ?int
    {
        $imgUrl = $this->readRowValue($row, ['url des hauptbildes', 'main-image-url', 'image']);
        return $imgUrl !== '' ? $this->downloadImage($imgUrl) : null;
    }

    /**
     * Extracts the last parenthetical option from a product title, e.g.
     * "... (ohne USB-C Adapter)" -> "ohne USB-C Adapter".
     */
    private function extractTitleOption(string $title): string
    {
        if (preg_match('/\(([^()]*)\)[^()]*$/', trim($title), $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function amazonProductExists(string $sku): bool
    {
        $existing = $this->db->fetchOne("SELECT id FROM " . VL_DB_PREFIX . "products WHERE sku = ?", [$sku]);
        return (bool) $existing;
    }

    public function importProducts(string $filePath, bool $importMedia, bool $dryRun = false, bool $updateExisting = false): array
    {
        $rows = $this->parseCsv($filePath);
        if (empty($rows)) {
            return ['success' => false, 'imported' => 0, 'variants' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable']];
        }

        $importMedia = $this->beginDryRun($dryRun, $importMedia);
        $errors      = [];
        $imported    = 0;
        $variants    = 0;
        $skipped     = 0;

        try {
            foreach ($rows as $row) {
                $normalized = [];
                foreach ($row as $key => $value) {
                    $key                   = strtolower(trim((string) $key));
                    $key                   = str_replace([' ', '-'], '_', $key);
                    $normalized[$key]      = $value;
                }

                $name = trim((string) ($normalized['name'] ?? ''));
                if ($name === '') {
                    $errors[] = 'Missing product name in row';
                    $skipped++;
                    continue;
                }

                $slugInput = trim((string) ($normalized['slug'] ?? ''));
                if ($slugInput === '') {
                    $slugInput = $name;
                }
                $slug    = $this->slugService->unique($slugInput, VL_DB_PREFIX . 'products');
                $slugStr = (string) $slug;

                $existing = $this->db->fetchOne(
                    "SELECT id FROM " . VL_DB_PREFIX . "products WHERE slug = ? LIMIT 1",
                    [$slugStr]
                );

                if ($existing) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }
                    $productId = (int) $existing;
                    $this->updateProductFromRow($productId, $normalized, $importMedia);
                    $imported++;
                    continue;
                }

                $productId = $this->insertProductFromRow($normalized, $slugStr, $importMedia);
                $imported++;

                $variationsJson = $normalized['variations'] ?? '';
                if ($variationsJson !== '' && is_string($variationsJson)) {
                    $variations = json_decode($variationsJson, true);
                    if (is_array($variations)) {
                        $variants += $this->insertVariationsForProduct($productId, $variations, $importMedia);
                    }
                }
            }

            $result = [
                'success'  => true,
                'imported' => $imported,
                'variants' => $variants,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ];
            return $this->finishDryRun($dryRun, $result);
        } catch (\Throwable $e) {
            $this->abortDryRun($dryRun);
            return [
                'success'  => false,
                'imported' => $imported,
                'variants' => $variants,
                'skipped'  => $skipped,
                'errors'   => array_merge($errors, [$e->getMessage()]),
            ];
        }
    }

    private function insertProductFromRow(array $row, string $slugStr, bool $importMedia): int
    {
        $now = date('Y-m-d H:i:s');

        $data = [
            'name'                                  => $row['name'],
            'language'                              => $row['language'] ?? 'en',
            'translation_of'                        => null,
            'slug'                                  => $slugStr,
            'sku'                                   => $this->nullIfEmpty($row['sku'] ?? ''),
            'description'                           => $this->nullIfEmpty($row['description'] ?? ''),
            'short_description'                     => $this->nullIfEmpty($row['short_description'] ?? ''),
            'price'                                 => $this->toFloat($row['price'] ?? 0),
            'sale_price'                            => $this->toFloatOrNull($row['sale_price'] ?? ''),
            'sale_price_start'                      => $this->parseDate($row['sale_price_start'] ?? ''),
            'sale_price_end'                        => $this->parseDate($row['sale_price_end'] ?? ''),
            'stock_quantity'                        => (int) ($row['stock_quantity'] ?? 0),
            'stock_status'                          => $this->toEnum($row['stock_status'] ?? '', 'in_stock', ['in_stock', 'out_of_stock', 'on_backorder']),
            'weight'                                => $this->toFloatOrNull($row['weight'] ?? ''),
            'dimensions'                            => $this->nullIfEmpty($row['dimensions'] ?? ''),
            'featured_image_id'                     => $this->resolveFeaturedImageId($row, $importMedia),
            'status'                                => $this->toEnum($row['status'] ?? '', 'draft', ['draft', 'published', 'unlisted', 'scheduled', 'trash']),
            'published_at'                          => $this->parseDate($row['published_at'] ?? ''),
            'comment_status'                        => $this->toEnum($row['comment_status'] ?? '', 'use_global', ['use_global', 'enabled', 'disabled']),
            'vote_status'                           => $this->toEnum($row['vote_status'] ?? '', 'use_global', ['use_global', 'enabled', 'disabled']),
            'type'                                  => $this->toEnum($row['type'] ?? '', 'simple', ['simple', 'variable', 'digital']),
            'shipping_class'                        => $this->toEnum($row['shipping_class'] ?? '', 'use_global', ['use_global', 'free', 'custom', 'digital', 'do_not_ship']),
            'shipping_cost'                         => $this->toFloatOrNull($row['shipping_cost'] ?? ''),
            'shipping_country_overrides'            => $this->encodeJson($row['shipping_country_overrides'] ?? ''),
            'vat_mode'                              => $this->toEnum($row['vat_mode'] ?? '', 'use_global', ['use_global', 'custom']),
            'vat_rate'                              => $this->toFloatOrNull($row['vat_rate'] ?? ''),
            'vat_country_overrides'                 => $this->encodeJson($row['vat_country_overrides'] ?? ''),
            'download_source'                       => $this->toEnum($row['download_source'] ?? '', 'none', ['none', 'internal', 'external']),
            'download_file_id'                      => null,
            'download_url'                          => $this->nullIfEmpty($row['download_url'] ?? ''),
            'download_url_proxy'                    => $this->toBool($row['download_url_proxy'] ?? '') ? 1 : 0,
            'download_limit'                        => (int) ($row['download_limit'] ?? 0),
            'download_expiry_days'                  => (int) ($row['download_expiry_days'] ?? 0),
            'meta_title'                            => $this->nullIfEmpty($row['meta_title'] ?? ''),
            'meta_description'                      => $this->nullIfEmpty($row['meta_description'] ?? ''),
            'total_sold'                            => 0,
            'gtin'                                  => $this->nullIfEmpty($row['gtin'] ?? ''),
            'mpn'                                   => $this->nullIfEmpty($row['mpn'] ?? ''),
            'brand'                                 => $this->nullIfEmpty($row['brand'] ?? ''),
            'google_product_category'               => $this->nullIfEmpty($row['google_product_category'] ?? ''),
            $this->db->quoteIdentifier('condition') => $this->toEnum($row['condition'] ?? '', 'new', ['new', 'refurbished', 'used']),
            'gender'                                => $this->nullIfEmpty($row['gender'] ?? ''),
            'age_group'                             => $this->nullIfEmpty($row['age_group'] ?? ''),
            'color'                                 => $this->nullIfEmpty($row['color'] ?? ''),
            'size'                                  => $this->nullIfEmpty($row['size'] ?? ''),
            'material'                              => $this->nullIfEmpty($row['material'] ?? ''),
            'pattern'                               => $this->nullIfEmpty($row['pattern'] ?? ''),
            'custom_label_0'                        => $this->nullIfEmpty($row['custom_label_0'] ?? ''),
            'custom_label_1'                        => $this->nullIfEmpty($row['custom_label_1'] ?? ''),
            'custom_label_2'                        => $this->nullIfEmpty($row['custom_label_2'] ?? ''),
            'custom_label_3'                        => $this->nullIfEmpty($row['custom_label_3'] ?? ''),
            'custom_label_4'                        => $this->nullIfEmpty($row['custom_label_4'] ?? ''),
            'gmc_exclude'                           => $this->toBool($row['gmc_exclude'] ?? '') ? 1 : 0,
            'upsell_ids'                            => json_encode($this->toIdArray($row['upsell_ids'] ?? '')),
            'cross_sell_ids'                        => json_encode($this->toIdArray($row['cross_sell_ids'] ?? '')),
            'length'                                => $this->toFloatOrNull($row['length'] ?? ''),
            'width'                                 => $this->toFloatOrNull($row['width'] ?? ''),
            'height'                                => $this->toFloatOrNull($row['height'] ?? ''),
            'created_at'                            => $now,
            'updated_at'                            => $now,
        ];

        $this->db->insert(VL_DB_PREFIX . 'products', $data);
        $productId = (int) $this->db->lastInsertId();

        $this->syncProductCategories($productId, $row['category_ids'] ?? '');
        $this->syncProductGallery($productId, $row, $importMedia);
        $this->syncProductDownloadFiles($productId, $row, $importMedia);

        return $productId;
    }

    private function updateProductFromRow(int $productId, array $row, bool $importMedia): void
    {
        $now = date('Y-m-d H:i:s');

        $data = [
            'name'                                  => $row['name'],
            'language'                              => $row['language'] ?? 'en',
            'slug'                                  => (string) $this->slugService->unique($row['slug'] ?? $row['name'], VL_DB_PREFIX . 'products', 'slug', $productId),
            'sku'                                   => $this->nullIfEmpty($row['sku'] ?? ''),
            'description'                           => $this->nullIfEmpty($row['description'] ?? ''),
            'short_description'                     => $this->nullIfEmpty($row['short_description'] ?? ''),
            'price'                                 => $this->toFloat($row['price'] ?? 0),
            'sale_price'                            => $this->toFloatOrNull($row['sale_price'] ?? ''),
            'sale_price_start'                      => $this->parseDate($row['sale_price_start'] ?? ''),
            'sale_price_end'                        => $this->parseDate($row['sale_price_end'] ?? ''),
            'stock_quantity'                        => (int) ($row['stock_quantity'] ?? 0),
            'stock_status'                          => $this->toEnum($row['stock_status'] ?? '', 'in_stock', ['in_stock', 'out_of_stock', 'on_backorder']),
            'weight'                                => $this->toFloatOrNull($row['weight'] ?? ''),
            'dimensions'                            => $this->nullIfEmpty($row['dimensions'] ?? ''),
            'featured_image_id'                     => $this->resolveFeaturedImageId($row, $importMedia),
            'status'                                => $this->toEnum($row['status'] ?? '', 'draft', ['draft', 'published', 'unlisted', 'scheduled', 'trash']),
            'published_at'                          => $this->parseDate($row['published_at'] ?? ''),
            'comment_status'                        => $this->toEnum($row['comment_status'] ?? '', 'use_global', ['use_global', 'enabled', 'disabled']),
            'vote_status'                           => $this->toEnum($row['vote_status'] ?? '', 'use_global', ['use_global', 'enabled', 'disabled']),
            'type'                                  => $this->toEnum($row['type'] ?? '', 'simple', ['simple', 'variable', 'digital']),
            'shipping_class'                        => $this->toEnum($row['shipping_class'] ?? '', 'use_global', ['use_global', 'free', 'custom', 'digital', 'do_not_ship']),
            'shipping_cost'                         => $this->toFloatOrNull($row['shipping_cost'] ?? ''),
            'shipping_country_overrides'            => $this->encodeJson($row['shipping_country_overrides'] ?? ''),
            'vat_mode'                              => $this->toEnum($row['vat_mode'] ?? '', 'use_global', ['use_global', 'custom']),
            'vat_rate'                              => $this->toFloatOrNull($row['vat_rate'] ?? ''),
            'vat_country_overrides'                 => $this->encodeJson($row['vat_country_overrides'] ?? ''),
            'download_source'                       => $this->toEnum($row['download_source'] ?? '', 'none', ['none', 'internal', 'external']),
            'download_file_id'                      => null,
            'download_url'                          => $this->nullIfEmpty($row['download_url'] ?? ''),
            'download_url_proxy'                    => $this->toBool($row['download_url_proxy'] ?? '') ? 1 : 0,
            'download_limit'                        => (int) ($row['download_limit'] ?? 0),
            'download_expiry_days'                  => (int) ($row['download_expiry_days'] ?? 0),
            'meta_title'                            => $this->nullIfEmpty($row['meta_title'] ?? ''),
            'meta_description'                      => $this->nullIfEmpty($row['meta_description'] ?? ''),
            'gtin'                                  => $this->nullIfEmpty($row['gtin'] ?? ''),
            'mpn'                                   => $this->nullIfEmpty($row['mpn'] ?? ''),
            'brand'                                 => $this->nullIfEmpty($row['brand'] ?? ''),
            'google_product_category'               => $this->nullIfEmpty($row['google_product_category'] ?? ''),
            $this->db->quoteIdentifier('condition') => $this->toEnum($row['condition'] ?? '', 'new', ['new', 'refurbished', 'used']),
            'gender'                                => $this->nullIfEmpty($row['gender'] ?? ''),
            'age_group'                             => $this->nullIfEmpty($row['age_group'] ?? ''),
            'color'                                 => $this->nullIfEmpty($row['color'] ?? ''),
            'size'                                  => $this->nullIfEmpty($row['size'] ?? ''),
            'material'                              => $this->nullIfEmpty($row['material'] ?? ''),
            'pattern'                               => $this->nullIfEmpty($row['pattern'] ?? ''),
            'custom_label_0'                        => $this->nullIfEmpty($row['custom_label_0'] ?? ''),
            'custom_label_1'                        => $this->nullIfEmpty($row['custom_label_1'] ?? ''),
            'custom_label_2'                        => $this->nullIfEmpty($row['custom_label_2'] ?? ''),
            'custom_label_3'                        => $this->nullIfEmpty($row['custom_label_3'] ?? ''),
            'custom_label_4'                        => $this->nullIfEmpty($row['custom_label_4'] ?? ''),
            'gmc_exclude'                           => $this->toBool($row['gmc_exclude'] ?? '') ? 1 : 0,
            'upsell_ids'                            => json_encode($this->toIdArray($row['upsell_ids'] ?? '')),
            'cross_sell_ids'                        => json_encode($this->toIdArray($row['cross_sell_ids'] ?? '')),
            'length'                                => $this->toFloatOrNull($row['length'] ?? ''),
            'width'                                 => $this->toFloatOrNull($row['width'] ?? ''),
            'height'                                => $this->toFloatOrNull($row['height'] ?? ''),
            'updated_at'                            => $now,
        ];

        $this->db->update(VL_DB_PREFIX . 'products', $data, ['id' => $productId]);

        $this->syncProductCategories($productId, $row['category_ids'] ?? '');
        $this->syncProductGallery($productId, $row, $importMedia);
        $this->syncProductDownloadFiles($productId, $row, $importMedia);
    }

    private function insertVariationsForProduct(int $productId, array $variations, bool $importMedia): int
    {
        $count = 0;
        foreach ($variations as $v) {
            if (!is_array($v)) {
                continue;
            }

            $attrs = $v['attributes'] ?? [];
            if (!is_array($attrs)) {
                $attrs = json_decode((string) $attrs, true) ?: [];
            }

            $imageId = null;
            if (isset($v['image_url'])) {
                $imageId = $this->resolveMediaId($v['image_url'], $importMedia);
            } elseif (isset($v['image'])) {
                $imageId = $this->resolveMediaId($v['image'], $importMedia);
            }

            $dlFileIds = [];
            if (isset($v['download_files']) && is_array($v['download_files'])) {
                foreach ($v['download_files'] as $dlUrl) {
                    if ($dlId = $this->resolveMediaId($dlUrl, $importMedia)) {
                        $dlFileIds[] = $dlId;
                    }
                }
            }

            $data = [
                'product_id'                 => $productId,
                'attributes'                 => json_encode($attrs ?: new \stdClass()),
                'sku'                        => $this->nullIfEmpty($v['sku'] ?? ''),
                'price'                      => $this->toFloat($v['price'] ?? 0),
                'sale_price'                 => $this->toFloatOrNull($v['sale_price'] ?? ''),
                'sale_price_start'           => $this->parseDate($v['sale_price_start'] ?? ''),
                'sale_price_end'             => $this->parseDate($v['sale_price_end'] ?? ''),
                'stock_quantity'             => (int) ($v['stock_quantity'] ?? 0),
                'stock_status'               => $this->toEnum($v['stock_status'] ?? '', 'in_stock', ['in_stock', 'out_of_stock', 'on_backorder']),
                'image_id'                   => $imageId,
                'download_mode'              => $this->toEnum($v['download_mode'] ?? '', 'use_parent', ['use_parent', 'internal', 'external']),
                'download_file_id'           => null,
                'download_url'               => $this->nullIfEmpty($v['download_url'] ?? ''),
                'download_url_proxy'         => $this->toBool($v['download_url_proxy'] ?? '') ? 1 : 0,
                'download_limit'             => (int) ($v['download_limit'] ?? 0),
                'download_expiry_days'       => (int) ($v['download_expiry_days'] ?? 0),
                'shipping_class'             => $this->toEnum($v['shipping_class'] ?? '', 'use_parent', ['use_parent', 'free', 'custom']),
                'shipping_cost'              => $this->toFloatOrNull($v['shipping_cost'] ?? ''),
                'shipping_country_overrides' => $this->encodeJson($v['shipping_country_overrides'] ?? ''),
                'vat_mode'                   => $this->toEnum($v['vat_mode'] ?? '', 'use_parent', ['use_parent', 'custom']),
                'vat_rate'                   => $this->toFloatOrNull($v['vat_rate'] ?? ''),
                'weight'                     => $this->toFloatOrNull($v['weight'] ?? ''),
                'length'                     => $this->toFloatOrNull($v['length'] ?? ''),
                'width'                      => $this->toFloatOrNull($v['width'] ?? ''),
                'height'                     => $this->toFloatOrNull($v['height'] ?? ''),
            ];

            $this->db->insert(VL_DB_PREFIX . 'product_variations', $data);
            $variationId = (int) $this->db->lastInsertId();
            $count++;

            if ($dlFileIds !== []) {
                foreach ($dlFileIds as $order => $mediaId) {
                    $this->db->insert(VL_DB_PREFIX . 'product_variation_download_files', [
                        'product_id'   => $productId,
                        'variation_id' => $variationId,
                        'media_id'     => (int) $mediaId,
                        'sort_order'   => $order,
                        'created_at'   => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        return $count;
    }

    private function syncProductCategories(int $productId, string $raw): void
    {
        if ($raw === '' || $raw === null) {
            return;
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), static fn ($v) => $v !== '');
        if ($parts === []) {
            return;
        }

        $this->db->delete(VL_DB_PREFIX . 'product_to_categories', ['product_id' => $productId]);

        foreach ($parts as $part) {
            $categoryId = is_numeric($part) ? (int) $part : null;
            if (!$categoryId) {
                $cat = $this->db->fetchOne(
                    "SELECT id FROM " . VL_DB_PREFIX . "product_categories WHERE slug = ?",
                    [$part]
                );
                $categoryId = $cat ? (int) $cat : null;
            }
            if ($categoryId) {
                $this->db->insert(VL_DB_PREFIX . 'product_to_categories', [
                    'product_id'  => $productId,
                    'category_id' => $categoryId,
                ]);
            }
        }
    }

    private function syncProductGallery(int $productId, array $row, bool $importMedia): void
    {
        $mediaIds = [];

        $featuredId = $this->resolveMediaId($row['featured_image_id'] ?? '', false);
        if ($featuredId) {
            $mediaIds[] = $featuredId;
        }

        if (isset($row['featured_image_url'])) {
            $downloaded = $this->resolveMediaId($row['featured_image_url'], $importMedia);
            if ($downloaded) {
                $mediaIds[] = $downloaded;
            }
        }

        $gallery = $row['gallery'] ?? '';
        if ($gallery !== '' && is_string($gallery)) {
            $urls = json_decode($gallery, true);
            if (is_array($urls)) {
                foreach ($urls as $url) {
                    if ($url = trim((string) $url)) {
                        $mid = $this->resolveMediaId($url, $importMedia);
                        if ($mid) {
                            $mediaIds[] = $mid;
                        }
                    }
                }
            }
        }

        $mediaIds = array_values(array_unique($mediaIds));

        $this->db->delete(VL_DB_PREFIX . 'product_images', ['product_id' => $productId]);
        foreach ($mediaIds as $order => $mediaId) {
            $this->db->insert(VL_DB_PREFIX . 'product_images', [
                'product_id' => $productId,
                'media_id'   => (int) $mediaId,
                'sort_order' => $order,
            ]);
        }
    }

    private function syncProductDownloadFiles(int $productId, array $row, bool $importMedia): void
    {
        $mediaIds = [];

        $raw = $row['download_files'] ?? '';
        if ($raw !== '' && is_string($raw)) {
            $urls = json_decode($raw, true);
            if (is_array($urls)) {
                foreach ($urls as $url) {
                    if ($url = trim((string) $url)) {
                        $mid = $this->resolveMediaId($url, $importMedia);
                        if ($mid) {
                            $mediaIds[] = $mid;
                        }
                    }
                }
            }
        }

        $this->db->delete(VL_DB_PREFIX . 'product_download_files', ['product_id' => $productId]);
        foreach ($mediaIds as $order => $mediaId) {
            $this->db->insert(VL_DB_PREFIX . 'product_download_files', [
                'product_id' => $productId,
                'media_id'   => (int) $mediaId,
                'sort_order' => $order,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function resolveFeaturedImageId(array $row, bool $importMedia): ?int
    {
        $rawId = $row['featured_image_id'] ?? '';
        if ($rawId !== '' && is_numeric($rawId)) {
            return (int) $rawId;
        }

        $url = $row['featured_image_url'] ?? '';
        if ($url !== '' && $importMedia) {
            return $this->resolveMediaId($url, true);
        }

        return null;
    }

    private function resolveMediaId(string $url, bool $importMedia): ?int
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (is_numeric($url)) {
            $media = $this->db->fetchOne(
                "SELECT id FROM " . VL_DB_PREFIX . "media WHERE id = ?",
                [$url]
            );
            return $media ? (int) $media : null;
        }

        if (!$importMedia) {
            return null;
        }

        return $this->downloadImage($url);
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function toFloat(mixed $value): float
    {
        $value = str_replace(',', '.', trim((string) $value));
        return $value === '' ? 0.0 : (float) $value;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || $value === false) {
            return null;
        }
        return (float) $value;
    }

    private function toBool(mixed $value): bool
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    private function toEnum(mixed $value, string $default, array $allowed): string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '' || $v === false) {
            return $default;
        }
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (is_string($value)) {
            if (json_validate($value)) {
                $decoded = json_decode($value, true);
                if ($decoded !== null) {
                    return json_encode($decoded);
                }
            }
            return json_encode(new \stdClass());
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return json_encode(new \stdClass());
    }

    private function toIdArray(string $value): array
    {
        if ($value === '' || $value === null) {
            return [];
        }
        $parts = array_filter(array_map('intval', explode(',', (string) $value)), static fn (int $id) => $id > 0);
        return array_values($parts);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
