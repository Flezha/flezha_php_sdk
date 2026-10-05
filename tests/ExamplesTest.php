<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Ffi\Core;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Relay;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every shipped example declares a topology the core would accept.
 *
 * Examples are the first thing an author copies, so a stale one is a defect
 * that ships. Requiring and validating them here makes them provably correct
 * declarations rather than prose — and it costs nothing, because each file
 * only runs a runner when it is the invoked script.
 */
final class ExamplesTest extends TestCase
{
    private static ?Core $core = null;

    public static function setUpBeforeClass(): void
    {
        self::$core = Core::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$core?->free();
        self::$core = null;
    }

    /** @return iterable<string, array{string}> */
    public static function examples(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/examples/*.php') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    /** @dataProvider examples */
    public function testAnExampleDeclaresATopologyTheCoreWouldAccept(string $path): void
    {
        require_once $path;
        // The classes this FILE declares, not the ones this require added: an
        // example another test loaded first adds none, and a diff would then
        // report the example as declaring nothing.
        $real = realpath($path);
        $relays = array_values(array_filter(
            get_declared_classes(),
            static function (string $class) use ($real): bool {
                $reflected = new ReflectionClass($class);

                return is_subclass_of($class, Relay::class)
                    && !$reflected->isAbstract()
                    && $reflected->getFileName() === $real;
            },
        ));

        self::assertNotEmpty($relays, "$path declares no relay");

        foreach ($relays as $class) {
            $spec = $class::spec();
            Validate::spec($spec, self::$core ?? Core::create());

            $decl = HostClassDecl::of($spec);
            self::assertSame(HostClassDecl::SDK_LANG_PHP, $decl['sdk_lang']);
            self::assertNotSame('', $decl['factory_id']);
        }
    }
}
