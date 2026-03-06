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

use Bydn\ImprovedPageCache\Model\WarmItem\Types;
use Magento\Framework\Data\OptionSourceInterface;

class Type implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Types::HOME,       'label' => __('Home')],
            ['value' => Types::PAGES,      'label' => __('CMS Pages')],
            ['value' => Types::CATEGORIES, 'label' => __('Categories')],
            ['value' => Types::PRODUCTS,   'label' => __('Products')],
            ['value' => Types::DIRECT_URL, 'label' => __('Direct URL')],
        ];
    }
}
