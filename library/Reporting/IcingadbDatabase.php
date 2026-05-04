<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting;

use Icinga\Application\Config;
use Icinga\Data\ResourceFactory;
use ipl\Sql;
use PDO;

final class IcingadbDatabase
{
    /** @var RetryConnection */
    private static $instance;

    /** @var ?string */
    private static $driver;

    private function __construct()
    {
    }

    public static function get(): RetryConnection
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    public static function getDriver(): string
    {
        if (self::$driver === null) {
            self::get();
        }

        return self::$driver;
    }

    private static function createConnection(): RetryConnection
    {
        $config = new Sql\Config(ResourceFactory::getResourceConfig(self::getResourceName()));
        $config->options = [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ];
        self::$driver = $config->db;

        return new RetryConnection($config);
    }

    private static function getResourceName(): string
    {
        $reportingConfig = Config::module('reporting');
        $resource = $reportingConfig->get('icingadb', 'resource');
        if ($resource !== null) {
            return $resource;
        }

        $icingadbConfig = Config::module('icingadb');

        return $icingadbConfig->get('database', 'resource')
            ?: $icingadbConfig->get('db', 'resource')
            ?: 'icingadb';
    }
}
