<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Binding;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\HostClassDecl;
use Flezha\Tasklet;
use PHPUnit\Framework\TestCase;

/** The FFI binding, against a live core. */
final class BindingTest extends TestCase
{
    public function testTheLoadedCoreSpeaksTheSchemaThisPackageWasBuiltAgainst(): void
    {
        $core = Core::create();

        try {
            self::assertSame(Core::SCHEMA_VERSION, $core->coreSchemaVersion());
        } finally {
            $core->free();
        }
    }

    /**
     * Negotiated at context creation, so a shim paired with a mismatched core
     * is refused before any controller connection is attempted.
     */
    public function testAMismatchedSchemaIsRefusedNamingBothVersions(): void
    {
        $ffi = Binding::current();

        // A refused creation answers the null address, which is why the shim
        // carries the context as an integer at all.
        self::assertSame(0, (int) $ffi->flz_core_context_new(Core::SCHEMA_VERSION + 99));
        self::assertStringContainsString(
            'activation schema version mismatch',
            (string) $ffi->flz_core_init_error(),
        );
    }

    /**
     * The address is what crosses a thread boundary: `Swoole\Thread` takes
     * scalars, never objects, so a worker attaches by integer and re-casts.
     */
    public function testAContextCrossesAsAnIntegerAndAttachesBack(): void
    {
        $core = Core::create();

        try {
            $attached = Core::attach($core->address());

            self::assertNotSame(0, $core->address());
            self::assertSame($core->address(), $attached->address());
            self::assertTrue($core->ownsContext());
            self::assertFalse($attached->ownsContext());

            // A call that DEREFERENCES the context, not one that ignores it.
            // `schema_version` takes no context, so it passes over a handle
            // whose pointer is garbage — which is exactly the shape the first
            // implementation had, and it panicked inside the core's DashMap
            // the first time a worker thread polled.
            try {
                $attached->declareClass('{"durable":"local"}');
                self::fail('the core accepted a declaration with no factory id');
            } catch (FlezhaException $e) {
                self::assertSame('topology.declaration_unreadable', $e->getCode());
                self::assertStringContainsString('factory_id', $e->getMessage());
            }

            // The asymmetry that makes the ownership rule structural rather
            // than a caller's discipline: freeing an attached handle is a
            // no-op, so a worker cannot double-free what it borrowed.
            $attached->free();
            self::assertSame(Core::SCHEMA_VERSION, $core->coreSchemaVersion());
        } finally {
            $core->free();
        }
    }

    public function testACallOnAFreedContextIsRefusedRatherThanSegfaulting(): void
    {
        $core = Core::create();
        $core->free();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been freed');

        $core->declareClass('{}');
    }

    public function testACoreRefusalCarriesTheReasonTheCoreRecorded(): void
    {
        $core = Core::create();

        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionCode('topology.declaration_unreadable');
            $this->expectExceptionMessage('factory_id');

            $core->declareClass('{"durable":"local"}');
        } finally {
            $core->free();
        }
    }

    /**
     * The checkpoint acknowledgements the core accepts. Asserted against the
     * core, which reads the tree and refuses a malformed one.
     */
    public function testHoldingOutputsAtTheCheckpointRegistersWithItsDelay(): void
    {
        $decl = HostClassDecl::of(CheckpointOutputsOnly::spec());
        self::assertSame(
            ['checkpoint' => ['outputs' => 'at_checkpoint', 'max_delay_ms' => 1500]],
            $decl['run']['flow']['exactly']['effectively_once']['ack'],
        );

        $core = Core::create();

        try {
            $json = (string) json_encode($decl);
            $core->declareClass($json);
            self::assertSame('checkpoint', self::granularity($core, $json));
        } finally {
            $core->free();
        }
    }

    public function testABareCheckpointRegistersWithNoCadence(): void
    {
        $decl = HostClassDecl::of(CheckpointAckOnly::spec());
        self::assertSame('checkpoint', $decl['run']['flow']['exactly']['effectively_once']['ack']);

        $core = Core::create();

        try {
            $json = (string) json_encode($decl);
            $core->declareClass($json);
            self::assertSame('checkpoint', self::granularity($core, $json));
        } finally {
            $core->free();
        }
    }

    /**
     * The read-only half of the declaration: a record the core cannot honour
     * is refused with the vocabulary every SDK renders, and nothing is
     * registered.
     */
    public function testARefusedRecordAnswersTheVerdictAndRegistersNothing(): void
    {
        $core = Core::create();

        try {
            $core->checkClass('{"factory_id":"x","channels":[],"tasklets":[]}');
            self::fail('the core refuses a record it cannot honour');
        } catch (FlezhaException $e) {
            // Every issue as DATA, the first one's code and message the
            // error's own, so a reader never parses the rendered prose.
            /** @var list<array{code: string, message: string}> $issues */
            $issues = $e->details['issues'];
            self::assertNotSame([], $issues);
            self::assertSame([$issues[0]['code'], $issues[0]['message']], [$e->getCode(), $e->getMessage()]);
        } finally {
            $core->free();
        }
    }

    /** The acknowledgement granularity the core derived from a record. */
    private static function granularity(Core $core, string $json): string
    {
        /** @var array{ack_granularity: string} $derived */
        $derived = json_decode($core->checkClass($json), true, 512, JSON_THROW_ON_ERROR);

        return $derived['ack_granularity'];
    }

    public function testTheMonotonicClockAdvances(): void
    {
        $core = Core::create();

        try {
            $first = $core->monotonicNanos();
            // The clock under test is the signal; PHP's own bounds the wait,
            // so a stopped core clock fails the assertion rather than hanging
            // the suite.
            $startedAt = hrtime(true);
            while ($core->monotonicNanos() <= $first && hrtime(true) - $startedAt < 1_000_000_000) {
                // spin
            }

            self::assertGreaterThan($first, $core->monotonicNanos());
        } finally {
            $core->free();
        }
    }

    public function testAKnobIsParsedByTheCoreWhichAnswersUnsetForTextThatIsNoCount(): void
    {
        $name = 'FLZ_TEST_KNOB';
        try {
            foreach (['5' => 5, '0' => null, 'lots' => null] as $raw => $expected) {
                putenv("$name=$raw");
                self::assertSame($expected, Core::knob('positive', (string) getenv($name)), (string) $raw);
            }
            putenv($name);
            self::assertNull(Core::knob('positive', (string) getenv($name)));
        } finally {
            putenv($name);
        }
        $this->expectException(\LogicException::class);
        Core::knob('percent', '5');
    }

    public function testThePlatformIsOneAReleasePublishes(): void
    {
        self::assertMatchesRegularExpression('/^(linux|macos|windows)-(x64|arm64)$/', Binding::platform());
        self::assertFileExists(Binding::libraryPath());
    }

    public function testAPopulatedCheckoutDownloadsNothing(): void
    {
        $fetched = Binding::obtain(
            Binding::platform(),
            '0.1.0',
            static fn (string $url): string => self::fail("a populated checkout fetched {$url}"),
        );

        self::assertNull($fetched);
    }

    /** The refusal tells such a host to name its own build in the override, so the override must be read. */
    public function testTheOverrideServesAPlatformNoReleasePublishesFor(): void
    {
        $unpublished = strstr(Binding::platform(), '-', true) . '-riscv64';
        $previous = getenv(Binding::NATIVE_LIB_DIR_ENV);
        putenv(Binding::NATIVE_LIB_DIR_ENV . '=' . dirname(Binding::libraryPath()));

        try {
            self::assertNull(Binding::obtain(
                $unpublished,
                '0.1.0',
                static fn (string $url): string => self::fail("an overridden platform fetched {$url}"),
            ));
        } finally {
            putenv($previous === false ? Binding::NATIVE_LIB_DIR_ENV : Binding::NATIVE_LIB_DIR_ENV . '=' . $previous);
        }
    }

    public function testAnAvailabilityCheckPassesOnABuildThatCanCall(): void
    {
        Binding::assertAvailable();

        self::assertTrue(class_exists(\FFI::class));
    }
}

#[Task(name: 'checkpoint_tasklet')]
final class CheckpointTasklet extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(
    factoryId: 'checkpoint_outputs_only',
    run: ['flow' => ['exactly' => ['effectively_once' => [
        'ack' => ['checkpoint' => ['outputs' => 'at_checkpoint', 'max_delay_ms' => 1500]],
    ]]]],
)]
final class CheckpointOutputsOnly extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(CheckpointTasklet::class)->keyed()->output('sink');
    }
}

#[Relay(
    factoryId: 'checkpoint_only',
    run: ['flow' => ['exactly' => ['effectively_once' => ['ack' => 'checkpoint']]]],
)]
final class CheckpointAckOnly extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(CheckpointTasklet::class)->keyed()->output('sink');
    }
}
