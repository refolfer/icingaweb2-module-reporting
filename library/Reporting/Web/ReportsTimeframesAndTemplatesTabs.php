<?php

// SPDX-FileCopyrightText: 2019 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting\Web;

trait ReportsTimeframesAndTemplatesTabs
{
    /**
     * Create tabs
     *
     * @return  \ipl\Web\Widget\Tabs
     */
    protected function createTabs()
    {
        $tabs = $this->getTabs();
        $tabs->getAttributes()->set('data-base-target', '_main');

        if ($this->hasPermission('reporting/reports')) {
            $tabs->add('reports', [
                'title' => $this->translate('Show reports'),
                'label' => $this->translate('Reports'),
                'url'   => 'reporting/reports'
            ]);
        }

        if ($this->hasPermission('reporting/timeframes')) {
            $tabs->add('timeframes', [
                'title' => $this->translate('Show time frames'),
                'label' => $this->translate('Time Frames'),
                'url'   => 'reporting/timeframes'
            ]);
        }

        if ($this->hasPermission('reporting/templates')) {
            $tabs->add('templates', [
                'title' => $this->translate('Show templates'),
                'label' => $this->translate('Templates'),
                'url'   => 'reporting/templates'
            ]);
        }

        return $tabs;
    }
}
