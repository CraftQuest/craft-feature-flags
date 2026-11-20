<?php

namespace craftquest\featureflags\variables;

use function CraftCms\Cms\{currentUser};
use craftquest\featureflags\FeatureFlags;

class FeatureFlagVariable
{
    public function isEnabled(string $handle, ?string $bucketKey = null, ?int $siteId = null): bool
    {
        return FeatureFlags::getInstance()->evaluationService->isEnabled($handle, null, $bucketKey, $siteId);
    }

    /**
     * @return \craftquest\featureflags\models\Flag[]
     */
    public function getAllFlags(): array
    {
        if (!currentUser()?->can('featureFlags:view')) {
            return [];
        }

        return FeatureFlags::getInstance()->flagService->getAllFlags();
    }
}
