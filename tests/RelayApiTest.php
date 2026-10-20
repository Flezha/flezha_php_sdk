<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\ChannelBuilder;
use Flezha\ChannelCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Runner;
use Flezha\Tasklet;
use PHPUnit\Framework\TestCase;

/** A relay's API: what each channel announces, and `Runner::call`. */
final class RelayApiTest extends TestCase
{
    public function testTypedPromotedPropertiesDeriveEverySupportedType(): void
    {
        $leg = [
            'type' => 'object',
            'properties' => ['venue' => ['type' => 'string'], 'weight' => ['type' => 'number']],
            'required' => ['venue', 'weight'],
        ];

        self::assertEquals([
            'type' => 'object',
            'properties' => [
                'pair' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'lots' => ['type' => 'integer'],
                'urgent' => ['type' => 'boolean'],
                'side' => ['enum' => ['buy', 'sell']],
                'main' => $leg,
                'tags' => new \stdClass(),
                'memo' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['pair', 'amount', 'lots', 'urgent', 'side', 'main', 'tags'],
        ], Json::schemaOf(ApiOrder::class));
    }

    public function testAClassThatContainsItselfStopsOneLevelDown(): void
    {
        self::assertEquals(new \stdClass(), Json::schemaOf(ApiNode::class)['properties']['parent']);
    }

    public function testAClassWithNoPromotedPropertiesDerivesNoSchema(): void
    {
        self::assertNull(Json::schemaOf(\stdClass::class));
        self::assertNull(Json::schemaOf(null));
        self::assertNull(Json::schemaOf('NoSuchClass'));
    }

    public function testEachChannelAnnouncesItsFormatSchemaAndDescription(): void
    {
        $channels = array_column(HostClassDecl::of(ApiFacts::spec())['channels'], null, 'name');

        self::assertSame('json', $channels['place']['codec']);
        self::assertSame('Places an order', $channels['place']['description']);
        self::assertEquals(Json::schemaOf(ApiQuote::class), $channels['place']['schema']);
        self::assertSame(['type' => 'object', 'required' => ['pair']], $channels['declared']['schema']);
        self::assertSame('text', $channels['audit']['codec']);
        self::assertArrayNotHasKey('schema', $channels['audit']);
        self::assertArrayNotHasKey('description', $channels['audit']);
        self::assertSame('custom', $channels['parsed']['codec']);
        self::assertSame('json', $channels['placed']['codec']);
        self::assertEquals(Json::schemaOf(ApiPlaced::class), $channels['placed']['schema']);
        self::assertSame('custom', $channels['encoded']['codec']);
    }

    public function testADeclaredSchemaThatIsNotAnObjectWithATypeIsRefused(): void
    {
        $core = Core::create();

        try {
            Validate::spec(ApiBrokenSchema::spec(), $core);
            self::fail('a schema with no type was accepted');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.schema_invalid'], array_column($e->details['issues'], 'code'));
            self::assertSame('place', $e->details['issues'][0]['details']['channel']);
        } finally {
            $core->free();
        }
    }

    /** As a send is: through the core, whose refusal names why. */
    public function testACallCrossesTheAdministrationCallAndBeforeStartIsRefused(): void
    {
        $runner = new Runner('api_test');
        try {
            $runner->call('orders', 'place', ['pair' => 'EUR/USD']);
            self::fail('a call before start reached a core');
        } catch (\LogicException $e) {
            self::assertStringContainsString('no core', $e->getMessage());
        }

        $core = Core::create();
        (new \ReflectionProperty(Runner::class, 'core'))->setValue($runner, $core);
        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionCode('control.no_control_plane');
            $runner->call('orders', 'place', ['pair' => 'EUR/USD']);
        } finally {
            $core->free();
        }
    }

    public function testACallReadsTheAnswerByItsFormat(): void
    {
        $answerOf = new \ReflectionMethod(Runner::class, 'answerOf');

        self::assertSame(['status' => 'placed'], $answerOf->invoke(null, ['answer' => ['status' => 'placed'], 'answer_codec' => 'json']));
        self::assertSame('done', $answerOf->invoke(null, ['answer' => 'done', 'answer_codec' => 'text']));
        self::assertSame("\x01\x02", $answerOf->invoke(null, ['answer' => 'AQI=', 'answer_codec' => 'bytes']));
        self::assertSame(['accepted' => true], $answerOf->invoke(null, ['accepted' => true]));
    }
}

enum ApiSide: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}

final class ApiLeg
{
    public function __construct(public string $venue, public float $weight)
    {
    }
}

final class ApiOrder
{
    /** @param list<string> $tags */
    public function __construct(
        public string $pair,
        public float $amount,
        public int $lots,
        public bool $urgent,
        public ApiSide $side,
        public ApiLeg $main,
        public array $tags,
        public ?string $memo,
        public string $note = '',
    ) {
    }
}

final class ApiNode
{
    public function __construct(public string $name, public ?ApiNode $parent = null)
    {
    }
}

final class ApiQuote
{
    public function __construct(public string $pair, public float $amount)
    {
    }
}

final class ApiPlaced
{
    public function __construct(public string $pair, public float $amount, public string $status)
    {
    }
}

#[Tasklet\Spec(name: 'api_place', inputType: ApiQuote::class, outputType: ApiPlaced::class)]
final class ApiPlace extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input + ['status' => 'placed']);
    }
}

#[Tasklet\Spec(name: 'api_audit')]
final class ApiAudit extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'api_facts')]
final class ApiFacts extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('place', description: 'Places an order')->into(ApiPlace::class)->output('placed');
        $c->input('declared', schema: ['type' => 'object', 'required' => ['pair']])->into(ApiPlace::class);
        $c->input('audit', codec: ChannelCodec::Text)->into(ApiAudit::class)->output('encoded', static fn (mixed $v): string => (string) $v);
        $c->input('parsed', static fn (string $bytes): string => $bytes)->into(ApiAudit::class);
    }
}

#[Relay(factoryId: 'api_broken_schema')]
final class ApiBrokenSchema extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('place', schema: ['properties' => ['pair' => ['type' => 'string']]])->into(ApiPlace::class);
    }
}
