<?php

declare(strict_types=1);

return ['guards' => ['iam_guard' => ['driver' => 'session', 'provider' => 'iam_provider']], 'providers' => ['iam_provider' => ['driver' => 'eloquent', 'model' => 'Apps\\Iam\\Models\\User']]];
