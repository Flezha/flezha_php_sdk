<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\ClassSpec;

/** An unbounded operator: fed by the controller through its entry channels. */
abstract class UnboundedOperator extends Operator
{
    protected static function buildSpec(OperatorAttribute $declaration): ClassSpec
    {
        $bounded = static::boundedDeclaration();
        if ($bounded !== null) {
            // Synchronous durability is the one setting of the attribute the
            // core has a stream refusal for, so it answers under that code.
            throw $bounded->syncDurability
                ? new FlezhaException('topology.sync_durability_on_unbounded', ['factory_id' => $declaration->factoryId])
                : new FlezhaException('topology.bounded_setting_on_unbounded', [
                    'factory_id' => $declaration->factoryId,
                    'settings' => [sprintf('#[Bounded] on %s', static::class)],
                ]);
        }

        return static::assemble($declaration, static::builder($declaration), 'unbounded');
    }
}
