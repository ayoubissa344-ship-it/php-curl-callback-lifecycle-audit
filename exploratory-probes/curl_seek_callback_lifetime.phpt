--TEST--
Seek callback receiver remains alive while clearing its own callback
--EXTENSIONS--
curl
--FILE--
<?php
include 'server.inc';
$host = curl_cli_server_start();

class SeekCallbackLifetimeProbe
{
    public string $marker = 'alive';

    public function seek($handle, int $position, int $origin): int
    {
        echo "seek-begin\n";
        curl_setopt($handle, CURLOPT_SEEKFUNCTION, null);
        gc_collect_cycles();
        echo "marker:", $this->marker, "\n";
        echo "seek-end\n";
        return CURL_SEEKFUNC_OK;
    }

    public function __destruct()
    {
        echo "destroyed\n";
    }
}

$body = 'callback-lifetime-test';
$offset = 0;
$ch = curl_init("{$host}/get.inc?test=redirect");
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_READFUNCTION, function ($handle, $fd, int $length) use ($body, &$offset) {
    $chunk = substr($body, $offset, $length);
    $offset += strlen($chunk);
    return $chunk;
});
// Deliberately avoid another userland reference to the receiver: the FCC owns it.
curl_setopt($ch, CURLOPT_SEEKFUNCTION, [new SeekCallbackLifetimeProbe(), 'seek']);
$response = curl_exec($ch);
var_dump($response !== false);
?>
--EXPECT--
seek-begin
marker:alive
seek-end
destroyed
bool(true)
