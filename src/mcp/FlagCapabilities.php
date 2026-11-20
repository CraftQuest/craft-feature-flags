<?php

namespace craftquest\featureflags\mcp;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use CraftCms\Cms\Mcp\Attributes\RequiresPermission;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\User\Contracts\CraftUser;
use craftquest\featureflags\enums\FlagType;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use DateTime;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * MCP tools for Craft's native MCP server.
 *
 * Registered from FeatureFlags::registerMcp() with Craft 6's capability registry. Craft
 * resolves this class from the Laravel container, so McpActor is injected per request.
 *
 * All writes go through FlagService, so validation, audit logging, cache invalidation,
 * and plugin events behave exactly as they do in the control panel.
 *
 * @since 1.2.0
 */
class FlagCapabilities
{
    public const TOGGLE_TOOL = 'feature_flags_toggle';

    public function __construct(private McpActor $actor)
    {
    }

    private const RULE_SCHEMA = [
        'type' => 'array',
        'description' => 'Targeting rules. Each rule is {type, value}. Built-in types: user (user ID), userGroup (group handle), environment (CRAFT_ENVIRONMENT value), and subscriptionPlan (plan handle, Craft Commerce only). Plugins can register more; feature_flags_get lists the available types.',
        'items' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string'],
                'value' => ['type' => 'string'],
            ],
            'required' => ['type', 'value'],
            'additionalProperties' => false,
        ],
    ];

    private const SITES_SCHEMA = [
        'type' => ['array', 'null'],
        'description' => 'Site handles the flag is enabled on. Null or an empty list means all sites (the default). A non-empty list scopes the flag to exactly those sites; any other site, including sites added later, is off. Only matters on multi-site installs.',
        'items' => ['type' => 'string'],
    ];

    private const DATE_DESCRIPTION = 'ISO 8601 date-time, such as 2026-12-01T09:00:00-05:00. Null clears it.';

    /**
     * @return array{count: int, flags: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'feature_flags_list',
        description: 'Lists feature flags with their status (active, disabled, scheduled, or expired), type, rollout percentage, and schedule. Use feature_flags_get for rules and site scope.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('featureFlags:view')]
    public function list(
        #[Schema(enum: ['release', 'experiment', 'ops', 'permission'])]
        ?string $type = null,
        #[Schema(description: 'Filter by the master switch.')]
        ?bool $enabled = null,
        #[Schema(description: 'Case-insensitive match against the flag name or handle.')]
        ?string $search = null,
    ): array {
        $flags = array_filter(
            FeatureFlags::getInstance()->flagService->getAllFlags(),
            static fn(Flag $flag): bool => ($type === null || $flag->flagType === $type)
                && ($enabled === null || $flag->enabled === $enabled)
                && ($search === null || $search === ''
                    || stripos($flag->name, $search) !== false
                    || stripos($flag->handle, $search) !== false),
        );

        $flags = array_values(array_map(fn(Flag $flag): array => $this->summarize($flag), $flags));

        return ['count' => count($flags), 'flags' => $flags];
    }

    /**
     * @return array{flag: array<string, mixed>, ruleTypes: list<string>}
     */
    #[McpTool(
        name: 'feature_flags_get',
        description: 'Gets one feature flag by handle, including its targeting rules, rollout strategy, schedule, and site scope.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('featureFlags:view')]
    public function get(string $handle): array
    {
        return [
            'flag' => $this->serialize($this->requireFlag($handle)),
            'ruleTypes' => FeatureFlags::getInstance()->flagService->getRuleTypeValues(),
        ];
    }

    /**
     * @return array{handle: string, enabled: bool, reason: string, explanation: string, bucket: int|null, matchedRule: array<string, mixed>|null, context: array<string, mixed>}
     */
    #[McpTool(
        name: 'feature_flags_evaluate',
        description: 'Evaluates a flag the way templates and PHP would for a given user, site, and bucket key, and explains the result (for example: disabled, outside its schedule, a rule matched, or the visitor fell outside the rollout percentage). Without a user or bucketKey it evaluates an anonymous visitor with no visitor cookie, so percentage rollouts are off. Read-only: it does not change the flag or set cookies.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('featureFlags:view')]
    public function evaluate(
        string $handle,
        #[Schema(description: 'User to evaluate as: a user ID, username, or email address. Omit for an anonymous visitor.')]
        ?string $user = null,
        #[Schema(description: 'Site ID to evaluate on. Matters only for flags scoped to specific sites on multi-site installs.')]
        ?int $siteId = null,
        #[Schema(description: 'Explicit rollout bucket key, such as a visitor or account ID. Takes precedence over the user ID for percentage rollouts.')]
        ?string $bucketKey = null,
    ): array {
        $evalUser = null;
        if ($user !== null && $user !== '') {
            $users = Craft::$app->getUsers();
            $evalUser = ctype_digit($user)
                ? $users->getUserById((int)$user)
                : $users->getUserByUsernameOrEmail($user);

            if (!$evalUser) {
                throw new ToolCallException("User not found: {$user}");
            }
        }

        if ($siteId !== null && !Craft::$app->getSites()->getSiteById($siteId)) {
            throw new ToolCallException("Site not found: {$siteId}");
        }

        $result = FeatureFlags::getInstance()->evaluationService->explain($handle, $evalUser, $bucketKey, $siteId);
        $flag = $result['reason'] === 'not_found' ? null : FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        return [
            'handle' => $handle,
            'enabled' => $result['enabled'],
            'reason' => $result['reason'],
            'explanation' => $this->explanation($result, $flag),
            'bucket' => $result['bucket'],
            'matchedRule' => $result['matchedRule'],
            'context' => [
                'user' => $evalUser ? ['id' => $evalUser->id, 'username' => $evalUser->username] : null,
                'siteId' => $siteId,
                'bucketKey' => $bucketKey,
            ],
        ];
    }

    /**
     * @return array{count: int, limit: int, offset: int, nextOffset: int|null, entries: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'feature_flags_audit_log',
        description: 'Lists audit log entries (created, updated, enabled, disabled, deleted), newest first. Filter by flag handle; entries for deleted flags appear only in the unfiltered log.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('featureFlags:view')]
    public function auditLog(
        ?string $handle = null,
        #[Schema(minimum: 1, maximum: 200)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $flagId = $handle !== null ? $this->requireFlag($handle)->id : null;

        $rows = FeatureFlags::getInstance()->flagService->getAuditLog($flagId, $limit, $offset);

        $entries = array_map(static function (array $row): array {
            $details = is_string($row['details']) ? (Json::decodeIfJson($row['details']) ?: []) : [];

            return [
                'id' => (int)$row['id'],
                'action' => $row['action'],
                'flagId' => $row['flagId'] !== null ? (int)$row['flagId'] : null,
                'flagHandle' => $details['handle'] ?? null,
                'flagName' => $row['flagName'] ?? ($details['name'] ?? null),
                'user' => $row['username'],
                'date' => DateTimeHelper::toDateTime($row['dateCreated'])?->format(DATE_ATOM),
                'details' => $details,
            ];
        }, $rows);

        return [
            'count' => count($entries),
            'limit' => $limit,
            'offset' => $offset,
            'nextOffset' => count($entries) === $limit ? $offset + $limit : null,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{flag: array<string, mixed>}
     */
    #[McpTool(
        name: self::TOGGLE_TOOL,
        description: 'Turns a flag\'s master switch on or off without changing its rules, rollout, schedule, or sites. Turning a flag off is the safe way to disable a feature quickly. Requires the "Toggle feature flags" or "Manage feature flags" permission.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    #[RequiresPermission('featureFlags:view')]
    public function toggle(string $handle, bool $enabled): array
    {
        if (!self::canToggle($this->actor->user())) {
            throw new ToolCallException('Toggling flags requires the "Toggle feature flags" or "Manage feature flags" permission.');
        }

        $flag = $this->requireFlag($handle);
        $flagService = FeatureFlags::getInstance()->flagService;

        if (!$flagService->toggleFlag($flag->id, $enabled)) {
            throw new ToolCallException('The flag could not be toggled.');
        }

        return ['flag' => $this->serialize($flagService->getFlagById($flag->id))];
    }

    /**
     * @return array{flag: array<string, mixed>}
     */
    #[McpTool(
        name: 'feature_flags_set_rollout',
        description: 'Sets a flag\'s percentage rollout (0–100), or removes it with null. Visitors are bucketed deterministically, so raising the percentage only adds visitors; it never removes ones already in.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    #[RequiresPermission('featureFlags:manage')]
    public function setRollout(
        string $handle,
        #[Schema(minimum: 0, maximum: 100, description: 'Whole number from 0 to 100, or null to remove the rollout.')]
        ?int $percentage,
    ): array {
        $flag = $this->requireFlag($handle);
        $flag->rolloutPercentage = $percentage;

        return ['flag' => $this->save($flag)];
    }

    /**
     * @param list<array{type: string, value: string}> $rules
     * @param list<string>|null $sites
     * @return array{flag: array<string, mixed>}
     */
    #[McpTool(
        name: 'feature_flags_create',
        description: 'Creates a feature flag. New flags are off unless enabled is true. With no rules and no rollout, an enabled flag is on for everyone.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    #[RequiresPermission('featureFlags:manage')]
    public function create(
        string $name,
        #[Schema(pattern: '^[a-z][a-z0-9-]*$', maxLength: 100, description: 'Kebab-case handle used in templates. Generated from the name when omitted.')]
        ?string $handle = null,
        #[Schema(enum: ['release', 'experiment', 'ops', 'permission'])]
        string $type = 'release',
        bool $enabled = false,
        ?string $description = null,
        #[Schema(minimum: 0, maximum: 100)]
        ?int $rolloutPercentage = null,
        #[Schema(enum: ['all', 'rule'], description: 'all: a matching rule turns the flag on, otherwise the rollout decides. rule: the rollout applies only within rule-matched users.')]
        string $rolloutStrategy = 'all',
        #[Schema(description: 'Flag is off before this date. ' . self::DATE_DESCRIPTION)]
        ?string $startsAt = null,
        #[Schema(description: 'Flag is off after this date. ' . self::DATE_DESCRIPTION)]
        ?string $expiresAt = null,
        #[Schema(definition: self::RULE_SCHEMA)]
        array $rules = [],
        #[Schema(definition: self::SITES_SCHEMA)]
        ?array $sites = null,
    ): array {
        $this->requireFlagType($type);

        $flag = new Flag();
        $flag->name = $name;
        $flag->handle = $handle;
        $flag->flagType = $type;
        $flag->enabled = $enabled;
        $flag->description = $description;
        $flag->rolloutPercentage = $rolloutPercentage;
        $flag->rolloutStrategy = $rolloutStrategy;
        $flag->startsAt = $this->parseDate($startsAt, 'startsAt');
        $flag->expiresAt = $this->parseDate($expiresAt, 'expiresAt');
        $flag->rules = $this->buildRules($rules);
        $flag->siteSettings = $this->buildSiteSettings($sites);

        return ['flag' => $this->save($flag)];
    }

    /**
     * @param list<array{type: string, value: string}>|null $rules
     * @param list<string>|null $sites
     * @return array{flag: array<string, mixed>}
     */
    #[McpTool(
        name: 'feature_flags_update',
        description: 'Updates a feature flag. Only the arguments you send change; omitted ones keep their current values. rules replaces the complete rule list, so read the flag with feature_flags_get first. Renaming the handle breaks templates and code that check the old handle (they silently get false).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    #[RequiresPermission('featureFlags:manage')]
    public function update(
        RequestContext $context,
        #[Schema(description: 'Current handle of the flag to update.')]
        string $handle,
        ?string $name = null,
        #[Schema(pattern: '^[a-z][a-z0-9-]*$', maxLength: 100, description: 'Renames the handle.')]
        ?string $newHandle = null,
        #[Schema(enum: ['release', 'experiment', 'ops', 'permission'])]
        ?string $type = null,
        ?bool $enabled = null,
        ?string $description = null,
        #[Schema(minimum: 0, maximum: 100, description: 'Null removes the rollout.')]
        ?int $rolloutPercentage = null,
        #[Schema(enum: ['all', 'rule'])]
        ?string $rolloutStrategy = null,
        #[Schema(description: self::DATE_DESCRIPTION)]
        ?string $startsAt = null,
        #[Schema(description: self::DATE_DESCRIPTION)]
        ?string $expiresAt = null,
        #[Schema(definition: self::RULE_SCHEMA)]
        ?array $rules = null,
        #[Schema(definition: self::SITES_SCHEMA)]
        ?array $sites = null,
    ): array {
        $request = $context->getRequest();
        assert($request instanceof CallToolRequest);
        $sent = $request->arguments;

        $flag = $this->requireFlag($handle);

        if (array_key_exists('name', $sent)) {
            $flag->name = $name;
        }
        if (array_key_exists('newHandle', $sent)) {
            $flag->handle = $newHandle;
        }
        if (array_key_exists('type', $sent)) {
            $this->requireFlagType($type);
            $flag->flagType = $type;
        }
        if (array_key_exists('enabled', $sent)) {
            if ($enabled === null) {
                throw new ToolCallException('enabled must be true or false.');
            }
            $flag->enabled = $enabled;
        }
        if (array_key_exists('description', $sent)) {
            $flag->description = $description;
        }
        if (array_key_exists('rolloutPercentage', $sent)) {
            $flag->rolloutPercentage = $rolloutPercentage;
        }
        if (array_key_exists('rolloutStrategy', $sent)) {
            $flag->rolloutStrategy = $rolloutStrategy ?? 'all';
        }
        if (array_key_exists('startsAt', $sent)) {
            $flag->startsAt = $this->parseDate($startsAt, 'startsAt');
        }
        if (array_key_exists('expiresAt', $sent)) {
            $flag->expiresAt = $this->parseDate($expiresAt, 'expiresAt');
        }
        if (array_key_exists('rules', $sent)) {
            $flag->rules = $this->buildRules($rules ?? []);
        }
        if (array_key_exists('sites', $sent)) {
            $flag->siteSettings = $this->buildSiteSettings($sites);
        }

        return ['flag' => $this->save($flag)];
    }

    /**
     * @return array{deleted: true, handle: string}
     */
    #[McpTool(
        name: 'feature_flags_delete',
        description: 'Permanently deletes a feature flag and its rules. There is no undo. Templates and code still checking the handle will get false. To stop a feature without losing its configuration, turn the flag off with feature_flags_toggle instead.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    #[RequiresPermission('featureFlags:manage')]
    public function delete(string $handle): array
    {
        $flag = $this->requireFlag($handle);

        if (!FeatureFlags::getInstance()->flagService->deleteFlag($flag->id)) {
            throw new ToolCallException('The flag could not be deleted.');
        }

        return ['deleted' => true, 'handle' => $flag->handle];
    }

    /**
     * Whether a user may flip a flag's master switch. Mirrors FlagsController::actionToggle().
     */
    public static function canToggle(?CraftUser $user): bool
    {
        return $user !== null && ($user->can('featureFlags:toggle') || $user->can('featureFlags:manage'));
    }

    private function requireFlag(string $handle): Flag
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            throw new ToolCallException("Flag not found: {$handle}. Use feature_flags_list to see existing handles.");
        }

        return $flag;
    }

    private function requireFlagType(?string $type): void
    {
        $allowed = array_column(FlagType::cases(), 'value');

        if (!in_array($type, $allowed, true)) {
            throw new ToolCallException('type must be one of: ' . implode(', ', $allowed) . '.');
        }
    }

    private function save(Flag $flag): array
    {
        $flagService = FeatureFlags::getInstance()->flagService;

        if (!$flagService->saveFlag($flag)) {
            $errors = [];
            foreach ($flag->getErrors() as $attribute => $messages) {
                foreach ($messages as $message) {
                    $errors[] = "{$attribute}: {$message}";
                }
            }

            throw new ToolCallException('The flag could not be saved. ' . ($errors ? implode(' ', $errors) : 'A plugin may have cancelled the save.'));
        }

        return $this->serialize($flagService->getFlagById($flag->id));
    }

    private function parseDate(?string $value, string $argument): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value);

        if (!$date) {
            throw new ToolCallException("{$argument} is not a valid date. Use ISO 8601, such as 2026-12-01T09:00:00-05:00.");
        }

        return $date;
    }

    /**
     * @return Rule[]
     */
    private function buildRules(array $rules): array
    {
        return array_map(static function (mixed $input): Rule {
            if (!is_array($input) || !isset($input['type'], $input['value'])) {
                throw new ToolCallException('Each rule must be an object with type and value.');
            }

            $rule = new Rule();
            $rule->ruleType = (string)$input['type'];
            $rule->ruleValue = (string)$input['value'];

            return $rule;
        }, array_values($rules));
    }

    /**
     * @return array<int, bool>|null
     */
    private function buildSiteSettings(?array $handles): ?array
    {
        if (empty($handles)) {
            return null;
        }

        $sites = Craft::$app->getSites();
        $settings = [];

        foreach ($handles as $handle) {
            $site = is_string($handle) ? $sites->getSiteByHandle($handle) : null;
            if (!$site) {
                $available = implode(', ', array_map(static fn($s) => $s->handle, $sites->getAllSites()));
                throw new ToolCallException("Site not found: {$handle}. Available sites: {$available}.");
            }
            $settings[$site->id] = true;
        }

        return $settings;
    }

    private static function status(Flag $flag): string
    {
        if (!$flag->enabled) {
            return 'disabled';
        }

        $now = DateTimeHelper::now();

        if ($flag->startsAt !== null && $now < $flag->startsAt) {
            return 'scheduled';
        }

        if ($flag->expiresAt !== null && $flag->expiresAt < $now) {
            return 'expired';
        }

        return 'active';
    }

    private function summarize(Flag $flag): array
    {
        return [
            'handle' => $flag->handle,
            'name' => $flag->name,
            'type' => $flag->flagType,
            'enabled' => $flag->enabled,
            'status' => self::status($flag),
            'rolloutPercentage' => $flag->rolloutPercentage,
            'rulesCount' => count($flag->rules),
            'startsAt' => $flag->startsAt?->format(DATE_ATOM),
            'expiresAt' => $flag->expiresAt?->format(DATE_ATOM),
        ];
    }

    private function serialize(Flag $flag): array
    {
        $sites = null;
        if (!empty($flag->siteSettings)) {
            $sites = [];
            foreach ($flag->siteSettings as $siteId => $enabled) {
                if ($enabled) {
                    $sites[] = Craft::$app->getSites()->getSiteById($siteId)?->handle ?? (string)$siteId;
                }
            }
        }

        return [
            'id' => $flag->id,
            'uid' => $flag->uid,
            'handle' => $flag->handle,
            'name' => $flag->name,
            'description' => $flag->description,
            'type' => $flag->flagType,
            'enabled' => $flag->enabled,
            'status' => self::status($flag),
            'rolloutPercentage' => $flag->rolloutPercentage,
            'rolloutStrategy' => $flag->rolloutStrategy,
            'startsAt' => $flag->startsAt?->format(DATE_ATOM),
            'expiresAt' => $flag->expiresAt?->format(DATE_ATOM),
            'rules' => array_map(static fn(Rule $rule): array => ['type' => $rule->ruleType, 'value' => $rule->ruleValue], $flag->rules),
            'sites' => $sites,
            'dateCreated' => $flag->dateCreated?->format(DATE_ATOM),
            'dateUpdated' => $flag->dateUpdated?->format(DATE_ATOM),
            'cpEditUrl' => UrlHelper::cpUrl('feature-flags/' . $flag->id),
            'usage' => [
                'twig' => "{% if craft.featureFlags.isEnabled('{$flag->handle}') %}",
                'php' => "FeatureFlags::getInstance()->evaluationService->isEnabled('{$flag->handle}')",
            ],
        ];
    }

    private function explanation(array $result, ?Flag $flag): string
    {
        $pct = $flag?->rolloutPercentage;
        $rule = $result['matchedRule'];

        return match ($result['reason']) {
            'not_found' => 'No flag has this handle, so checks return false.',
            'disabled' => 'The master switch is off.',
            'site_gated' => 'The flag is scoped to specific sites and is not enabled on this site (or no site was given).',
            'not_started' => 'The flag\'s start date is in the future: ' . $flag?->startsAt?->format(DATE_ATOM) . '.',
            'expired' => 'The flag expired on ' . $flag?->expiresAt?->format(DATE_ATOM) . '.',
            'global_on' => 'The flag is on with no rules and no rollout, so it is on for everyone.',
            'rule_matched' => $rule
                ? "Targeting rule {$rule['type']} = {$rule['value']} matched."
                : 'The flag has no rules and no rollout limit under the rule strategy.',
            'no_rule_matched' => 'No targeting rule matched' . ($pct === null ? ' and there is no rollout.' : '.'),
            'rollout_zero' => 'The rollout is 0%.',
            'rollout_in_bucket' => "Bucket {$result['bucket']} is below the {$pct}% rollout.",
            'rollout_out_of_bucket' => "Bucket {$result['bucket']} is not below the {$pct}% rollout.",
            'rollout_no_bucket_input' => "The {$pct}% rollout needs a user or bucketKey to place the visitor in a bucket.",
            default => $result['reason'],
        };
    }
}
