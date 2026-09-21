<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operative as OperativeAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\ClassSpec;

/**
 * A workflow operative: a chain of tasklets over a channels graph, run by
 * the core's driver. A tasklet ends its instance with {@see Ctx::complete()}.
 */
abstract class WorkflowOperative extends Operative
{
    protected static function buildSpec(OperativeAttribute $declaration): ClassSpec
    {
        $builder = static::builder($declaration);
        if ($builder->tasklets === []) {
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                'declares no tasklets; a workflow runs a graph of tasklets, declared in channels()'
            );
        }

        $workflow = static::workflowDeclaration();

        return static::assemble(
            $declaration,
            $builder,
            'workflow',
            $workflow->syncDurability || $builder->syncDurability,
            $workflow,
        );
    }
}
