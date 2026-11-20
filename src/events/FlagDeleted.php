<?php

namespace craftquest\featureflags\events;

use craftquest\featureflags\models\Flag;

/**
 * Dispatched after a flag is deleted. `$flag` is the flag as it was before deletion.
 *
 * @since 2.0.0
 */
class FlagDeleted
{
    public function __construct(
        public Flag $flag,
    ) {}
}
