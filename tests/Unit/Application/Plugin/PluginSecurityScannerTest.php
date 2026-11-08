<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginManifest;
use ValeApp\Application\Plugin\PluginSecurityScanner;

final class PluginSecurityScannerTest extends TestCase
{
    private PluginSecurityScanner $scanner;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->scanner = new PluginSecurityScanner();
        $this->tempDir = sys_get_temp_dir() . '/vl_test_plugin_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->tempDir);
        }
    }

    public function testCleanPluginHasNoCapabilitiesOrViolations(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;
class CleanClass {
    public function calculate(int $a, int $b): int {
        return $a + $b;
    }
}
PHP;
        file_put_contents($this->tempDir . '/Clean.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
        $this->assertSame([], $result['violations']);
    }

    public function testDetectsEnvVariablesAndFunctions(): void
    {
        $code = <<<'PHP'
<?php
$val = getenv('DB_PASS');
$s = $_SERVER['REMOTE_ADDR'];
$e = $_ENV['SECRET'];
putenv('FOO=bar');
PHP;
        file_put_contents($this->tempDir . '/EnvTest.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['env'], $result['detected_capabilities']);
        $this->assertCount(4, $result['violations']);

        // When 'env' capability is allowed:
        $allowedResult = $this->scanner->scanDirectory($this->tempDir, ['env']);
        $this->assertTrue($allowedResult['is_valid']);
        $this->assertSame([], $allowedResult['violations']);
    }

    public function testDetectsProcessExecution(): void
    {
        $code = <<<'PHP'
<?php
exec('ls -la');
shell_exec('whoami');
system('uname');
passthru('id');
$out = `date`;
proc_open('cat', [], $pipes);
popen('echo 1', 'r');
PHP;
        file_put_contents($this->tempDir . '/ProcessTest.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['process'], $result['detected_capabilities']);
        $this->assertCount(7, $result['violations']);

        $allowedResult = $this->scanner->scanDirectory($this->tempDir, ['process']);
        $this->assertTrue($allowedResult['is_valid']);
    }

    public function testDetectsCodeExecution(): void
    {
        $code = <<<'PHP'
<?php
eval('$x = 1;');
assert(1 === 1);
PHP;
        file_put_contents($this->tempDir . '/EvalTest.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['code_execution'], $result['detected_capabilities']);
        $this->assertCount(2, $result['violations']);

        $allowedResult = $this->scanner->scanDirectory($this->tempDir, ['code_execution']);
        $this->assertTrue($allowedResult['is_valid']);
    }

    public function testDetectsFilesystemReadAndWriteSeparately(): void
    {
        $code = <<<'PHP'
<?php
$content = file_get_contents('/etc/hosts');
$scanned = scandir('/tmp');
file_put_contents('/tmp/out.txt', 'data');
unlink('/tmp/out.txt');
PHP;
        file_put_contents($this->tempDir . '/FsTest.php', $code);

        // Disallowed completely
        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $expectedCaps = ['filesystem_read', 'filesystem_write'];
        $this->assertSame($expectedCaps, $result['detected_capabilities']);
        $this->assertCount(4, $result['violations']);

        // Allowed read-only: write operations still fail
        $readOnlyResult = $this->scanner->scanDirectory(
            $this->tempDir,
            ['filesystem_read'],
        );
        $this->assertFalse($readOnlyResult['is_valid']);
        $this->assertCount(2, $readOnlyResult['violations']);
        $this->assertSame('filesystem_write', $readOnlyResult['violations'][0]['capability']);

        // Allowed both
        $bothResult = $this->scanner->scanDirectory(
            $this->tempDir,
            ['filesystem_read', 'filesystem_write'],
        );
        $this->assertTrue($bothResult['is_valid']);
    }

    public function testDetectsNetworkCapabilities(): void
    {
        $code = <<<'PHP'
<?php
$ch = curl_init('https://example.com');
curl_exec($ch);
$fp = fsockopen('127.0.0.1', 80);
PHP;
        file_put_contents($this->tempDir . '/NetTest.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['network'], $result['detected_capabilities']);
        $this->assertCount(3, $result['violations']);

        $allowedResult = $this->scanner->scanDirectory($this->tempDir, ['network']);
        $this->assertTrue($allowedResult['is_valid']);
    }

    public function testIgnoresMethodCallsWithMatchingNames(): void
    {
        $code = <<<'PHP'
<?php
class Service {
    public function getenv(): string { return ''; }
    public function exec(): void {}
}
$s = new Service();
$s->getenv();
$s?->exec();
Service::exec();
PHP;
        file_put_contents($this->tempDir . '/Methods.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
    }

    public function testAssertAllowedThrowsDescriptiveException(): void
    {
        $code = <<<'PHP'
<?php
$secret = getenv('API_KEY');
PHP;
        file_put_contents($this->tempDir . '/plugin.php', $code);

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage(
            'Security violation: undeclared capability "env" used in plugin.php line 2: getenv()',
        );

        $this->scanner->assertAllowed($this->tempDir, []);
    }

    public function testManifestDeclaresCapabilities(): void
    {
        $manifestContent = <<<'PHP'
<?php
return [
    'name' => 'Demo Plugin',
    'version' => '1.0.0',
    'capabilities' => ['env', 'filesystem_read', 'invalid_one'],
    'register' => static function ($app, $c): void {},
];
PHP;
        file_put_contents($this->tempDir . '/plugin.php', $manifestContent);

        $manifest = PluginManifest::fromFile($this->tempDir . '/plugin.php', 'demo-plugin');
        $this->assertSame(['env', 'filesystem_read'], $manifest->capabilities);
    }

    public function testDetectsFullyQualifiedFunctionCalls(): void
    {
        $code = <<<'PHP'
<?php
\shell_exec('id');
\file_put_contents('/tmp/out.txt', 'data');
\assert(1 === 1);
PHP;
        file_put_contents($this->tempDir . '/QualifiedCalls.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['code_execution', 'filesystem_write', 'process'], $result['detected_capabilities']);
        $this->assertCount(3, $result['violations']);
        $this->assertSame('process', $result['violations'][0]['capability']);
        $this->assertSame('\shell_exec()', $result['violations'][0]['expression']);
        $this->assertSame(2, $result['violations'][0]['line']);

        $allowedResult = $this->scanner->scanDirectory(
            $this->tempDir,
            ['code_execution', 'filesystem_write', 'process'],
        );
        $this->assertTrue($allowedResult['is_valid']);
    }

    public function testDetectsNamespacedFunctionCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;

Support\shell_exec('id');
namespace\file_put_contents('/tmp/acme.txt', 'data');
PHP;
        file_put_contents($this->tempDir . '/NamespacedCalls.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['filesystem_write', 'process'], $result['detected_capabilities']);
        $this->assertCount(2, $result['violations']);
        $this->assertSame('Support\shell_exec()', $result['violations'][0]['expression']);

        $allowedResult = $this->scanner->scanDirectory($this->tempDir, ['filesystem_write', 'process']);
        $this->assertTrue($allowedResult['is_valid']);
    }

    public function testIgnoresQualifiedNamesThatAreNotFunctionCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;

final class Service {
    public static function exec(): void {}
}

$redis    = new \Redis();
$instance = new Support\shell_exec();
Service::exec();
$redis?->fopen('x');
PHP;
        file_put_contents($this->tempDir . '/QualifiedRefs.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
    }

    public function testResolvesParenthesizedConstantCalls(): void
    {
        $code = <<<'PHP'
<?php
('shell_exec')('id');
('sys' . 'tem')('uname');
( 'file_put_contents' )('/tmp/x', 'y');
PHP;
        file_put_contents($this->tempDir . '/ParenCalls.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['filesystem_write', 'process'], $result['detected_capabilities']);
        $this->assertCount(3, $result['violations']);
    }

    public function testResolvesGroupFunctionImports(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;

use function Acme\{shell_exec as run, file_put_contents as save};

run('id');
save('/tmp/out.txt', 'data');
PHP;
        file_put_contents($this->tempDir . '/GroupImport.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['filesystem_write', 'process'], $result['detected_capabilities']);
        $this->assertCount(2, $result['violations']);
    }

    public function testResolvesFunctionAliases(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;

use function shell_exec as run;
use function \file_put_contents as save;

run('id');
save('/tmp/out.txt', 'data');
PHP;
        file_put_contents($this->tempDir . '/Aliases.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['filesystem_write', 'process'], $result['detected_capabilities']);
        $this->assertCount(2, $result['violations']);

        $allowed = $this->scanner->scanDirectory(
            $this->tempDir,
            ['filesystem_write', 'process'],
        );
        $this->assertTrue($allowed['is_valid']);
    }

    public function testAliasToNonSensitiveFunctionIsIgnored(): void
    {
        $code = <<<'PHP'
<?php
namespace Acme;

use function Acme\helper as exec;

exec('id');
PHP;
        file_put_contents($this->tempDir . '/SafeAlias.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
    }

    public function testResolvesConstantFoldedCalls(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'sys' . 'tem';
$fn('id');
call_user_func('file_' . 'get_contents', '/etc/hosts');
'file_put_contents'('/tmp/x', 'y');
fopen('/tmp/out.txt', 'w' . 'b');
PHP;
        file_put_contents($this->tempDir . '/Folded.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(
            ['filesystem_read', 'filesystem_write', 'process'],
            $result['detected_capabilities'],
        );
        $this->assertCount(4, $result['violations']);
    }

    public function testComputedNonLiteralTargetStaysUnclassified(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'sys' . $suffix;
$fn('id');
call_user_func($name, 'x');
PHP;
        file_put_contents($this->tempDir . '/Computed.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
    }

    public function testFopenModeDecidesReadOrWriteCapability(): void
    {
        $code = <<<'PHP'
<?php
$w = fopen('/tmp/out.txt', 'w');
$rw = fopen('/tmp/out.txt', 'r+');
$r = fopen('/tmp/in.txt', 'r');
$d = fopen('/tmp/in.txt');
$dynamic = fopen('/tmp/x.txt', $mode);
PHP;
        file_put_contents($this->tempDir . '/FopenModes.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertSame(['filesystem_read', 'filesystem_write'], $result['detected_capabilities']);

        $readOnly = $this->scanner->scanDirectory($this->tempDir, ['filesystem_read']);
        $this->assertFalse($readOnly['is_valid']);
        $this->assertCount(3, $readOnly['violations']);
        foreach ($readOnly['violations'] as $violation) {
            $this->assertSame('filesystem_write', $violation['capability']);
        }

        $writeOnly = $this->scanner->scanDirectory($this->tempDir, ['filesystem_write']);
        $this->assertFalse($writeOnly['is_valid']);
        $this->assertCount(4, $writeOnly['violations']);
        foreach ($writeOnly['violations'] as $violation) {
            $this->assertSame('filesystem_read', $violation['capability']);
        }

        $both = $this->scanner->scanDirectory(
            $this->tempDir,
            ['filesystem_read', 'filesystem_write'],
        );
        $this->assertTrue($both['is_valid']);
    }

    public function testResolvesLiteralDynamicCallsAndFilesystemClasses(): void
    {
        $code = <<<'PHP'
<?php
call_user_func('file_get_contents', '/etc/hosts');
$fn = 'scandir';
$fn('/tmp');
$file = new SplFileObject('/etc/hosts');
$dir = new DirectoryIterator('/tmp');
hash_file('sha256', '/etc/hosts');
stat('/etc/hosts');
PHP;
        file_put_contents($this->tempDir . '/DynamicCalls.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['filesystem_read'], $result['detected_capabilities']);
        $this->assertCount(6, $result['violations']);

        $allowed = $this->scanner->scanDirectory($this->tempDir, ['filesystem_read']);
        $this->assertTrue($allowed['is_valid']);
    }

    public function testAmbiguousDynamicCallsStayUnclassified(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'shell_exec';
$fn = $other;
$fn('id');
call_user_func($other, 'x');
PHP;
        file_put_contents($this->tempDir . '/AmbiguousCalls.php', $code);

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['detected_capabilities']);
    }

    public function testScansIncludeStyledPhpFiles(): void
    {
        file_put_contents(
            $this->tempDir . '/lib.inc',
            "<?php\nshell_exec('id');\n",
        );

        $result = $this->scanner->scanDirectory($this->tempDir, []);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['process'], $result['detected_capabilities']);
        $this->assertSame('lib.inc', $result['violations'][0]['file']);
    }

    public function testFopenWriteModeOnlyNeedsWriteCapability(): void
    {
        $code = <<<'PHP'
<?php
fopen('/tmp/out.txt', 'wb');
PHP;
        file_put_contents($this->tempDir . '/FopenWrite.php', $code);

        $this->assertTrue(
            $this->scanner->scanDirectory($this->tempDir, ['filesystem_write'])['is_valid'],
        );
        $this->assertFalse(
            $this->scanner->scanDirectory($this->tempDir, ['filesystem_read'])['is_valid'],
        );
    }
}
