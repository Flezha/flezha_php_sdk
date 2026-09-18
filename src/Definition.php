<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Proc;
use Flezha\Codec\Json;
use Flezha\Codec\TypeCodec;
use Flezha\Codec\ValueCodec;
use Flezha\Exception\DeclarationException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * What a declared procedure resolves to: the scalar declarations, and the one
 * instance whose methods the dispatcher calls.
 *
 * Procedures hold no state of their own — an entity's state lives in the
 * registry and is handed to each invocation — so one instance per class is
 * enough and the dispatcher never allocates per activation.
 *
 * Which optional hooks a procedure declares is read from the methods it
 * overrides, not from a second list: `applyChange` overridden means it folds
 * changes, `onTimer` overridden means it may register timers. A declaration
 * and its implementation therefore cannot disagree.
 *
 * The class is a `Procedure` subclass, or a §5 short form: an operative class
 * whose own `run` and hooks are its one procedure, named its factory id.
 */
final class Definition
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    public readonly string $name;
    public readonly Proc $decl;
    public readonly Procedure|Operative $instance;
    public readonly ?ValueCodec $stateCodec;
    public readonly ?ValueCodec $changeCodec;

    /** @var array<string, bool> */
    private readonly array $overrides;

    /** @param class-string<Procedure>|class-string<Operative> $class */
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
        if ($this->declares('state') && $this->stateCodec === null) {
            Json::assertRepresentable("$this->name.state", $this->instance->state(null));
        }
    }

    /**
     * The resolved declaration, built once per class: it instantiates the
     * procedure, and the dispatcher reads it on the hot path.
     *
     * @param class-string<Procedure>|class-string<Operative> $class
     */
    public static function of(string $class): self
    {
        return self::$cache[$class] ??= new self($class);
    }

    /** `single` | `static` | `dynamic`, as the core's declaration spells it. */
    public function entityMode(): string
    {
        if ($this->decl->entityKeys !== null) {
            return 'static';
        }

        return $this->decl->dynamicEntities ? 'dynamic' : 'single';
    }

    /** Whether the procedure overrides one of the base class's hooks. */
    public function declares(string $method): bool
    {
        return $this->overrides[$method] ?? false;
    }

    public function initialState(?string $entityKey): mixed
    {
        return $this->declares('state') ? $this->instance->state($entityKey) : null;
    }

    /**
     * Encode a durable State or Change: the type this procedure declared, or
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
    public function entitiesFor(mixed $input): array
    {
        return array_map(strval(...), $this->instance->entitiesFor($input));
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
     * `class Sub extends Base {}` would silently come out single-entity with
     * the default mailbox capacity and schema version 1, and nothing would
     * report the difference until the topology was wrong at runtime.
     *
     * Field by field rather than nearest-attribute-wins, because the common
     * case is a subclass that renames the node and changes nothing else —
     * `#[Proc(name: 'stress_b')]` on a child of a static-keyed parent — and
     * whole-attribute inheritance would silently drop the key set exactly
     * there. The one field never inherited is `name`: a node identity is per
     * class, and an inherited one would make two procedures claim one node.
     *
     * @param ReflectionClass<Procedure>|ReflectionClass<Operative> $reflection
     */
    private static function declarationOf(ReflectionClass $reflection): Proc
    {
        $chain = [];
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            $chain[] = $class;
        }

        $arguments = [];
        // Ancestor first, so a derived class's own argument wins.
        foreach (array_reverse($chain) as $class) {
            $attributes = $class->getAttributes(Proc::class);
            if ($attributes === []) {
                continue;
            }
            $own = self::namedArguments($attributes[0]);
            if ($class->getName() !== $reflection->getName()) {
                unset($own['name']);
            }
            $arguments = [...$arguments, ...$own];
        }
        foreach (['kind', 'sink', 'input', 'codec'] as $operativeArgument) {
            if (array_key_exists($operativeArgument, $arguments)) {
                throw new DeclarationException($reflection->getName(), $arguments['name'] ?? null, sprintf(
                    '#[Proc] takes no `%s`: a procedure is not an operative. A one-procedure operative '
                    . 'is a class extending StreamOperative or WorkflowOperative that defines `run`, '
                    . 'with `sink`, `input` and `codec` on its #[Operative]',
                    $operativeArgument,
                ));
            }
        }

        return new Proc(...$arguments);
    }

    /**
     * One attribute's written arguments, keyed by parameter name — positional
     * ones included, so an author who writes them that way is merged the same.
     *
     * @param ReflectionAttribute<Proc> $attribute
     * @return array<string, mixed>
     */
    private static function namedArguments(ReflectionAttribute $attribute): array
    {
        $parameters = (new ReflectionClass(Proc::class))->getConstructor()?->getParameters() ?? [];
        $named = [];
        foreach ($attribute->getArguments() as $key => $value) {
            $name = is_int($key) ? ($parameters[$key]?->getName() ?? (string) $key) : $key;
            $named[$name] = $value;
        }

        return $named;
    }

    /**
     * Which of the procedure hooks this class actually defines: the methods
     * `Procedure` gives a default. Reflection once, at registration, so the
     * dispatcher asks an array.
     *
     * PHP holds a `Procedure` subclass's hooks to `Procedure`'s signatures
     * when it loads the class. A short form declares no hook to PHP, so each
     * one it defines is held to them here: an `applyChange` taking the state
     * by value would otherwise fold nothing.
     *
     * @param class-string<Procedure>|class-string<Operative> $class
     * @return array<string, bool>
     */
    private static function hooksOf(string $class, string $name): array
    {
        $checkedByPhp = is_subclass_of($class, Procedure::class);
        $overrides = [];
        foreach ((new ReflectionClass(Procedure::class))->getMethods() as $hook) {
            if ($hook->isAbstract()) {
                continue;
            }
            $overrides[$hook->name] = self::overrides($class, $hook->name);
            $defect = $overrides[$hook->name] && !$checkedByPhp
                ? self::incompatibility(new ReflectionMethod($class, $hook->name), $hook)
                : null;
            if ($defect !== null) {
                throw new DeclarationException($class, $name, sprintf(
                    '`%s` must be compatible with Procedure::%s, as a procedure\'s is: %s',
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
     * default: how a procedure's hooks, a short form's `run` and an
     * operative's `channels()` are all read.
     *
     * @param class-string $class
     * @internal Read by {@see Operative} too.
     */
    public static function overrides(string $class, string $method): bool
    {
        if (!method_exists($class, $method)) {
            return false;
        }
        $declaring = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

        return $declaring !== Procedure::class && $declaring !== Operative::class;
    }

    /**
     * The node identity: the written name or the class's own, and a short
     * form's factory id verbatim, because a procedure's name is part of every
     * entity's durable path and a short form grown into its full form must
     * keep it.
     *
     * @param ReflectionClass<Procedure>|ReflectionClass<Operative> $reflection
     */
    private static function nameOf(ReflectionClass $reflection, Proc $decl): string
    {
        $class = $reflection->getName();
        if (!$reflection->isSubclassOf(Operative::class)) {
            return $decl->name ?? self::defaultName($reflection->getShortName());
        }
        if (!self::overrides($class, 'run')) {
            throw new DeclarationException(
                $class,
                null,
                'is an operative with no `run`, so it is no procedure; wire the procedures its '
                . '`channels()` declares',
            );
        }
        if ($decl->name !== null) {
            throw new DeclarationException(
                $class,
                $decl->name,
                'a short form\'s procedure is named after its factory id; drop `name` from its #[Proc]',
            );
        }

        return $class::declaration()->factoryId;
    }

    /**
     * The declarations that contradict each other, refused where they are
     * written rather than at the far end of the graph.
     */
    private function refuseContradictions(): void
    {
        if ($this->decl->entityKeys !== null && $this->decl->dynamicEntities) {
            throw new DeclarationException(
                $this->class,
                $this->name,
                'declares both a static key set and dynamic entities; the two modes are exclusive'
            );
        }
        if ($this->decl->entityKeys === []) {
            throw new DeclarationException(
                $this->class,
                $this->name,
                'declares an empty static key set; a static procedure names its entities'
            );
        }
        // State without a fold is legitimate: a value seeded at start and read
        // but never mutated has nothing to journal, and `Ctx::change()` already
        // refuses at the point of use. A fold with no state to fold into is not.
        if (!$this->declares('state') && $this->declares('applyChange')) {
            throw new DeclarationException(
                $this->class,
                $this->name,
                'declares applyChange but no state; there is nothing for a change to fold into'
            );
        }
    }

    /** The class's short name in snake_case, which is what an author would type. */
    private static function defaultName(string $shortName): string
    {
        return strtolower((string) preg_replace(
            ['/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'],
            ['$1_$2', '$1_$2'],
            $shortName,
        ));
    }
}
