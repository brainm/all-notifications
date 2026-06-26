<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';
[$config, $pdo] = initWebApp();
logoutUser($config);
header('Location: login.php');
