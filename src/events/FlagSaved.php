<?php

namespace craftquest\featureflags\events;

use craftquest\featureflags\models\Flag;

/**
 * Dispatched after a flag is saved and its cache entry cleared, e.g. to purge static caches.
 *
 * @since 2.0.0
 */
class FlagSaved
{
    public function __construct(
        public Flag $flag,
        public bool $isNew = false,
    ) {}
}
