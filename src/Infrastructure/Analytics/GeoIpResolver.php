<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Analytics;

use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Infrastructure\Payment\HttpTransport;

final class GeoIpResolver
{
    public const MODE_NONE                = 'none';
    public const MODE_CLOUDFLARE          = 'cloudflare';
    public const MODE_IP2LOCATION_COUNTRY = 'ip2location_country';
    public const MODE_IP2LOCATION_CITY    = 'ip2location_city';
    public const MODE_MAXMIND_CITY        = 'maxmind_city';
    public const MODE_DBIP_CITY           = 'dbip_city';

    private ?string $dbPath = null;
    private ?string $mode   = null;

    public function __construct(private readonly string $storageDir = '')
    {
    }

    public function configure(string $mode, ?string $dbPath = null): self
    {
        $this->mode   = $mode;
        $this->dbPath = $dbPath;
        return $this;
    }

    public function resolve(?string $ip, ServerRequestInterface $request): ?array
    {
        if ($this->mode === null || $this->mode === self::MODE_NONE) {
            return null;
        }

        if ($this->mode === self::MODE_CLOUDFLARE) {
            $country        = $request->getHeaderLine('CF-IPCountry');
            if ($country === '') {
                $country = (string) ($request->getServerParams()['HTTP_CF_IPCOUNTRY'] ?? '');
            }
            $city = $request->getHeaderLine('CF-IPCity');
            if ($city === '') {
                $city = (string) ($request->getServerParams()['HTTP_CF_IPCITY'] ?? '');
            }

            $remoteAddr     = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            $trustedProxies = env('TRUSTED_PROXIES', '');

            if ($country !== '' && $country !== 'XX') {
                if ($trustedProxies === '' || vl_is_trusted_proxy($remoteAddr, $trustedProxies)) {
                    $resolvedCity = null;
                    if ($city !== '') {
                        $resolvedCity = rawurldecode(trim($city));
                        if ($resolvedCity === '' || $resolvedCity === 'XX') {
                            $resolvedCity = null;
                        }
                    }
                    return ['country' => $country, 'city' => $resolvedCity];
                }
            }
            return null;
        }

        if ($ip === null || $ip === '') {
            return null;
        }

        $ip2lModes = [
            self::MODE_IP2LOCATION_COUNTRY,
            self::MODE_IP2LOCATION_CITY,
        ];
        if (in_array($this->mode, $ip2lModes, true)) {
            return $this->resolveIp2Location($ip);
        }

        $mmdbModes = [
            self::MODE_MAXMIND_CITY,
            self::MODE_DBIP_CITY,
        ];
        if (in_array($this->mode, $mmdbModes, true)) {
            return $this->resolveMmdb($ip);
        }

        return null;
    }

    public function getDatabasePath(string $mode): ?string
    {
        $base = $this->storageDir !== '' ? $this->storageDir : (string) getcwd() . '/storage';

        $fileName = match ($mode) {
            self::MODE_IP2LOCATION_COUNTRY => 'ip2location-db1.bin',
            self::MODE_IP2LOCATION_CITY    => 'ip2location-db3.bin',
            self::MODE_MAXMIND_CITY        => 'GeoLite2-City.mmdb',
            self::MODE_DBIP_CITY           => 'dbip-city-lite.mmdb',
            default                        => null,
        };

        if ($fileName === null) {
            return null;
        }

        $isGeo    = str_ends_with($base, '/geo');
        $geoPath  = rtrim($base, '/') . ($isGeo ? '' : '/geo') . '/' . $fileName;
        $rootPath = rtrim($base, '/') . '/' . $fileName;

        if (file_exists($geoPath)) {
            return $geoPath;
        }

        if (file_exists($rootPath)) {
            return $rootPath;
        }

        return $geoPath;
    }

    /**
     * @param array{download_url?: ?string, bin_file?: string} $urlConfig
     */
    public function downloadDatabase(string $mode, array $urlConfig = []): bool
    {
        if ($mode === self::MODE_DBIP_CITY) {
            return $this->downloadDbIpDatabase();
        }

        $path = $this->getDatabasePath($mode);
        if ($path === null) {
            return false;
        }

        $url     = $urlConfig['download_url'] ?? null;
        $binFile = $urlConfig['bin_file']     ?? null;

        if ($url === null || $binFile === null) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'ip2l_');
            if ($tmp === false) {
                return false;
            }

            $downloaded = false;
            $resp       = HttpTransport::request(
                'GET',
                $url,
                ['User-Agent: ValeCMS/1.0'],
                null,
                300
            );

            if ($resp['ok'] && $resp['status'] === 200) {
                $written = @file_put_contents($tmp, $resp['body']);
                if ($written !== false) {
                    $downloaded = true;
                }
            }

            if (!$downloaded) {
                $src = @fopen($url, 'r');
                if ($src === false) {
                    @unlink($tmp);
                    return false;
                }
                $dst = @fopen($tmp, 'w');
                if ($dst === false) {
                    @fclose($src);
                    @unlink($tmp);
                    return false;
                }
                stream_copy_to_stream($src, $dst);
                fclose($src);
                fclose($dst);
            }

            if (!file_exists($tmp) || filesize($tmp) < 1000) {
                @unlink($tmp);
                return false;
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                @unlink($tmp);
                return false;
            }

            $extracted = $zip->getStream($binFile);
            if ($extracted === false) {
                $zip->close();
                @unlink($tmp);
                return false;
            }

            $dest = @fopen($path, 'w');
            if ($dest === false) {
                fclose($extracted);
                $zip->close();
                @unlink($tmp);
                return false;
            }

            stream_copy_to_stream($extracted, $dest);
            fclose($dest);
            fclose($extracted);
            $zip->close();
            @unlink($tmp);

            return file_exists($path);
        } catch (\Throwable) {
            if (isset($tmp) && file_exists($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    private function downloadDbIpDatabase(): bool
    {
        $path = $this->getDatabasePath(self::MODE_DBIP_CITY);
        if ($path === null) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        // DB-IP publishes monthly: YYYY-MM. Try current month, then previous month
        $months = [
            date('Y-m'),
            date('Y-m', strtotime('-1 month')),
        ];

        $tmpGz = tempnam(sys_get_temp_dir(), 'dbip_');
        if ($tmpGz === false) {
            return false;
        }

        $downloaded = false;

        foreach ($months as $ym) {
            $url  = "https://download.db-ip.com/free/dbip-city-lite-{$ym}.mmdb.gz";
            $resp = HttpTransport::request(
                'GET',
                $url,
                ['User-Agent: ValeCMS/1.0'],
                null,
                300
            );

            if ($resp['ok'] && $resp['status'] === 200 && strlen((string) $resp['body']) > 1000) {
                if (@file_put_contents($tmpGz, $resp['body']) !== false) {
                    $downloaded = true;
                    break;
                }
            }
        }

        if (!$downloaded) {
            @unlink($tmpGz);
            return false;
        }

        try {
            $gz = @gzopen($tmpGz, 'rb');
            if ($gz === false) {
                @unlink($tmpGz);
                return false;
            }

            $out = @fopen($path, 'wb');
            if ($out === false) {
                gzclose($gz);
                @unlink($tmpGz);
                return false;
            }

            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536);
                if ($chunk === false) {
                    break;
                }
                fwrite($out, $chunk);
            }

            fclose($out);
            gzclose($gz);
            @unlink($tmpGz);

            return file_exists($path) && filesize($path) > 1000;
        } catch (\Throwable) {
            if (isset($tmpGz) && file_exists($tmpGz)) {
                @unlink($tmpGz);
            }
            return false;
        }
    }

    public function databaseExists(?string $mode = null): bool
    {
        $m      = $mode ?? $this->mode;
        $remote = [
            $m === null,
            $m === self::MODE_NONE,
            $m === self::MODE_CLOUDFLARE,
        ];
        if (in_array(true, $remote, true)) {
            return true;
        }

        $path = $this->getDatabasePath($m);
        return $path !== null && file_exists($path);
    }

    public function getDatabaseSize(?string $mode = null): ?int
    {
        $m = $mode ?? $this->mode;
        if ($m === null) {
            return null;
        }

        $path = $this->getDatabasePath($m);
        if ($path === null || !file_exists($path)) {
            return null;
        }

        $size = @filesize($path);
        return $size !== false ? $size : null;
    }

    private function resolveIp2Location(string $ip): ?array
    {
        $path = $this->getDatabasePath($this->mode);
        if ($path === null || !file_exists($path)) {
            return null;
        }

        try {
            $bin = @file_get_contents($path);
            if ($bin === false) {
                return null;
            }

            return $this->ip2LocationLookup($bin, $ip);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ip2LocationLookup(string $bin, string $ip): ?array
    {
        $binLen = strlen($bin);
        if ($binLen < 64) {
            return null;
        }

        $dbType    = ord($bin[0]);
        $dbCol     = ord($bin[1]);
        $ipv4Count = unpack('V', substr($bin, 5, 4))[1];
        $ipv4Base  = unpack('V', substr($bin, 9, 4))[1];
        $ipv6Count = unpack('V', substr($bin, 13, 4))[1];
        $ipv6Base  = unpack('V', substr($bin, 17, 4))[1];

        $isIpv4 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isIpv6 = !$isIpv4 && (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if (!$isIpv4 && !$isIpv6) {
            return null;
        }

        $pos = -1;

        if ($isIpv4) {
            if ($ipv4Count === 0 || $ipv4Base === 0 || $ipv4Base > $binLen) {
                return null;
            }
            $baseAddr = $ipv4Base - 1;
            $ipLong   = sprintf('%u', ip2long($ip));

            $stride = match ($dbType) {
                1       => 8,  // Country (1 pointer)
                2       => 12, // Country, ISP
                3       => 16, // Country, Region, City
                4, 5, 6 => 20, // Country, Region, City, ISP/Lat/Long
                default => 4 + ($dbCol * 4),
            };

            $low  = 0;
            $high = $ipv4Count - 1;

            while ($low <= $high) {
                $mid = (int) (($low + $high) / 2);
                $off = $baseAddr + $mid * $stride;
                if ($off + $stride > $binLen) {
                    break;
                }

                $ipFrom = unpack('V', substr($bin, $off, 4))[1];
                $ipTo   = ($mid < $ipv4Count - 1 && $off + $stride + 4 <= $binLen)
                    ? unpack('V', substr($bin, $off + $stride, 4))[1] - 1
                    : 4294967295;

                if ($ipLong < $ipFrom) {
                    $high = $mid - 1;
                } elseif ($ipLong > $ipTo) {
                    $low = $mid + 1;
                } else {
                    $pos = $off;
                    break;
                }
            }
        } else {
            if ($ipv6Count === 0 || $ipv6Base === 0 || $ipv6Base > $binLen) {
                return null;
            }
            $baseAddr  = $ipv6Base - 1;
            $targetBin = inet_pton($ip);
            if ($targetBin === false) {
                return null;
            }

            $stride = match ($dbType) {
                1       => 20, // 16 + 4
                2       => 24, // 16 + 8
                3       => 28, // 16 + 12
                4, 5, 6 => 32,
                default => 16 + ($dbCol * 4),
            };

            $low  = 0;
            $high = $ipv6Count - 1;

            while ($low <= $high) {
                $mid = (int) (($low + $high) / 2);
                $off = $baseAddr + $mid * $stride;
                if ($off + $stride > $binLen) {
                    break;
                }

                $ipFromBin = substr($bin, $off, 16);
                $maxHex    = "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
                $ipToBin   = ($mid < $ipv6Count - 1 && $off + $stride + 16 <= $binLen)
                    ? substr($bin, $off + $stride, 16)
                    : $maxHex . $maxHex;

                if (strcmp($targetBin, $ipFromBin) < 0) {
                    $high = $mid - 1;
                } elseif (strcmp($targetBin, $ipToBin) > 0) {
                    $low = $mid + 1;
                } else {
                    $pos = $off;
                    break;
                }
            }
        }

        if ($pos === -1) {
            return null;
        }

        $ipOffset = $isIpv4 ? 4 : 16;

        $cPointer = unpack('V', substr($bin, $pos + $ipOffset, 4))[1];
        if ($cPointer <= 0 || $cPointer > $binLen) {
            return null;
        }

        $cOff     = $cPointer; // data pointers are 0-based offsets to the length byte
        $shortLen = ord($bin[$cOff]);
        $country  = substr($bin, $cOff + 1, $shortLen);
        if ($country === '-' || $country === '') {
            $country = null;
        }

        $city       = null;
        $isCityMode = $this->mode === self::MODE_IP2LOCATION_CITY;
        if (in_array($dbType, [3, 4, 5, 6, 8, 11], true) || $isCityMode) {
            $cityPointerOffset = match ($dbType) {
                3, 4, 5, 6 => $pos + $ipOffset + 8,
                default    => $pos + $ipOffset + 8,
            };

            if ($cityPointerOffset + 4 <= $binLen) {
                $cityPointer = unpack('V', substr($bin, $cityPointerOffset, 4))[1];
                if ($cityPointer > 0 && $cityPointer <= $binLen) {
                    $cityOff = $cityPointer; // 0-based offset to the length byte
                    $cityLen = ord($bin[$cityOff]);
                    $cityStr = substr($bin, $cityOff + 1, $cityLen);
                    if ($cityStr !== '-' && $cityStr !== '') {
                        $city = $cityStr;
                    }
                }
            }
        }

        return [
            'country' => $country,
            'city'    => $city,
        ];
    }

    private function resolveMmdb(string $ip): ?array
    {
        $path = $this->getDatabasePath((string) $this->mode);
        if ($path === null || !file_exists($path)) {
            return null;
        }

        try {
            $bin = @file_get_contents($path);
            if ($bin === false) {
                return null;
            }

            return $this->mmdbLookup($bin, $ip);
        } catch (\Throwable) {
            return null;
        }
    }

    public function mmdbLookup(string $bin, string $ip): ?array
    {
        $binLen = strlen($bin);
        if ($binLen < 64) {
            return null;
        }

        $markerPos = strrpos($bin, "\xab\xcd\xefMaxMind.com");
        if ($markerPos === false) {
            return null;
        }

        $metaOffset = $markerPos + 14;
        $offset     = $metaOffset;
        [$metadata] = $this->decodeMmdbData($bin, $offset, $metaOffset, 0, $binLen);

        if (!is_array($metadata)) {
            return null;
        }

        $nodeCount  = (int) ($metadata['node_count'] ?? 0);
        $recordSize = (int) ($metadata['record_size'] ?? 0);
        $ipVersion  = (int) ($metadata['ip_version'] ?? 4);

        if ($nodeCount <= 0 || !in_array($recordSize, [24, 28, 32], true)) {
            return null;
        }

        $isIpv4 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isIpv6 = !$isIpv4 && (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if (!$isIpv4 && !$isIpv6) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if ($isIpv4 && $ipVersion === 6) {
            $packed = str_repeat("\x00", 12) . $packed;
        }

        $nodeByteSize      = (int) (($recordSize * 2) / 8);
        $searchTreeSize    = $nodeCount * $nodeByteSize;
        $dataSectionStart  = $searchTreeSize + 16;
        $bitCount          = strlen($packed) * 8;
        $node              = 0;

        for ($i = 0; $i < $bitCount; $i++) {
            $byteIdx = (int) ($i / 8);
            $bit     = (ord($packed[$byteIdx]) >> (7 - ($i % 8))) & 1;

            $nodeOffset = $node * $nodeByteSize;
            if ($nodeOffset + $nodeByteSize > $searchTreeSize) {
                return null;
            }

            if ($recordSize === 24) {
                $record = $bit === 0
                    ? (ord($bin[$nodeOffset]) << 16)     | (ord($bin[$nodeOffset + 1]) << 8)
                                                         | ord($bin[$nodeOffset + 2])
                    : (ord($bin[$nodeOffset + 3]) << 16) | (ord($bin[$nodeOffset + 4]) << 8)
                                                         | ord($bin[$nodeOffset + 5]);
            } elseif ($recordSize === 28) {
                $middle = ord($bin[$nodeOffset + 3]);
                if ($bit === 0) {
                    $high   = ($middle >> 4) & 0x0F;
                    $record = ($high << 24) | (ord($bin[$nodeOffset]) << 16)
                                            | (ord($bin[$nodeOffset + 1]) << 8)
                                            | ord($bin[$nodeOffset + 2]);
                } else {
                    $high   = $middle & 0x0F;
                    $record = ($high << 24) | (ord($bin[$nodeOffset + 4]) << 16)
                                            | (ord($bin[$nodeOffset + 5]) << 8)
                                            | ord($bin[$nodeOffset + 6]);
                }
            } else { // 32
                $record = $bit === 0
                    ? unpack('N', substr($bin, $nodeOffset, 4))[1]
                    : unpack('N', substr($bin, $nodeOffset + 4, 4))[1];
            }

            if ($record === $nodeCount) {
                return null;
            }

            if ($record > $nodeCount) {
                $dataOffset = ($record - $nodeCount) - 16;
                $absOffset  = $dataSectionStart + $dataOffset;
                if ($absOffset >= $binLen) {
                    return null;
                }
                $dataPtr   = $absOffset;
                [$decoded] = $this->decodeMmdbData($bin, $dataPtr, $dataSectionStart, 0, $binLen);

                if (!is_array($decoded)) {
                    return null;
                }

                $country = $decoded['country']['iso_code']
                    ?? $decoded['registered_country']['iso_code']
                    ?? $decoded['continent']['code']
                    ?? null;

                $city = $decoded['city']['names']['en']
                    ?? $decoded['city']['name']
                    ?? null;

                return [
                    'country' => is_string($country) ? strtoupper($country) : null,
                    'city'    => is_string($city) && $city !== '' ? $city : null,
                ];
            }

            $node = $record;
        }

        return null;
    }

    private function decodeMmdbData(
        string $bin,
        int &$offset,
        int $baseSectionOffset,
        int $depth,
        int $binLen
    ): array {
        if ($depth > 512 || $offset >= $binLen) {
            return [null];
        }

        $ctrl = ord($bin[$offset++]);
        $type = $ctrl >> 5;

        if ($type === 0) {
            if ($offset >= $binLen) {
                return [null];
            }
            $type = 7 + ord($bin[$offset++]);
        }

        $size = $ctrl & 0x1F;

        if ($type === 1) { // Pointer
            $ptrSize = ($size >> 3) & 0x03;
            $valPart = $size        & 0x07;

            if ($ptrSize === 0) {
                if ($offset >= $binLen) {
                    return [null];
                }
                $b0  = ord($bin[$offset++]);
                $ptr = ($valPart << 8) | $b0;
            } elseif ($ptrSize === 1) {
                if ($offset + 1 >= $binLen) {
                    return [null];
                }
                $b0  = ord($bin[$offset++]);
                $b1  = ord($bin[$offset++]);
                $ptr = 2048 + (($valPart << 16) | ($b0 << 8) | $b1);
            } elseif ($ptrSize === 2) {
                if ($offset + 2 >= $binLen) {
                    return [null];
                }
                $b0  = ord($bin[$offset++]);
                $b1  = ord($bin[$offset++]);
                $b2  = ord($bin[$offset++]);
                $ptr = 526336 + (($valPart << 24) | ($b0 << 16) | ($b1 << 8) | $b2);
            } else { // 3
                if ($offset + 3 >= $binLen) {
                    return [null];
                }
                $ptr     = unpack('N', substr($bin, $offset, 4))[1];
                $offset += 4;
            }

            $targetOffset = $baseSectionOffset + $ptr;
            [$val]        = $this->decodeMmdbData(
                $bin,
                $targetOffset,
                $baseSectionOffset,
                $depth + 1,
                $binLen
            );
            return [$val];
        }

        if ($size === 29) {
            if ($offset >= $binLen) {
                return [null];
            }
            $size = 29 + ord($bin[$offset++]);
        } elseif ($size === 30) {
            if ($offset + 1 >= $binLen) {
                return [null];
            }
            $b0   = ord($bin[$offset++]);
            $b1   = ord($bin[$offset++]);
            $size = 285 + (($b0 << 8) | $b1);
        } elseif ($size === 31) {
            if ($offset + 2 >= $binLen) {
                return [null];
            }
            $b0   = ord($bin[$offset++]);
            $b1   = ord($bin[$offset++]);
            $b2   = ord($bin[$offset++]);
            $size = 65821 + (($b0 << 16) | ($b1 << 8) | $b2);
        }

        switch ($type) {
            case 2: // UTF-8 string
                $str     = substr($bin, $offset, $size);
                $offset += $size;
                return [$str];

            case 3: // double
                $raw     = substr($bin, $offset, 8);
                $offset += 8;
                $val     = strlen($raw) === 8 ? unpack('E', $raw)[1] : 0.0;
                return [$val];

            case 4: // bytes
                $bytes   = substr($bin, $offset, $size);
                $offset += $size;
                return [$bytes];

            case 5: // uint16
            case 6: // uint32
            case 9: // uint64
            case 10: // uint128
                $val = 0;
                for ($i = 0; $i < $size && $offset < $binLen; $i++) {
                    $val = ($val << 8) | ord($bin[$offset++]);
                }
                return [$val];

            case 7: // map
                $map = [];
                for ($i = 0; $i < $size && $offset < $binLen; $i++) {
                    [$key] = $this->decodeMmdbData(
                        $bin,
                        $offset,
                        $baseSectionOffset,
                        $depth + 1,
                        $binLen
                    );
                    [$val] = $this->decodeMmdbData(
                        $bin,
                        $offset,
                        $baseSectionOffset,
                        $depth + 1,
                        $binLen
                    );
                    if ($key !== null) {
                        $map[(string) $key] = $val;
                    }
                }
                return [$map];

            case 8: // int32
                $raw    = substr($bin, $offset, $size);
                $offset += $size;
                $pad    = str_pad($raw, 4, "\x00", STR_PAD_LEFT);
                $val    = unpack('N', $pad)[1];
                if ($size === 4 && ($val & 0x80000000)) {
                    $val -= 0x100000000;
                }
                return [$val];

            case 11: // array
                $arr = [];
                for ($i = 0; $i < $size && $offset < $binLen; $i++) {
                    [$val] = $this->decodeMmdbData(
                        $bin,
                        $offset,
                        $baseSectionOffset,
                        $depth + 1,
                        $binLen
                    );
                    $arr[] = $val;
                }
                return [$arr];

            case 14: // boolean
                return [$size === 1];

            case 15: // float
                $raw     = substr($bin, $offset, 4);
                $offset += 4;
                $val     = strlen($raw) === 4 ? unpack('G', $raw)[1] : 0.0;
                return [$val];

            default:
                $offset += $size;
                return [null];
        }
    }

    private function ipv6ToLong(string $ip): ?string
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $parts = unpack('N*', $bin);
        if ($parts === false) {
            return null;
        }
        return sprintf('%u', $parts[1]);
    }
}
