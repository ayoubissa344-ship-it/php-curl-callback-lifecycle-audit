--TEST--
HTTP/2 push callback receiver remains alive while replacing its own callback
--EXTENSIONS--
curl
--SKIPIF--
<?php
include 'skipif-nocaddy.inc';

$curl_version = curl_version();
if ($curl_version['version_number'] < 0x080100) {
    exit("skip: test may crash with curl < 8.1.0");
}
?>
--FILE--
<?php
class PushCallbackLifetimeProbe
{
    private $multi;
    public string $marker = 'alive';

    public function __construct($multi)
    {
        $this->multi = $multi;
    }

    public function push($parent, $pushed, $headers): int
    {
        echo "push-begin\n";
        curl_multi_setopt($this->multi, CURLMOPT_PUSHFUNCTION,
            static function ($parent, $pushed, $headers): int {
                return CURL_PUSH_DENY;
            });
        gc_collect_cycles();
        echo "marker:", $this->marker, "\n";
        echo "push-end\n";
        return CURL_PUSH_OK;
    }

    public function __destruct()
    {
        echo "destroyed\n";
    }
}

$multi = curl_multi_init();
curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
// No external reference to the receiver: the callback FCC owns it.
curl_multi_setopt($multi, CURLMOPT_PUSHFUNCTION, [new PushCallbackLifetimeProbe($multi), 'push']);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://localhost/serverpush');
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($multi, $ch);

$responses = [];
$active = null;
do {
    $status = curl_multi_exec($multi, $active);
    do {
        $info = curl_multi_info_read($multi);
        if (false !== $info && $info['msg'] === CURLMSG_DONE) {
            $handle = $info['handle'];
            if ($handle !== null) {
                $responses[] = curl_multi_getcontent($handle);
                curl_multi_remove_handle($multi, $handle);
            }
        }
    } while ($info);
    if ($active) {
        curl_multi_select($multi, 1.0);
    }
} while (count($responses) !== 2);

curl_multi_close($multi);
var_dump(count($responses) === 2);
?>
--EXPECT--
push-begin
marker:alive
push-end
destroyed
bool(true)
