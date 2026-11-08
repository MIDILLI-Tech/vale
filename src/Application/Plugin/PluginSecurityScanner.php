<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static security scanner for plugin PHP source files.
 *
 * Uses native PHP PhpToken (zero third-party dependencies) to inspect token
 * streams for sensitive runtime capabilities.
 *
 * Detects:
 *   - env: getenv(), putenv(), $_ENV, $_SERVER
 *   - process: exec(), shell_exec(), system(), passthru(), proc_open(), popen(), backtick execution
 *   - code_execution: eval(), assert()
 *   - filesystem_read: file_get_contents(), readfile(), fread(), file(), fopen() in read mode,
 *                      scandir(), glob(), stat(), lstat(), hash_file(), md5_file(), sha1_file(),
 *                      is_file(), is_dir(), file_exists(), parse_ini_file(), new SplFileObject(),
 *                      new DirectoryIterator() and the other filesystem iterators
 *   - filesystem_write: file_put_contents(), fwrite(), fputs(), unlink(), rmdir(), mkdir(), rename(),
 *                       copy(), chmod(), chown(), touch(), fopen() in write mode
 *   - network: curl_init(), curl_exec(), fsockopen(), pfsockopen(), stream_socket_client(),
 *              dns_get_record(), gethostbyname()
 *
 * Qualified calls (`\exec()`, `Acme\exec()`, `namespace\exec()`) are reduced to
 * their last segment before the lookup, so a leading backslash cannot hide one.
 *
 * `fopen()` is the one direct call inspected by argument: its mode literal
 * decides read, write or both, and a mode that is not a string literal
 * requires both. Literal `call_user_func()` targets, `use function … as …`
 * imports (group imports included), parenthesized constant expressions,
 * single-assignment variable functions (`$fn = 'scandir'; $fn()`),
 * concatenations of string literals and filesystem classes such as
 * `new SplFileObject()` are classified too.
 */
final class PluginSecurityScanner
{
    public const CAPABILITY_ENV              = 'env';
    public const CAPABILITY_PROCESS          = 'process';
    public const CAPABILITY_CODE_EXECUTION   = 'code_execution';
    public const CAPABILITY_FILESYSTEM_READ  = 'filesystem_read';
    public const CAPABILITY_FILESYSTEM_WRITE = 'filesystem_write';
    public const CAPABILITY_NETWORK          = 'network';

    public const ALL_CAPABILITIES = [
        self::CAPABILITY_ENV,
        self::CAPABILITY_PROCESS,
        self::CAPABILITY_CODE_EXECUTION,
        self::CAPABILITY_FILESYSTEM_READ,
        self::CAPABILITY_FILESYSTEM_WRITE,
        self::CAPABILITY_NETWORK,
    ];

    /** Superglobal variables that trigger the 'env' capability. */
    private const ENV_SUPERGLOBALS = [
        '$_ENV'    => self::CAPABILITY_ENV,
        '$_SERVER' => self::CAPABILITY_ENV,
    ];

    /** Extensions that can hold executable PHP, including include-able snippets. */
    private const SCANNED_EXTENSIONS = ['php', 'inc', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8'];

    /** Functions that call another function named by their first argument. */
    private const CALL_DISPATCH_FUNCTIONS = ['call_user_func', 'call_user_func_array'];

    /** Filesystem classes whose instantiation reads files or directories. */
    private const FILESYSTEM_CLASS_CAPABILITY = [
        'splfileobject'              => self::CAPABILITY_FILESYSTEM_READ,
        'splfileinfo'                => self::CAPABILITY_FILESYSTEM_READ,
        'directoryiterator'          => self::CAPABILITY_FILESYSTEM_READ,
        'filesystemiterator'         => self::CAPABILITY_FILESYSTEM_READ,
        'recursivedirectoryiterator' => self::CAPABILITY_FILESYSTEM_READ,
        'globiterator'               => self::CAPABILITY_FILESYSTEM_READ,
    ];

    /** Sensitive functions and the capability they require. */
    private const FUNCTION_CAPABILITY_MAP = [
        // env
        'getenv'                => self::CAPABILITY_ENV,
        'putenv'                => self::CAPABILITY_ENV,

        // process
        'exec'                  => self::CAPABILITY_PROCESS,
        'shell_exec'            => self::CAPABILITY_PROCESS,
        'system'                => self::CAPABILITY_PROCESS,
        'passthru'              => self::CAPABILITY_PROCESS,
        'proc_open'             => self::CAPABILITY_PROCESS,
        'proc_close'            => self::CAPABILITY_PROCESS,
        'proc_terminate'        => self::CAPABILITY_PROCESS,
        'proc_get_status'       => self::CAPABILITY_PROCESS,
        'popen'                 => self::CAPABILITY_PROCESS,
        'pcntl_exec'            => self::CAPABILITY_PROCESS,
        'pcntl_fork'            => self::CAPABILITY_PROCESS,

        // code execution
        'assert'                => self::CAPABILITY_CODE_EXECUTION,

        // filesystem read
        'file_get_contents'     => self::CAPABILITY_FILESYSTEM_READ,
        'readfile'              => self::CAPABILITY_FILESYSTEM_READ,
        'fread'                 => self::CAPABILITY_FILESYSTEM_READ,
        'file'                  => self::CAPABILITY_FILESYSTEM_READ,
        'fopen'                 => self::CAPABILITY_FILESYSTEM_READ,
        'stat'                  => self::CAPABILITY_FILESYSTEM_READ,
        'lstat'                 => self::CAPABILITY_FILESYSTEM_READ,
        'hash_file'             => self::CAPABILITY_FILESYSTEM_READ,
        'md5_file'              => self::CAPABILITY_FILESYSTEM_READ,
        'sha1_file'             => self::CAPABILITY_FILESYSTEM_READ,
        'scandir'               => self::CAPABILITY_FILESYSTEM_READ,
        'glob'                  => self::CAPABILITY_FILESYSTEM_READ,
        'is_file'               => self::CAPABILITY_FILESYSTEM_READ,
        'is_dir'                => self::CAPABILITY_FILESYSTEM_READ,
        'file_exists'           => self::CAPABILITY_FILESYSTEM_READ,
        'filesize'              => self::CAPABILITY_FILESYSTEM_READ,
        'filemtime'             => self::CAPABILITY_FILESYSTEM_READ,
        'filectime'             => self::CAPABILITY_FILESYSTEM_READ,
        'parse_ini_file'        => self::CAPABILITY_FILESYSTEM_READ,

        // filesystem write
        'file_put_contents'     => self::CAPABILITY_FILESYSTEM_WRITE,
        'fwrite'                => self::CAPABILITY_FILESYSTEM_WRITE,
        'fputs'                 => self::CAPABILITY_FILESYSTEM_WRITE,
        'unlink'                => self::CAPABILITY_FILESYSTEM_WRITE,
        'rmdir'                 => self::CAPABILITY_FILESYSTEM_WRITE,
        'mkdir'                 => self::CAPABILITY_FILESYSTEM_WRITE,
        'rename'                => self::CAPABILITY_FILESYSTEM_WRITE,
        'copy'                  => self::CAPABILITY_FILESYSTEM_WRITE,
        'chmod'                 => self::CAPABILITY_FILESYSTEM_WRITE,
        'chown'                 => self::CAPABILITY_FILESYSTEM_WRITE,
        'touch'                 => self::CAPABILITY_FILESYSTEM_WRITE,

        // network
        'curl_init'             => self::CAPABILITY_NETWORK,
        'curl_exec'             => self::CAPABILITY_NETWORK,
        'curl_multi_init'       => self::CAPABILITY_NETWORK,
        'fsockopen'             => self::CAPABILITY_NETWORK,
        'pfsockopen'            => self::CAPABILITY_NETWORK,
        'stream_socket_client'  => self::CAPABILITY_NETWORK,
        'dns_get_record'        => self::CAPABILITY_NETWORK,
        'gethostbyname'         => self::CAPABILITY_NETWORK,
        'gethostbynamel'        => self::CAPABILITY_NETWORK,
    ];

    /** Token types that can name a called function: bare, `\exec`, `Acme\exec`, `namespace\exec`. */
    private const CALLABLE_NAME_TOKENS = [
        T_STRING,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * Inspect a directory of PHP files and return all detected capabilities and violations.
     *
     * @param list<string> $allowedCapabilities
     * @return array{
     *     detected_capabilities: list<string>,
     *     violations: list<array{file: string, line: int, capability: string, expression: string}>,
     *     is_valid: bool
     * }
     */
    public function scanDirectory(string $directory, array $allowedCapabilities = []): array
    {
        $allowedMap           = array_fill_keys($allowedCapabilities, true);
        $detectedCapabilities = [];
        $violations           = [];

        if (!is_dir($directory)) {
            return [
                'detected_capabilities' => [],
                'violations'            => [],
                'is_valid'              => true,
            ];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()
                || !in_array(strtolower($fileInfo->getExtension()), self::SCANNED_EXTENSIONS, true)
            ) {
                continue;
            }

            $relative   = ltrim(substr($fileInfo->getPathname(), strlen($directory)), '/\\');
            $fileSource = (string) @file_get_contents($fileInfo->getPathname());
            $fileResult = $this->scanSource($fileSource, $relative);

            foreach ($fileResult['detected_capabilities'] as $cap) {
                if (!in_array($cap, $detectedCapabilities, true)) {
                    $detectedCapabilities[] = $cap;
                }
            }

            foreach ($fileResult['occurrences'] as $occ) {
                if (!isset($allowedMap[$occ['capability']])) {
                    $violations[] = [
                        'file'       => $relative,
                        'line'       => $occ['line'],
                        'capability' => $occ['capability'],
                        'expression' => $occ['expression'],
                    ];
                }
            }
        }

        sort($detectedCapabilities);

        return [
            'detected_capabilities' => $detectedCapabilities,
            'violations'            => $violations,
            'is_valid'              => $violations === [],
        ];
    }

    /**
     * Scan PHP source code and return all capability occurrences.
     *
     * @return array{
     *     detected_capabilities: list<string>,
     *     occurrences: list<array{line: int, capability: string, expression: string}>
     * }
     */
    public function scanSource(string $source, string $filename = ''): array
    {
        try {
            $tokens = PhpToken::tokenize($source);
        } catch (\Throwable) {
            return [
                'detected_capabilities' => [],
                'occurrences'           => [],
            ];
        }

        $occurrences          = [];
        $detectedCapabilities = [];
        $count                = count($tokens);
        $aliases              = $this->functionAliases($tokens, $count);
        $literalFunctions     = $this->literalVariableFunctions($tokens, $count, $aliases);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 1. eval expression
            if ($token->id === T_EVAL) {
                $cap                        = self::CAPABILITY_CODE_EXECUTION;
                $detectedCapabilities[$cap] = true;
                $occurrences[]              = [
                    'line'       => $token->line,
                    'capability' => $cap,
                    'expression' => 'eval(...)',
                ];
                continue;
            }

            // 2. Execution operator (backticks: `command`)
            if ($token->text === '`') {
                $cap                        = self::CAPABILITY_PROCESS;
                $detectedCapabilities[$cap] = true;
                $occurrences[]              = [
                    'line'       => $token->line,
                    'capability' => $cap,
                    'expression' => '`...` (shell backtick)',
                ];
                // Skip inner tokens until closing backtick
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->text === '`') {
                        $i = $j;
                        break;
                    }
                }
                continue;
            }

            // 3. Superglobal variables ($ _ENV, $ _SERVER)
            if ($token->id === T_VARIABLE && isset(self::ENV_SUPERGLOBALS[$token->text])) {
                $cap                        = self::ENV_SUPERGLOBALS[$token->text];
                $detectedCapabilities[$cap] = true;
                $occurrences[]              = [
                    'line'       => $token->line,
                    'capability' => $cap,
                    'expression' => $token->text,
                ];
                continue;
            }

            // 4. Call through a parenthesized constant expression: ('a' . 'b')()
            if ($token->text === '(') {
                $innerIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
                if ($innerIdx !== null
                    && ($tokens[$innerIdx]->id      === T_CONSTANT_ENCAPSED_STRING
                        || $tokens[$innerIdx]->text === '(')
                ) {
                    $closeIdx = $this->matchingParenthesisIndex($tokens, $i, $count);
                    $callIdx  = $closeIdx === null
                        ? null
                        : $this->nextNonWhitespaceIndex($tokens, $closeIdx + 1, $count);

                    if ($closeIdx !== null && $callIdx !== null && $tokens[$callIdx]->text === '(') {
                        $name = $this->evaluateStringExpression(
                            array_slice($tokens, $i + 1, $closeIdx - $i - 1),
                        );
                        $name = $name === null ? null : self::shortName($name);
                        $name = $name === null ? null : ($aliases[$name] ?? $name);

                        if ($name !== null && isset(self::FUNCTION_CAPABILITY_MAP[$name])) {
                            $capabilities = $name === 'fopen'
                                ? $this->fopenCapabilities($tokens, $callIdx, $count)
                                : [self::FUNCTION_CAPABILITY_MAP[$name]];

                            foreach ($capabilities as $cap) {
                                $detectedCapabilities[$cap] = true;
                                $occurrences[]              = [
                                    'line'       => $token->line,
                                    'capability' => $cap,
                                    'expression' => '(...)()',
                                ];
                            }

                            continue;
                        }
                    }
                }
            }

            // 5. Filesystem objects that read without a function call
            if ($token->id === T_NEW) {
                $classIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
                if ($classIdx !== null) {
                    $className = self::shortName($tokens[$classIdx]->text);
                    if (isset(self::FILESYSTEM_CLASS_CAPABILITY[$className])) {
                        $cap                        = self::FILESYSTEM_CLASS_CAPABILITY[$className];
                        $detectedCapabilities[$cap] = true;
                        $occurrences[]              = [
                            'line'       => $token->line,
                            'capability' => $cap,
                            'expression' => 'new ' . $tokens[$classIdx]->text . '()',
                        ];
                    }
                }
                continue;
            }

            // 6. Function calls — unqualified, namespaced or fully-qualified
            if (in_array($token->id, self::CALLABLE_NAME_TOKENS, true)) {
                $fnName     = self::shortName($token->text);
                $callName   = $aliases[$fnName] ?? $fnName;
                $isDispatch = in_array($callName, self::CALL_DISPATCH_FUNCTIONS, true);
                if (!isset(self::FUNCTION_CAPABILITY_MAP[$callName]) && !$isDispatch) {
                    continue;
                }

                // Check if this is a function call: followed by '(' (skipping whitespace/comments)
                $nextIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
                if ($nextIdx === null || $tokens[$nextIdx]->text !== '(') {
                    continue;
                }

                // Skip definitions, method calls, instantiations and static references:
                // function getenv() ... $o->getenv() ... new \getenv() ... MyClass::getenv()
                if (!$this->isPlainCall($tokens, $i)) {
                    continue;
                }

                if ($isDispatch) {
                    $target = $this->literalArgument($tokens, $nextIdx, $count, 0);
                    $target = $target === null ? null : self::shortName($target);
                    $target = $target === null ? null : ($aliases[$target] ?? $target);
                    if ($target === null || !isset(self::FUNCTION_CAPABILITY_MAP[$target])) {
                        continue;
                    }

                    $capabilities = [self::FUNCTION_CAPABILITY_MAP[$target]];
                    $expression   = $token->text . '(' . $target . ')';
                } else {
                    $capabilities = $callName === 'fopen'
                        ? $this->fopenCapabilities($tokens, $nextIdx, $count)
                        : [self::FUNCTION_CAPABILITY_MAP[$callName]];
                    $expression   = $token->text . '()';
                }

                foreach ($capabilities as $cap) {
                    $detectedCapabilities[$cap] = true;
                    $occurrences[]              = [
                        'line'       => $token->line,
                        'capability' => $cap,
                        'expression' => $expression,
                    ];
                }

                continue;
            }

            // 7. Direct call through a string literal: 'shell_exec'(...)
            if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                $nextIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
                if ($nextIdx === null || $tokens[$nextIdx]->text !== '(') {
                    continue;
                }

                $name = $this->unquote($token->text);
                if ($name === null) {
                    continue;
                }

                $name = self::shortName($name);
                $name = $aliases[$name] ?? $name;
                if (!isset(self::FUNCTION_CAPABILITY_MAP[$name])) {
                    continue;
                }

                $capabilities = $name === 'fopen'
                    ? $this->fopenCapabilities($tokens, $nextIdx, $count)
                    : [self::FUNCTION_CAPABILITY_MAP[$name]];

                foreach ($capabilities as $cap) {
                    $detectedCapabilities[$cap] = true;
                    $occurrences[]              = [
                        'line'       => $token->line,
                        'capability' => $cap,
                        'expression' => $token->text . '(...)',
                    ];
                }

                continue;
            }

            // 8. Variable function calls bound to one constant string expression
            if ($token->id === T_VARIABLE && isset($literalFunctions[$token->text])) {
                $fnName  = $literalFunctions[$token->text];
                $nextIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
                if ($nextIdx === null || $tokens[$nextIdx]->text !== '(') {
                    continue;
                }

                if (!isset(self::FUNCTION_CAPABILITY_MAP[$fnName])) {
                    continue;
                }

                $capabilities = $fnName === 'fopen'
                    ? $this->fopenCapabilities($tokens, $nextIdx, $count)
                    : [self::FUNCTION_CAPABILITY_MAP[$fnName]];

                foreach ($capabilities as $cap) {
                    $detectedCapabilities[$cap] = true;
                    $occurrences[]              = [
                        'line'       => $token->line,
                        'capability' => $cap,
                        'expression' => $token->text . '()',
                    ];
                }
            }
        }

        return [
            'detected_capabilities' => array_values(array_keys($detectedCapabilities)),
            'occurrences'           => $occurrences,
        ];
    }

    /**
     * Asserts that a directory satisfies the allowed capabilities.
     *
     * @param list<string> $allowedCapabilities
     * @throws InvalidPluginException on undeclared capability usage.
     */
    public function assertAllowed(string $directory, array $allowedCapabilities = []): void
    {
        $scan = $this->scanDirectory($directory, $allowedCapabilities);
        if (!$scan['is_valid']) {
            $first = $scan['violations'][0];
            $count = count($scan['violations']);
            $msg   = sprintf(
                'Security violation: undeclared capability "%s" used in %s line %d: %s',
                $first['capability'],
                $first['file'],
                $first['line'],
                $first['expression'],
            );

            if ($count > 1) {
                $msg .= sprintf(' (+%d other violation(s))', $count - 1);
            }

            $capabilities = array_values(array_unique(array_column($scan['violations'], 'capability')));
            sort($capabilities);
            $userMessage = count($capabilities) === 1
                ? sprintf('Plugin uses undeclared capability: %s', $capabilities[0])
                : sprintf('Plugin uses undeclared capabilities: %s', implode(', ', $capabilities));

            throw new InvalidPluginException($msg, $userMessage);
        }
    }

    /**
     * Unqualified name of a callable or class name.
     *
     * `\shell_exec` (T_NAME_FULLY_QUALIFIED), `Acme\shell_exec`
     * (T_NAME_QUALIFIED) and `namespace\shell_exec` (T_NAME_RELATIVE) are
     * reduced to their last segment, so a helper shadowing a sensitive global
     * still reports and a leading backslash cannot bypass the lookup.
     */
    private static function shortName(string $name): string
    {
        $name = strtolower($name);
        $pos  = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    private function isPlainCall(array $tokens, int $nameIdx): bool
    {
        $prevIdx = $this->prevNonWhitespaceIndex($tokens, $nameIdx - 1);
        if ($prevIdx === null) {
            return true;
        }

        return !in_array($tokens[$prevIdx]->id, [
            T_FUNCTION,
            T_NEW,
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
        ], true);
    }

    /**
     * `use function Name [as Alias]` imports, mapped alias => imported short name.
     *
     * Group imports (`use function A\{b, c as d}`) resolve the same way: each
     * entry is reduced to its last segment before the lookup.
     *
     * @param list<PhpToken> $tokens
     * @return array<string, string>
     */
    private function functionAliases(array $tokens, int $count): array
    {
        $aliases = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_USE) {
                continue;
            }

            $functionIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
            if ($functionIdx === null || $tokens[$functionIdx]->id !== T_FUNCTION) {
                continue;
            }

            $import      = null;
            $alias       = null;
            $expectAlias = false;
            $j           = $functionIdx + 1;

            for (; $j < $count; $j++) {
                $token = $tokens[$j];

                if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($token->text === ';' || $token->text === ',') {
                    if ($import !== null) {
                        $aliases[$alias ?? $import] = $import;
                    }

                    $import      = null;
                    $alias       = null;
                    $expectAlias = false;

                    if ($token->text === ';') {
                        break;
                    }
                    continue;
                }

                if ($token->text === '{' || str_ends_with($token->text, '{')) {
                    // Group import: prefixes are irrelevant, the last segment wins.
                    $import      = null;
                    $alias       = null;
                    $expectAlias = false;
                    continue;
                }

                if ($token->text === '}') {
                    continue;
                }

                if ($token->id === T_AS) {
                    $expectAlias = true;
                    continue;
                }

                if (in_array($token->id, self::CALLABLE_NAME_TOKENS, true)) {
                    if ($expectAlias) {
                        $alias       = self::shortName($token->text);
                        $expectAlias = false;
                    } else {
                        $import = self::shortName($token->text);
                    }
                }
            }

            $i = $j;
        }

        return $aliases;
    }

    /**
     * Variables bound to one constant string expression, mapped to the
     * resolved function name.
     *
     * A variable assigned more than one value, or anything that is not a
     * literal concatenation, is dropped: the call target cannot be proven.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $aliases
     * @return array<string, string>
     */
    private function literalVariableFunctions(array $tokens, int $count, array $aliases): array
    {
        $values    = [];
        $ambiguous = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_VARIABLE) {
                continue;
            }

            $equalsIdx = $this->nextNonWhitespaceIndex($tokens, $i + 1, $count);
            if ($equalsIdx === null || $tokens[$equalsIdx]->text !== '=') {
                continue;
            }

            $name       = $tokens[$i]->text;
            $expression = [];

            for ($j = $equalsIdx + 1; $j < $count; $j++) {
                $token = $tokens[$j];

                if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                    || $token->id   === T_CONSTANT_ENCAPSED_STRING
                    || $token->text === '.'
                ) {
                    $expression[] = $token;
                    continue;
                }

                break;
            }

            $value = $this->evaluateStringExpression($expression);
            if ($value === null) {
                $ambiguous[$name] = true;
                continue;
            }

            $value = self::shortName($value);
            $value = $aliases[$value] ?? $value;

            if (isset($values[$name]) && $values[$name] !== $value) {
                $ambiguous[$name] = true;
            }

            $values[$name] = $value;
        }

        foreach (array_keys($ambiguous) as $name) {
            unset($values[$name]);
        }

        return $values;
    }

    /**
     * Value of a constant string expression (`'a' . 'b'`), or null when it is
     * not statically known.
     *
     * @param list<PhpToken> $tokens
     */
    private function evaluateStringExpression(array $tokens): ?string
    {
        $value       = '';
        $sawValue    = false;
        $expectValue = true;

        foreach ($tokens as $token) {
            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($expectValue) {
                if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                    return null;
                }

                $part = $this->unquote($token->text);
                if ($part === null) {
                    return null;
                }

                $value      .= $part;
                $sawValue    = true;
                $expectValue = false;
                continue;
            }

            if ($token->text !== '.') {
                return null;
            }

            $expectValue = true;
        }

        return $sawValue && !$expectValue ? $value : null;
    }

    /**
     * Contents of a string literal, or null when it cannot be unquoted safely.
     */
    private function unquote(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        $body  = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        if (str_contains($body, '$') || str_contains($body, '\\')) {
            return null;
        }

        return $body;
    }

    /**
     * Capabilities required by an fopen() call, derived from its mode argument.
     *
     * A non-literal mode is treated as read+write: the scanner cannot prove it
     * is read-only.
     *
     * @param list<PhpToken> $tokens
     * @return list<string>
     */
    private function fopenCapabilities(array $tokens, int $openParenIdx, int $count): array
    {
        $mode = $this->literalArgument($tokens, $openParenIdx, $count, 1);

        if ($mode === null) {
            return [self::CAPABILITY_FILESYSTEM_READ, self::CAPABILITY_FILESYSTEM_WRITE];
        }

        $read  = $mode === '' || $mode[0] === 'r' || str_contains($mode, '+');
        $write = strpbrk($mode, 'waxc+') !== false;

        return array_values(array_filter([
            $read ? self::CAPABILITY_FILESYSTEM_READ : null,
            $write ? self::CAPABILITY_FILESYSTEM_WRITE : null,
        ]));
    }

    /**
     * Value of a constant string argument, or null when it is not static.
     *
     * An empty string means the argument is absent (fopen()'s default mode).
     *
     * @param list<PhpToken> $tokens
     */
    private function literalArgument(array $tokens, int $openParenIdx, int $count, int $position): ?string
    {
        $arguments = $this->splitArguments($tokens, $openParenIdx, $count);

        if (!array_key_exists($position, $arguments)) {
            return '';
        }

        return $this->evaluateStringExpression($arguments[$position]);
    }

    private function matchingParenthesisIndex(array $tokens, int $openIdx, int $count): ?int
    {
        $depth = 0;

        for ($i = $openIdx; $i < $count; $i++) {
            if ($tokens[$i]->text === '(') {
                $depth++;
                continue;
            }

            if ($tokens[$i]->text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Top-level arguments of a call, as token lists.
     *
     * @param list<PhpToken> $tokens
     * @return list<list<PhpToken>>
     */
    private function splitArguments(array $tokens, int $openParenIdx, int $count): array
    {
        $arguments = [];
        $current   = [];
        $depth     = 0;

        for ($i = $openParenIdx; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                if ($depth > 1) {
                    $current[] = $tokens[$i];
                }
                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    $arguments[] = $current;
                    break;
                }
                $current[] = $tokens[$i];
                continue;
            }

            if ($text === ',' && $depth === 1) {
                $arguments[] = $current;
                $current     = [];
                continue;
            }

            if ($depth >= 1) {
                $current[] = $tokens[$i];
            }
        }

        return $arguments;
    }

    private function nextNonWhitespaceIndex(array $tokens, int $start, int $count): ?int
    {
        for ($i = $start; $i < $count; $i++) {
            $id = $tokens[$i]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $i;
            }
        }

        return null;
    }

    private function prevNonWhitespaceIndex(array $tokens, int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--) {
            $id = $tokens[$i]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $i;
            }
        }

        return null;
    }
}
