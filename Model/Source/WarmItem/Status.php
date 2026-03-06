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

use Bydn\ImprovedPageCache\Model\WarmItem\Status as StatusConstants;
use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => StatusConstants::NEW,        'label' => __('New')],
            ['value' => StatusConstants::PROCESSING,  'label' => __('Processing')],
            ['value' => StatusConstants::DONE,        'label' => __('Done')],
            ['value' => StatusConstants::ERROR,       'label' => __('Error')],
            ['value' => StatusConstants::DISABLED,    'label' => __('Disabled')],
        ];
    }
}
