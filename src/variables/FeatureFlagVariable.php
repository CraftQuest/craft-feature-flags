<?php

namespace craftquest\featureflags\variables;

use Craft;
use craftquest\featureflags\FeatureFlags;

class FeatureFlagVariable
{
    public function isEnabled(string $handle, ?string $bucketKey = null): bool
    {
        return FeatureFlags::getInstance()->evaluationService->isEnabled($handle, null, $bucketKey);
    }

    /**
     * @return \craftquest\featureflags\models\Flag[]
     */
    public function getAllFlags(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('featureFlags:view')) {
            return [];
        }

        return FeatureFlags::getInstance()->flagService->getAllFlags();
    }
}
