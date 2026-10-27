<?php
/**
 * Local PHP cURL HTTP/2 Server Push callback-replacement probe.
 * Usage: php push_callback_replacement.php control|invalid [url]
 * Run only against the bundled local nghttpd fixture or another server you control.
 */

declare(strict_types=1);
error_reporting(E_ALL);

$mode = $argv[1] ?? 'control';
$url = $argv[2] ?? 'http://127.0.0.1:8080/index.html';
if (!in_array($mode, ['control', 'invalid'], true)) {
    fwrite(STDERR, "Usage: php {$argv[0]} control|invalid [url]\n");
    exit(64);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "ERROR: PHP curl extension is not loaded\n");
    exit(77);
}
if (!defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE')) {
    fwrite(STDERR, "ERROR: PHP/libcurl lacks HTTP/2 prior-knowledge support\n");
    exit(77);
}

$GLOBALS['push_callback_count'] = 0;

class PushCallbackReceiver
{
    public int $calls = 0;

    public function onPush($parent, $pushed, array $headers): int
    {
        ++$this->calls;
        ++$GLOBALS['push_callback_count'];
        echo "CALLBACK_INVOKED\n";
        return CURL_PUSH_OK;
    }

    public function __destruct()
    {
        echo "RECEIVER_DESTROYED\n";
    }
}

$multi = curl_multi_init();
$pipelineResult = curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
echo 'PIPELINING_SET=', var_export($pipelineResult, true), "\n";

$receiver = new PushCallbackReceiver();
$weakReceiver = WeakReference::create($receiver);
$callbackResult = curl_multi_setopt($multi, CURLMOPT_PUSHFUNCTION, [$receiver, 'onPush']);
echo 'REGISTER_RESULT=', var_export($callbackResult, true), "\n";
unset($receiver);
echo 'ALIVE_AFTER_REGISTER=', $weakReceiver->get() !== null ? 'yes' : 'no', "\n";

if ($mode === 'invalid') {
    try {
        // Deliberately invalid replacement. The TypeError is expected for this probe.
        curl_multi_setopt($multi, CURLMOPT_PUSHFUNCTION, null);
        echo "INVALID_SET_RETURNED_WITHOUT_EXCEPTION\n";
    } catch (Throwable $e) {
        echo 'INVALID_SET_EXCEPTION=', get_class($e), "\n";
        echo 'INVALID_SET_MESSAGE=', $e->getMessage(), "\n";
    }
    echo 'ALIVE_AFTER_INVALID_SET=', $weakReceiver->get() !== null ? 'yes' : 'no', "\n";
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE);
$addStatus = curl_multi_add_handle($multi, $ch);
echo "ADD_STATUS={$addStatus}\n";
if ($addStatus !== CURLM_OK) {
    fwrite(STDERR, "ERROR: curl_multi_add_handle failed\n");
    exit(3);
}

$active = 0;
$multiStatus = CURLM_OK;
$deadline = microtime(true) + 10.0;
do {
    do {
        $multiStatus = curl_multi_exec($multi, $active);
    } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

    if ($multiStatus !== CURLM_OK || $active === 0) {
        break;
    }
    if (microtime(true) >= $deadline) {
        echo "TIMEOUT\n";
        break;
    }
    $selected = curl_multi_select($multi, 1.0);
    if ($selected === -1) {
        usleep(100000);
    }
} while (true);

echo 'MULTI_STATUS=', curl_multi_strerror($multiStatus), "\n";
echo 'HTTP_CODE=', curl_getinfo($ch, CURLINFO_RESPONSE_CODE), "\n";
echo 'CURL_ERROR=', curl_error($ch), "\n";
echo 'CALLBACK_COUNT=', $GLOBALS['push_callback_count'], "\n";

curl_multi_remove_handle($multi, $ch);
curl_close($ch);
curl_multi_close($multi);
unset($multi);
gc_collect_cycles();
echo 'ALIVE_AFTER_MULTI_OBJECT_RELEASE=', $weakReceiver->get() !== null ? 'yes' : 'no', "\n";
echo "PROCESS_END\n";
