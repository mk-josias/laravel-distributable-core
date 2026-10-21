<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Distributable\Tests\Fixtures\Payments\Models\Payment;

/** @implements Handler<FindPayment> */
final class FindPaymentHandler implements Handler
{
    /** @return array{id: int, amount: int}|null */
    public function handle(FindPayment $message): ?array
    {
        $payment = Payment::query()->find($message->id);

        return $payment === null ? null : ['id' => $payment->id, 'amount' => $payment->amount];
    }
}
