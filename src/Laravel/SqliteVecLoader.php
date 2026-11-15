<?php

declare(strict_types=1);

namespace Prism\Graph\Laravel;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use WeakMap;

/**
 * Loads the sqlite-vec extension on named Laravel SQLite connections when they open (G-281).
 *
 * This loads NATIVE code into the PHP process, so it is guarded:
 * - Off unless `prism-graph.sqlite_vec.extension_path` is set, and only from configuration.
 * - The path must be absolute and name an existing regular file; it is resolved once, at boot.
 * - Only the configured connection names are touched, and only if their driver is sqlite.
 * - It needs PHP 8.4 (Pdo\Sqlite::loadExtension); configured on an older PHP, boot fails loudly.
 *
 * A load that fails throws, so the connection is unusable rather than silently missing the extension.
 */
final class SqliteVecLoader
{
    /** @var WeakMap<object, true> PDO handles already loaded */
    private WeakMap $loaded;

    /** @param list<string> $connections */
    private function __construct(private readonly string $path, private readonly array $connections)
    {
        $this->loaded = new WeakMap;
    }

    /** @param list<string> $connections */
    public static function make(mixed $path, array $connections): self
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || ! self::isAbsolute($path)) {
            throw new InvalidArgumentException('prism-graph sqlite_vec.extension_path must be an absolute path.');
        }
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new InvalidArgumentException('prism-graph sqlite_vec.extension_path does not name an existing file.');
        }
        if (PHP_VERSION_ID < 80400) {
            throw new InvalidArgumentException('Loading sqlite-vec through PDO needs PHP 8.4 (Pdo\Sqlite::loadExtension).');
        }
        $names = array_values(array_unique(array_filter($connections, fn ($name) => is_string($name) && $name !== '')));
        if ($names === []) {
            throw new InvalidArgumentException('prism-graph sqlite_vec.connections must name at least one connection.');
        }

        return new self($real, $names);
    }

    /** @return list<string> */
    public function connections(): array
    {
        return $this->connections;
    }

    public function load(Connection $connection): void
    {
        if (! in_array($connection->getName(), $this->connections, true)) {
            return;
        }
        if ($connection->getDriverName() !== 'sqlite') {
            throw new InvalidArgumentException("prism-graph sqlite_vec is configured for [{$connection->getName()}], which is not a sqlite connection.");
        }
        $pdo = $connection->getPdo();
        if (isset($this->loaded[$pdo])) {
            return;
        }
        if (! $pdo instanceof \Pdo\Sqlite) {
            throw new InvalidArgumentException("prism-graph sqlite_vec needs a Pdo\\Sqlite connection for [{$connection->getName()}] (PHP 8.4).");
        }
        $pdo->loadExtension($this->path);
        $this->loaded[$pdo] = true;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, '\\\\');
    }
}
