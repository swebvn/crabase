<?php

declare(strict_types=1);

namespace app\model;

use app\service\Store;
use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use Crab\Contracts\Database\Query as QueryContract;

/**
 * Small application-owned record wrapper around Crab's query builder.
 *
 * It intentionally keeps the old model-facing API used by the application
 * tests, while all SQL and transactions go through Crab contracts.
 */
abstract class Model implements ArrayAccess
{
    protected $table = '';
    protected $primaryKey = 'id';
    protected $incrementing = true;
    /** @var list<string> */
    protected $fillable = [];
    /** @var list<string> */
    protected $hidden = [];
    /** @var array<string, string> */
    protected $casts = [];
    /** @var list<string> Columns stored as SQLite/MySQL integer booleans. */
    protected $booleans = [];
    /** @var array<string, mixed> */
    protected array $attributes = [];
    /** @var array<string, mixed> */
    protected array $relations = [];
    protected bool $exists = false;

    /** @param array<string, mixed> $attributes */
    final public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->exists = $exists;
        $this->fillRaw($attributes);
    }

    public static function query(): ModelQuery
    {
        return new ModelQuery(static::class);
    }

    public static function tableName(): string { return (new static())->table; }
    public static function keyName(): string { return (new static())->primaryKey; }
    public function fillableNames(): array { return $this->fillable; }
    public function incrementingValue(): bool { return $this->incrementing; }
    public function databaseValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (($this->casts[$key] ?? null) === 'array') $values[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            elseif (in_array($key, $this->booleans, true)) $values[$key] = (int) (bool) $value;
        }
        return $values;
    }
    public function fillValues(array $values, bool $raw = false): void
    {
        foreach ($values as $key => $value) $this->attributes[$key] = $raw ? $this->castIn((string) $key, $value) : $value;
    }
    public function markExists(): void { $this->exists = true; }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new \InvalidArgumentException(static::class.' record not found.');
    }

    public static function all(): ModelCollection
    {
        return static::query()->get();
    }

    public static function create(array $values): static
    {
        return static::query()->create($values);
    }

    public static function where(string $column, mixed $operator = null, mixed $value = null): ModelQuery
    {
        $query = static::query();
        return func_num_args() === 2 ? $query->where($column, $operator) : $query->where($column, $operator, $value);
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }
        if (method_exists($this, $name)) {
            return $this->relations[$name] = $this->{$name}();
        }
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $this->castIn($name, $value);
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }
            $result[$key] = $this->castOut($key, $value);
        }
        foreach ($this->relations as $key => $value) {
            $result[$key] = $value instanceof ModelCollection ? $value->toArray() : ($value instanceof self ? $value->toArray() : $value);
        }
        return $result;
    }

    public function update(array $values): bool
    {
        $values = $this->filter($values);
        if ($values === []) {
            return true;
        }
        $affected = Store::connection()->table($this->table)
            ->where($this->primaryKey, $this->getKey())
            ->update($this->castValues($values));
        if ($affected > 0) {
            $this->fillRaw($values);
        }
        return $affected > 0;
    }

    public function delete(): bool
    {
        $affected = Store::connection()->table($this->table)->where($this->primaryKey, $this->getKey())->delete();
        if ($affected > 0) {
            $this->exists = false;
        }
        return $affected > 0;
    }

    public function fresh(): ?static
    {
        return static::find($this->getKey());
    }

    /** @param array<string, mixed> $attributes */
    private function fillRaw(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $this->castIn((string) $key, $value);
        }
    }

    /** @param array<string, mixed> $values */
    private function filter(array $values): array
    {
        if ($this->fillable === []) {
            return $values;
        }
        return array_intersect_key($values, array_fill_keys($this->fillable, true));
    }

    /** @param array<string, mixed> $values */
    private function castValues(array $values): array
    {
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $values[$key] = in_array($key, $this->booleans, true)
                ? (int) (bool) $value
                : (($this->casts[$key] ?? null) === 'array' ? json_encode($value, JSON_THROW_ON_ERROR) : $value);
        }
        return $values;
    }

    private function castIn(string $key, mixed $value): mixed
    {
        if (in_array($key, $this->booleans, true)) return (int) (bool) $value;
        return match ($this->casts[$key] ?? null) {
            'array' => is_string($value) ? (json_decode($value, true) ?? []) : $value,
            'bool', 'boolean' => (bool) $value,
            'int', 'integer' => (int) $value,
            default => $value,
        };
    }

    private function castOut(string $key, mixed $value): mixed
    {
        return ($this->casts[$key] ?? null) === 'array' && is_string($value)
            ? (json_decode($value, true) ?? [])
            : $value;
    }

    public function offsetExists(mixed $offset): bool { return array_key_exists((string) $offset, $this->attributes); }
    public function offsetGet(mixed $offset): mixed { return $this->__get((string) $offset); }
    public function offsetSet(mixed $offset, mixed $value): void { $this->__set((string) $offset, $value); }
    public function offsetUnset(mixed $offset): void { unset($this->attributes[(string) $offset]); }
}

final class ModelCollection implements Countable, IteratorAggregate
{
    /** @param list<Model> $items */
    public function __construct(private array $items) {}
    public function all(): array { return $this->items; }
    public function count(): int { return count($this->items); }
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
    public function toArray(): array { return array_map(static fn(Model $item): array => $item->toArray(), $this->items); }
    public function first(): ?Model { return $this->items[0] ?? null; }
    public function reverse(): self { return new self(array_reverse($this->items)); }
    public function values(): self { return new self(array_values($this->items)); }
    public function pluck(string $column, ?string $key = null): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $value = $item->{$column};
            if ($key === null) $result[] = $value;
            else $result[$item->{$key}] = $value;
        }
        return $result;
    }
}

final class ModelQuery
{
    private QueryContract $query;
    /** @param class-string<Model> $model */
    public function __construct(private string $model)
    {
        $this->query = Store::connection()->table($model::tableName());
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'whereKey') {
            return $this->where($this->model::keyName(), $arguments[0] ?? null);
        }
        foreach ($arguments as $index => $argument) {
            if ($argument instanceof self) $arguments[$index] = $argument->rawQuery();
        }
        $result = $this->query->{$name}(...$arguments);
        return $result instanceof QueryContract ? $this : $result;
    }

    public function rawQuery(): QueryContract { return $this->query; }

    public function from(string $table): self
    {
        $this->query = Store::connection()->table($table);
        return $this;
    }

    public function select(array|string ...$columns): self
    {
        $flat = [];
        foreach ($columns as $column) foreach ((array) $column as $value) $flat[] = $value;
        $this->query->select(...$flat);
        return $this;
    }

    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) $this->query->where($column, $operator);
        else $this->query->where($column, $operator, $value);
        return $this;
    }

    public function get(array|string $columns = []): ModelCollection
    {
        if ($columns !== []) $this->select($columns);
        return new ModelCollection(array_map(fn(array $row): Model => new ($this->model)($row, true), $this->query->get()));
    }

    public function first(array|string $columns = []): ?Model
    {
        if ($columns !== []) $this->select($columns);
        $row = $this->query->first();
        return $row === null ? null : new ($this->model)($row, true);
    }

    public function find(int|string $id, array|string $columns = []): ?Model
    {
        return $this->where($this->model::keyName(), $id)->first($columns);
    }

    public function findOrFail(int|string $id, array|string $columns = []): Model
    {
        return $this->find($id, $columns) ?? throw new \InvalidArgumentException($this->model.' record not found.');
    }

    public function create(array $values): Model
    {
        $instance = new ($this->model)();
        $values = array_intersect_key($values, array_fill_keys($instance->fillableNames(), true));
        $id = $this->query->insert($instance->databaseValues($values));
        if (!array_key_exists($instance->keyName(), $values) && $instance->incrementingValue()) {
            $values[$instance->keyName()] = $id;
        }
        $instance->fillValues($values, true);
        $instance->markExists();
        return $instance;
    }

}
