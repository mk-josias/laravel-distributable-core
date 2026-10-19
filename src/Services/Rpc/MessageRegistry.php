<?php

declare(strict_types=1);

namespace Distributable\Services\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Context\ModuleContext;
use Distributable\Contracts\Rpc\Handler;
use Distributable\Contracts\Rpc\Message;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The handler each local module gives its messages (a provider's $rpc). Every transport ends here,
 * so a message behaves the same whether it came from this process or another.
 */
final class MessageRegistry
{
    /** @var array<string, array{module: string, message: class-string<Message>, handler: class-string<Handler<*>>}> */
    private array $handlers = [];

    /** @param array<class-string<Message>, class-string<Handler<*>>> $handlers */
    public function add(string $module, array $handlers): void
    {
        foreach ($handlers as $message => $handler) {
            $rpc = self::describe($message);

            if ($rpc['module'] !== $module) {
                throw ConfigurationException::messageOfAnotherModule($message, $rpc['module'], $module);
            }

            self::assertAnswers($message, $handler);

            $this->handlers[self::key($module, $rpc['name'], $rpc['version'])] = ['module' => $module, 'message' => $message, 'handler' => $handler];
        }
    }

    /** @return array<string, array{module: string, message: class-string<Message>, handler: class-string<Handler<*>>}> */
    public function all(): array
    {
        return $this->handlers;
    }

    public function answers(string $module, string $name, int $version): bool
    {
        return isset($this->handlers[self::key($module, $name, $version)]);
    }

    /**
     * Runs the handler of a message in its module's context and returns the answer as JSON decodes
     * it, the shape a remote caller receives.
     *
     * @param  array<string, mixed>  $payload
     */
    public function answer(string $module, string $name, int $version, array $payload): mixed
    {
        $entry = $this->handlers[self::key($module, $name, $version)]
            ?? throw ModuleException::unknownRpcMessage($module, $name, $version);

        $message = self::build($entry['message'], $payload);
        $container = Container::getInstance();
        $answer = $container->make(ModuleContext::class)->within(
            $container->make(ModuleRegistry::class)->get($module),
            static fn (): mixed => Container::getInstance()->make($entry['handler'])->handle($message),
        );

        return json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  class-string<Message>  $class
     * @return array{module: string, name: string, version: int}
     */
    public static function describe(string $class): array
    {
        $attribute = (new ReflectionClass($class))->getAttributes(Rpc::class)[0] ?? null;

        if ($attribute === null) {
            throw ConfigurationException::messageWithoutRpc($class);
        }

        $rpc = $attribute->newInstance();

        return ['module' => $rpc->module, 'name' => $rpc->name ?? Str::kebab(class_basename($class)), 'version' => $rpc->version];
    }

    /** @return array<string, mixed> the message's properties, validated by its own rules */
    public static function payloadOf(Message $message): array
    {
        return Validator::make(get_object_vars($message), $message::rules())->validate();
    }

    /**
     * @param  class-string<Message>  $class
     * @param  array<string, mixed>  $payload
     */
    public static function build(string $class, array $payload): Message
    {
        return new $class(...Validator::make($payload, $class::rules())->validate());
    }

    /** @param class-string<Message> $message */
    private static function assertAnswers(string $message, string $handler): void
    {
        if (! is_subclass_of($handler, Handler::class)) {
            throw ConfigurationException::handlerWithoutContract($handler);
        }

        $class = new ReflectionClass($handler);
        $type = $class->hasMethod('handle') ? ($class->getMethod('handle')->getParameters()[0] ?? null)?->getType() : null;

        if (! $type instanceof ReflectionNamedType || $type->getName() !== $message) {
            throw ConfigurationException::handlerOfAnotherMessage($handler, $message);
        }
    }

    private static function key(string $module, string $name, int $version): string
    {
        return "{$module}/{$version}/{$name}";
    }
}
