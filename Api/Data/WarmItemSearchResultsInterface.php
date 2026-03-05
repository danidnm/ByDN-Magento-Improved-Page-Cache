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

namespace Bydn\ImprovedPageCache\Api\Data;

use Bydn\ImprovedPageCache\Api\Data\WarmItemInterface;
use Magento\Framework\Api\SearchResultsInterface;

interface WarmItemSearchResultsInterface extends SearchResultsInterface
{
    /**
     * Get WarmItem items
     *
     * @return WarmItemInterface[]
     */
    public function getItems(): array;

    /**
     * Set WarmItem items
     *
     * @param WarmItemInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
