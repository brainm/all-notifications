<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "Public Key:  {$keys['publicKey']}" . PHP_EOL;
echo "Private Key: {$keys['privateKey']}" . PHP_EOL;
