<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Attribute\Task;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use LogicException;
use ReflectionClass;

/**
 * The base an authored operator extends: an `#[Operator]` attribute for the
 * factory id and the class settings, and either `channels()` for the graph
 * or, as the §5 short form, the one tasklet's `run`.
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
            throw new FlezhaException('topology.missing_factory_id', ['operator' => static::class]);
        }

        return $attributes[0]->newInstance();
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
     * The graph and the settings tree as written: the core judges the tree
     * and refuses a misplaced setting by name.
     */
    protected static function buildSpec(OperatorAttribute $declaration): ClassSpec
    {
        $builder = static::builder($declaration);
        $spec = new ClassSpec(
            factoryId: $declaration->factoryId,
            description: $declaration->description,
            maxDurationMs: $declaration->maxDurationMs,
            run: $declaration->run,
            durable: $declaration->durable,
            compactAfterBytes: $builder->compactAfterBytes,
            tasklets: $builder->tasklets,
            channels: $builder->channels,
        );
        if ($spec->runsInSteps() && $spec->tasklets === []) {
            throw new FlezhaException('topology.no_steps_body', ['factory_id' => $declaration->factoryId]);
        }

        return $spec;
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

            // A tasklet is registered by the operator that wires it.
            throw new FlezhaException('topology.no_operator_exported', ['module' => $class]);
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
            throw new FlezhaException('topology.short_form_with_topology', ['factory_id' => $declaration->factoryId]);
        }
        if (!$body && !$topology) {
            throw new FlezhaException('topology.missing_body', [
                'declaration' => "operator '{$declaration->factoryId}'",
                'body' => "`channels()`, or one tasklet's `run` as the short form",
            ]);
        }
        if ($topology) {
            $shortFormArguments = [
                'sink' => $declaration->sink,
                'input' => $declaration->input,
                'codec' => $declaration->codec,
            ];
            foreach ($shortFormArguments as $argument => $value) {
                if ($value !== null) {
                    self::refuseFields('topology.short_form_field_on_full_form', $declaration, ["`$argument`"]);
                }
            }
            if ((new ReflectionClass(static::class))->getAttributes(Task::class) !== []) {
                self::refuseFields('topology.short_form_field_on_full_form', $declaration, ['#[Task]']);
            }

            return false;
        }

        return true;
    }

    /** @param list<string> $fields */
    private static function refuseFields(string $code, OperatorAttribute $declaration, array $fields): never
    {
        throw new FlezhaException($code, ['factory_id' => $declaration->factoryId, 'fields' => $fields]);
    }
}
