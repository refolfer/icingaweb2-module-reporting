<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting;

use Exception;
use Icinga\Authentication\Auth;
use ipl\Stdlib\Filter;

final class Restrictions
{
    public const FILTER_OBJECTS = 'reporting/filter/objects';
    public const USERS = 'reporting/users';
    public const GROUPS = 'reporting/groups';

    private function __construct()
    {
    }

    /**
     * Apply runtime object restrictions to a reportlet config.
     *
     * Reportlets that expose a `filter` option are restricted directly. In addition,
     * Icinga DB reporting hooks are treated as filter-aware even if the persisted
     * config does not yet contain a `filter` key.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function applyToReportletConfig(array $config, ?string $reportletClass = null): array
    {
        if (! array_key_exists('filter', $config) && ! self::supportsObjectFilter($reportletClass)) {
            return $config;
        }

        $restrictionFilter = self::buildFilterExpression(self::getObjectRestrictions());
        if ($restrictionFilter === null) {
            return $config;
        }

        $config['filter'] = self::mergeFilters($config['filter'] ?? null, $restrictionFilter);

        return $config;
    }

    public static function hasAccess(): bool
    {
        try {
            $auth = Auth::getInstance();
            $user = $auth->getUser();
        } catch (Exception $_) {
            return true;
        }

        if ($user === null || $user->isUnrestricted()) {
            return true;
        }

        $users = self::normalizeAccessRestrictions($user->getRestrictions(self::USERS));
        $groups = self::normalizeAccessRestrictions($user->getRestrictions(self::GROUPS));

        if (empty($users) && empty($groups)) {
            return true;
        }

        $username = strtolower($user->getUsername());
        if (in_array('*', $users, true) || in_array($username, $users, true)) {
            return true;
        }

        $userGroups = array_map('strtolower', $user->getGroups());
        if (in_array('*', $groups, true) || ! empty(array_intersect($userGroups, $groups))) {
            return true;
        }

        return false;
    }

    public static function getReportAccessFilter()
    {
        if (self::canAccessAllReports()) {
            return null;
        }

        $username = self::getUsername();
        if ($username === null) {
            return Filter::equal('author', '');
        }

        return Filter::equal('author', $username);
    }

    public static function canAccessAllReports(): bool
    {
        try {
            $user = Auth::getInstance()->getUser();
        } catch (Exception $_) {
            return true;
        }

        if ($user === null || $user->isUnrestricted()) {
            return true;
        }

        $users = self::normalizeAccessRestrictions($user->getRestrictions(self::USERS));
        $groups = self::normalizeAccessRestrictions($user->getRestrictions(self::GROUPS));

        return empty($users) && empty($groups);
    }

    /**
     * @return string[]
     */
    public static function getObjectRestrictions(): array
    {
        try {
            $user = Auth::getInstance()->getUser();
        } catch (Exception $_) {
            return [];
        }

        if ($user === null) {
            return [];
        }

        $restrictions = $user->getRestrictions(self::FILTER_OBJECTS);
        if ($restrictions === null) {
            return [];
        }

        if (! is_array($restrictions)) {
            $restrictions = [$restrictions];
        }

        $filters = [];
        foreach ($restrictions as $restriction) {
            $restriction = trim((string) $restriction);
            if ($restriction !== '') {
                $filters[] = $restriction;
            }
        }

        return $filters;
    }

    /**
     * @param string[] $filters
     */
    private static function buildFilterExpression(array $filters): ?string
    {
        if (empty($filters)) {
            return null;
        }

        return implode('|', array_map([self::class, 'wrapFilter'], $filters));
    }

    /**
     * @param mixed $filter
     */
    private static function mergeFilters($filter, string $restrictionFilter): string
    {
        $filter = trim((string) $filter);
        if ($filter === '') {
            return $restrictionFilter;
        }

        return sprintf(
            '%s&%s',
            self::wrapFilter($filter),
            self::wrapFilter($restrictionFilter)
        );
    }

    private static function wrapFilter(string $filter): string
    {
        return sprintf('(%s)', trim($filter));
    }

    private static function supportsObjectFilter(?string $reportletClass): bool
    {
        return $reportletClass !== null
            && strpos($reportletClass, 'Icinga\\Module\\Icingadb\\ProvidedHook\\Reporting\\') === 0;
    }

    private static function getUsername(): ?string
    {
        try {
            $user = Auth::getInstance()->getUser();
        } catch (Exception $_) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        return $user->getUsername();
    }

    /**
     * @param mixed $restrictions
     *
     * @return string[]
     */
    private static function normalizeAccessRestrictions($restrictions): array
    {
        if ($restrictions === null || $restrictions === '') {
            return [];
        }

        if (! is_array($restrictions)) {
            $restrictions = [$restrictions];
        }

        $values = [];
        foreach ($restrictions as $restriction) {
            $parts = preg_split('/[,\r\n]+/', (string) $restriction) ?: [];
            foreach ($parts as $value) {
                $value = strtolower(trim($value));
                if ($value !== '') {
                    $values[$value] = $value;
                }
            }
        }

        return array_values($values);
    }
}
