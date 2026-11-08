<?php

$projectRoot = dirname(__DIR__, 2);

$finder = PhpCsFixer\Finder::create()
    ->in([
        $projectRoot . '/app',
        $projectRoot . '/src',
        $projectRoot . '/tests',
        $projectRoot . '/config',
        $projectRoot . '/bootstrap',
        $projectRoot . '/database',
        $projectRoot . '/public',
        $projectRoot . '/scripts',
    ])
    ->exclude([
        'vendor',
        'storage',
        'node_modules',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'binary_operator_spaces' => ['default' => 'align_single_space'],
        'braces' => [
            'position_after_control_structures' => 'same',
            'position_after_functions_and_oop_constructs' => 'next',
            'allow_single_line_closure' => false,
        ],
        'elseif' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['imports_order' => ['const', 'class', 'function']],
        'single_line_comment_style' => ['comment_types' => ['hash']],
    ])
    ->setFinder($finder);
