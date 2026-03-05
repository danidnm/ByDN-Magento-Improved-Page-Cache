<?php
/**
 * @package     Bydn_ImprovedPageCache
 * @author      Daniel Navarro <https://github.com/danidnm>
 * @license     GPL-3.0-or-later
 * @copyright   Copyright (c) 2025 Daniel Navarro
 *
 * This file is part of a free software package licensed under the
 * GNU General Public License v3.0.
 * You may redistribute and/or modify it under the same license.
 */

namespace Bydn\ImprovedPageCache\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WarmItem extends AbstractDb
{
    /**
     * Internal constructor
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('bydn_warm_item', 'entity_id');
    }
}
