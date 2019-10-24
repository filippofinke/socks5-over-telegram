<?php
/**
 * Socks server.
 * 
 * Filippo Finke
 */

use Clue\React\Socks\Server;
use React\Socket\Server as Socket;
require __DIR__ . '/../vendor/autoload.php';

if(count($argv) != 3) {
    echo "ℹ️ Usage: php ".$argv[0].".php HOST:PORT USERNAME:PASSWORD" . PHP_EOL;
    exit;
}

[$username, $password] = explode(":", $argv[2]);
$loop = React\EventLoop\Factory::create();
$server = new Server($loop, null, array(
    $username => $password
));
$socket = new Socket($argv[1], $loop);
$server->listen($socket);
echo '⌛ SOCKS5 server requiring authentication listening on ' . $socket->getAddress() . PHP_EOL;
$loop->run();