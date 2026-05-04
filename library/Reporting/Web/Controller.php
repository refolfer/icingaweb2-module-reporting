<?php

// SPDX-FileCopyrightText: 2019 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Reporting\Web;

use Icinga\Module\Reporting\Restrictions;
use Icinga\Security\SecurityException;
use ipl\Web\Compat\CompatController;

class Controller extends CompatController
{
    public function init(): void
    {
        parent::init();

        if (! Restrictions::hasAccess()) {
            throw new SecurityException($this->translate('No permission to access reporting'));
        }
    }
}
