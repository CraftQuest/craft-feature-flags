<?php

namespace craftquest\featureflags\Http\Controllers;

use CraftCms\Cms\Http\Requests\TableRequest;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Ui\Nodes\Table as UiTable;
use CraftCms\Cms\Ui\Ui;
use craftquest\featureflags\FeatureFlags;

use function CraftCms\Cms\t;

/**
 * The audit log: every flag change, newest first, optionally for a single flag.
 */
class AuditController
{
    public function index(TableRequest $request, ?int $flagId = null): CpScreenResponse
    {
        $flagService = FeatureFlags::getInstance()->flagService;
        $flag = $flagId ? $flagService->getFlagById($flagId) : null;

        $perPage = $request->limit();
        $page = max(1, $request->page());
        // Fetch one extra row to know whether there's a next page.
        $entries = $flagService->getAuditLog($flagId, $perPage + 1, ($page - 1) * $perPage);
        $hasMore = count($entries) > $perPage;
        $entries = array_slice($entries, 0, $perPage);

        $actions = [
            'created' => t('Created', [], 'feature-flags'),
            'updated' => t('Updated', [], 'feature-flags'),
            'enabled' => t('Enabled', [], 'feature-flags'),
            'disabled' => t('Disabled', [], 'feature-flags'),
            'deleted' => t('Deleted', [], 'feature-flags'),
        ];

        $rows = array_map(function(array $entry) use ($actions) {
            $details = Json::decodeIfJson((string)$entry['details']) ?: [];
            $flagLabel = $entry['flagName'] ?? ($details['name'] ?? '—');

            return [
                'id' => (int)$entry['id'],
                'date' => ['date' => (new \DateTime($entry['dateCreated'], new \DateTimeZone('UTC')))->format(DATE_ATOM)],
                'user' => $entry['username'] ?? t('System', [], 'feature-flags'),
                'action' => $actions[$entry['action']] ?? $entry['action'],
                'flag' => $entry['flagId']
                    ? ['label' => $flagLabel, 'url' => route('feature-flags.audit', ['flagId' => $entry['flagId']])]
                    : $flagLabel,
                'details' => ['html' => '<code>' . Html::encode(Json::encode($details)) . '</code>'],
            ];
        }, $entries);

        $from = $rows ? ($page - 1) * $perPage + 1 : 0;
        $table = UiTable::make('feature-flags-audit')
            ->columns([
                ['key' => 'date', 'label' => t('Date', [], 'feature-flags')],
                ['key' => 'user', 'label' => t('User', [], 'feature-flags')],
                ['key' => 'action', 'label' => t('Action', [], 'feature-flags')],
                ['key' => 'flag', 'label' => t('Flag', [], 'feature-flags')],
                ['key' => 'details', 'label' => t('Details', [], 'feature-flags'), 'wrap' => true],
            ])
            ->rows($rows)
            ->pagination([
                'per_page' => $perPage,
                'current_page' => $page,
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
                'next_page_url' => $hasMore ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
                'prev_page_url' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            ])
            ->emptyMessage(t('No audit entries yet.', [], 'feature-flags'));

        $title = $flag
            ? t('Audit Log: {name}', ['name' => $flag->name], 'feature-flags')
            : t('Audit Log', [], 'feature-flags');

        return new CpScreenResponse()
            ->title($title)
            ->addCrumb(t('Feature Flags', [], 'feature-flags'), 'feature-flags')
            ->ui(Ui::make([$table]));
    }
}
