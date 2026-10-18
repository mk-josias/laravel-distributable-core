<?php

declare(strict_types=1);

namespace Apps\Analytics\Reports;

use Illuminate\Support\Facades\DB;

/** Analytics code that opens a connection by its name. */
final class UsesConnection
{
    public function open(string $name): string
    {
        return DB::connection($name)->getName();
    }
}
