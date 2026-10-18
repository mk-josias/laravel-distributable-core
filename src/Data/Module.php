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
        public string $foundation,
    ) {}

    /**
     * Every convention derives from the name; a module has a database once a connection named after it exists, in its config/database.php or the root one.
     *
     * @param  string|null  $foundationFolder  its folder and namespace segment under the foundation root, when not its studly name
     * @param  string|null  $path  its own folder (the one holding app/, config/, routes/), when not {modulesPath}/{Name}; '.' is the project
     * @param  string|null  $namespace  the namespace of its app/, when not {modulesNamespace}\{Name}
     */
    public static function fromName(string $name, string $modulesNamespace, string $modulesPath = 'apps', ?string $foundationFolder = null, ?string $path = null, ?string $namespace = null): self
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
            hasDatabase: is_file($absolute.'/config/database.php') || config("database.connections.{$name}") !== null,
            foundation: $foundationFolder ?? $studly,
        );
    }

    /** @return array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool, foundation: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'provider' => $this->provider,
            'path' => $this->path,
            'hasDatabase' => $this->hasDatabase,
            'foundation' => $this->foundation,
        ];
    }

    /** @param array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool, foundation: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
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

    /** The `default` of its config/database.php, as the root one names the application's; without it, its name. */
    public function connection(): string
    {
        return $this->databaseKey('default') ?? $this->name;
    }

    /** @return list<string> the connections its code may use: its own, its owner, and each one its config/database.php declares */
    public function connections(): array
    {
        $declared = array_keys((array) ($this->databaseConfig()['connections'] ?? []));

        return array_values(array_unique([$this->connection(), $this->ownerConnection(), ...array_map(strval(...), $declared)]));
    }

    /**
     * The connection the migrate commands and DDL use: the `owner` its config/database.php names, as
     * `default` names the one it runs on; without it, that one. The runtime connection stays
     * least-privileged — and on PostgreSQL, subject to the row-level security policies the owner installs.
     */
    public function ownerConnection(): string
    {
        return $this->databaseKey('owner') ?? $this->connection();
    }

    private function databaseKey(string $key): ?string
    {
        $value = $this->databaseConfig()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> its config/database.php, read once */
    private function databaseConfig(): array
    {
        static $configs = [];
        $file = $this->path().'/config/database.php';

        return $configs[$file] ??= is_file($file) ? (array) require $file : [];
    }
}
