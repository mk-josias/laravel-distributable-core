<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Handlers;

use Distributable\Contracts\Stream\Handler;
use Illuminate\Support\Facades\DB;

/** Each run leaves a row, so a replay the guard let through would show. */
final class OnReceiptSent implements Handler
{
    public function handle(string $name, array $payload): void
    {
        DB::table('sent_receipts')->insert(['payment_id' => $payload['payment_id']]);
    }
}
