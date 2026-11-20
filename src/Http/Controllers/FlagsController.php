<?php

namespace craftquest\featureflags\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\DateTimeHelper;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\DateTime as DateTimeControl;
use CraftCms\Cms\Ui\Controls\Handle;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table as TableControl;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\CopyAttribute;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\HiddenField;
use CraftCms\Cms\Ui\Nodes\Table as UiTable;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiPayload;
use CraftCms\Cms\Ui\UiResolver;
use craftquest\featureflags\enums\FlagType;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use DateTime;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\t;

/**
 * Control panel screens for listing, editing, toggling, and deleting flags.
 */
class FlagsController
{
    use RespondsWithFlash;

    public function index(): CpScreenResponse
    {
        $canManage = Gate::check('featureFlags:manage');
        $canToggle = $canManage || Gate::check('featureFlags:toggle');
        $now = now();

        $rows = array_map(function(Flag $flag) use ($canManage, $now) {
            $status = match (true) {
                !$flag->enabled => t('Disabled', [], 'feature-flags'),
                $flag->startsAt !== null && $now < $flag->startsAt => t('Scheduled', [], 'feature-flags'),
                $flag->expiresAt !== null && $flag->expiresAt < $now => t('Expired', [], 'feature-flags'),
                default => t('Active', [], 'feature-flags'),
            };

            return [
                'id' => $flag->id,
                'name' => $canManage
                    ? ['label' => $flag->name, 'url' => route('feature-flags.edit', ['flagId' => $flag->id])]
                    : $flag->name,
                'handle' => ['html' => '<code>' . Html::encode($flag->handle) . '</code>'],
                'type' => t(ucfirst($flag->flagType), [], 'feature-flags'),
                'status' => $status,
                'rollout' => $flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : '—',
                'rules' => count($flag->rules),
                'expires' => $flag->expiresAt ? ['date' => $flag->expiresAt->format(DATE_ATOM)] : '—',
                '_status' => $flag->enabled,
                ...($canManage ? [
                    '_deleteUrl' => route('feature-flags.destroy', ['flagId' => $flag->id]),
                    '_deleteConfirmMessage' => t('Are you sure you want to delete “{name}”? Templates checking “{handle}” will get false.', ['name' => $flag->name, 'handle' => $flag->handle], 'feature-flags'),
                ] : []),
            ];
        }, FeatureFlags::getInstance()->flagService->getAllFlags());

        $table = UiTable::make('feature-flags')
            ->columns([
                ['key' => 'name', 'label' => t('Name', [], 'feature-flags'), 'sortable' => true],
                ['key' => 'handle', 'label' => t('Handle', [], 'feature-flags')],
                ['key' => 'type', 'label' => t('Type', [], 'feature-flags')],
                ['key' => 'status', 'label' => t('Status', [], 'feature-flags')],
                ['key' => 'rollout', 'label' => t('Rollout', [], 'feature-flags'), 'thin' => true],
                ['key' => 'rules', 'label' => t('Rules', [], 'feature-flags'), 'thin' => true],
                ['key' => 'expires', 'label' => t('Expires', [], 'feature-flags')],
            ])
            ->rows($rows)
            ->statusFilter()
            ->searchable()
            ->emptyMessage(t('No feature flags yet.', [], 'feature-flags'))
            ->when($canToggle, fn(UiTable $t) => $t->statusActions([
                ['label' => t('Enabled', [], 'feature-flags'), 'url' => route('feature-flags.status'), 'params' => ['enabled' => true], 'fill' => 'success'],
                ['label' => t('Disabled', [], 'feature-flags'), 'url' => route('feature-flags.status'), 'params' => ['enabled' => false], 'fill' => 'gray'],
            ]))
            ->when($canManage, fn(UiTable $t) => $t
                ->deletable()
                ->createAction(t('New flag', [], 'feature-flags'), route('feature-flags.create'))
                ->createActionInPageHeader());

        return new CpScreenResponse()
            ->title(FeatureFlags::getInstance()->getSettings()->pluginName ?: t('Feature Flags', [], 'feature-flags'))
            ->ui(Ui::make([$table]));
    }

    public function create(): CpScreenResponse
    {
        return $this->editScreen(new Flag());
    }

    public function edit(int $flagId): CpScreenResponse
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagById($flagId);
        abort_if($flag === null, 404);

        return $this->editScreen($flag);
    }

    /**
     * Rebuilds the edit form as values change (the handle-rename warning).
     */
    public function renderUi(Request $request): JsonResponse
    {
        $request->validate(['values' => ['required', 'array'], 'scope' => ['present', 'array', 'size:0']]);
        $values = $request->array('values');
        $flag = $this->flagForValues($values) ?? new Flag();

        return new JsonResponse(['ui' => $this->formUi($flag, $values)]);
    }

    public function store(Request $request): Response
    {
        $flagService = FeatureFlags::getInstance()->flagService;
        $values = $request->all();

        $flag = $this->flagForValues($values);
        if (!empty($values['flagId']) && $flag === null) {
            abort(404);
        }
        $flag ??= new Flag();
        $isNew = !$flag->id;

        $flag->name = (string)($values['name'] ?? '');
        $flag->handle = (string)($values['handle'] ?? '');
        $flag->description = ($values['description'] ?? '') !== '' ? (string)$values['description'] : null;
        $flag->enabled = filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $flag->flagType = (string)($values['type'] ?? FlagType::Release->value);
        $flag->startsAt = $this->dateTime($values['startsAt'] ?? null);
        $flag->expiresAt = $this->dateTime($values['expiresAt'] ?? null);
        $flag->rolloutStrategy = (string)($values['rolloutStrategy'] ?? 'all');
        $rollout = $values['rolloutPercentage'] ?? null;
        $flag->rolloutPercentage = ($rollout === null || $rollout === '') ? null : (int)$rollout;
        $flag->rules = $this->rules($values['rules'] ?? []);

        // The Sites field only exists on multi-site installs; leave the scope alone otherwise.
        if (array_key_exists('siteIds', $values)) {
            $flag->siteSettings = $this->siteSettings($values['siteIds']);
        }

        if (!$flagService->saveFlag($flag)) {
            throw ValidationException::withMessages($this->formErrors($flag));
        }

        return $this->asSuccess(
            t('Flag saved.', [], 'feature-flags'),
            redirect: $isNew ? $this->createdRedirectUrl(route('feature-flags.edit', ['flagId' => $flag->id])) : null,
        );
    }

    /**
     * Status action from the flag list: enables or disables the selected flags.
     */
    public function setStatus(Request $request): Response
    {
        abort_unless(Gate::any(['featureFlags:toggle', 'featureFlags:manage']), 403);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $flagService = FeatureFlags::getInstance()->flagService;
        foreach ($data['ids'] as $id) {
            $flagService->toggleFlag((int)$id, (bool)$data['enabled']);
        }

        return $this->asSuccess($data['enabled']
            ? t('Flags enabled.', [], 'feature-flags')
            : t('Flags disabled.', [], 'feature-flags'));
    }

    public function destroy(int $flagId): Response
    {
        if (!FeatureFlags::getInstance()->flagService->deleteFlag($flagId)) {
            return $this->asFailure(t('Couldn’t delete the flag.', [], 'feature-flags'));
        }

        return $this->asSuccess(t('Flag deleted.', [], 'feature-flags'));
    }

    private function editScreen(Flag $flag): CpScreenResponse
    {
        $title = $flag->id ? $flag->name : t('New flag', [], 'feature-flags');

        return new CpScreenResponse()
            ->title($title)
            ->addCrumb(t('Feature Flags', [], 'feature-flags'), 'feature-flags')
            ->redirectUrl('feature-flags')
            ->ui($this->formUi($flag, $this->values($flag)), props: [
                'submit' => ['method' => 'post', 'url' => route('feature-flags.store')],
                'refreshUrl' => route('feature-flags.render-ui'),
                'sidebarUi' => $this->sidebarUi($flag),
            ]);
    }

    private function formUi(Flag $flag, array $values): UiPayload
    {
        // Templates checking the old handle silently get false, so warn on rename.
        $handle = Handle::make('handle')->reactive();
        if (!$flag->id) {
            $handle->source('name');
        }
        $handleField = Field::make(t('Handle', [], 'feature-flags'), $handle)
            ->required()
            ->instructions(t('Used in templates and code: lowercase letters, numbers, and hyphens.', [], 'feature-flags'));
        if ($flag->id && ($values['handle'] ?? $flag->handle) !== $flag->handle) {
            $handleField->warning(t('Renaming the handle will disable this flag anywhere the old handle “{handle}” is still used.', ['handle' => $flag->handle], 'feature-flags'));
        }

        $nodes = [
            HiddenField::make('flagId'),
            Field::make(t('Enabled', [], 'feature-flags'), Lightswitch::make('enabled'))
                ->instructions(t('Master switch. When off, the flag is disabled regardless of rules or rollout.', [], 'feature-flags')),
            Field::make(t('Name', [], 'feature-flags'), Text::make('name')->autofocus())->required(),
            $handleField,
            Field::make(t('Description', [], 'feature-flags'), Textarea::make('description')->rows(2)),
            Field::make(t('Type', [], 'feature-flags'), Choice::make('type')->options(array_map(
                fn(FlagType $type) => ['label' => t(ucfirst($type->value), [], 'feature-flags'), 'value' => $type->value],
                FlagType::cases(),
            ))->withoutPlaceholder()),
            Field::make(t('Starts At', [], 'feature-flags'), DateTimeControl::make('startsAt')->showTime())
                ->instructions(t('Optional. The flag is off before this date.', [], 'feature-flags')),
            Field::make(t('Expires At', [], 'feature-flags'), DateTimeControl::make('expiresAt')->showTime())
                ->instructions(t('Optional. The flag is off after this date.', [], 'feature-flags')),
        ];

        $sites = Sites::getAllSites();
        if ($sites->count() > 1) {
            $nodes[] = Field::make(t('Sites', [], 'feature-flags'), Choice::make('siteIds')
                ->options($sites->map(fn($site) => ['label' => $site->getName(), 'value' => (string)$site->id])->values()->all())
                ->allOption(t('All sites', [], 'feature-flags')))
                ->instructions(t('Sites added later are off unless “All sites” is selected.', [], 'feature-flags'));
        }

        $nodes[] = Field::make(t('Targeting Rules', [], 'feature-flags'), TableControl::make('rules')
            ->columns([
                'type' => [
                    'heading' => t('Rule type', [], 'feature-flags'),
                    'type' => 'select',
                    'options' => FeatureFlags::getInstance()->flagService->getRuleTypes(),
                ],
                'value' => ['heading' => t('Value', [], 'feature-flags'), 'type' => 'singleline'],
            ])
            ->allowAdd()
            ->allowDelete()
            ->allowReorder()
            ->addRowLabel(t('Add a rule', [], 'feature-flags')))
            ->instructions(t('The flag is on for anyone matching any rule. With no rules and no rollout, it’s on for everyone.', [], 'feature-flags'));

        $nodes[] = Field::make(t('Rollout Strategy', [], 'feature-flags'), Choice::make('rolloutStrategy')->options([
            ['label' => t('All visitors — matching a rule turns the flag on; the rollout decides for everyone else', [], 'feature-flags'), 'value' => 'all'],
            ['label' => t('Rule-matched only — the rollout percentage filters the targeted group', [], 'feature-flags'), 'value' => 'rule'],
        ])->withoutPlaceholder());

        $nodes[] = Field::make(t('Rollout Percentage', [], 'feature-flags'), Number::make('rolloutPercentage')->min('0')->max('100')->size(5)->suffix('%'))
            ->instructions(t('Leave empty for no rollout. Visitors are bucketed consistently, so raising it only adds visitors.', [], 'feature-flags'));

        return app(UiResolver::class)->resolve(Ui::make($nodes), new UiContext(values: $values, refreshable: true));
    }

    private function sidebarUi(Flag $flag): ?UiPayload
    {
        if (!$flag->id) {
            return null;
        }

        return app(UiResolver::class)->resolve(Ui::make([
            Heading::make('usage-heading', t('Usage', [], 'feature-flags')),
            CopyAttribute::make('usage-twig', "{% if craft.featureFlags.isEnabled('{$flag->handle}') %}"),
            CopyAttribute::make('usage-php', "FeatureFlags::getInstance()->evaluationService->isEnabled('{$flag->handle}')"),
            CopyAttribute::make('usage-console', "php artisan craft:feature-flags:flags:enable {$flag->handle}"),
        ]), new UiContext());
    }

    /** Form values for a saved (or new) flag. */
    private function values(Flag $flag): array
    {
        $siteIds = '*';
        if (!empty($flag->siteSettings)) {
            $siteIds = array_map('strval', array_keys(array_filter($flag->siteSettings)));
        }

        return [
            'flagId' => $flag->id,
            'enabled' => $flag->enabled,
            'name' => $flag->name ?? '',
            'handle' => $flag->handle ?? '',
            'description' => $flag->description ?? '',
            'type' => $flag->flagType,
            'startsAt' => $this->dateTimeValue($flag->startsAt),
            'expiresAt' => $this->dateTimeValue($flag->expiresAt),
            'siteIds' => $siteIds,
            'rules' => array_map(fn(Rule $rule) => ['type' => $rule->ruleType, 'value' => $rule->ruleValue], $flag->rules),
            'rolloutStrategy' => $flag->rolloutStrategy,
            'rolloutPercentage' => $flag->rolloutPercentage,
        ];
    }

    private function flagForValues(array $values): ?Flag
    {
        $id = (int)($values['flagId'] ?? 0);

        return $id ? FeatureFlags::getInstance()->flagService->getFlagById($id) : null;
    }

    /** @return Rule[] */
    private function rules(mixed $rows): array
    {
        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $type = trim((string)($row['type'] ?? ''));
            $value = trim((string)($row['value'] ?? ''));
            if ($type === '' && $value === '') {
                continue; // an empty row someone added and left blank
            }
            $rule = new Rule();
            $rule->ruleType = $type;
            $rule->ruleValue = $value;
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * "All sites" (posted as `*`) means no per-site rows. Otherwise store an explicit row for
     * every site, so sites added later stay off and deleting a site never widens the scope.
     *
     * @return array<int, bool>|null
     */
    private function siteSettings(mixed $siteIds): ?array
    {
        $siteIds = (array)$siteIds;
        if ($siteIds === [] || in_array('*', $siteIds, true)) {
            return null;
        }

        $selected = array_map('intval', $siteIds);
        $settings = [];
        foreach (Sites::getAllSites() as $site) {
            $settings[$site->id] = in_array($site->id, $selected, true);
        }

        return $settings;
    }

    /** Maps model error keys onto the form's field paths. */
    private function formErrors(Flag $flag): array
    {
        $errors = [];
        foreach ($flag->errors()->getMessages() as $key => $messages) {
            $key = match (true) {
                $key === 'flagType' => 'type',
                $key === 'siteSettings' => 'siteIds',
                default => preg_replace(['/\.ruleType$/', '/\.ruleValue$/'], ['.type', '.value'], $key),
            };
            $errors[$key] = $messages;
        }

        return $errors ?: ['name' => [t('Couldn’t save the flag.', [], 'feature-flags')]];
    }

    /** @return array{date: string, time: string, timezone: string} */
    private function dateTimeValue(?DateTimeInterface $date): array
    {
        $date = $date ? Date::instance($date)->setTimezone(Cms::timezone()) : null;

        return [
            'date' => $date?->format('Y-m-d') ?? '',
            'time' => $date?->format('H:i') ?? '',
            'timezone' => Cms::timezone(),
        ];
    }

    private function dateTime(mixed $value): ?DateTime
    {
        if (!is_array($value) || ($value['date'] ?? '') === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime([
            'datetime' => trim(sprintf('%s %s', $value['date'], $value['time'] ?? '00:00')),
            'timezone' => $value['timezone'] ?? Cms::timezone(),
        ]);

        return $date ? DateTime::createFromInterface($date) : null;
    }
}
