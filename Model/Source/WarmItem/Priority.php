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

namespace Bydn\ImprovedPageCache\Model\Source\WarmItem;

use Bydn\ImprovedPageCache\Model\WarmItem\Priority as PriorityConstants;
use Magento\Framework\Data\OptionSourceInterface;

class Priority implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => PriorityConstants::LOWEST,  'label' => __('Lowest')],
            ['value' => PriorityConstants::LOW,      'label' => __('Low')],
            ['value' => PriorityConstants::MEDIUM,   'label' => __('Medium')],
            ['value' => PriorityConstants::HIGH,     'label' => __('High')],
            ['value' => PriorityConstants::HIGHEST,  'label' => __('Highest')],
        ];
    }
}
