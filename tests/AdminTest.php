<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Admin;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\StartOutcome;
use Flezha\StartResult;
use PHPUnit\Framework\TestCase;

/** Starting an operator from application code. */
final class AdminTest extends TestCase
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
        $admin = $this->answering('null');
        $admin->enablePlugin('writer', 'operator_file_sink', ['path' => 'out.txt']);
        $admin->enablePlugin(
            'feed',
            'operator_websocket',
            single: true,
            operatorId: 'my_echo',
            hooks: [['on_output', new \stdClass()]],
        );
        $admin->bindPlugin('writer', 'my_echo');
        $admin->bindPlugin('writer', 'my_other', hooks: ['on_output'], channel: 'in');
        $admin->unbindPlugin('writer', 'my_echo');
        $admin->disablePlugin('writer');
        $admin->listPlugins();

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
        $this->expectExceptionCode('admin.unreadable_answer');

        $this->answering('"duplicate"')->startOperator('echo', id: 'my_echo');
    }

    /** Reported as every administration call reports a refusal: the core's reason, thrown. */
    public function testWithNoControlPlaneAStartIsRefusedAsEveryCallIs(): void
    {
        $core = Core::create();

        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionCode('admin.no_control_plane');

            (new Admin($core->adminCall(...)))->startOperator('echo');
        } finally {
            $core->free();
        }
    }

    private function answering(string $answer): Admin
    {
        return new Admin(function (string $verb, string $args) use ($answer): string {
            $this->calls[] = [$verb, $args];

            return $answer;
        });
    }
}
