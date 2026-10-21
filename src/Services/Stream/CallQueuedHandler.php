<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Data\Envelope;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\CallQueuedListener;

/** Laravel's queued listener for a stream handler: the worker runs it through the Dispatcher, as a consumer would. */
final class CallQueuedHandler extends CallQueuedListener
{
    /**
     * @param  class-string  $class
     * @param  string  $method
     * @param  array<int, mixed>  $data
     * @param  array<string, mixed>  $envelope
     */
    public function __construct($class, $method, $data, public array $envelope, public ?string $module)
    {
        parent::__construct($class, $method, $data);
    }

    public function handle(Container $container): void
    {
        $container->make(Dispatcher::class)->handle($this->class, Envelope::fromArray($this->envelope), $this->module);
    }
}
