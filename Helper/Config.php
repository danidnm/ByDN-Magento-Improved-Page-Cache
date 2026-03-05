<?php

namespace Bydn\ImprovedPageCache\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_GRID_PER_PAGE = 'catalog/frontend/grid_per_page';
    public const XML_PATH_PRODUCT_USE_CATEGORIES = 'catalog/seo/product_use_categories';

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