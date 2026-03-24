<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting;

use Exception;
use Icinga\Authentication\Auth;

final class Restrictions
{
    public const FILTER_OBJECTS = 'reporting/filter/objects';

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
}
