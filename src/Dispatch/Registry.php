<?php

declare(strict_types=1);

namespace Flezha\Dispatch;

use Flezha\Exception\FlezhaException;
use Flezha\Graph\ClassSpec;

/** The declared classes this runner serves. */
final class Registry
{
    /** @var array<string, RegisteredClass> */
    private array $classes = [];

    /** Add `$spec` and, `$embedded`, every steps class it embeds that is not here yet. */
    public function add(ClassSpec $spec, bool $embedded = false): void
    {
        $existing = $this->classes[$spec->factoryId] ?? null;
        if ($existing !== null) {
            // The one class, embedded by another and registered on its own too.
            if ($existing->spec !== $spec || (!$existing->embedded && !$embedded)) {
                throw new FlezhaException('topology.duplicate_factory_id', ['factory_id' => $spec->factoryId]);
            }
            $existing->embedded = $existing->embedded && $embedded;

            return;
        }

        $registered = new RegisteredClass($spec);
        $registered->embedded = $embedded;
        $this->classes[$spec->factoryId] = $registered;
        foreach ($spec->tasklets as $node) {
            if ($node->embeds !== null) {
                $this->add($node->embeds::spec(), true);
            }
        }
    }

    public function get(string $factoryId): ?RegisteredClass
    {
        return $this->classes[$factoryId] ?? null;
    }

    /** @return list<RegisteredClass> */
    public function all(): array
    {
        return array_values($this->classes);
    }
}
