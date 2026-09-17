<?php

declare(strict_types=1);

namespace Flezha;

/**
 * Which operative kind a `#[Proc]` with a `sink` generates.
 *
 * A `sink` makes one procedure declaration a whole operative, and the kind is
 * written rather than inferred: a reader sees it on the declaration, and a
 * stream is never recognised by a missing word. See
 * `docs/sdk/authoring_contract.md` §5.
 */
enum OperativeKind: string
{
    /** A continuous, stateful graph over a stream. */
    case Stream = 'stream';

    /** Ordered steps that run to completion. */
    case Workflow = 'workflow';
}
