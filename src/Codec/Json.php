<?php

declare(strict_types=1);

namespace Flezha\Codec;

use Flezha\Codable;
use Flezha\Exception\FlezhaException;
use JsonException;

/**
 * The JSON codec for durable `State` and `Change` bodies.
 *
 * JSON, not PHP's `serialize()`: the durable bytes are read back after a
 * restart, a restore fold and a hot-swap migration, and `serialize()` embeds
 * fully-qualified class names and mangles private property names, so a class
 * rename breaks the restore of every record already written. It is also what
 * `flz state at` shows a user, and what a migration handover carries —
 * so the durable form, the live read and the handover are one encoding rather
 * than three.
 *
 * The type mapping is the JSON one, stated rather than discovered:
 *
 * | PHP                  | JSON      | Note                                    |
 * | -------------------- | --------- | --------------------------------------- |
 * | `int`, `float`       | number    | `1.0` keeps its fraction                 |
 * | `string`             | string    | must be valid UTF-8; bytes need a codec  |
 * | `bool`, `null`       | as-is     |                                          |
 * | list array           | array     |                                          |
 * | map array            | object    | keys come back as strings                |
 * | {@see Codable}       | object    | through `toArray()` / `fromArray()`      |
 *
 * An object that is not `Codable` has no durable form, and saying so at
 * registration is the whole point of {@see assertRepresentable()}: the
 * alternative is learning it at the first restart.
 */
final class Json
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;

    private function __construct()
    {
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::plain($value), self::FLAGS);
    }

    public static function decode(string $bytes): mixed
    {
        if ($bytes === '') {
            return null;
        }

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A value the codec cannot carry, named by the declaring type and the path
     * to the offending field.
     *
     * Walked once at registration against what the tasklet's initial state
     * produces, so an author learns at startup rather than at the first
     * restore that their state does not survive one.
     */
    public static function assertRepresentable(string $typeName, mixed $sample): void
    {
        self::walk($typeName, $sample, '');
    }

    /** Everything `Codable` collapsed to arrays, ready for `json_encode`. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof Codable) {
            return self::plain($value->toArray());
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }

    private static function walk(string $typeName, mixed $value, string $path): void
    {
        $where = $path === '' ? '<root>' : $path;

        if ($value instanceof Codable) {
            self::walk($typeName, $value->toArray(), $path);

            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::walk($typeName, $item, $path === '' ? (string) $key : "$path.$key");
            }

            return;
        }
        if (is_object($value)) {
            throw self::unrepresentable($typeName, $where, sprintf(
                'an instance of %s has no durable form; implement %s on it, or hold a plain '
                . 'array instead',
                $value::class,
                Codable::class,
            ));
        }
        if (is_resource($value)) {
            throw self::unrepresentable(
                $typeName,
                $where,
                'a resource has no durable form; hold what it names instead',
            );
        }
        if (is_string($value) && !self::isUtf8($value)) {
            throw self::unrepresentable(
                $typeName,
                $where,
                'the string is not valid UTF-8, and JSON carries no byte string; base64 it, or '
                . 'supply a hand-written codec',
            );
        }
        if (is_float($value) && !is_finite($value)) {
            throw self::unrepresentable(
                $typeName,
                $where,
                'JSON has no representation for NAN or INF',
            );
        }
    }

    /** Whether a string is valid UTF-8, which JSON and a text channel both require. */
    public static function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /** A value the durable codec cannot represent, named by the declaring type and the path to it. */
    public static function unrepresentable(string $typeName, string $path, string $reason): FlezhaException
    {
        return new FlezhaException('topology.state_unrepresentable', ['path' => "$typeName.$path", 'reason' => $reason]);
    }

    /** A value sent as JSON, refused naming its sender when it has no JSON form. */
    public static function sent(string $owner, mixed $value): string
    {
        try {
            return self::encode($value);
        } catch (JsonException $e) {
            throw new FlezhaException(
                'runtime.value_not_json',
                ['owner' => $owner, 'type' => get_debug_type($value), 'reason' => $e->getMessage()],
            );
        }
    }

    /** Re-raise an encoding failure naming the type that could not be carried. */
    public static function guard(string $typeName, callable $encode): string
    {
        try {
            return $encode();
        } catch (JsonException $e) {
            throw self::unrepresentable($typeName, '<root>', $e->getMessage());
        }
    }

    /**
     * The JSON Schema a class's typed constructor-promoted properties give, for
     * a relay's API; null for a class with none, so derivation never refuses a
     * class. A property with a default or a nullable type is not required, and
     * one whose type is not a scalar, a backed enum or such a class accepts any
     * value.
     *
     * @param class-string|null $class
     * @return array<string, mixed>|null
     */
    public static function schemaOf(?string $class): ?array
    {
        if ($class === null || !class_exists($class)) {
            return null;
        }
        $schema = self::objectSchema($class, []);

        return is_array($schema) && $schema['properties'] instanceof \stdClass ? null : $schema;
    }

    /**
     * @param class-string $class
     * @param array<string, true> $open the classes being read, so one that contains itself stops
     * @return array<string, mixed>|\stdClass
     */
    private static function objectSchema(string $class, array $open): array|\stdClass
    {
        if (isset($open[$class])) {
            return new \stdClass();
        }
        $open[$class] = true;
        $properties = [];
        $required = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }
            $properties[$parameter->getName()] = self::typeSchema($parameter->getType(), $open);
            if (!$parameter->isOptional() && !$parameter->allowsNull()) {
                $required[] = $parameter->getName();
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];

        return $required === [] ? $schema : $schema + ['required' => $required];
    }

    /**
     * @param array<string, true> $open
     * @return array<string, mixed>|\stdClass
     */
    private static function typeSchema(?\ReflectionType $type, array $open): array|\stdClass
    {
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        return match (true) {
            $name === 'string' => ['type' => 'string'],
            $name === 'int' => ['type' => 'integer'],
            $name === 'float' => ['type' => 'number'],
            $name === 'bool' => ['type' => 'boolean'],
            is_subclass_of($name, \BackedEnum::class) => [
                'enum' => array_map(static fn (\BackedEnum $case): int|string => $case->value, $name::cases()),
            ],
            class_exists($name) => self::objectSchema($name, $open),
            default => new \stdClass(),
        };
    }
}
