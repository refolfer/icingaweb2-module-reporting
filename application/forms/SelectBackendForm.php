<?php

// SPDX-FileCopyrightText: 2019 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting\Forms;

use Icinga\Application\Config;
use Icinga\Data\ResourceFactory;
use Icinga\Forms\ConfigForm;

class SelectBackendForm extends ConfigForm
{
    public function init()
    {
        $this->setName('reporting_backend');
        $this->setSubmitLabel($this->translate('Save Changes'));
    }

    public function createElements(array $formData)
    {
        $dbResources = ResourceFactory::getResourceConfigs('db')->keys();
        $options = array_combine($dbResources, $dbResources);

        $default = null;
        if (isset($options['reporting'])) {
            $default = 'reporting';
        }

        $this->addElement('select', 'backend_resource', [
            'label'        => $this->translate('Database'),
            'description'  => $this->translate('Database resource'),
            'multiOptions' => $options,
            'value'        => $default,
            'required'     => true
        ]);

        $this->addElement('select', 'icingadb_resource', [
            'label'        => $this->translate('Icinga DB Resource Config'),
            'description'  => $this->translate('Icinga DB database resource'),
            'multiOptions' => ['' => $this->translate('Use Icinga DB module configuration')] + $options,
            'value'        => $this->getIcingadbResourceDefault($options),
            'required'     => false
        ]);
    }

    private function getIcingadbResourceDefault(array $options)
    {
        $icingadbConfig = Config::module('icingadb');
        $resource = $icingadbConfig->get('database', 'resource')
            ?: $icingadbConfig->get('db', 'resource')
            ?: 'icingadb';

        return isset($options[$resource]) ? $resource : null;
    }
}
