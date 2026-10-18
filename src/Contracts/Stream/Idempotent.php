<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

/** A handler whose replay changes nothing by itself: it runs without the consumption guard. */
interface Idempotent {}
