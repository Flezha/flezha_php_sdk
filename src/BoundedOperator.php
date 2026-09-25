<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operator as OperatorAttribute;
use Flezha\Attribute\Bounded as BoundedAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\ClassSpec;

/**
 * A bounded operator: a chain of tasklets over a channels graph, run by
 * the core's driver. A tasklet ends its instance with {@see Ctx::complete()}.
 */
abstract class BoundedOperator extends Operator
{
    protected static function buildSpec(OperatorAttribute $declaration): ClassSpec
    {
        $builder = static::builder($declaration);
        if ($builder->tasklets === []) {
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                'declares no tasklets; a bounded operator runs a graph of tasklets, declared in channels()'
            );
        }

        $bounded = static::boundedDeclaration() ?? new BoundedAttribute();

        return static::assemble($declaration, $builder, 'bounded', $bounded);
    }
}
