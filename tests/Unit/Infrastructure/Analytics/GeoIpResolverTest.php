<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Analytics;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;

final class GeoIpResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vale_geo_test_' . uniqid();
        @mkdir($this->tempDir . '/geo', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testGetDatabasePathReturnsGeoSubdirectoryPath(): void
    {
        $resolver = new GeoIpResolver($this->tempDir);
        $path     = $resolver->getDatabasePath(GeoIpResolver::MODE_IP2LOCATION_COUNTRY);

        $this->assertSame($this->tempDir . '/geo/ip2location-db1.bin', $path);
    }

    public function testCloudflareHeaderResolution(): void
    {
        $resolver = new GeoIpResolver($this->tempDir);
        $resolver->configure(GeoIpResolver::MODE_CLOUDFLARE);

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', '/')
            ->withHeader('CF-IPCountry', 'TR')
            ->withHeader('CF-IPCity', 'Istanbul');
        $res     = $resolver->resolve('88.255.216.1', $request);

        $this->assertSame(['country' => 'TR', 'city' => 'Istanbul'], $res);
    }

    public function testCloudflareHeaderWithUrlEncodedCity(): void
    {
        $resolver = new GeoIpResolver($this->tempDir);
        $resolver->configure(GeoIpResolver::MODE_CLOUDFLARE);

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', '/')
            ->withHeader('CF-IPCountry', 'TR')
            ->withHeader('CF-IPCity', 'San%20Francisco');
        $res     = $resolver->resolve('88.255.216.1', $request);

        $this->assertSame(['country' => 'TR', 'city' => 'San Francisco'], $res);
    }

    public function testGetDatabasePathForMaxMindAndDbIp(): void
    {
        $resolver = new GeoIpResolver($this->tempDir);

        $this->assertSame(
            $this->tempDir . '/geo/GeoLite2-City.mmdb',
            $resolver->getDatabasePath(GeoIpResolver::MODE_MAXMIND_CITY)
        );
        $this->assertSame(
            $this->tempDir . '/geo/dbip-city-lite.mmdb',
            $resolver->getDatabasePath(GeoIpResolver::MODE_DBIP_CITY)
        );
    }

    public function testMmdbLookupSyntheticDatabase(): void
    {
        $mmdbBinary = $this->createSyntheticMmdb('US', 'Los Angeles');
        $filePath   = $this->tempDir . '/geo/GeoLite2-City.mmdb';
        file_put_contents($filePath, $mmdbBinary);

        $resolver = new GeoIpResolver($this->tempDir);
        $resolver->configure(GeoIpResolver::MODE_MAXMIND_CITY);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $res = $resolver->resolve('1.2.3.4', $req);
        $this->assertNotNull($res);
        $this->assertSame('US', $res['country']);
        $this->assertSame('Los Angeles', $res['city']);

        $resNotFound = $resolver->resolve('192.168.1.1', $req);
        $this->assertNull($resNotFound);
    }

    public function testMmdbLookupDirectWithDbIpMode(): void
    {
        $mmdbBinary = $this->createSyntheticMmdb('DE', 'Berlin');
        $filePath   = $this->tempDir . '/geo/dbip-city-lite.mmdb';
        file_put_contents($filePath, $mmdbBinary);

        $resolver = new GeoIpResolver($this->tempDir);
        $resolver->configure(GeoIpResolver::MODE_DBIP_CITY);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $res = $resolver->resolve('1.2.3.4', $req);
        $this->assertNotNull($res);
        $this->assertSame('DE', $res['country']);
        $this->assertSame('Berlin', $res['city']);
    }

    private function createSyntheticMmdb(string $country, string $city): string
    {
        $encodeString = function (string $s): string {
            $len = strlen($s);
            if ($len < 29) {
                return chr((2 << 5) | $len) . $s;
            }
            return chr((2 << 5) | 29) . chr($len - 29) . $s;
        };

        $encodeUint = function (int $type, int $val): string {
            if ($val === 0) {
                return chr($type << 5);
            }
            $bytes = '';
            while ($val > 0) {
                $bytes = chr($val & 0xFF) . $bytes;
                $val >>= 8;
            }
            return chr(($type << 5) | strlen($bytes)) . $bytes;
        };

        $encodeMap = function (array $map) use ($encodeString): string {
            $payload = '';
            foreach ($map as $k => $v) {
                $payload .= $encodeString($k);
                $payload .= $v;
            }
            $count = count($map);
            if ($count < 29) {
                return chr((7 << 5) | $count) . $payload;
            }
            return chr((7 << 5) | 29) . chr($count - 29) . $payload;
        };

        $cityMap = $encodeMap([
            'country' => $encodeMap(['iso_code' => $encodeString($country)]),
            'city'    => $encodeMap(['names' => $encodeMap(['en' => $encodeString($city)])]),
        ]);

        $dataSection = $cityMap;
        $tree        = "\x00\x00\x11\x00\x00\x01";
        $separator   = str_repeat("\x00", 16);

        $metaMap = $encodeMap([
            'node_count'                  => $encodeUint(6, 1),
            'record_size'                 => $encodeUint(5, 24),
            'ip_version'                  => $encodeUint(5, 4),
            'database_type'               => $encodeString('GeoLite2-City'),
            'binary_format_major_version' => $encodeUint(5, 2),
            'binary_format_minor_version' => $encodeUint(5, 0),
            'build_epoch'                 => $encodeUint(6, 1700000000),
        ]);

        return $tree . $separator . $dataSection . "\xab\xcd\xefMaxMind.com" . $metaMap;
    }

    public function testCloudflareHeaderIgnoredWhenProxyUntrusted(): void
    {
        $envBackup               = $_ENV;
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        try {
            $resolver = new GeoIpResolver($this->tempDir);
            $resolver->configure(GeoIpResolver::MODE_CLOUDFLARE);

            $factory = new ServerRequestFactory();
            $request = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '203.0.113.50'])
                ->withHeader('CF-IPCountry', 'DE');

            // Untrusted direct connection must ignore CF-IPCountry
            $res = $resolver->resolve('203.0.113.50', $request);
            $this->assertNull($res);
        } finally {
            $_ENV = $envBackup;
        }
    }

    public function testIp2LocationCountryResolutionWithBinFile(): void
    {
        // Real IP2Location BIN fixture. The reader auto-detects the db type, so
        // any LITE DB works — DB1 is expected. Provide it via the
        // IP2LOCATION_FIXTURE_BIN env var, or drop it at
        // sys_get_temp_dir()/test_extracted/ (Vale ships one at
        // storage/geo/ip2location-db1.bin after the admin geo-db download).
        $binFile = getenv('IP2LOCATION_FIXTURE_BIN')
            ?: sys_get_temp_dir() . '/test_extracted/IP2LOCATION-LITE-DB1.BIN';
        if (!file_exists($binFile)) {
            $this->markTestSkipped('IP2LOCATION fixture not present.');
        }

        @copy($binFile, $this->tempDir . '/geo/ip2location-db1.bin');

        $resolver = new GeoIpResolver($this->tempDir);
        $resolver->configure(GeoIpResolver::MODE_IP2LOCATION_COUNTRY);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $usRes = $resolver->resolve('8.8.8.8', $req);
        $this->assertNotNull($usRes);
        $this->assertSame('US', $usRes['country']);

        $trRes = $resolver->resolve('88.255.216.1', $req);
        $this->assertNotNull($trRes);
        $this->assertSame('TR', $trRes['country']);
    }
}
