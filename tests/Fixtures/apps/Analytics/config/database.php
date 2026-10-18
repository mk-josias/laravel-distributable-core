<?php

declare(strict_types=1);

return [
    'default' => 'analytics',
    'connections' => [
        'analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'warehouse' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
