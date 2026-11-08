<?php

/**
 * Dependency security scanner for Vale.
 * Runs Composer audit and, if present, npm audit.
 */

$repoRoot = dirname(__DIR__);
chdir($repoRoot);

function writeLabel($text)
{
    echo "\n$text\n";
    echo str_repeat('=', strlen($text)) . "\n";
}

function writeStatus($level, $text)
{
    switch ($level) {
        case 'Error':   echo "[ERROR] $text\n";
            break;
        case 'Warning': echo "[WARN ] $text\n";
            break;
        case 'Info':    echo "[INFO ] $text\n";
            break;
        default:        echo "$text\n";
            break;
    }
}

function normalizeSeverity($rawSeverity)
{
    if (!$rawSeverity) {
        return 'unknown';
    }
    switch (strtolower((string)$rawSeverity)) {
        case 'critical': return 'critical';
        case 'high':     return 'high';
        case 'medium':
        case 'moderate': return 'moderate';
        case 'low':      return 'low';
        default:         return 'unknown';
    }
}

function createFinding($tool, $package, $version, $title, $severity, $url, $cve)
{
    return [
        'Tool'     => $tool,
        'Package'  => $package,
        'Version'  => $version,
        'Title'    => $title,
        'Severity' => $severity,
        'URL'      => $url,
        'CVE'      => $cve,
    ];
}

function parseComposerAdvisories($json)
{
    $findings = [];
    if (!$json) {
        return $findings;
    }

    $data = isset($json['advisories']) ? $json['advisories'] : (isset($json['vulnerabilities']) ? $json['vulnerabilities'] : []);

    foreach ($data as $packageName => $advisories) {
        if (!is_array($advisories)) {
            $advisories = [$advisories];
        }
        foreach ($advisories as $adv) {
            $severity = normalizeSeverity($adv['severity'] ?? $adv['severity_level'] ?? 'unknown');
            $package  = $adv['packageName']          ?? $adv['package'] ?? $adv['package_name'] ?? $adv['name'] ?? $packageName;
            $title    = $adv['title']                ?? $adv['name'] ?? $adv['message'] ?? 'No title provided';
            $url      = $adv['link']                 ?? $adv['url'] ?? '';
            $version  = $adv['affectedVersions']     ?? $adv['affected_versions'] ?? $adv['version'] ?? '';
            $cve      = is_array($adv['cve'] ?? null) ? implode(', ', $adv['cve']) : ($adv['cve'] ?? '');

            $findings[] = createFinding('composer', $package, $version, $title, $severity, $url, $cve);
        }
    }
    return $findings;
}

function parseNpmAdvisories($json)
{
    $findings = [];
    if (!$json) {
        return $findings;
    }

    $data = isset($json['advisories']) ? $json['advisories'] : (isset($json['vulnerabilities']) ? $json['vulnerabilities'] : []);

    foreach ($data as $key => $adv) {
        $severity = normalizeSeverity($adv['severity'] ?? 'unknown');
        $package  = $adv['name']     ?? $adv['module_name'] ?? $adv['package'] ?? $key;
        $title    = $adv['title']    ?? $adv['overview'] ?? 'No title provided';
        $url      = $adv['url']      ?? '';

        $version = '';
        if (isset($adv['findings']) && is_array($adv['findings']) && count($adv['findings']) > 0) {
            $version = $adv['findings'][0]['version'] ?? '';
        } else {
            $version = $adv['vulnerable_versions'] ?? $adv['range'] ?? '';
        }

        $cve = is_array($adv['cves'] ?? null) ? implode(', ', $adv['cves']) : ($adv['cves'] ?? '');

        $findings[] = createFinding('npm', $package, $version, $title, $severity, $url, $cve);
    }

    return $findings;
}

function invokeComposerAudit()
{
    writeLabel('Composer audit');
    if (!file_exists('composer.json')) {
        writeStatus('Info', 'composer.json not found; skipping Composer audit.');
        return [];
    }

    $output = shell_exec('composer audit --locked --format=json --no-interaction 2>&1');
    if (!$output || !trim($output)) {
        writeStatus('Warning', 'Composer audit returned no output.');
        return [];
    }

    // Try to find the start of the JSON object, as npm might prepend or append non-JSON text (like update notices)
    $jsonStart = strpos($output, '{');
    $jsonEnd   = strrpos($output, '}');

    if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
        $jsonStr = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
        $json    = json_decode($jsonStr, true);
    } else {
        $json = null;
    }

    if ($json === null) {
        if (str_contains($output, 'No security vulnerabilities found') || str_contains($output, 'No packages - skipping audit')) {
            writeStatus('Info', 'Composer audit completed: no vulnerabilities found or no packages to scan.');
            return [];
        }
        writeStatus('Error', 'Unable to parse Composer audit JSON output.');
        echo $output;
        exit(2);
    }

    $findings = parseComposerAdvisories($json);
    if (empty($findings)) {
        writeStatus('Info', 'Composer audit completed: no vulnerabilities found.');
    } else {
        writeStatus('Info', "Composer audit discovered " . count($findings) . " vulnerability(ies).");
    }
    return $findings;
}

function invokeNpmAudit()
{
    writeLabel('npm audit');
    if (!file_exists('package.json')) {
        writeStatus('Info', 'package.json not found; skipping npm audit.');
        return [];
    }

    $output = shell_exec('npm audit --json 2>&1');
    if (!$output || !trim($output)) {
        writeStatus('Warning', 'npm audit returned no output.');
        return [];
    }

    // Extract the JSON payload (npm audit may prepend or append non-JSON warnings/notices)
    $jsonStart = strpos($output, '{');
    $jsonEnd   = strrpos($output, '}');

    if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
        $jsonStr = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
        $json    = json_decode($jsonStr, true);
    } else {
        $json = null;
    }

    if ($json === null) {
        writeStatus('Warning', 'Unable to parse npm audit JSON output (likely npm registry warning or connectivity issue).');
        echo $output;
        return [];
    }

    if (isset($json['error'])) {
        $errMsg = $json['error']['summary'] ?? $json['error']['message'] ?? 'npm audit returned an error';

        // Distinguish between temporary issues and actual errors
        if (str_contains($errMsg, 'connectivity') || str_contains($errMsg, 'timeout') || str_contains($errMsg, 'ENOTFOUND') || str_contains($errMsg, 'network')) {
            writeStatus('Warning', "npm audit temporary issue: $errMsg");
            return [];  // SOFT FAIL - Temporary issue, allow build to continue
        } else {
            writeStatus('Error', "npm audit error: $errMsg");
            exit(2);  // HARD FAIL - Actual problem with npm audit or configuration
        }
    }

    $findings = parseNpmAdvisories($json);
    if (empty($findings)) {
        if (isset($json['metadata']['vulnerabilities']['total']) && $json['metadata']['vulnerabilities']['total'] === 0) {
            writeStatus('Info', 'npm audit completed: no vulnerabilities found.');
            return [];
        }
        writeStatus('Info', 'npm audit completed: no vulnerabilities found or no detailed advisories returned.');
    } else {
        writeStatus('Info', "npm audit discovered " . count($findings) . " vulnerability(ies).");
    }
    return $findings;
}

$allFindings = [];
$allFindings = array_merge($allFindings, invokeComposerAudit());
$allFindings = array_merge($allFindings, invokeNpmAudit());

if (empty($allFindings)) {
    writeStatus('Info', 'Dependency scan completed successfully: no vulnerabilities found.');
    exit(0);
}

writeLabel('Vulnerability Summary');
// Sort findings
usort($allFindings, function ($a, $b) {
    $sevOrder = ['critical' => 0, 'high' => 1, 'moderate' => 2, 'low' => 3, 'unknown' => 4];
    $aS       = $sevOrder[$a['Severity']] ?? 5;
    $bS       = $sevOrder[$b['Severity']] ?? 5;
    if ($aS !== $bS) {
        return $aS <=> $bS;
    }
    return $a['Package'] <=> $b['Package'];
});

// Print table-like output
printf("%-10s | %-10s | %-20s | %-10s | %-30s\n", 'Tool', 'Severity', 'Package', 'Version', 'Title');
echo str_repeat('-', 90) . "\n";
foreach ($allFindings as $f) {
    printf(
        "%-10s | %-10s | %-20s | %-10s | %-30s\n",
        $f['Tool'],
        strtoupper($f['Severity']),
        substr($f['Package'], 0, 20),
        substr($f['Version'], 0, 10),
        substr($f['Title'], 0, 30)
    );
}

$criticalCount = 0;
$moderateCount = 0;
$lowCount      = 0;
$unknownCount  = 0;

foreach ($allFindings as $f) {
    if (in_array($f['Severity'], ['critical', 'high'])) {
        $criticalCount++;
    } elseif ($f['Severity'] === 'moderate') {
        $moderateCount++;
    } elseif ($f['Severity'] === 'low') {
        $lowCount++;
    } else {
        $unknownCount++;
    }
}

echo "\n";
writeStatus('Info', "Summary: $criticalCount critical/high, $moderateCount moderate, $lowCount low, $unknownCount unknown.");

if ($criticalCount > 0) {
    writeStatus('Error', 'Critical/high vulnerabilities were found. Failing the scan.');
    exit(1);
}

if ($moderateCount > 0) {
    writeStatus('Warning', 'Moderate vulnerabilities were found. Review before merging.');
    exit(0);
}

writeStatus('Info', 'Only low or unknown severities were found. Scan passes.');
exit(0);
