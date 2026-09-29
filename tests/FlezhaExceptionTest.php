<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Exception\FlezhaException;

/**
 * The one exception: a refusal this package makes is rendered by the core from
 * `flz_transport/errors.json`, so every code its source raises must be one the
 * catalogue holds, or it reads as `internal.uncatalogued`.
 */
final class FlezhaExceptionTest extends RepositoryTestCase
{
    public function testEveryCodeTheSourceRaisesIsCatalogued(): void
    {
        /** @var array{codes: array<string, mixed>} $catalogue */
        $catalogue = json_decode(
            (string) file_get_contents($this->repositoryFile('flz_transport/errors.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $raised = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all(
                    "/(?:FlezhaException|refuseFields)\\(\\s*'([a-z_]+\\.[a-z_]+)'/",
                    (string) file_get_contents((string) $file),
                    $found,
                );
                $raised = [...$raised, ...$found[1]];
            }
        }

        self::assertNotSame([], $raised);
        self::assertSame([], array_values(array_diff(array_unique($raised), array_keys($catalogue['codes']))));
    }

    public function testATemplateRendersAsTheCoreRendersIt(): void
    {
        $error = new FlezhaException(
            'usage.unknown_word',
            ['noun' => 'executor', 'value' => 'fibers', 'allowed' => ['sync', 'swoole']],
        );

        self::assertSame("unknown executor 'fibers'; use one of sync, swoole", $error->getMessage());
        self::assertSame(['usage.unknown_word', 'invalid', null], [$error->getCode(), $error->errorClass, $error->hint]);
        self::assertSame(
            "the tasklet name 'x' is longer than 255 bytes",
            (new FlezhaException('runtime.identity_too_long', ['part' => 'tasklet name', 'value' => 'x', 'limit' => 255]))
                ->getMessage(),
        );
    }

    public function testAnErrorDocumentIsReadIntoTheOneType(): void
    {
        $error = FlezhaException::fromDocument(
            '{"error":{"code":"quota.exceeded","class":"denied","message":"the quota is reached",'
            . '"details":{"kind":"max_operators"},"hint":"flz namespace quota"}}',
        );

        self::assertSame(
            ['quota.exceeded', 'denied', 'the quota is reached', ['kind' => 'max_operators'], 'flz namespace quota'],
            [$error->getCode(), $error->errorClass, $error->getMessage(), $error->details, $error->hint],
        );
    }
}
