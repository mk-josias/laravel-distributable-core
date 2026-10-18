<?php

declare(strict_types=1);

return [
    'default' => 'iam',
    'connections' => [
        'iam' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
