<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Attribute\Operative as OperativeAttribute;
use Flezha\Exception\DeclarationException;
use Flezha\Graph\ClassSpec;

/** A stream operative: fed by the controller through its entry channels. */
abstract class StreamOperative extends Operative
{
    protected static function buildSpec(OperativeAttribute $declaration): ClassSpec
    {
        $workflow = static::workflowDeclaration();
        if ($workflow !== null) {
            // Synchronous durability is the one setting of the attribute the
            // core has a stream refusal for, so it answers under that name.
            throw new DeclarationException(
                $declaration->factoryId,
                null,
                sprintf(
                    '%s extends StreamOperative and carries #[Workflow], a workflow\'s settings, '
                    . 'which a stream never reads; drop it, or extend WorkflowOperative',
                    static::class,
                ),
                $workflow->syncDurability ? ['SyncDurabilityOnStream'] : [],
            );
        }

        return static::assemble($declaration, static::builder($declaration), 'stream');
    }
}
