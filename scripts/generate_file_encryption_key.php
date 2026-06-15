<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

echo 'APP_FILE_ENCRYPTION_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
