<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Relay as RelayAttribute;
use Flezha\Codec\Json;
use Flezha\Codec\TypeCodec;
use Flezha\Codec\ValueCodec;
use Flezha\Exception\FlezhaException;
use Flezha\Tasklet\Spec;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * What a declared tasklet resolves to: the scalar declarations, and the one
 * instance whose methods the dispatcher calls.
 *
 * Tasklets hold no state of their own — a cell's state lives in the
 * registry and is handed to each invocation — so one instance per class is
 * enough and the dispatcher never allocates per activation.
 *
 * Which optional hooks a tasklet declares is read from the methods it
 * overrides, not from a second list: `applyChange` overridden means it folds
 * changes, `onTimer` overridden means it may register timers. A declaration
 * and its implementation therefore cannot disagree.
 *
 * The class is a `Tasklet` subclass, or a §5 short form: a relay class
 * whose own `run` and hooks are its one tasklet, named its factory id.
 */
final class Definition
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    public readonly string $name;
    public readonly Spec $decl;
    public readonly Tasklet|Relay $instance;
    public readonly ?ValueCodec $stateCodec;
    public readonly ?ValueCodec $changeCodec;

    /** @var array<string, bool> */
    private readonly array $overrides;

    /** @param class-string<Tasklet>|class-string<Relay> $class */
    private function __construct(public readonly string $class)
    {
        $reflection = new ReflectionClass($class);
        $this->decl = self::declarationOf($reflection);
        $this->name = self::nameOf($reflection, $this->decl);
        $this->overrides = self::hooksOf($class, $this->name);
        $this->stateCodec = $this->decl->stateType === null
            ? null
            : new TypeCodec($this->decl->stateType, 'state');
        $this->changeCodec = $this->decl->changeType === null
            ? null
            : new TypeCodec($this->decl->changeType, 'change');

        $this->refuseContradictions();
        $this->instance = new $class();

        // A state that cannot be encoded cannot be restored, and the first
        // anyone would learn of it is a restart. Walk the declared starting
        // state now.
        if ($this->declares('initState') && $this->stateCodec === null) {
            Json::assertRepresentable("$this->name.initState", $this->instance->initState(null));
        }
    }

    /**
     * The resolved declaration, built once per class: it instantiates the
     * tasklet, and the dispatcher reads it on the hot path.
     *
     * @param class-string<Tasklet>|class-string<Relay> $class
     */
    public static function of(string $class): self
    {
        return self::$cache[$class] ??= new self($class);
    }

    /**
     * `event_time` | `logical` | `wall`, as the core's declaration spells it:
     * read from what the class writes, an `eventTime` override or
     * `logicalClock`.
     */
    public function clock(): string
    {
        return match (true) {
            $this->declares('eventTime') => 'event_time',
            $this->decl->logicalClock => 'logical',
            default => 'wall',
        };
    }

    /** Whether the tasklet overrides one of the base class's hooks. */
    public function declares(string $method): bool
    {
        return $this->overrides[$method] ?? false;
    }

    public function initialState(?string $cellKey): mixed
    {
        return $this->declares('initState') ? $this->instance->initState($cellKey) : null;
    }

    /**
     * Encode a durable State or Change: the type this tasklet declared, or
     * the JSON default.
     *
     * The choice lives here, once, because it is asked at four places — a
     * recorded change, a snapshot, a live read, a migration handover — and a
     * class encoded one way live and another on replay is the failure the
     * whole codec exists to prevent.
     */
    public function encode(string $which, mixed $value): string
    {
        $codec = $this->codecFor($which);

        return $codec !== null
            ? $codec->encode($value)
            : Json::guard($this->name, static fn (): string => Json::encode($value));
    }

    /** The inverse. `''` decodes to null, as an absent body does. */
    public function decode(string $which, string $bytes): mixed
    {
        $codec = $this->codecFor($which);

        return $codec !== null ? $codec->decode($bytes) : Json::decode($bytes);
    }

    /** @return ValueCodec|null the declared codec for `state` or `change` */
    public function codecFor(string $which): ?ValueCodec
    {
        return $which === 'state' ? $this->stateCodec : $this->changeCodec;
    }

    public function foldChange(mixed &$state, mixed $change): void
    {
        $this->instance->applyChange($state, $change);
    }

    /** @return list<string> */
    public function cellsFor(mixed $input): array
    {
        return array_map(strval(...), $this->instance->cellsFor($input));
    }

    public function eventTimeOf(mixed $input): int
    {
        return $this->instance->eventTime($input);
    }

    /**
     * The declaration a class carries, merged **field by field** up its
     * hierarchy.
     *
     * PHP does not inherit attributes, and taking that literally is a trap:
     * `class Sub extends Base {}` would silently come out single-cell with
     * the default mailbox capacity and schema version 1, and nothing would
     * report the difference until the topology was wrong at runtime.
     *
     * Field by field rather than nearest-attribute-wins, because the common
     * case is a subclass that renames the node and changes nothing else —
     * `#[Tasklet\Spec(name: 'stress_b')]` on a child of a static-keyed parent — and
     * whole-attribute inheritance would silently drop the key set exactly
     * there. The one field never inherited is `name`: a node identity is per
     * class, and an inherited one would make two tasklets claim one node.
     *
     * @param ReflectionClass<Tasklet>|ReflectionClass<Relay> $reflection
     */
    private static function declarationOf(ReflectionClass $reflection): Spec
    {
        $chain = [];
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            $chain[] = $class;
        }

        $arguments = [];
        // Ancestor first, so a derived class's own argument wins.
        foreach (array_reverse($chain) as $class) {
            $attributes = $class->getAttributes(Spec::class);
            if ($attributes === []) {
                continue;
            }
            $own = self::namedArguments($attributes[0]);
            if ($class->getName() !== $reflection->getName()) {
                unset($own['name']);
            }
            $arguments = [...$arguments, ...$own];
        }
        foreach (['run', 'durable', ...RelayAttribute::SHORT_FORM_ARGUMENTS] as $relayArgument) {
            if (array_key_exists($relayArgument, $arguments)) {
                throw new FlezhaException('topology.relay_field_on_tasklet', [
                    'tasklet' => $arguments['name'] ?? $reflection->getName(),
                    'fields' => ["`$relayArgument` on #[Tasklet\Spec]"],
                    'instead' => '#[Relay] on a class extending Relay that defines `run`',
                ]);
            }
        }

        return new Spec(...$arguments);
    }

    /**
     * One attribute's written arguments, keyed by parameter name — positional
     * ones included, so an author who writes them that way is merged the same.
     *
     * @param ReflectionAttribute<Spec> $attribute
     * @return array<string, mixed>
     */
    private static function namedArguments(ReflectionAttribute $attribute): array
    {
        $parameters = (new ReflectionClass(Spec::class))->getConstructor()?->getParameters() ?? [];
        $named = [];
        foreach ($attribute->getArguments() as $key => $value) {
            $name = is_int($key) ? ($parameters[$key]?->getName() ?? (string) $key) : $key;
            $named[$name] = $value;
        }

        return $named;
    }

    /**
     * Which of the tasklet hooks this class actually defines: the methods
     * `Tasklet` gives a default. Reflection once, at registration, so the
     * dispatcher asks an array.
     *
     * PHP holds a `Tasklet` subclass's hooks to `Tasklet`'s signatures
     * when it loads the class. A short form declares no hook to PHP, so each
     * one it defines is held to them here: an `applyChange` taking the state
     * by value would otherwise fold nothing.
     *
     * @param class-string<Tasklet>|class-string<Relay> $class
     * @return array<string, bool>
     */
    private static function hooksOf(string $class, string $name): array
    {
        $checkedByPhp = is_subclass_of($class, Tasklet::class);
        $overrides = [];
        foreach ((new ReflectionClass(Tasklet::class))->getMethods() as $hook) {
            if ($hook->isAbstract()) {
                continue;
            }
            $overrides[$hook->name] = self::overrides($class, $hook->name);
            $defect = $overrides[$hook->name] && !$checkedByPhp
                ? self::incompatibility(new ReflectionMethod($class, $hook->name), $hook)
                : null;
            if ($defect !== null) {
                self::refuse($name, sprintf(
                    '`%s` must be compatible with Tasklet::%s, as a tasklet\'s is: %s',
                    $hook->name,
                    $hook->name,
                    $defect,
                ));
            }
        }

        return $overrides;
    }

    /**
     * Why `$ours` could not override `$reference`, or null when it could: PHP's
     * inheritance rule, read from reflection. The reference's arguments must
     * all still fit, each passed as the reference passes it and accepted at
     * every type the reference accepts, and the return type may only narrow.
     */
    private static function incompatibility(ReflectionMethod $ours, ReflectionMethod $reference): ?string
    {
        if (!$ours->isPublic() || $ours->isStatic()) {
            return 'it must be a public instance method';
        }
        if ($ours->getNumberOfRequiredParameters() > $reference->getNumberOfRequiredParameters()) {
            return sprintf(
                'it requires %d arguments, and the hook is passed %d',
                $ours->getNumberOfRequiredParameters(),
                $reference->getNumberOfParameters(),
            );
        }
        $parameters = $ours->getParameters();
        $variadic = $ours->isVariadic() ? $parameters[array_key_last($parameters)] : null;
        foreach ($reference->getParameters() as $i => $expected) {
            $parameter = $parameters[$i] ?? $variadic;
            if ($parameter === null) {
                return sprintf('it has no parameter for `$%s`', $expected->getName());
            }
            if ($parameter->isPassedByReference() !== $expected->isPassedByReference()) {
                return sprintf(
                    '`$%s` is taken by %s',
                    $parameter->getName(),
                    $expected->isPassedByReference() ? 'value, not by reference' : 'reference, not by value',
                );
            }
            if (!self::narrows($expected->getType(), $parameter->getType())) {
                return sprintf(
                    '`$%s` is typed `%s`, which does not accept every `%s`',
                    $parameter->getName(),
                    $parameter->getType(),
                    $expected->getType(),
                );
            }
        }
        $returns = $ours->getReturnType();
        if ($returns === null) {
            return sprintf('it declares no return type, not `%s`', $reference->getReturnType());
        }

        return self::narrows($returns, $reference->getReturnType())
            ? null
            : sprintf('it returns `%s`, not `%s`', $returns, $reference->getReturnType());
    }

    /**
     * Whether every value of `$sub` is a value of `$super`, as PHP's variance
     * check reads types: each a union of intersections, a missing type
     * `mixed`, and every alternative of `$sub` narrowing each name of some
     * alternative of `$super`.
     */
    private static function narrows(?ReflectionType $sub, ?ReflectionType $super): bool
    {
        return array_all(self::alternatives($sub), static fn (array $narrow): bool => array_any(
            self::alternatives($super),
            static fn (array $wide): bool => array_all($wide, static fn (string $name): bool => array_any(
                $narrow,
                static fn (string $own): bool => self::narrowsName($own, $name),
            )),
        ));
    }

    /** @return list<list<string>> the type as a union of intersections of names */
    private static function alternatives(?ReflectionType $type): array
    {
        return match (true) {
            $type === null => [['mixed']],
            $type instanceof ReflectionUnionType => array_merge(
                ...array_map(self::alternatives(...), $type->getTypes()),
            ),
            $type instanceof ReflectionIntersectionType => [array_map(
                static fn (ReflectionNamedType $member): string => $member->getName(),
                $type->getTypes(),
            )],
            $type instanceof ReflectionNamedType => $type->allowsNull()
                    && !in_array($type->getName(), ['mixed', 'null'], true)
                ? [[$type->getName()], ['null']]
                : [[$type->getName()]],
        };
    }

    /** One type name narrowing another: the builtin pairs PHP relates, or a class and its ancestor. */
    private static function narrowsName(string $sub, string $super): bool
    {
        return strcasecmp($sub, $super) === 0
            || $sub === 'never'
            || ($super === 'mixed' && $sub !== 'void')
            || ($super === 'iterable' && $sub === 'array')
            || ((class_exists($sub) || interface_exists($sub)) && ($super === 'object' || is_a($sub, $super, true)));
    }

    /**
     * Whether `$class` defines `$method` rather than taking the SDK base's
     * default: how a tasklet's hooks, a short form's `run` and an
     * relay's `channels()` are all read.
     *
     * @param class-string $class
     * @internal Read by {@see Relay} too.
     */
    public static function overrides(string $class, string $method): bool
    {
        if (!method_exists($class, $method)) {
            return false;
        }
        $declaring = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

        return $declaring !== Tasklet::class && $declaring !== Relay::class;
    }

    /**
     * The node identity: the written name, and a short form's factory id
     * verbatim, because a tasklet's name is part of every cell's durable path.
     * Never the class's own, so renaming a class never moves saved state, and
     * a short form grown into its full form keeps it.
     *
     * @param ReflectionClass<Tasklet>|ReflectionClass<Relay> $reflection
     */
    private static function nameOf(ReflectionClass $reflection, Spec $decl): string
    {
        $class = $reflection->getName();
        if (!$reflection->isSubclassOf(Relay::class)) {
            if (($decl->name ?? '') === '') {
                throw new FlezhaException('topology.tasklet_name_missing', ['tasklet' => $class]);
            }

            return $decl->name;
        }
        if (!self::overrides($class, 'run')) {
            throw new FlezhaException('topology.missing_body', ['declaration' => "tasklet '$class'", 'body' => '`run`']);
        }
        if ($decl->name !== null) {
            throw new FlezhaException('topology.short_form_name_mismatch', [
                'class' => $class,
                'factory_id' => $class::declaration()->factoryId,
                'name' => $decl->name,
            ]);
        }

        return $class::declaration()->factoryId;
    }

    /**
     * The declarations that contradict each other, refused where they are
     * written rather than at the far end of the graph.
     */
    private function refuseContradictions(): void
    {
        if ($this->decl->logicalClock && $this->declares('eventTime')) {
            throw new FlezhaException('topology.two_clocks', ['tasklet' => $this->name]);
        }
        if ($this->decl->cellKeys === []) {
            throw new FlezhaException('topology.static_tasklet_without_cell_keys', ['tasklet' => $this->name]);
        }
        // State without a fold is legitimate: a value seeded at start and read
        // but never mutated has nothing to journal, and `Ctx::change()` already
        // refuses at the point of use. A fold with no state to fold into is not.
        if (!$this->declares('initState') && $this->declares('applyChange')) {
            throw new FlezhaException('topology.change_without_state', ['tasklet' => $this->name]);
        }
    }

    private static function refuse(string $tasklet, string $reason): never
    {
        throw new FlezhaException('topology.invalid_tasklet', ['tasklet' => $tasklet, 'reason' => $reason]);
    }
}
