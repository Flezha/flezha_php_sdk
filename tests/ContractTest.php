<?php

declare(strict_types=1);

namespace Flezha\Tests;

final class ContractTest extends RepositoryTestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        $this->checkout = sys_get_temp_dir() . '/flezha-contract-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->checkout));
    }

    public function testAnInTreeCopyDoesNotShadowTheCheckout(): void
    {
        $package = $this->checkout . '/sdk/php';
        self::carryManifest($this->checkout);
        self::carryManifest($package . '/contract');

        self::assertSame($this->checkout, self::locateContract($package, null));

        unlink($this->checkout . '/test/fixtures/contract.txt');
        self::assertSame($package . '/contract', self::locateContract($package, null));
    }

    private static function carryManifest(string $tree): void
    {
        mkdir($tree . '/test/fixtures', 0o777, true);
        file_put_contents($tree . '/test/fixtures/contract.txt', '');
    }
}
