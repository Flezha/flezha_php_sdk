<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\ClassSpec;

/** An unbounded operator: fed by the controller through its entry channels. */
abstract class UnboundedOperator extends Operator
{
    protected static function buildSpec(OperatorAttribute $declaration): ClassSpec
    {
        $bounded = static::boundedDeclaration();
        if ($bounded !== null) {
            // Synchronous durability is the one setting of the attribute the
            // core has a stream refusal for, so it answers under that name.
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                sprintf(
                    '%s extends UnboundedOperator and carries #[Bounded], a bounded operator\'s settings, '
                    . 'which an unbounded operator never reads; drop it, or extend BoundedOperator',
                    static::class,
                ),
                $bounded->syncDurability ? ['SyncDurabilityOnUnbounded'] : [],
            );
        }

        return static::assemble($declaration, static::builder($declaration), 'unbounded');
    }
}
