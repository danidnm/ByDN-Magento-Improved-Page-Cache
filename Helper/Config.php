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

namespace Bydn\ImprovedPageCache\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'bydn_improved_page_cache/general/enabled';
    public const XML_PATH_GRID_PER_PAGE = 'catalog/frontend/grid_per_page';
    public const XML_PATH_PRODUCT_USE_CATEGORIES = 'catalog/seo/product_use_categories';

    /**
     * Check if module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get grid per page configuration
     *
     * @param int|null $storeId
     * @return int
     */
    public function getProductsPerPage($storeId = null)
    {
        $pageSize = (int)$this->scopeConfig->getValue(
            self::XML_PATH_GRID_PER_PAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!$pageSize) {
            $pageSize = 12; // Default if not set
        }

        return $pageSize;
    }

    /**
     * Check if categories path should be used in product URLs
     *
     * @param int|null $storeId
     * @return bool
     */
    public function useCategoryPathInProductUrl($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRODUCT_USE_CATEGORIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}