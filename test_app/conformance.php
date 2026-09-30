<?php

declare(strict_types=1);

/**
 * The conformance run: every row of `test/fixtures/reference_topologies.txt`
 * driven through THIS SDK's builder.
 *
 * `flz_core/src/host/check_tests.rs` is the same table driven against the
 * record directly, because the core has no builder. This one maps the same
 * step vocabulary onto the PHP graph builder, so what is measured here is what
 * an author of a PHP operator actually writes.
 *
 * Two entry points, both off `runner.php`:
 *
 *   FLEZHA_CONFORMANCE_REPORT=<path>  drive every row and write the report the
 *                                     drill reads
 *                                     (test/system/topology_refusal_e2e_test.exs),
 *                                     then exit without connecting
 *   FLEZHA_REFUSAL_FIXTURE=<id>       register ONLY that row's topology at
 *                                     startup, so a refused one kills the
 *                                     process with the refusal's own name in
 *                                     its output
 *
 * The palette tasklets live here rather than in `fixtures.php`: they exist
 * only for this run, and nothing registers them but a row that names them.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Attribute\Task;
use Flezha\Attribute\Bounded;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Dispatch\Registry;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\Edge;
use Flezha\Graph\Merge;
use Flezha\Graph\Validate;
use Flezha\Operator;
use Flezha\Overflow;
use Flezha\Tasklet;
use Flezha\Runner;
use Flezha\UnboundedOperator;
use Flezha\BoundedOperator;

/**
 * The palette. One fixture tasklet per role the fixture header names; the
 * facts a role declares are the facts a rule reads.
 */
#[Task(name: 'plain')]
class PalettePlain extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'second')]
final class PaletteSecond extends PalettePlain
{
}

/** A distinct class declaring the SAME name as `plain`: that IS the collision. */
#[Task(name: 'plain')]
final class PaletteTwin extends PalettePlain
{
}

#[Task(name: 'static', cellKeys: ['a', 'b'])]
final class PaletteStatic extends PalettePlain
{
}

#[Task(name: 'routed', cellKeys: ['a', 'b'])]
final class PaletteRouted extends PalettePlain
{
    public function cellsFor(mixed $input): array
    {
        return ['a'];
    }
}

#[Task(name: 'dynamic')]
final class PaletteDynamic extends PalettePlain
{
}

#[Task(name: 'nochange', logicalClock: true)]
final class PaletteNoChange extends PalettePlain
{
}

#[Task(name: 'eventtime')]
final class PaletteEventTime extends PalettePlain
{
    public function eventTime(mixed $input): int
    {
        return strlen((string) $input);
    }
}

/**
 * A mailbox capacity is an attribute argument in this SDK, and an attribute
 * argument is a constant expression — so a row that declares one is bound to
 * the palette class declaring that capacity rather than setting it.
 */
#[Task(name: 'plain', mailboxCapacity: 0)]
final class PalettePlainMailboxZero extends PalettePlain
{
}

#[Task(name: 'plain', mailboxCapacity: 2000000)]
final class PalettePlainMailboxHuge extends PalettePlain
{
}

/**
 * The record a row's steps describe, written the way an author writes it.
 */
final class RowInterpreter
{
    /** role => the palette class it is bound to */
    private const PALETTE = [
        'plain' => PalettePlain::class,
        'second' => PaletteSecond::class,
        'twin' => PaletteTwin::class,
        'static' => PaletteStatic::class,
        'routed' => PaletteRouted::class,
        'dynamic' => PaletteDynamic::class,
        'nochange' => PaletteNoChange::class,
        'eventtime' => PaletteEventTime::class,
    ];

    /** `<role>/<capacity>` => the palette class declaring that capacity */
    private const MAILBOX = [
        'plain/0' => PalettePlainMailboxZero::class,
        'plain/2000000' => PalettePlainMailboxHuge::class,
    ];

    public readonly bool $persistent;

    public readonly bool $bounded;

    public readonly bool $syncDurability;

    /** Unwritten, the row keeps the kind's default rather than declaring it off. */
    public readonly ?bool $sleepWhenIdle;

    /** @var list<string> */
    private readonly array $steps;

    /**
     * A handle is a channel id from an `entry(...)` step, or a tasklet role
     * naming that tasklet's output channel.
     *
     * @var array<string, Edge>
     */
    private array $handles = [];

    /** @var array<string, int> */
    private readonly array $mailbox;

    /** The row the conformance classes declare. */
    public static ?self $row = null;

    public function __construct(public readonly string $factoryId, string $steps)
    {
        $this->steps = array_values(array_filter(
            array_map(trim(...), explode(';', $steps)),
            static fn (string $step): bool => $step !== '',
        ));
        $this->persistent = str_contains($steps, 'class.persistent');
        $this->bounded = str_contains($steps, 'class.bounded');
        $this->syncDurability = str_contains($steps, 'class.sync_durability');
        $this->sleepWhenIdle = str_contains($steps, 'class.sleep_when_idle') ? true : null;
        $this->mailbox = $this->mailboxCapacities();
    }

    public static function loaded(): self
    {
        return self::$row ?? throw new \RuntimeException('no row of the refusal table is loaded');
    }

    public function declare(Builder $c): void
    {
        foreach ($this->steps as $step) {
            $this->step($c, $step);
        }
    }

    /** `verb(arg)` => ['verb', 'arg']; a bare word => ['word', '']. */
    public static function splitCall(string $text): array
    {
        $open = strpos($text, '(');
        if ($open === false) {
            return [trim($text), ''];
        }

        return [trim(substr($text, 0, $open)), trim(rtrim(substr($text, $open + 1), ')'))];
    }

    private function step(Builder $c, string $step): void
    {
        [$verb, $arg] = self::splitCall($step);
        match ($verb) {
            'entry' => $this->handles[$arg] = $c->input($arg),
            'branch' => $this->branch($arg),
            'merge' => $this->merge($c, $arg),
            default => $this->dotted($c, $step),
        };
    }

    /**
     * `<handle>.<link>…` — a chain declared off a handle, a channel knob, a
     * tasklet knob, or a class flag.
     */
    private function dotted(Builder $c, string $step): void
    {
        [$head, $rest] = explode('.', $step, 2);
        if ($head === 'class') {
            $this->classFlag($c, $rest);

            return;
        }

        $edge = $this->handle($head);
        foreach (explode('.', $rest) as $link) {
            $edge = $this->applyLink($edge, $link, $head);
        }
    }

    private function applyLink(Edge $edge, string $link, string $role): Edge
    {
        [$verb, $arg] = self::splitCall($link);
        if (str_starts_with($verb, 'call')) {
            return $this->call($edge, $verb, $arg, $role);
        }

        return match ($verb) {
            'map' => $edge->map(static fn (mixed $value): mixed => $value),
            'filter' => $edge->filter(static fn (mixed $value): bool => true),
            'flat_map' => $edge->flatMap(static fn (mixed $value): array => [$value]),
            'key' => $edge->keyBy(static fn (mixed $value): string => (string) $value),
            'named' => $edge->named($arg),
            'keyed' => $edge->keyed(),
            'retry_max' => $edge->retryMax((int) $arg),
            'on_full' => $edge->onFull(Overflow::from($arg)),
            'sink' => $arg === '' ? $edge->sink() : $edge->sink($arg),
            'response' => $edge->response(),
            'into' => $this->wire($edge, $arg),
            // Declared on the tasklet in this SDK, and read as the palette
            // class was bound — one step earlier than the step that names it.
            'mailbox' => $edge,
            default => throw new \RuntimeException(
                "the fixture spells a link this interpreter does not map: $verb"
            ),
        };
    }

    /**
     * `<x>.call…(<arg>)`: a 500 ms call whose Response channel feeds the role
     * that produces x, unless the step leaves it unread.
     */
    private function call(Edge $edge, string $verb, string $arg, string $role): Edge
    {
        $response = match ($verb) {
            'call_factory' => $edge->call(factory: $arg, deadlineMs: 500),
            'call_factory_nocreate' => $edge->call(factory: $arg, deadlineMs: 500, create: false),
            'call_deadline' => $edge->call('pricing', deadlineMs: (int) $arg),
            'call', 'call_unread' => $edge->call($arg, deadlineMs: 500),
            default => throw new \RuntimeException("the fixture spells a call this interpreter does not map: $verb"),
        };
        if ($verb !== 'call_unread') {
            $response->into($this->taskletClass($role));
        }

        return $edge;
    }

    /**
     * `<x>.into(<role>)`, or a merge's close: the palette role bound to its
     * fixture tasklet. The result is that tasklet's output channel,
     * addressed by its role.
     */
    private function wire(Edge|Merge $closing, string $role): Edge
    {
        return $this->handles[$role] = $closing->into($this->taskletClass($role));
    }

    /**
     * `merge(<role>, <x>, <x>…)`: one merge over the listed sources, each
     * mapped as it stands.
     */
    private function merge(Builder $c, string $arg): void
    {
        [$role, $sources] = array_map(trim(...), explode(',', $arg, 2));
        $merge = $c->merge();
        foreach (array_map(trim(...), explode(',', $sources)) as $source) {
            $merge->from($this->handle($source), static fn (mixed $value): mixed => $value);
        }
        $this->wire($merge, $role);
    }

    /**
     * `branch(<x>, <n>)` — the branch sugar's arity. The arms are empty: what
     * the row states is how many there are.
     */
    private function branch(string $arg): void
    {
        [$target, $arms] = array_map(trim(...), explode(',', $arg));
        $this->handle($target)->branch(
            ...array_fill(0, (int) $arms, static fn (Edge $arm): Edge => $arm),
        );
    }

    /**
     * `class.<flag>[(<value>)]` — the facts the class declares on the builder.
     * The four the builder does not hold were read as the row's class was
     * chosen: `persistent` and `sleep_when_idle` are `#[Operator]`'s,
     * `bounded` the class's kind, and `sync_durability` `#[Bounded operator]`'s.
     */
    private function classFlag(Builder $c, string $rest): void
    {
        [$flag, $value] = self::splitCall($rest);
        match ($flag) {
            'persistent', 'sleep_when_idle', 'bounded', 'sync_durability' => null,
            'effectively_once' => $c->effectivelyOnce(),
            'checkpoint_ack' => $c->checkpointAck(),
            'checkpoint_every' => $c->checkpointEvery((int) $value),
            'correction_window' => $c->correctionWindow((int) $value),
            'outputs_at_checkpoint' => $c->outputsAtCheckpoint((int) $value),
            default => throw new \RuntimeException(
                "the fixture spells a class flag this interpreter does not map: $flag"
            ),
        };
    }

    private function handle(string $name): Edge
    {
        return $this->handles[$name] ?? throw new \RuntimeException(
            "the row addresses `$name`, which nothing has declared"
        );
    }

    /** @return class-string<Tasklet> */
    private function taskletClass(string $role): string
    {
        $capacity = $this->mailbox[$role] ?? null;
        if ($capacity !== null) {
            return self::MAILBOX["$role/$capacity"] ?? throw new \RuntimeException(
                "the fixture declares $role.mailbox($capacity); add the palette class for it"
            );
        }

        return self::PALETTE[$role] ?? throw new \RuntimeException(
            "the fixture names a palette role that is not one: $role"
        );
    }

    /**
     * The capacities a row declares, read BEFORE the steps run: a tasklet is
     * bound by the `into` that first names it, which is one step earlier than
     * the `mailbox` step that declares its capacity.
     *
     * @return array<string, int>
     */
    private function mailboxCapacities(): array
    {
        $capacities = [];
        foreach ($this->steps as $step) {
            $parts = explode('.', $step, 2);
            if (count($parts) === 2 && str_starts_with($parts[1], 'mailbox(')) {
                $capacities[$parts[0]] = (int) self::splitCall($parts[1])[1];
            }
        }

        return $capacities;
    }
}

// ---------------------------------------------------------------------------
// The §5 short-form palette. PHP writes a short form's arguments in an
// attribute, which is written where the class is, so each spelling the
// fixture's `shorthand(...)` step describes is one class here. Nothing
// registers them but a row that names them.

/** Half of the duplicate row's pair. */
#[OperatorAttribute(factoryId: 'plain', sink: 'out')]
final class ShorthandDupUnbounded extends UnboundedOperator
{
    use PassesThrough;
}

/** The other half: a bounded operator claiming the id the stream holds. */
#[OperatorAttribute(factoryId: 'plain', sink: 'twin_sink')]
final class ShorthandDupBounded extends BoundedOperator
{
    use PassesThrough;
}

/** A short form that also carries a topology body. */
#[OperatorAttribute(factoryId: 'plain', sink: 'out')]
final class ShorthandWithTopology extends UnboundedOperator
{
    use PassesThrough;

    public static function channels(Builder $c): void
    {
        $c->input('input')->into(PalettePlain::class)->sink('out');
    }
}

/**
 * A row's operator class: one per class-level spelling a row can need, each
 * declaring whichever row is loaded.
 *
 * Not one class per row: PHP reads a factory id off a class attribute, which
 * is a constant expression, so the row's own id arrives by overriding the
 * reader. `spec()` is overridden for the same reason: the base memoises one
 * spec per class, and a run declares every row through these.
 */
trait DeclaresTheLoadedRow
{
    public static function spec(): ClassSpec
    {
        return static::buildSpec(static::declaration());
    }

    public static function channels(Builder $c): void
    {
        RowInterpreter::loaded()->declare($c);
    }

    public static function declaration(): OperatorAttribute
    {
        $row = RowInterpreter::loaded();

        return new OperatorAttribute(
            factoryId: $row->factoryId,
            persistent: $row->persistent,
            sleepWhenIdle: $row->sleepWhenIdle,
        );
    }
}

final class ConformanceRow extends UnboundedOperator
{
    use DeclaresTheLoadedRow;
}

/** A row writing `class.bounded`: the class is of the bounded kind. */
final class ConformanceBoundedRow extends BoundedOperator
{
    use DeclaresTheLoadedRow;
}

/**
 * A stream row writing `class.sync_durability`, in the one spelling it has:
 * `#[Bounded operator]`, which an unbounded class is refused for carrying.
 */
#[Bounded(syncDurability: true)]
final class ConformanceSyncDurableRow extends UnboundedOperator
{
    use DeclaresTheLoadedRow;
}

/** The refusal table, driven and reported. */
final class Conformance
{
    /** The fixture, relative to the repository root the app is launched from. */
    private const FIXTURE = 'test/fixtures/reference_topologies.txt';

    /**
     * The authoring surfaces this builder carries — the same list the app's
     * `conformance.json` declares. A row needing anything else is skipped BY
     * NAME, which is what makes the skip checkable. The class flag a typed
     * builder cannot spell is `#[Bounded(syncDurability: true)]` here, on
     * the unbounded class the row declares.
     */
    private const SURFACES = [
        'branch',
        'merge',
        'bounded',
        'sync_durability',
        'shorthand',
        'response',
        'call',
    ];

    /**
     * The §5 short-form palette: one class per spelling the fixture's
     * `shorthand(...)` step can describe. PHP declares a short form in an
     * attribute, which is written where the class is, so the step selects the
     * class whose declaration is that shape rather than building one.
     *
     * Keyed by `<kind> <id>|<sink>|<input>` with `-` for an unwritten field,
     * then the step's modifier: `.topology` for a declaration that also
     * carries a topology body. A row's first step is an unbounded operator and its second
     * a bounded operator, so a collision crosses kinds.
     */
    private const SHORTHAND_PALETTE = [
        'unbounded plain|out|-' => ShorthandDupUnbounded::class,
        'bounded plain|twin_sink|-' => ShorthandDupBounded::class,
        'unbounded plain|out|-.topology' => ShorthandWithTopology::class,
    ];

    /**
     * The two topologies the fixture cites rather than re-spells: this app's
     * own fixtures, the twins of its seven-column table.
     */
    private const CITED = [
        'hybrid_channel' => HybridChannelOperator::class,
        'commit_block_chain' => TierCommitBlockBounded::class,
    ];

    private function __construct()
    {
    }

    /**
     * Drive the whole table and write the report. Nothing here connects to a
     * controller: the run is the builder's answer to a file.
     */
    public static function writeReport(string $path): void
    {
        $core = Core::create();
        try {
            $rows = self::rows();
            $judged = array_map(static fn (array $row): array => self::judge($row, $core), $rows);
            file_put_contents($path, Json::encode(['runtime' => 'php', 'rows' => $judged]));
            $skipped = count(array_filter($judged, static fn (array $e): bool => $e['outcome'] === 'skipped'));
            fwrite(STDERR, sprintf(
                "conformance: %d rows, %d ran, %d skipped\n",
                count($rows),
                count($rows) - $skipped,
                $skipped,
            ));
        } finally {
            $core->free();
        }
    }

    /**
     * The `shorthand(<id>|-, <sink>|-, <input>|-)` steps of a row, each
     * resolved to the palette class whose attribute is that declaration.
     *
     * @param array{steps: string} $row
     * @return list<class-string<Operator>>
     */
    private static function shorthandSteps(array $row): array
    {
        $classes = [];
        foreach (explode(';', $row['steps']) as $raw) {
            $step = trim($raw);
            if (!str_starts_with($step, 'shorthand(')) {
                continue;
            }
            $close = strrpos($step, ')');
            [, $args] = RowInterpreter::splitCall(substr($step, 0, $close + 1));
            $fields = array_map('trim', explode(',', $args));
            if (count($fields) !== 3) {
                throw new \RuntimeException("a shorthand step declares three fields: read `$step`");
            }
            $kind = $classes === [] ? 'unbounded' : 'bounded';
            $key = "$kind " . implode('|', $fields) . substr($step, $close + 1);
            $classes[] = self::SHORTHAND_PALETTE[$key]
                ?? throw new \RuntimeException("the palette has no class for `$step`");
        }

        return $classes;
    }

    /**
     * A short-form row's verdict: what registering its declarations answered.
     * PHP raises every short-form refusal at registration, so each row boots
     * for real.
     *
     * @param array{topology: string} $row
     * @param list<class-string<Operator>> $classes
     * @return array<string, mixed>
     */
    private static function judgeShorthand(array $row, array $classes): array
    {
        try {
            // A duplicate factory id is the registry's refusal, which a
            // deployed runner reaches at boot; the registry is driven here
            // rather than restated, so the name reported is the name an author
            // reads.
            $registry = new Registry();
            foreach ($classes as $class) {
                $registry->add(Operator::specFor($class));
            }
        } catch (FlezhaException $e) {
            return ['topology' => $row['topology'], 'outcome' => 'refused', 'names' => self::codesOf($e)];
        }

        return ['topology' => $row['topology'], 'outcome' => 'accepted'];
    }

    /**
     * Every code a refusal carries: each issue's, or its own.
     *
     * @return list<string>
     */
    public static function codesOf(FlezhaException $e): array
    {
        /** @var list<array{code: string}> $issues */
        $issues = $e->details['issues'] ?? [];

        return $issues === [] ? [(string) $e->getCode()] : array_map(static fn (array $i): string => $i['code'], $issues);
    }

    /**
     * Register ONLY the named row's topology, the way the app registers any
     * class. A refused row never reaches a connected runner: the declaration is
     * judged before the core is started, and the process dies naming the
     * refusal.
     */
    public static function registerOnly(Runner $runner, string $topology): void
    {
        foreach (self::rows() as $row) {
            if ($row['topology'] === $topology) {
                $shorthand = self::shorthandSteps($row);
                if ($shorthand === []) {
                    $runner->registerFactory(self::classFor($row));
                } else {
                    foreach ($shorthand as $class) {
                        $runner->registerFactory($class);
                    }
                }

                return;
            }
        }

        throw new \RuntimeException(self::FIXTURE . " has no row `$topology`");
    }

    /**
     * One row's verdict, in the report's shape. The refusal NAMES are the
     * core's own, carried on the refusal this package raises; nothing here
     * re-derives one.
     *
     * @param array{refusal: string, topology: string, steps: string, needs: string} $row
     * @return array<string, mixed>
     */
    private static function judge(array $row, Core $core): array
    {
        if ($row['needs'] !== '-' && !in_array($row['needs'], self::SURFACES, true)) {
            return ['topology' => $row['topology'], 'outcome' => 'skipped', 'needs' => $row['needs']];
        }

        $shorthand = self::shorthandSteps($row);
        if ($shorthand !== []) {
            return self::judgeShorthand($row, $shorthand);
        }

        try {
            $class = self::classFor($row);
            $derived = Validate::spec($class::spec(), $core);
        } catch (FlezhaException $e) {
            return ['topology' => $row['topology'], 'outcome' => 'refused', 'names' => self::codesOf($e)];
        }

        return [
            'topology' => $row['topology'],
            'outcome' => 'accepted',
            'derived' => self::channelsOf($derived),
        ];
    }

    /**
     * @param array{topology: string, steps: string} $row
     * @return class-string<\Flezha\Operator>
     */
    private static function classFor(array $row): string
    {
        $cited = self::CITED[$row['topology']] ?? null;
        if ($cited !== null) {
            return $cited;
        }

        $loaded = RowInterpreter::$row = new RowInterpreter($row['topology'], $row['steps']);

        return match (true) {
            $loaded->bounded => ConformanceBoundedRow::class,
            $loaded->syncDurability => ConformanceSyncDurableRow::class,
            default => ConformanceRow::class,
        };
    }

    /**
     * The derived declaration in the columns the fixture's seven-column table
     * states. The core spells a consumer `{"tasklet":N,"chain":M}`; the
     * report carries the flat list of tasklet indices, which is what that
     * table pins.
     *
     * @param array<string, mixed> $derived
     * @return list<array<string, mixed>>
     */
    private static function channelsOf(array $derived): array
    {
        /** @var list<array<string, mixed>> $channels */
        $channels = $derived['channels'];

        return array_map(static fn (array $channel): array => [
            'index' => $channel['index'],
            'name' => $channel['name'],
            'kind' => $channel['kind'],
            'producer' => $channel['producer'],
            'consumers' => array_map(
                static fn (array $edge): int => $edge['tasklet'],
                $channel['consumers'],
            ),
            'sink' => $channel['sink'],
        ], $channels);
    }

    /**
     * The refusal table, in file order.
     *
     * @return list<array{refusal: string, topology: string, steps: string, needs: string}>
     */
    private static function rows(): array
    {
        $rows = [];
        foreach (file(self::fixturePath(), FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $cols = array_map(trim(...), explode('|', $line));
            if (count($cols) === 4) {
                $rows[] = [
                    'refusal' => $cols[0],
                    'topology' => $cols[1],
                    'steps' => $cols[2],
                    'needs' => $cols[3],
                ];
            }
        }

        return $rows;
    }

    /**
     * The fixture as seen from wherever the app was launched: the drill runs it
     * from the repository root, a developer from the app's own directory.
     */
    private static function fixturePath(): string
    {
        $base = '.';
        for ($depth = 0; $depth < 5; $depth++) {
            $candidate = "$base/" . self::FIXTURE;
            if (file_exists($candidate)) {
                return $candidate;
            }
            $base .= '/..';
        }

        throw new \RuntimeException(self::FIXTURE . ' is not under any parent of the working directory');
    }
}
