<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting;

use Icinga\Module\Icingadb\ProvidedHook\Reporting\HostSlaReport;
use Icinga\Module\Icingadb\ProvidedHook\Reporting\SlaReport;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\ValidHtml;

use function ipl\I18n\t;

final class SlaChart
{
    public const CONFIG_KEY = 'sla_chart';
    public const TABLE = 'table';
    public const BARS = 'bars';
    public const COLUMNS = 'columns';
    public const GAUGE = 'gauge';

    private function __construct()
    {
    }

    public static function supports(string $reportletClass): bool
    {
        return is_a($reportletClass, SlaReport::class, true);
    }

    public static function addConfigFormElement(Form $form): void
    {
        $form->addElement('select', self::CONFIG_KEY, [
            'label'       => t('SLA Visualization'),
            'description' => t('Choose how SLA results should be shown in HTML and PDF reports'),
            'options'     => self::getOptions()
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getOptions(): array
    {
        return [
            self::TABLE   => t('Table'),
            self::BARS    => t('Horizontal Bars'),
            self::COLUMNS => t('Columns'),
            self::GAUGE   => t('Pie Charts')
        ];
    }

    public static function shouldRenderChart(array $config): bool
    {
        return self::getType($config) !== self::TABLE;
    }

    public static function render(SlaReport $report, Timerange $timerange, array $config): ValidHtml
    {
        $data = $report->getData($timerange, $config);

        if (! count($data)) {
            return Html::tag('p', ['class' => 'empty-state'], t('No data found.'));
        }

        switch (self::getType($config)) {
            case self::COLUMNS:
                return self::renderColumns($data, $config);
            case self::GAUGE:
                return self::renderGauge($data, $config, $report instanceof HostSlaReport);
            case self::BARS:
            default:
                return self::renderBars($data, $config);
        }
    }

    private static function getType(array $config): string
    {
        $type = $config[self::CONFIG_KEY] ?? self::TABLE;

        return array_key_exists($type, self::getOptions()) ? $type : self::TABLE;
    }

    private static function getThreshold(array $config): float
    {
        return isset($config['threshold']) && $config['threshold'] !== ''
            ? (float) $config['threshold']
            : SlaReport::DEFAULT_THRESHOLD;
    }

    private static function getPrecision(array $config): int
    {
        return isset($config['sla_precision']) && $config['sla_precision'] !== ''
            ? (int) $config['sla_precision']
            : SlaReport::DEFAULT_REPORT_PRECISION;
    }

    private static function renderBars(ReportData $data, array $config): HtmlDocument
    {
        $threshold = self::getThreshold($config);
        $precision = self::getPrecision($config);
        $rows = [];

        foreach ($data->getRows() as $row) {
            $sla = self::normalizeSla((float) $row->getValues()[0]);
            $slaClass = self::getSlaClass($sla, $threshold);

            $rows[] = Html::tag('div', ['class' => 'sla-chart-row'], [
                Html::tag('div', ['class' => 'sla-chart-label'], self::formatDimensions($row->getDimensions())),
                Html::tag(
                    'div',
                    ['class' => 'sla-chart-track'],
                    Html::tag('div', [
                        'class' => "sla-chart-bar $slaClass",
                        'style' => sprintf('width: %s%%', self::formatNumber($sla, 2))
                    ])
                ),
                Html::tag('div', ['class' => "sla-chart-value $slaClass"], self::formatSla($sla, $precision))
            ]);
        }

        return (new HtmlDocument())
            ->addHtml(self::renderSummary($data, $config))
            ->addHtml(Html::tag('div', ['class' => 'sla-chart sla-chart-bars'], $rows));
    }

    private static function renderColumns(ReportData $data, array $config): HtmlDocument
    {
        $threshold = self::getThreshold($config);
        $precision = self::getPrecision($config);
        $columns = [];

        foreach ($data->getRows() as $row) {
            $sla = self::normalizeSla((float) $row->getValues()[0]);
            $slaClass = self::getSlaClass($sla, $threshold);

            $columns[] = Html::tag('div', ['class' => 'sla-chart-column'], [
                Html::tag(
                    'div',
                    ['class' => 'sla-chart-column-track'],
                    Html::tag('div', [
                        'class' => "sla-chart-column-bar $slaClass",
                        'style' => sprintf('height: %s%%', self::formatNumber($sla, 2))
                    ])
                ),
                Html::tag('div', ['class' => "sla-chart-column-value $slaClass"], self::formatSla($sla, $precision)),
                Html::tag('div', ['class' => 'sla-chart-column-label'], self::formatDimensions($row->getDimensions()))
            ]);
        }

        return (new HtmlDocument())
            ->addHtml(self::renderSummary($data, $config))
            ->addHtml(Html::tag('div', ['class' => 'sla-chart sla-chart-columns'], $columns));
    }

    private static function renderGauge(ReportData $data, array $config, bool $isHostReport): HtmlDocument
    {
        $threshold = self::getThreshold($config);
        $precision = self::getPrecision($config);
        $average = self::normalizeSla((float) $data->getAverages()[0]);
        $total = $isHostReport
            ? sprintf(t('%d Hosts'), $data->count())
            : sprintf(t('%d Services'), $data->count());
        $charts = [];

        foreach ($data->getRows() as $row) {
            $sla = self::normalizeSla((float) $row->getValues()[0]);

            $charts[] = Html::tag('div', ['class' => 'sla-chart-gauge-item'], [
                self::renderGaugeRing(
                    $sla,
                    $precision,
                    t('SLA'),
                    self::getSlaClass($sla, $threshold),
                    'sla-chart-gauge-ring-small'
                ),
                Html::tag('div', ['class' => 'sla-chart-gauge-label'], self::formatDimensions($row->getDimensions())),
                Html::tag('div', ['class' => 'sla-chart-gauge-split'], [
                    Html::tag('span', ['class' => 'ok'], self::formatSla($sla, $precision)),
                    Html::tag('span', ['class' => 'nok'], self::formatSla(100 - $sla, $precision))
                ])
            ]);
        }

        return (new HtmlDocument())
            ->addHtml(Html::tag('div', ['class' => 'sla-chart sla-chart-gauge'], [
                self::renderGaugeRing(
                    $average,
                    $precision,
                    t('Average SLA'),
                    self::getSlaClass($average, $threshold)
                ),
                Html::tag('dl', ['class' => 'sla-chart-gauge-details'], [
                    Html::tag('dt', null, t('Objects')),
                    Html::tag('dd', null, $total),
                    Html::tag('dt', null, t('Available')),
                    Html::tag('dd', ['class' => 'ok'], self::formatSla($average, $precision)),
                    Html::tag('dt', null, t('Unavailable')),
                    Html::tag('dd', ['class' => 'nok'], self::formatSla(100 - $average, $precision)),
                    Html::tag('dt', null, t('Threshold')),
                    Html::tag('dd', null, self::formatSla($threshold, $precision))
                ])
            ]))
            ->addHtml(Html::tag('div', ['class' => 'sla-chart sla-chart-gauge-grid'], $charts));
    }

    private static function renderGaugeRing(
        float $sla,
        int $precision,
        string $label,
        string $slaClass,
        string $sizeClass = ''
    ): ValidHtml
    {
        $angle = self::formatNumber($sla * 3.6, 2);
        $class = trim("sla-chart-gauge-ring $sizeClass $slaClass");

        return Html::tag(
            'div',
            [
                'class' => $class,
                'style' => sprintf('--sla-angle: %sdeg', $angle)
            ],
            Html::tag('div', ['class' => 'sla-chart-gauge-center'], [
                Html::tag('strong', null, self::formatSla($sla, $precision)),
                Html::tag('span', null, $label)
            ])
        );
    }

    private static function renderSummary(ReportData $data, array $config): ValidHtml
    {
        $threshold = self::getThreshold($config);
        $precision = self::getPrecision($config);
        $average = self::normalizeSla((float) $data->getAverages()[0]);
        $slaClass = self::getSlaClass($average, $threshold);

        return Html::tag('div', ['class' => 'sla-chart-summary'], [
            Html::tag('span', null, sprintf(t('%d Rows'), $data->count())),
            Html::tag('span', ['class' => $slaClass], sprintf(t('Average: %s'), self::formatSla($average, $precision))),
            Html::tag('span', null, sprintf(t('Threshold: %s'), self::formatSla($threshold, $precision)))
        ]);
    }

    /**
     * @param array<int, mixed> $dimensions
     */
    private static function formatDimensions(array $dimensions): string
    {
        return implode(' / ', array_map('strval', $dimensions));
    }

    private static function formatSla(float $sla, int $precision): string
    {
        return sprintf('%s%%', self::formatNumber($sla, $precision));
    }

    private static function formatNumber(float $number, int $precision): string
    {
        $formatted = rtrim(rtrim(number_format($number, $precision, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private static function normalizeSla(float $sla): float
    {
        return max(0, min(100, $sla));
    }

    private static function getSlaClass(float $sla, float $threshold): string
    {
        return $sla < $threshold ? 'nok' : 'ok';
    }
}
