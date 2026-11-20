<?php

namespace craftquest\featureflags\events;

use CraftCms\Cms\Shared\Concerns\ValidatableEvent;
use craftquest\featureflags\models\Flag;

/**
 * Dispatched before a flag is saved, after it passes validation.
 * Set `$isValid = false` to cancel the save.
 *
 * ```php
 * Event::listen(function (FlagSaving $event) {
 *     $event->isValid = $event->flag->flagType !== 'permission';
 * });
 * ```
 *
 * @since 2.0.0
 */
class FlagSaving
{
    use ValidatableEvent;

    public function __construct(
        public Flag $flag,
        public bool $isNew = false,
    ) {}
}
