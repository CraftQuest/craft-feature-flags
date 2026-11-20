<?php

namespace craftquest\featureflags\events;

use craftquest\featureflags\models\Flag;

/**
 * Dispatched after a flag's master switch is turned on or off. A full save dispatches
 * FlagSaved instead.
 *
 * @since 2.0.0
 */
class FlagToggled
{
    public function __construct(
        public Flag $flag,
    ) {}
}
