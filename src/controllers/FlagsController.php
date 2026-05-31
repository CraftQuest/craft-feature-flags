<?php

namespace craftquest\featureflags\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craftquest\featureflags\enums\FlagType;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FlagsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requirePermission('featureFlags:view');

        $flags = FeatureFlags::getInstance()->flagService->getAllFlags();

        return $this->renderTemplate('feature-flags/flags/_index', [
            'flags' => $flags,
        ]);
    }

    public function actionEdit(?int $flagId = null): Response
    {
        $this->requirePermission('featureFlags:manage');

        if ($flagId) {
            $flag = FeatureFlags::getInstance()->flagService->getFlagById($flagId);
            if (!$flag) {
                throw new NotFoundHttpException(Craft::t('feature-flags', 'Flag not found'));
            }
            $title = Craft::t('feature-flags', 'Edit Flag: {name}', ['name' => $flag->name]);
        } else {
            $flag = new Flag();
            $title = Craft::t('feature-flags', 'Create Flag');
        }

        $flagTypes = array_map(
            fn(FlagType $type) => [
                'label' => Craft::t('feature-flags', ucfirst($type->value)),
                'value' => $type->value,
            ],
            FlagType::cases()
        );

        return $this->renderTemplate('feature-flags/flags/_edit', [
            'flag' => $flag,
            'title' => $title,
            'flagTypes' => $flagTypes,
            'ruleTypes' => FeatureFlags::getInstance()->flagService->getRuleTypes(),
            'sites' => Craft::$app->getIsMultiSite() ? Craft::$app->getSites()->getAllSites() : [],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePermission('featureFlags:manage');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        // Load existing flag or create a new one
        $flagId = $request->getBodyParam('flagId');
        if ($flagId) {
            $flag = FeatureFlags::getInstance()->flagService->getFlagById((int)$flagId);
            if (!$flag) {
                throw new NotFoundHttpException(Craft::t('feature-flags', 'Flag not found'));
            }
        } else {
            $flag = new Flag();
        }

        $flag->name = $request->getBodyParam('name');
        $flag->handle = $request->getBodyParam('handle');
        $flag->description = $request->getBodyParam('description');
        $flag->enabled = (bool)$request->getBodyParam('enabled');
        $rollout = $request->getBodyParam('rolloutPercentage');
        $flag->rolloutPercentage = ($rollout !== null && $rollout !== '') ? (int)$rollout : null;
        $flag->rolloutStrategy = $request->getBodyParam('rolloutStrategy', 'all');
        $flag->flagType = $request->getBodyParam('flagType', 'release');
        $flag->expiresAt = DateTimeHelper::toDateTime($request->getBodyParam('expiresAt')) ?: null;

        // Per-site scope is only posted on multi-site installs.
        $siteScope = $request->getBodyParam('siteScope');
        if ($siteScope !== null) {
            if ($siteScope === 'specific') {
                $posted = (array)$request->getBodyParam('siteSettings', []);
                $siteSettings = [];
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    $siteSettings[$site->id] = !empty($posted[$site->id]);
                }
                $flag->siteSettings = $siteSettings;
            } else {
                // "All sites": no per-site rows.
                $flag->siteSettings = null;
            }
        }

        $ruleTypes = (array)$request->getBodyParam('ruleType', []);
        $ruleValues = (array)$request->getBodyParam('ruleValue', []);

        $rules = [];
        foreach ($ruleTypes as $i => $type) {
            $value = $ruleValues[$i] ?? '';
            if (!is_string($type) || !is_string($value)) {
                continue;
            }
            if ($type !== '' && $value !== '') {
                $rule = new Rule();
                $rule->ruleType = $type;
                $rule->ruleValue = $value;
                $rules[] = $rule;
            }
        }
        $flag->rules = $rules;

        if (!FeatureFlags::getInstance()->flagService->saveFlag($flag)) {
            return $this->asModelFailure($flag, Craft::t('feature-flags', 'Couldn\'t save flag.'), 'flag');
        }

        return $this->asModelSuccess($flag, Craft::t('feature-flags', 'Flag saved.'), 'flag');
    }

    public function actionToggle(): Response
    {
        $this->requirePermission('featureFlags:manage');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $flagId = (int)Craft::$app->getRequest()->getRequiredBodyParam('flagId');
        $enabled = (bool)Craft::$app->getRequest()->getRequiredBodyParam('enabled');

        if (!FeatureFlags::getInstance()->flagService->toggleFlag($flagId, $enabled)) {
            return $this->asFailure(Craft::t('feature-flags', 'Could not toggle flag.'));
        }

        return $this->asSuccess(Craft::t('feature-flags', 'Flag toggled.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePermission('featureFlags:manage');
        $this->requirePostRequest();

        $flagId = (int)Craft::$app->getRequest()->getRequiredBodyParam('flagId');

        if (!FeatureFlags::getInstance()->flagService->deleteFlag($flagId)) {
            return $this->asFailure(Craft::t('feature-flags', 'Could not delete flag.'));
        }

        return $this->asSuccess(Craft::t('feature-flags', 'Flag deleted.'));
    }

    public function actionSettings(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('feature-flags/flags/_settings', [
            'settings' => FeatureFlags::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionAudit(?int $flagId = null): Response
    {
        $this->requirePermission('featureFlags:view');

        $flag = null;
        if ($flagId) {
            $flag = FeatureFlags::getInstance()->flagService->getFlagById($flagId);
            if (!$flag) {
                throw new NotFoundHttpException(Craft::t('feature-flags', 'Flag not found'));
            }
        }

        $auditLog = FeatureFlags::getInstance()->flagService->getAuditLog($flagId);

        return $this->renderTemplate('feature-flags/flags/_audit', [
            'flag' => $flag,
            'auditLog' => $auditLog,
        ]);
    }
}
