<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Attribute\Task;
use Flezha\Attribute\Bounded as BoundedAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use LogicException;
use ReflectionClass;

/**
 * The base an authored operator extends: an `#[Operator]` attribute for the
 * factory id, and either `channels()` for the graph or, as the §5 short form,
 * the one tasklet's `run`.
 */
abstract class Operator
{
    /** @var array<class-string, ClassSpec> */
    private static array $cache = [];

    /** Declare the channels graph. */
    public static function channels(Builder $c): void
    {
    }

    /**
     * The body of the one tasklet a §5 short form is, defined instead of
     * `channels()`: the entry channel its `#[Operator]` names feeds that
     * tasklet, named the factory id, whose output reaches the `sink` if one
     * is written. A short form may also define the hooks {@see Tasklet}
     * declares (`initState`, `applyChange`, ...), which that tasklet takes,
     * held to the signatures `Tasklet` gives them.
     *
     * Declared `: void`, as a tasklet's is, so a value leaves only through
     * `$ctx->emit()` and a body returning one does not load.
     */
    public function run(mixed $input, Ctx $ctx): void
    {
        throw new LogicException(static::class . ' defines no `run`; its channels() wires its tasklets');
    }

    /** The resolved declaration, built once per class. */
    public static function spec(): ClassSpec
    {
        return self::$cache[static::class] ??= static::buildSpec(static::declaration());
    }

    /** The `#[Operator]` attribute, refusing a class that carries none. */
    public static function declaration(): OperatorAttribute
    {
        // Not inherited on purpose: two operators cannot share a factory id,
        // and PHP attributes are per class, which is the behaviour this wants.
        $attributes = (new ReflectionClass(static::class))->getAttributes(OperatorAttribute::class);
        if ($attributes === []) {
            throw new DeclarationException(static::class, null, sprintf(
                'declares no #[%s(factoryId: ...)]',
                OperatorAttribute::class,
            ));
        }

        return $attributes[0]->newInstance();
    }

    /** The `#[Bounded operator]` attribute the class carries, if any. */
    protected static function boundedDeclaration(): ?BoundedAttribute
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(BoundedAttribute::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    protected static function builder(OperatorAttribute $declaration): Builder
    {
        $builder = new Builder($declaration->factoryId);
        if (self::isShortForm($declaration)) {
            // The class is its own tasklet, which `Definition` names after
            // the factory id.
            $edge = $builder->input($declaration->input ?? 'input', null, $declaration->codec)
                ->into(static::class);
            if ($declaration->sink !== null) {
                $edge->sink($declaration->sink);
            }
        } else {
            static::channels($builder);
        }

        // Sealed before the channels are read: filing every declared sequence
        // on its channel is what can still create one.
        return $builder->seal();
    }

    /**
     * The declaration both kinds carry, assembled once. An unbounded class names
     * only what the builder holds; a bounded operator adds its own attribute.
     */
    protected static function assemble(
        OperatorAttribute $declaration,
        Builder $builder,
        string $kind,
        ?BoundedAttribute $bounded = null,
    ): ClassSpec {
        return new ClassSpec(
            factoryId: $declaration->factoryId,
            kind: $kind,
            persistent: $declaration->persistent,
            effectivelyOnce: $builder->effectivelyOnce,
            checkpointAck: $builder->checkpointAck,
            checkpointEvery: $builder->checkpointEvery,
            checkpointOutputs: $builder->checkpointOutputs,
            checkpointMaxDelayMs: $builder->checkpointMaxDelayMs,
            correctionWindowMs: $builder->correctionWindowMs,
            beyondWindowPolicy: $builder->beyondWindowPolicy,
            compactAfterBytes: $builder->compactAfterBytes,
            tasklets: $builder->tasklets,
            channels: $builder->channels,
            bounded: $bounded,
            sleepWhenIdle: $declaration->sleepWhenIdle ?? ($kind === 'bounded' && $declaration->persistent),
        );
    }

    /**
     * The spec a registration call was handed, refusing a tasklet, which
     * declares no operator. The one resolver, so the runner and a local run
     * cannot disagree about what a class declares.
     *
     * @param class-string<Operator> $class
     */
    public static function specFor(string $class): ClassSpec
    {
        if (is_subclass_of($class, Tasklet::class)) {
            // Read first, so a tasklet carrying an operator's argument is
            // refused naming it.
            Definition::of($class);

            throw new DeclarationException(
                $class,
                null,
                'is a tasklet, which declares no operator; register the operator that wires '
                . 'it, or write it as one: a class extending UnboundedOperator or BoundedOperator '
                . 'that defines `run`',
            );
        }

        return $class::spec();
    }

    /**
     * Whether this class is a §5 short form, read from the body it defines:
     * `run` for one tasklet, `channels()` for a topology of its own.
     */
    private static function isShortForm(OperatorAttribute $declaration): bool
    {
        $body = Definition::overrides(static::class, 'run');
        $topology = Definition::overrides(static::class, 'channels');
        if ($body && $topology) {
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                'ShortFormWithTopology: this class defines both `run` and `channels()`; a short '
                . 'form is its one tasklet\'s `run`, and a topology wires tasklets of its own '
                . 'in `channels()`, so define one of them',
                ['ShortFormWithTopology'],
            );
        }
        if (!$body && !$topology) {
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                'defines neither `run` nor `channels()`; define `run` for a one-tasklet short '
                . 'form, or `channels()` to wire a topology',
            );
        }
        if ($topology) {
            $shortFormArguments = [
                'sink' => $declaration->sink,
                'input' => $declaration->input,
                'codec' => $declaration->codec,
            ];
            foreach ($shortFormArguments as $argument => $value) {
                if ($value !== null) {
                    throw new DeclarationException($declaration->factoryId, null, sprintf(
                        '`%s` names a channel a short form generates, and this class declares its '
                        . 'own in `channels()`; drop it from #[Operator]',
                        $argument,
                    ));
                }
            }
            if ((new ReflectionClass(static::class))->getAttributes(Task::class) !== []) {
                throw new DeclarationException(
                    $declaration->factoryId,
                    null,
                    '#[Task] carries a tasklet\'s settings, and this class wires its tasklets in '
                    . '`channels()`; a full form\'s tasklet settings go on its tasklet classes',
                );
            }

            return false;
        }
        $written = $declaration->persistent ? [] : ['`persistent: false` on #[Operator]'];
        if ($declaration->sleepWhenIdle !== null) {
            $written[] = '`sleepWhenIdle` on #[Operator]';
        }
        // By value, against the default carrying the written loop mode: the
        // one class-level setting a short form keeps, and restating a default
        // sets nothing.
        $bounded = static::boundedDeclaration() ?? new BoundedAttribute();
        $default = get_object_vars(new BoundedAttribute(loopMode: $bounded->loopMode));
        foreach (get_object_vars($bounded) as $setting => $value) {
            if ($value !== $default[$setting]) {
                $written[] = "`$setting` on #[Bounded]";
            }
        }
        if ($written !== []) {
            throw new DeclarationException($declaration->factoryId, null, sprintf(
                'a short form keeps every class-level setting but a bounded operator\'s `loopMode` at its '
                . 'default, and this one writes %s; write the full form, with `channels()`, to set it',
                implode(', ', $written),
            ));
        }

        return true;
    }

    abstract protected static function buildSpec(OperatorAttribute $declaration): ClassSpec;
}
