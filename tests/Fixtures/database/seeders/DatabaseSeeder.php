<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** The application's DatabaseSeeder: it notes the connection each run lands on. */
final class DatabaseSeeder extends Seeder
{
    /** @var list<string> */
    public static array $ran = [];

    public function run(): void
    {
        self::$ran[] = DB::getDefaultConnection();
    }
}
