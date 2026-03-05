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

namespace Bydn\ImprovedPageCache\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\LocalizedException;

interface WarmItemRepositoryInterface
{
    /**
     * Retrieve entity.
     *
     * @param int $id
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($id);

    /**
     * Retrieve WarmItem matching the specified criteria.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemSearchResultsInterface
     * @throws LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria);

    /**
     * Save WarmItems entry
     *
     * @param \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface $warmItem
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface
     * @throws LocalizedException
     */
    public function save(\Bydn\ImprovedPageCache\Api\Data\WarmItemInterface $warmItem)
        : \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface;
}
