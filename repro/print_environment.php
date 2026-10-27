<?php
declare(strict_types=1);

if (!extension_loaded('curl')) {
    fwrite(STDERR, "ERROR: PHP curl extension is not loaded\n");
    exit(77);
}
$v = curl_version();
echo 'PHP=', PHP_VERSION, PHP_EOL;
echo 'libcurl=', $v['version'] ?? 'unknown', PHP_EOL;
echo 'HTTP2=', (($v['features'] & CURL_VERSION_HTTP2) !== 0 ? 'yes' : 'no'), PHP_EOL;
echo 'PROTOCOLS=', implode(',', $v['protocols'] ?? []), PHP_EOL;
echo 'PUSH_CONSTANT=', (defined('CURLMOPT_PUSHFUNCTION') ? 'available' : 'missing'), PHP_EOL;
echo 'HTTP2_PRIOR_KNOWLEDGE_CONSTANT=', (defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') ? 'available' : 'missing'), PHP_EOL;
