<?php

return [
    // Navigation & headings
    'Feature Flags' => 'Feature Flags',
    'All Flags' => 'All Flags',
    'Audit Log' => 'Audit Log',
    'Audit Log: {name}' => 'Audit Log: {name}',
    'Create Flag' => 'Create Flag',
    'Edit Flag: {name}' => 'Edit Flag: {name}',
    'New Flag' => 'New Flag',
    'Targeting Rules' => 'Targeting Rules',

    // Permissions
    'View feature flags' => 'View feature flags',
    'Manage feature flags' => 'Manage feature flags',

    // Flag types
    'Release' => 'Release',
    'Experiment' => 'Experiment',
    'Ops' => 'Ops',
    'Permission' => 'Permission',

    // Rule types
    'User ID' => 'User ID',
    'User Group' => 'User Group',
    'Environment' => 'Environment',
    'Subscription Plan' => 'Subscription Plan',

    // Flag edit form – labels
    'Enabled' => 'Enabled',
    'Name' => 'Name',
    'Handle' => 'Handle',
    'Description' => 'Description',
    'Type' => 'Type',
    'Rollout Percentage' => 'Rollout Percentage',
    'Expires At' => 'Expires At',
    'Date Created' => 'Date Created',
    'Date Updated' => 'Date Updated',

    // Flag edit form – instructions
    'Master switch. When off, the flag is disabled regardless of rules or rollout.' => 'Master switch. When off, the flag is disabled regardless of rules or rollout.',
    'A human-readable name for this flag.' => 'A human-readable name for this flag.',
    'Used in templates and APIs.' => 'Used in templates and APIs.',
    'Human-readable description of what this flag controls.' => 'Human-readable description of what this flag controls.',
    '0–100. Users are bucketed consistently by their user ID.' => '0–100. Users are bucketed consistently by their user ID.',
    'Optional. Flag returns false after this date.' => 'Optional. Flag returns false after this date.',
    'Rules are evaluated in order. If any rule matches, the flag is enabled for that user. Leave empty for a global flag.' => 'Rules are evaluated in order. If any rule matches, the flag is enabled for that user. Leave empty for a global flag.',

    // Rule rows
    'Rule Type' => 'Rule Type',
    'Value' => 'Value',
    'User ID, group handle, plan handle, or env name' => 'User ID, group handle, plan handle, or env name',
    'Add Rule' => 'Add Rule',
    'Remove rule' => 'Remove rule',

    // Flag index table
    'Status' => 'Status',
    'Rollout %' => 'Rollout %',
    'Rules' => 'Rules',
    'Expires' => 'Expires',
    'Disabled' => 'Disabled',
    'No feature flags yet.' => 'No feature flags yet.',

    // Audit log table
    'Date' => 'Date',
    'Flag' => 'Flag',
    'Action' => 'Action',
    'User' => 'User',
    'Details' => 'Details',
    '(deleted)' => '(deleted)',
    'No audit entries yet.' => 'No audit entries yet.',
    'View history' => 'View history',

    // Actions & confirmations
    'Are you sure you want to delete this flag?' => 'Are you sure you want to delete this flag?',

    // Flash messages
    'Flag saved.' => 'Flag saved.',
    'Couldn\'t save flag.' => 'Couldn\'t save flag.',
    'Flag toggled.' => 'Flag toggled.',
    'Could not toggle flag.' => 'Could not toggle flag.',
    'Flag deleted.' => 'Flag deleted.',
    'Could not delete flag.' => 'Could not delete flag.',

    // Errors
    'Flag not found' => 'Flag not found',
    'Handle must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens.' => 'Handle must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens.',
    'Unknown rule type: {type}.' => 'Unknown rule type: {type}.',

    // Console commands
    'Yes' => 'Yes',
    'No' => 'No',
    'Aborted.' => 'Aborted.',
    'No targeting rules.' => 'No targeting rules.',
    'Flag "{name}" enabled.' => 'Flag "{name}" enabled.',
    'Flag "{name}" disabled.' => 'Flag "{name}" disabled.',
    'Flag "{name}" deleted.' => 'Flag "{name}" deleted.',
    'No expired flags found.' => 'No expired flags found.',
    '{count} expired flag(s) found.' => '{count} expired flag(s) found.',
    'Delete all expired flags?' => 'Delete all expired flags?',
    'Deleted: {name}' => 'Deleted: {name}',
    'Failed to delete: {name}' => 'Failed to delete: {name}',
    '{count} flag(s) deleted.' => '{count} flag(s) deleted.',

    // Settings
    'Plugin Name' => 'Plugin Name',
    'Override the plugin name shown in the CP navigation.' => 'Override the plugin name shown in the CP navigation.',
    'Cache TTL' => 'Cache TTL',
    'How long (in seconds) to cache flag data in the application cache. Set to 0 to disable caching.' => 'How long (in seconds) to cache flag data in the application cache. Set to 0 to disable caching.',
    'Enable Audit Log' => 'Enable Audit Log',
    'Log all flag changes with user attribution. Disabling this stops new audit entries from being created.' => 'Log all flag changes with user attribution. Disabling this stops new audit entries from being created.',
    'Anonymous Cookie Name' => 'Anonymous Cookie Name',
    'Name of the cookie used to assign a stable visitor ID for anonymous percentage rollouts.' => 'Name of the cookie used to assign a stable visitor ID for anonymous percentage rollouts.',
    'Anonymous Cookie TTL' => 'Anonymous Cookie TTL',
    'How long (in seconds) the anonymous visitor cookie lasts. Set to 0 to disable cookie-based bucketing.' => 'How long (in seconds) the anonymous visitor cookie lasts. Set to 0 to disable cookie-based bucketing.',
];
