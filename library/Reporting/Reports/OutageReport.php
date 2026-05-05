<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting\Reports;

use Exception;
use Icinga\Module\Reporting\Hook\ReportHook;
use Icinga\Module\Reporting\IcingadbDatabase;
use Icinga\Module\Reporting\ReportData;
use Icinga\Module\Reporting\ReportRow;
use Icinga\Module\Reporting\Timerange;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlString;
use ipl\Html\ValidHtml;
use PDO;

use function ipl\I18n\t;

class OutageReport extends ReportHook
{
    private const TYPE_ALL = 'all';
    private const TYPE_HOST = 'host';
    private const TYPE_SERVICE = 'service';

    public function getName()
    {
        return 'Outage Report (Icinga DB)';
    }

    public function getDescription()
    {
        return t('Reports host and service outages with detailed descriptions and time series charts.');
    }

    public function initConfigForm(Form $form)
    {
        $form->addElement('select', 'outage_object_type', [
            'label'       => t('Objects'),
            'description' => t('Choose whether host outages, service outages or both should be reported'),
            'options'     => [
                self::TYPE_ALL     => t('Hosts and Services'),
                self::TYPE_HOST    => t('Hosts'),
                self::TYPE_SERVICE => t('Services')
            ]
        ]);

        $form->addElement('text', 'outage_filter', [
            'label'       => t('Name Filter'),
            'description' => t('Optional case-insensitive text filter for host or service names')
        ]);

        $form->addElement('select', 'outage_service_state', [
            'label'       => t('Service Outage State'),
            'description' => t('Choose which service states should count as outages'),
            'options'     => [
                'critical' => t('Critical and Unknown'),
                'warning'  => t('Warning, Critical and Unknown')
            ]
        ]);

        $form->addElement('number', 'outage_limit', [
            'label'       => t('Object Limit'),
            'description' => t('Maximum number of hosts and services included in the report'),
            'value'       => 25,
            'min'         => 1,
            'max'         => 200
        ]);

        $form->addElement('number', 'outage_max_details', [
            'label'       => t('Details per Object'),
            'description' => t('Maximum number of outage intervals listed below each chart'),
            'value'       => 10,
            'min'         => 1,
            'max'         => 50
        ]);
    }

    public function getData(Timerange $timerange, ?array $config = null)
    {
        $rows = [];

        foreach ($this->fetchOutages($timerange, $config ?? []) as $object) {
            $row = new ReportRow();
            $rows[] = $row
                ->setDimensions([$object['type_label'], $object['label']])
                ->setValues([
                    $object['outage_count'],
                    $object['outage_seconds'],
                    $object['outage_percent']
                ]);
        }

        return (new ReportData())
            ->setDimensions([t('Type'), t('Object')])
            ->setValues([t('Outages'), t('Outage Seconds'), t('Outage Percent')])
            ->setRows($rows);
    }

    public function getHtml(Timerange $timerange, ?array $config = null)
    {
        try {
            $objects = $this->fetchOutages($timerange, $config ?? []);
        } catch (Exception $e) {
            return Html::tag('p', ['class' => 'empty-state'], sprintf(
                t('Unable to render outage report: %s'),
                $e->getMessage()
            ));
        }

        if (empty($objects)) {
            return Html::tag('p', ['class' => 'empty-state'], t('No outages found.'));
        }

        $document = new HtmlDocument();
        $document->addHtml($this->renderOverview($objects, $timerange));

        foreach ($objects as $object) {
            $document->addHtml($this->renderObject($object, $timerange, $config ?? []));
        }

        return $document;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOutages(Timerange $timerange, array $config): array
    {
        $start = $timerange->getStart()->getTimestamp();
        $end = $timerange->getEnd()->getTimestamp();
        $historyStart = $start * 1000;
        $historyEnd = $end * 1000;
        $candidates = $this->fetchCandidates($historyStart, $historyEnd, $config);
        $objects = [];

        foreach ($candidates as $candidate) {
            $events = $this->fetchEvents($candidate, $historyStart, $historyEnd);
            $object = $this->buildObject($candidate, $events, $start, $end, $config);

            if ($object['outage_seconds'] > 0) {
                $objects[] = $object;
            }
        }

        usort($objects, function (array $a, array $b): int {
            if ($a['outage_seconds'] === $b['outage_seconds']) {
                return strcasecmp($a['label'], $b['label']);
            }

            return $a['outage_seconds'] < $b['outage_seconds'] ? 1 : -1;
        });

        return array_slice($objects, 0, $this->getLimit($config));
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function fetchCandidates(int $historyStart, int $historyEnd, array $config): array
    {
        $type = $config['outage_object_type'] ?? self::TYPE_ALL;
        $limit = $this->getLimit($config) * 4;
        $rows = [];

        if ($type === self::TYPE_ALL || $type === self::TYPE_HOST) {
            $rows = array_merge(
                $rows,
                $this->fetchHostHistoryCandidates($historyStart, $historyEnd, $config, $limit),
                $this->fetchCurrentHostCandidates($config, $limit)
            );
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_SERVICE) {
            $rows = array_merge(
                $rows,
                $this->fetchServiceHistoryCandidates($historyStart, $historyEnd, $config, $limit),
                $this->fetchCurrentServiceCandidates($config, $limit)
            );
        }

        $candidates = [];
        foreach ($rows as $row) {
            $key = $row['object_type'] . '|' . $row['host_id'] . '|' . ($row['service_id'] ?? '');
            if (! isset($candidates[$key])) {
                $candidates[$key] = $row;
            } else {
                $candidates[$key]['problem_events'] += $row['problem_events'];
            }
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['problem_events'] === $b['problem_events']) {
                return strcasecmp($a['label'], $b['label']);
            }

            return $a['problem_events'] < $b['problem_events'] ? 1 : -1;
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function fetchHostHistoryCandidates(int $historyStart, int $historyEnd, array $config, int $limit): array
    {
        $filter = $this->getFilter($config);
        $where = "sh.object_type = 'host'"
            . ' AND sh.event_time > ? AND sh.event_time < ?'
            . ' AND (sh.hard_state > 0 OR sh.previous_hard_state > 0)';
        $params = [$historyStart, $historyEnd];

        if ($filter !== '') {
            $where .= ' AND LOWER(h.display_name) LIKE ?';
            $params[] = $filter;
        }

        $params[] = $limit;
        $sql = sprintf(
            "SELECT 'host' AS object_type, %s AS host_id, NULL AS service_id, h.display_name AS label,"
            . ' NULL AS current_state,'
            . ' COUNT(*) AS problem_events'
            . ' FROM state_history sh INNER JOIN host h ON h.id = sh.host_id'
            . " WHERE $where GROUP BY h.id, h.display_name ORDER BY problem_events DESC, h.display_name LIMIT ?",
            $this->hexExpression('h.id')
        );

        return $this->fetchCandidateRows($sql, $params);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function fetchCurrentHostCandidates(array $config, int $limit): array
    {
        $filter = $this->getFilter($config);
        $where = 'hs.hard_state > 0';
        $params = [];

        if ($filter !== '') {
            $where .= ' AND LOWER(h.display_name) LIKE ?';
            $params[] = $filter;
        }

        $params[] = $limit;
        $sql = sprintf(
            "SELECT 'host' AS object_type, %s AS host_id, NULL AS service_id, h.display_name AS label,"
            . ' hs.hard_state AS current_state, 1 AS problem_events'
            . ' FROM host_state hs INNER JOIN host h ON h.id = hs.host_id'
            . " WHERE $where ORDER BY h.display_name LIMIT ?",
            $this->hexExpression('h.id')
        );

        return $this->fetchCandidateRows($sql, $params);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function fetchServiceHistoryCandidates(int $historyStart, int $historyEnd, array $config, int $limit): array
    {
        $filter = $this->getFilter($config);
        $serviceOutageState = $this->getServiceOutageState($config);
        $where = "sh.object_type = 'service'"
            . ' AND sh.event_time > ? AND sh.event_time < ?'
            . " AND (sh.hard_state >= $serviceOutageState OR sh.previous_hard_state >= $serviceOutageState)";
        $params = [$historyStart, $historyEnd];

        if ($filter !== '') {
            $where .= ' AND LOWER(CONCAT(h.display_name, ?, s.display_name)) LIKE ?';
            $params[] = ' / ';
            $params[] = $filter;
        }

        $params[] = $limit;
        $sql = sprintf(
            "SELECT 'service' AS object_type, %s AS host_id, %s AS service_id,"
            . " CONCAT(h.display_name, ' / ', s.display_name) AS label,"
            . ' NULL AS current_state,'
            . ' COUNT(*) AS problem_events'
            . ' FROM state_history sh'
            . ' INNER JOIN host h ON h.id = sh.host_id'
            . ' INNER JOIN service s ON s.id = sh.service_id'
            . " WHERE $where GROUP BY h.id, s.id, h.display_name, s.display_name"
            . ' ORDER BY problem_events DESC, h.display_name, s.display_name LIMIT ?',
            $this->hexExpression('h.id'),
            $this->hexExpression('s.id')
        );

        return $this->fetchCandidateRows($sql, $params);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function fetchCurrentServiceCandidates(array $config, int $limit): array
    {
        $filter = $this->getFilter($config);
        $where = sprintf('ss.hard_state >= %d', $this->getServiceOutageState($config));
        $params = [];

        if ($filter !== '') {
            $where .= ' AND LOWER(CONCAT(h.display_name, ?, s.display_name)) LIKE ?';
            $params[] = ' / ';
            $params[] = $filter;
        }

        $params[] = $limit;
        $sql = sprintf(
            "SELECT 'service' AS object_type, %s AS host_id, %s AS service_id,"
            . " CONCAT(h.display_name, ' / ', s.display_name) AS label,"
            . ' ss.hard_state AS current_state, 1 AS problem_events FROM service_state ss'
            . ' INNER JOIN service s ON s.id = ss.service_id'
            . ' INNER JOIN host h ON h.id = s.host_id'
            . " WHERE $where ORDER BY h.display_name, s.display_name LIMIT ?",
            $this->hexExpression('h.id'),
            $this->hexExpression('s.id')
        );

        return $this->fetchCandidateRows($sql, $params);
    }

    /**
     * @param array<int, mixed> $params
     *
     * @return array<int, array<string, string|int>>
     */
    private function fetchCandidateRows(string $sql, array $params): array
    {
        $rows = [];

        foreach (IcingadbDatabase::get()->prepexec($sql, $params)->fetchAll(PDO::FETCH_OBJ) as $row) {
            $rows[] = [
                'object_type'    => (string) $row->object_type,
                'host_id'        => (string) $row->host_id,
                'service_id'     => $row->service_id === null ? null : (string) $row->service_id,
                'label'          => (string) $row->label,
                'current_state'  => $row->current_state === null ? null : (int) $row->current_state,
                'problem_events' => (int) $row->problem_events
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string|int> $candidate
     *
     * @return array<int, object>
     */
    private function fetchEvents(array $candidate, int $historyStart, int $historyEnd): array
    {
        $objectType = $candidate['object_type'];
        $currentObjectCondition = sprintf('sh.object_type = ? AND sh.host_id = %s', $this->binaryExpression());
        $previousObjectCondition = sprintf('prev.object_type = ? AND prev.host_id = %s', $this->binaryExpression());
        $currentParams = [$objectType, $candidate['host_id']];
        $previousParams = [$objectType, $candidate['host_id']];

        if ($objectType === self::TYPE_SERVICE) {
            $currentObjectCondition .= sprintf(' AND sh.service_id = %s', $this->binaryExpression());
            $previousObjectCondition .= sprintf(' AND prev.service_id = %s', $this->binaryExpression());
            $currentParams[] = $candidate['service_id'];
            $previousParams[] = $candidate['service_id'];
        } else {
            $currentObjectCondition .= ' AND sh.service_id IS NULL';
            $previousObjectCondition .= ' AND prev.service_id IS NULL';
        }

        $sql = "SELECT sh.event_time, sh.hard_state, sh.previous_hard_state, sh.output, sh.long_output"
            . ' FROM state_history sh'
            . " WHERE $currentObjectCondition AND sh.event_time > ? AND sh.event_time <= ?"
            . ' UNION ALL'
            . ' SELECT sh.event_time, sh.hard_state, sh.previous_hard_state, sh.output, sh.long_output'
            . ' FROM state_history sh'
            . " WHERE $currentObjectCondition AND sh.event_time = ("
            . ' SELECT MAX(prev.event_time) FROM state_history prev'
            . " WHERE $previousObjectCondition AND prev.event_time <= ?"
            . ' ) ORDER BY event_time ASC';
        $params = array_merge(
            $currentParams,
            [$historyStart, $historyEnd],
            $currentParams,
            $previousParams,
            [$historyStart]
        );

        return IcingadbDatabase::get()->prepexec($sql, $params)->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * @param array<string, string|int> $candidate
     * @param array<int, object>        $events
     *
     * @return array<string, mixed>
     */
    private function buildObject(array $candidate, array $events, int $start, int $end, array $config): array
    {
        $state = $candidate['current_state'] ?? 0;
        $output = '';
        $cursor = $start;
        $segments = [];

        foreach ($events as $event) {
            $eventTime = $this->historyTimeToTimestamp((int) $event->event_time);
            $eventTime = max($start, min($end, $eventTime));

            if ($this->historyTimeToTimestamp((int) $event->event_time) <= $start) {
                $state = (int) $event->hard_state;
                $output = $this->formatOutput($event);
                continue;
            }

            if ($eventTime > $cursor) {
                $segments[] = $this->createSegment($cursor, $eventTime, $state, $output);
            }

            $state = (int) $event->hard_state;
            $output = $this->formatOutput($event);
            $cursor = $eventTime;
        }

        if ($cursor < $end) {
            $segments[] = $this->createSegment($cursor, $end, $state, $output);
        }

        $outages = [];
        $outageSeconds = 0;
        foreach ($segments as $segment) {
            if ($this->isOutageState($candidate['object_type'], $segment['state'], $config)) {
                $outages[] = $segment;
                $outageSeconds += $segment['duration'];
            }
        }

        $duration = max(1, $end - $start);

        return [
            'type'                 => $candidate['object_type'],
            'type_label'           => $candidate['object_type'] === self::TYPE_HOST ? t('Host') : t('Service'),
            'service_outage_state' => $this->getServiceOutageState($config),
            'label'                => $candidate['label'],
            'segments'             => $segments,
            'outages'              => $outages,
            'outage_count'         => count($outages),
            'outage_seconds'       => $outageSeconds,
            'outage_percent'       => round($outageSeconds / $duration * 100, 4),
            'availability'         => round((1 - $outageSeconds / $duration) * 100, 4),
            'longest_outage'       => empty($outages) ? 0 : max(array_column($outages, 'duration'))
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function createSegment(int $start, int $end, int $state, string $output): array
    {
        return [
            'start'    => $start,
            'end'      => $end,
            'state'    => $state,
            'duration' => max(0, $end - $start),
            'output'   => $output
        ];
    }

    private function renderOverview(array $objects, Timerange $timerange): ValidHtml
    {
        $outageSeconds = array_sum(array_column($objects, 'outage_seconds'));
        $outageCount = array_sum(array_column($objects, 'outage_count'));
        $duration = max(1, $timerange->getEnd()->getTimestamp() - $timerange->getStart()->getTimestamp());
        $objectSeconds = max(1, $duration * count($objects));
        $availability = (1 - $outageSeconds / $objectSeconds) * 100;

        return Html::tag('div', ['class' => 'outage-report-overview'], [
            Html::tag('span', null, sprintf(t('%d Objects'), count($objects))),
            Html::tag('span', ['class' => 'nok'], sprintf(t('%d Outages'), $outageCount)),
            Html::tag('span', ['class' => 'nok'], sprintf(t('Outage Time: %s'), $this->formatDuration($outageSeconds))),
            Html::tag('span', ['class' => 'ok'], sprintf(t('Availability: %s%%'), $this->formatNumber($availability, 2)))
        ]);
    }

    private function renderObject(array $object, Timerange $timerange, array $config): ValidHtml
    {
        $maxDetails = $this->getMaxDetails($config);
        $outages = array_slice($object['outages'], 0, $maxDetails);
        $rows = [];

        foreach ($outages as $outage) {
            $rows[] = Html::tag('tr', null, [
                Html::tag('td', null, $this->formatTime($outage['start'])),
                Html::tag('td', null, $this->formatTime($outage['end'])),
                Html::tag('td', null, $this->formatDuration($outage['duration'])),
                Html::tag('td', null, $this->formatState($object['type'], $outage['state'])),
                Html::tag('td', null, $outage['output'])
            ]);
        }

        if (empty($rows)) {
            $rows[] = Html::tag('tr', null, [
                Html::tag('td', ['colspan' => 5], t('No outage intervals in this time range.'))
            ]);
        }

        return Html::tag('section', ['class' => 'outage-report-object'], [
            Html::tag('header', null, [
                Html::tag('h2', null, $object['label']),
                Html::tag('dl', ['class' => 'outage-report-stats'], [
                    Html::tag('dt', null, t('Type')),
                    Html::tag('dd', null, $object['type_label']),
                    Html::tag('dt', null, t('Outages')),
                    Html::tag('dd', ['class' => 'nok'], (string) $object['outage_count']),
                    Html::tag('dt', null, t('Outage Time')),
                    Html::tag('dd', ['class' => 'nok'], $this->formatDuration($object['outage_seconds'])),
                    Html::tag('dt', null, t('Longest Outage')),
                    Html::tag('dd', null, $this->formatDuration($object['longest_outage'])),
                    Html::tag('dt', null, t('Availability')),
                    Html::tag('dd', ['class' => 'ok'], $this->formatNumber($object['availability'], 2) . '%')
                ])
            ]),
            $this->renderTimeSeries($object, $timerange),
            Html::tag('p', ['class' => 'outage-report-description'], $this->describeObject($object)),
            Html::tag('table', ['class' => 'outage-report-details common-table'], [
                Html::tag('thead', null, Html::tag('tr', null, [
                    Html::tag('th', null, t('Start')),
                    Html::tag('th', null, t('End')),
                    Html::tag('th', null, t('Duration')),
                    Html::tag('th', null, t('State')),
                    Html::tag('th', null, t('Output'))
                ])),
                Html::tag('tbody', null, $rows)
            ])
        ]);
    }

    private function renderTimeSeries(array $object, Timerange $timerange): ValidHtml
    {
        $start = $timerange->getStart()->getTimestamp();
        $end = $timerange->getEnd()->getTimestamp();
        $duration = max(1, $end - $start);
        $rects = '';

        foreach ($object['segments'] as $segment) {
            $x = 2 + (($segment['start'] - $start) / $duration * 756);
            $width = max(1, ($segment['duration'] / $duration * 756));
            $class = $this->getStateClass($object, $segment['state']);
            $title = sprintf(
                '%s - %s, %s',
                $this->formatTime($segment['start']),
                $this->formatTime($segment['end']),
                $this->formatState($object['type'], $segment['state'])
            );

            $rects .= sprintf(
                '<rect class="%s" x="%s" y="18" width="%s" height="34"><title>%s</title></rect>',
                $this->escape($class),
                $this->formatNumber($x, 2),
                $this->formatNumber($width, 2),
                $this->escape($title)
            );
        }

        $svg = sprintf(
            '<div class="outage-report-timeseries">'
            . '<svg viewBox="0 0 760 74" role="img" aria-label="%s">'
            . '<rect class="background" x="1" y="17" width="758" height="36"></rect>'
            . '%s'
            . '<line class="axis" x1="1" y1="54" x2="759" y2="54"></line>'
            . '<text x="2" y="70">%s</text><text x="758" y="70" text-anchor="end">%s</text>'
            . '</svg></div>',
            $this->escape(t('Outage time series')),
            $rects,
            $this->escape($this->formatTime($start)),
            $this->escape($this->formatTime($end))
        );

        return new HtmlString($svg);
    }

    private function describeObject(array $object): string
    {
        if ($object['outage_count'] === 0) {
            return t('No outage was detected for this object in the selected time range.');
        }

        return sprintf(
            t('%s had %d outage intervals. Total outage time was %s, the longest single outage lasted %s.'),
            $object['label'],
            $object['outage_count'],
            $this->formatDuration($object['outage_seconds']),
            $this->formatDuration($object['longest_outage'])
        );
    }

    private function getStateClass(array $object, int $state): string
    {
        if ($this->isOutageState($object['type'], $state, $object)) {
            return 'outage';
        }

        if ($object['type'] === self::TYPE_SERVICE && $state === 1) {
            return 'warning';
        }

        return 'ok';
    }

    private function isOutageState(string $type, int $state, array $config): bool
    {
        return $type === self::TYPE_HOST ? $state > 0 : $state >= $this->getServiceOutageState($config);
    }

    private function formatState(string $type, int $state): string
    {
        if ($type === self::TYPE_HOST) {
            return $state > 0 ? t('DOWN') : t('UP');
        }

        switch ($state) {
            case 1:
                return t('WARNING');
            case 2:
                return t('CRITICAL');
            case 3:
                return t('UNKNOWN');
            default:
                return t('OK');
        }
    }

    private function formatOutput(object $event): string
    {
        $output = trim((string) $event->output);
        $longOutput = trim((string) $event->long_output);

        if ($longOutput !== '') {
            $output .= ($output === '' ? '' : "\n") . $longOutput;
        }

        return $output;
    }

    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = sprintf(t('%dd'), $days);
        }

        if ($hours > 0 || ! empty($parts)) {
            $parts[] = sprintf(t('%dh'), $hours);
        }

        if ($minutes > 0 || ! empty($parts)) {
            $parts[] = sprintf(t('%dm'), $minutes);
        }

        $parts[] = sprintf(t('%ds'), $seconds);

        return implode(' ', $parts);
    }

    private function formatTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function historyTimeToTimestamp(int $eventTime): int
    {
        return (int) floor($eventTime / 1000);
    }

    private function formatNumber(float $number, int $precision): string
    {
        $formatted = rtrim(rtrim(number_format($number, $precision, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function getFilter(array $config): string
    {
        $filter = strtolower(trim((string) ($config['outage_filter'] ?? '')));

        return $filter === '' ? '' : '%' . $filter . '%';
    }

    private function getLimit(array $config): int
    {
        return max(1, min(200, (int) ($config['outage_limit'] ?? 25)));
    }

    private function getMaxDetails(array $config): int
    {
        return max(1, min(50, (int) ($config['outage_max_details'] ?? 10)));
    }

    private function getServiceOutageState(array $config): int
    {
        return ($config['outage_service_state'] ?? 'critical') === 'warning' ? 1 : 2;
    }

    private function hexExpression(string $column): string
    {
        return IcingadbDatabase::getDriver() === 'pgsql'
            ? "LOWER(ENCODE($column, 'hex'))"
            : "LOWER(HEX($column))";
    }

    private function binaryExpression(): string
    {
        return IcingadbDatabase::getDriver() === 'pgsql' ? "DECODE(?, 'hex')" : 'UNHEX(?)';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
