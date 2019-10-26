<?php
/**
 * Filippo Finke
 */

define("MADELINE_BRANCH", "master");

require __DIR__ . '/../vendor/autoload.php';

if (count($argv) != 3) {
    echo "ℹ️ Usage: php ".$argv[0].".php SOCKS:PORT @channel" . PHP_EOL;
    exit;
}
$socks = $argv[1];
$channel = $argv[2];

$settings = [];
$settings['logger']['logger'] = \danog\MadelineProto\Logger::NO_LOGGER;
$MadelineProto = new \danog\MadelineProto\API('telegram_to_socks.madeline', $settings);
$MadelineProto->async(true);

$sockets = [];


$MadelineProto->setCallback(function ($update) use ($socks, &$sockets, $MadelineProto, $channel) {
    $read = function ($id) use (&$sockets, $socks, $MadelineProto, $channel) {
        $socket = $sockets[$id];
        try {
            /**
             * FIX REQUESTS BIGGER THAN 8096 bytes
             */
            //while (($read = yield $socket->read()) !== null) {
            $read = yield $socket->read();
            printf("📡 ID:%d %s -> TELEGRAM  [%d bytes]".PHP_EOL, $id, $socks, strlen($read));
            $filename = uniqid().".txt";
            file_put_contents('storage/'.$filename, $read);
            yield $MadelineProto->messages->sendMedia([
                'peer' => $channel,
                'media' => [
                    '_' => 'inputMediaUploadedDocument',
                    'file' => 'storage/'.$filename
                ],
                'message' => 'socket:'.$id
            ]);
            unlink('storage/'.$filename);
            // }
        } catch (\Amp\ByteStream\ClosedException $e) {
            printf("❌ %s!".PHP_EOL, $e->getMessage());
            $socket->close();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            printf("❌ %s!".PHP_EOL, $e->getMessage());
            $socket->close();
        }
    };

    if ($update["_"] === "updateNewChannelMessage" &&
    !$update["message"]["out"]) {
        $socket_id = str_replace("socket:", "", $update["message"]["message"]);
        $file = yield $MadelineProto->download_to_dir($update, 'storage/');
        $content = file_get_contents($file);
        unlink($file);
        if (!isset($sockets[$socket_id])) {
            $sockets[$socket_id] = yield \Amp\Socket\connect($socks);
        }
        try {
            printf("📡 ID:%d TELEGRAM -> %s  [%d bytes]".PHP_EOL, $socket_id, $socks, strlen($content));
            $write = yield $sockets[$socket_id]->write($content);
            \Amp\asyncCall($read, $socket_id);
        } catch (\Amp\ByteStream\ClosedException $e) {
            printf("❌ %s Socket closed!".PHP_EOL, $socks);
            $sockets[$socket_id]->close();
        }
    }
});
$MadelineProto->loop(function () use ($MadelineProto, $socks) {
    yield $MadelineProto->start();
    $me = yield $MadelineProto->get_self();
    printf("🤖 Logged as @%s [%d]".PHP_EOL, $me["username"], $me["id"]);
    printf("⌛ Waiting for request to forward to %s".PHP_EOL, $socks);
});
$MadelineProto->loop();
