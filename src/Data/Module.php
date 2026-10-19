<?php

declare(strict_types=1);

namespace Distributable\Data;

use Distributable\Exceptions\ModuleException;
use Illuminate\Support\Str;

/**
 * Topology-independent descriptor of one module: identity and conventions only.
 * Whether the module is booted by this process is the registry's knowledge.
 */
final readonly class Module
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $provider,
        private string $path,
        public bool $hasDatabase,
    ) {}

    /**
     * Every convention derives from the name; a module has a database once its config/database.php names its `default`.
     *
     * @param  string|null  $path  its own folder (the one holding app/, config/, routes/), when not {modulesPath}/{Name}; '.' is the project
     * @param  string|null  $namespace  the namespace of its app/, when not {modulesNamespace}\{Name}
     */
    public static function fromName(string $name, string $modulesNamespace, string $modulesPath = 'apps', ?string $path = null, ?string $namespace = null): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw ModuleException::invalidName($name);
        }

        $studly = Str::studly($name);
        $path ??= $modulesPath.'/'.$studly;
        $namespace = trim($namespace ?? $modulesNamespace.'\\'.$studly, '\\');
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return new self(
            name: $name,
            namespace: $namespace,
            provider: $namespace.'\\Providers\\'.class_basename($namespace).'ServiceProvider',
            path: $path,
            hasDatabase: self::stringKey(self::databaseConfigAt($absolute.'/config/database.php'), 'default') !== null,
        );
    }

    /** @return array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'provider' => $this->provider,
            'path' => $this->path,
            'hasDatabase' => $this->hasDatabase,
        ];
    }

    /** @param array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['namespace'], $data['provider'], $data['path'], $data['hasDatabase']);
    }

    /** Stored relative to the project root so a compiled registry stays portable across hosts. */
    public function path(): string
    {
        return str_starts_with($this->path, DIRECTORY_SEPARATOR) ? $this->path : base_path($this->path);
    }

    /**
     * The providers its bootstrap/providers.php lists, as a Laravel application's.
     *
     * @return list<class-string>
     */
    public function providers(): array
    {
        return is_file($this->providersFile()) ? array_values(require $this->providersFile()) : [];
    }

    public function providersFile(): string
    {
        return $this->path().'/bootstrap/providers.php';
    }

    /** The PSR-4 root of the module's classes: {module}/app maps to its namespace. */
    public function classPath(): string
    {
        return $this->path().'/app';
    }

    /** The `default` of its config/database.php, as the root one names the application's. */
    public function defaultConnection(): ?string
    {
        return $this->databaseKey('default');
    }

    /** @return list<string> its default, its owner, and each connection its config/database.php declares */
    public function connections(): array
    {
        $declared = array_map(strval(...), array_keys((array) ($this->databaseConfig()['connections'] ?? [])));

        return array_values(array_unique(array_filter([$this->defaultConnection(), $this->ownerConnection(), ...$declared], is_string(...))));
    }

    /**
     * The connection the migrate commands and DDL use: the `owner` its config/database.php names, as
     * `default` names the one it runs on; without it, that one. The runtime connection stays
     * least-privileged — and on PostgreSQL, subject to the row-level security policies the owner installs.
     */
    public function ownerConnection(): ?string
    {
        return $this->databaseKey('owner') ?? $this->defaultConnection();
    }

    private function databaseKey(string $key): ?string
    {
        return self::stringKey($this->databaseConfig(), $key);
    }

    /** @return array<string, mixed> its config/database.php, read once */
    private function databaseConfig(): array
    {
        return self::databaseConfigAt($this->path().'/config/database.php');
    }

    /** @return array<string, mixed> */
    private static function databaseConfigAt(string $file): array
    {
        static $configs = [];

        return $configs[$file] ??= is_file($file) ? (array) require $file : [];
    }

    /** @param array<string, mixed> $config */
    private static function stringKey(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
