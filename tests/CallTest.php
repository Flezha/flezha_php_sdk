<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Response;
use Flezha\Tasklet;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `call(...)`: a chain's end on another relay. The builder records the
 * `call` link and declares the Response channel `<channel>.response`; an emit
 * on the calling channel leaves as one plugin output per request, with the
 * call chain's transforms and key applied here; each outcome arrives as a
 * `Response` decoded from the controller's envelope. The refusals are the
 * core's, reached through this builder.
 */
final class CallTest extends RepositoryTestCase
{
    use Dispatches;

    public function testTheCallLinkClosesTheChainAndTheResponseChannelIsAnEntry(): void
    {
        $channels = HostClassDecl::of(CallingBack::spec())['channels'];

        self::assertSame([
            [['link' => 'named', 'name' => 'quote']],
            [['link' => 'call', 'target' => 'pricing', 'deadline_ms' => 500, 'create' => true,
                'response' => 'quote.response']],
        ], $channels[1]['chains']);
        self::assertSame(['quote.response', 'entry'], [$channels[2]['name'], $channels[2]['kind']]);
        self::assertSame([[['link' => 'into', 'tasklet' => 0]]], $channels[2]['chains']);
    }

    public function testAFactoryCallRecordsCreateChannelAndItsKey(): void
    {
        self::assertSame(
            [['link' => 'transform'], ['link' => 'transform'], ['link' => 'key'], ['link' => 'call', 'factory' => 'agent', 'deadline_ms' => 60_000,
                'create' => false, 'channel' => 'turn', 'answer_channel' => 'reply',
                'response' => 'call_ask.response']],
            HostClassDecl::of(CallingFactory::spec())['channels'][1]['chains'][0],
        );
    }

    public function testTheCoreDerivesTheCallEndAndItsResponseChannel(): void
    {
        $derived = array_map(
            static fn (array $c): array => [
                $c['name'], $c['kind'], $c['producer'], array_column($c['consumers'], 'tasklet'),
            ],
            self::derive(CallingBack::spec())['channels'],
        );

        self::assertSame(
            [['in', 'entry', null, [0]], ['quote', 'plugin', 0, []], ['quote.response', 'entry', null, [0]]],
            $derived,
        );
    }

    /** @return iterable<string, array{string, class-string<\Flezha\Relay>}> */
    public static function refusals(): iterable
    {
        yield 'beside a sink' => ['topology.channel_ends_twice', CallingBesideSink::class];
        yield 'zero deadline' => ['topology.call_deadline_zero', CallingZero::class];
        yield 'unkeyed, may not create' => ['topology.call_without_key', CallingUnkeyed::class];
        yield 'unread' => ['topology.call_response_unread', CallingUnread::class];
        yield 'once loop' => ['topology.call_outside_per_input_loop', CallingOnce::class];
    }

    /** @param class-string<\Flezha\Relay> $relay */
    #[DataProvider('refusals')]
    public function testTheRefusalsAreTheCores(string $code, string $relay): void
    {
        try {
            self::derive($relay::spec());
            self::fail("$relay was accepted");
        } catch (FlezhaException $e) {
            self::assertContains($code, array_column($e->details['issues'] ?? [['code' => $e->getCode()]], 'code'));
        }
    }

    public function testAPerInputLoopMayCall(): void
    {
        self::assertNotEmpty(self::derive(CallingPerInput::spec())['channels']);
    }

    public function testACallOnAnEntryChannelIsRefusedAsAnOutputThereIs(): void
    {
        try {
            $builder = new Builder('on_entry');
            $builder->input('in')->call('p', deadlineMs: 5);
            self::fail('a call on an entry channel was accepted');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.output_on_entry', 'in'], [$e->getCode(), $e->details['channel']]);
        }
    }

    public function testAnEmitOnACallingChannelLeavesOnePluginRequestPerValue(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(CallingFactory::class);
        try {
            $effects = $dispatcher->answer(
                self::activation('call_factory', 'work') + ['items' => [self::input('"drop"'), self::input('"s1"')]],
            )['effects'];
        } finally {
            $core->free();
        }

        self::assertSame([], $effects[0]['outputs']);
        $request = $effects[1]['outputs'][0];
        self::assertSame(
            ['plugin', 'call_ask', 's1', 'req:s1'],
            [$request['target'], $request['plugin_channel'], $request['key'], $request['payload']],
        );
    }

    public function testTheResponseChannelDecodesTheEnvelopeWithTheCallsCodec(): void
    {
        $spec = CallingFactory::spec();
        $channel = array_values(array_filter($spec->channels, static fn ($c): bool => $c->name === 'call_ask.response'))[0];
        $envelope = json_encode([
            'call' => 'c', 'target' => 'agent-1', 'created' => true,
            'request' => base64_encode('req:s1'), 'value' => base64_encode('fine'),
        ], JSON_THROW_ON_ERROR);

        self::assertEquals(new Response('req:s1', 'fine', null, 'agent-1', true), ($channel->decode)($envelope));
    }

    /** Every case of the shared fixture, read raw: request and value bytes, outcome, target, created. */
    public function testEveryEnvelopeCaseDecodes(): void
    {
        $path = $this->repositoryFile('test/fixtures/call_response_envelope.json');
        /** @var array{cases: list<array{name: string, payload: string, expect: array<string, mixed>}>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($fixture['cases']);

        foreach ($fixture['cases'] as $case) {
            self::assertEnvelopeCase($case['expect'], Response::decode($case['payload'], static fn (string $b): string => $b));
        }
    }

    /** @param array<string, mixed> $expect */
    private static function assertEnvelopeCase(array $expect, Response $response): void
    {
        self::assertSame(
            [$expect['target'], $expect['created'], base64_decode($expect['request'], true), $expect['ok']],
            [$response->target, $response->created, $response->request, $response->ok()],
        );
        if ($expect['ok']) {
            self::assertSame(base64_decode($expect['value'], true), $response->value);

            return;
        }
        self::assertNull($response->value);
        self::assertSame(
            [$expect['error_code'], $expect['error_class'], $expect['error_message']],
            [$response->error?->getCode(), $response->error?->errorClass, $response->error?->getMessage()],
        );
    }

    /** @return array<string, mixed> */
    private static function derive(\Flezha\Graph\ClassSpec $spec): array
    {
        $core = Core::create();
        try {
            return Validate::spec($spec, $core);
        } finally {
            $core->free();
        }
    }
}

#[Task(name: 'call_ask')]
final class CallAsk extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'call_back')]
final class CallingBack extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->named('quote')->call('pricing', deadlineMs: 500)->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_factory')]
final class CallingFactory extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)
            ->filter(static fn (mixed $line): bool => $line !== 'drop')
            ->map(static fn (mixed $line): string => "req:$line")
            ->keyBy(static fn (mixed $line): string => explode(':', $line)[1])
            ->call(
                factory: 'agent',
                deadlineMs: 60_000,
                create: false,
                channel: 'turn',
                answerChannel: 'reply',
                codec: ChannelCodec::Text,
            )
            ->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_beside_sink')]
final class CallingBesideSink extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->output('out')->call('p', deadlineMs: 5)->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_zero')]
final class CallingZero extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->call('p', deadlineMs: 0)->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_unkeyed')]
final class CallingUnkeyed extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->call(factory: 'a', deadlineMs: 5, create: false)->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_unread')]
final class CallingUnread extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->call('p', deadlineMs: 5);
    }
}

#[Relay(factoryId: 'call_once', run: 'steps')]
final class CallingOnce extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->call('p', deadlineMs: 5)->into(CallAsk::class);
    }
}

#[Relay(factoryId: 'call_per_input', run: ['steps' => ['each' => 'per_input']], maxDurationMs: 60_000)]
final class CallingPerInput extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(CallAsk::class)->call('p', deadlineMs: 5)->into(CallAsk::class);
    }
}
