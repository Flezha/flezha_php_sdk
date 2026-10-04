<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Control;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\StartOutcome;
use Flezha\StartResult;
use PHPUnit\Framework\TestCase;

/** Starting an operator from application code. */
final class ControlTest extends TestCase
{
    /** @var list<array{string, string}> each call's verb and JSON arguments */
    private array $calls = [];

    public function testAStartWithoutAnIdSendsNoneAndAnswersTheGeneratedOne(): void
    {
        $result = $this->answering('{"id":"echo-k7m2qzxw4pd3fn2a","outcome":"started"}')->startOperator('echo');

        self::assertSame([['Operator.start', '[{"factory_id":"echo"}]']], $this->calls);
        self::assertEquals(new StartResult('echo-k7m2qzxw4pd3fn2a', StartOutcome::Started), $result);
    }

    public function testAStartOfARunningIdAnswersAlreadyRunning(): void
    {
        $result = $this->answering('{"id":"my_echo","outcome":"already_running"}')
            ->startOperator('echo', id: 'my_echo');

        self::assertSame(
            [['Operator.start', '[{"factory_id":"echo","id":"my_echo"}]']],
            $this->calls,
        );
        self::assertEquals(new StartResult('my_echo', StartOutcome::AlreadyRunning), $result);
    }

    public function testAPluginIsEnabledByNameAndBoundAndUnboundByOperator(): void
    {
        $control = $this->answering('null');
        $control->enablePlugin('writer', 'operator_file_sink', ['path' => 'out.txt']);
        $control->enablePlugin(
            'feed',
            'operator_websocket',
            single: true,
            operatorId: 'my_echo',
            hooks: [['on_output', new \stdClass()]],
        );
        $control->bindPlugin('writer', 'my_echo');
        $control->bindPlugin('writer', 'my_other', hooks: ['on_output'], channel: 'in');
        $control->unbindPlugin('writer', 'my_echo');
        $control->disablePlugin('writer');
        $control->listPlugins();

        self::assertSame([
            ['PluginManager.enable', '["writer",{"provider":"operator_file_sink","opts":{"path":"out.txt"}}]'],
            [
                'PluginManager.enable',
                '["feed",{"provider":"operator_websocket","opts":{},"single":true,'
                    . '"operator_id":"my_echo","hooks":[["on_output",{}]]}]',
            ],
            ['PluginManager.bind', '["writer",{"operator_id":"my_echo"}]'],
            ['PluginManager.bind', '["writer",{"operator_id":"my_other","hooks":["on_output"],"channel":"in"}]'],
            ['PluginManager.unbind', '["writer",{"operator_id":"my_echo"}]'],
            ['PluginManager.disable', '["writer",{}]'],
            ['PluginManager.list', '[]'],
        ], $this->calls);
    }

    public function testAnAnswerOfAnotherShapeIsRefused(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('control.unreadable_answer');

        $this->answering('"duplicate"')->startOperator('echo', id: 'my_echo');
    }

    /** Reported as every administration call reports a refusal: the core's reason, thrown. */
    public function testWithNoControlPlaneAStartIsRefusedAsEveryCallIs(): void
    {
        $core = Core::create();

        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionCode('control.no_control_plane');

            (new Control($core->adminCall(...)))->startOperator('echo');
        } finally {
            $core->free();
        }
    }

    /** Against the real core: no runner here holds `elsewhere`, and only the controller reads an id. */
    public function testTheCoreRefusesALocalSendHeldElsewhereOrWithAnId(): void
    {
        $core = Core::create();

        try {
            self::assertSame([], $core->operators());
            foreach ([['elsewhere', null, 'runtime.operator_not_local'], ['orders', 'm-1', 'usage.options_conflict']] as [$operator, $msgId, $code]) {
                try {
                    $core->sendMessage($operator, 'in', 'x', $msgId, null, true);
                    self::fail("a local send to {$operator} was not refused");
                } catch (FlezhaException $refused) {
                    self::assertSame($code, $refused->getCode());
                }
            }
        } finally {
            $core->free();
        }
    }

    private function answering(string $answer): Control
    {
        return new Control(function (string $verb, string $args) use ($answer): string {
            $this->calls[] = [$verb, $args];

            return $answer;
        });
    }
}
