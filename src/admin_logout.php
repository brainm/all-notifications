<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';
[$config, $pdo] = initWebApp();
logoutAdmin($config);
header('Location: admin.php');
