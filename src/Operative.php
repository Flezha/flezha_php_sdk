<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operative as OperativeAttribute;
use Flezha\Attribute\Workflow as WorkflowAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use ReflectionClass;

/**
 * The base an authored operative extends: an `#[Operative]` attribute for the
 * factory id, and `channels()` for the graph.
 */
abstract class Operative
{
    /** @var array<class-string, ClassSpec> */
    private static array $cache = [];

    /**
     * Declare the channels graph. A run-sugar workflow leaves it alone and
     * writes {@see WorkflowOperative::run()} instead.
     */
    public static function channels(Builder $c): void
    {
    }

    /** The resolved declaration, built once per class. */
    public static function spec(): ClassSpec
    {
        return self::$cache[static::class] ??= static::buildSpec(static::declaration());
    }

    /** The `#[Operative]` attribute, refusing a class that carries none. */
    protected static function declaration(): OperativeAttribute
    {
        // Not inherited on purpose: two operatives cannot share a factory id,
        // and PHP attributes are per class, which is the behaviour this wants.
        $attributes = (new ReflectionClass(static::class))->getAttributes(OperativeAttribute::class);
        if ($attributes === []) {
            throw new DeclarationException(static::class, null, sprintf(
                'declares no #[%s(factoryId: ...)]',
                OperativeAttribute::class,
            ));
        }

        return $attributes[0]->newInstance();
    }

    /** The `#[Workflow]` attribute, or its defaults. */
    protected static function workflowDeclaration(): WorkflowAttribute
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(WorkflowAttribute::class);

        return $attributes === [] ? new WorkflowAttribute() : $attributes[0]->newInstance();
    }

    protected static function builder(OperativeAttribute $declaration): Builder
    {
        $builder = new Builder($declaration->factoryId);
        static::channels($builder);

        // Sealed before the channels are read: filing every declared sequence
        // on its channel is what can still create one.
        return $builder->seal();
    }

    /**
     * The declaration both kinds carry, assembled once. A stream class names
     * only what the builder holds; a workflow adds its own attribute and, for
     * a run-sugar body, the callable.
     *
     * @param (callable(mixed, WorkflowCtx):mixed)|null $run
     */
    protected static function assemble(
        OperativeAttribute $declaration,
        Builder $builder,
        string $kind,
        bool $syncDurability,
        ?WorkflowAttribute $workflow = null,
        ?\Closure $run = null,
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
            syncDurability: $syncDurability,
            procedures: $builder->procedures,
            channels: $builder->channels,
            workflow: $workflow,
            run: $run,
        );
    }

    /**
     * The spec a registration call was handed: an operative class answers its
     * own, and a `Procedure` subclass the one its `sink` generates.
     *
     * The one resolver, so the runner and a local run cannot disagree about
     * what a short form declares.
     *
     * @param class-string<Operative>|class-string<Procedure> $class
     */
    public static function specFor(string $class): ClassSpec
    {
        if (!is_subclass_of($class, Procedure::class)) {
            return $class::spec();
        }

        return Definition::of($class)->shortForm() ?? throw new DeclarationException(
            $class,
            null,
            'is a procedure with no `sink`, so it declares no operative; register the operative '
            . 'that wires it, or declare a `sink` on it',
        );
    }

    /**
     * The spec a §5 short form generates: one entry channel into that one
     * procedure, that procedure to one plugin sink, and every class-level
     * setting at its kind's default.
     *
     * Assembled here, beside the assembly both hand-written kinds use, so a
     * short form and a full form cannot drift apart in what they declare.
     *
     * @param class-string<Procedure> $procedure
     * @internal Called by {@see Procedure::operative()}.
     */
    public static function shortForm(
        string $procedure,
        string $factoryId,
        OperativeKind $kind,
        string $sink,
        string $entry,
        ?ChannelCodec $codec,
    ): ClassSpec {
        $builder = new Builder($factoryId);
        $builder->input($entry, null, $codec)->into($procedure)->sink($sink);
        // Sealed before the channels are read, as `builder()` seals a
        // hand-written class's.
        $builder = $builder->seal();
        $declaration = new OperativeAttribute(factoryId: $factoryId);

        return $kind === OperativeKind::Workflow
            ? static::assemble($declaration, $builder, 'workflow', false, new WorkflowAttribute())
            : static::assemble($declaration, $builder, 'stream', $builder->syncDurability);
    }

    abstract protected static function buildSpec(OperativeAttribute $declaration): ClassSpec;
}
