<?php

namespace craftquest\featureflags\events;

use craft\events\CancelableEvent;
use craftquest\featureflags\models\Flag;

/**
 * FlagEvent is fired by FlagService when a flag is saved, toggled, or deleted.
 *
 * For `EVENT_BEFORE_SAVE_FLAG`, set `$isValid` to false to cancel the save.
 * The after-events ignore `$isValid`.
 *
 * ```php
 * Event::on(
 *     FlagService::class,
 *     FlagService::EVENT_AFTER_SAVE_FLAG,
 *     function (FlagEvent $event) {
 *         // e.g. refresh Blitz pages tagged with the flag handle
 *         Blitz::$plugin->refreshCache->refreshCacheTags(['flag:' . $event->flag->handle]);
 *     }
 * );
 * ```
 */
class FlagEvent extends CancelableEvent
{
    public Flag $flag;

    /** Whether the flag is being created (save events only). */
    public bool $isNew = false;
}
