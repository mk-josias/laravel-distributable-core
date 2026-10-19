<?php

declare(strict_types=1);

namespace Distributable\Contracts\Rpc;

/** A transport that tells `modules:doctor` what stops its calls from working. */
interface ReportsRpcProblems
{
    /** @return list<string> */
    public function problems(): array;
}
