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

namespace Bydn\ImprovedPageCache\Model\Queue;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;

use Bydn\ImprovedPageCache\Helper\Config as HelperConfig;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Bydn\ImprovedPageCache\Model\WarmItemFactory;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Type as WarmTypes;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Priority as WarmPriority;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Status as WarmStatus;

use Psr\Log\LoggerInterface;

Class Publisher
{
    public const ALL = 'all';

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var PageCollectionFactory
     */
    private $pageCollectionFactory;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var CategoryResource
     */
    private $categoryResource;

    /**
     * @var WarmItemResource
     */
    private $warmItemResource;

    /**
     * @var WarmItemCollectionFactory
     */
    private $warmItemCollectionFactory;

    /**
     * @var WarmItemFactory
     */
    private $warmItemFactory;

    /**
     * @var HelperConfig
     */
    private $helperConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $inserts = [];

    /**
     * @var array
     */
    private $updates = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param PageCollectionFactory $pageCollectionFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ProductResource $productResource
     * @param CategoryResource $categoryResource
     * @param WarmItemResource $warmItemResource
     * @param WarmItemCollectionFactory $warmItemCollectionFactory
     * @param WarmItemFactory $warmItemFactory
     * @param HelperConfig $helperConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        PageCollectionFactory $pageCollectionFactory,
        CategoryRepositoryInterface $categoryRepository,
        ProductResource $productResource,
        CategoryResource $categoryResource,
        WarmItemResource $warmItemResource,
        WarmItemCollectionFactory $warmItemCollectionFactory,
        WarmItemFactory $warmItemFactory,
        HelperConfig $helperConfig,
        LoggerInterface $logger
         
    ) {
        $this->storeManager = $storeManager;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->categoryRepository = $categoryRepository;
        $this->productResource = $productResource;
        $this->categoryResource = $categoryResource;
        $this->warmItemResource = $warmItemResource;
        $this->warmItemCollectionFactory = $warmItemCollectionFactory;
        $this->warmItemFactory = $warmItemFactory;
        $this->helperConfig = $helperConfig;
        $this->logger = $logger;
    }

    /**
     * @param $stores
     * @param $type
     * @param $data
     * @param int $priority
     * @return void
     * @throws LocalizedException
     */
    public function sendEntitiesToQueue($stores, $type, $data, $priority = WarmPriority::LOWEST)
    {
        if (!$this->helperConfig->isEnabled()) {
            return;
        }

        $this->logger->info(sprintf('Adding entities to queue: Type=%s, Stores=%s, Priority=%s', $type, $stores, $priority));

        // Validate type
        $type = $this->validateType($type);

        $this->inserts = [];
        $this->updates = [];

        // Follow depending on type, validate params
        switch ($type) {
            case WarmTypes::HOME:
                $this->enqueueHome($stores, $priority);
                break;
            case WarmTypes::PAGES:
                $this->enqueuePages($stores, $data, $priority);
                break;
            case WarmTypes::CATEGORIES:
                $this->enqueueCategories($stores, $data, $priority);
                break;
            case WarmTypes::PRODUCTS:
                $this->enqueueProducts($stores, $data, $priority);
                break;
            case WarmTypes::DIRECT_URL:
                $this->enqueueUrl($stores, $data, $priority);
                break;
        }

        $this->processQueueArrays();
    }

    /**
     * Enqueue home page for given stores
     */
    private function enqueueHome($stores, $priority)
    {
        $stores = $this->extractStores($stores);
        foreach ($stores as $storeId) {
            $this->enqueueEntity($storeId, WarmTypes::HOME, '', $priority);
        }
    }

    /**
     * Enqueue CMS pages
     */
    private function enqueuePages($stores, $data, $priority)
    {
        $stores = $this->extractStores($stores);
        $pageIds = $this->extractPageIds($data);
        foreach ($stores as $storeId) {
            foreach ($pageIds as $pageId) {
                $this->enqueueEntity($storeId, WarmTypes::PAGES, $pageId, $priority);
            }
        }
    }

    /**
     * Enqueue Categories
     */
    private function enqueueCategories($stores, $data, $priority)
    {
        $stores = $this->extractStores($stores);
        $categoryIds = $this->extractIds(WarmTypes::CATEGORIES, $data);
        foreach ($stores as $storeId) {
            $pageSize = $this->helperConfig->getProductsPerPage($storeId);
            foreach ($categoryIds as $categoryId) {
                try {

                    // Full count with category
                    // /** @var \Magento\Catalog\Model\Category $category */
                    // $category = $this->categoryRepository->get($categoryId, $storeId);
                    // $collection = $this->productCollectionFactory->create();
                    // $collection->setStoreId($storeId);
                    // $collection->addCategoryFilter($category);
                    // $collection->addAttributeToFilter('status', ['eq' => ProductStatus::STATUS_ENABLED]);
                    // $collection->addAttributeToFilter('visibility', [
                    //     'in' => [
                    //         ProductVisibility::VISIBILITY_BOTH,
                    //         ProductVisibility::VISIBILITY_IN_CATALOG,
                    //         ProductVisibility::VISIBILITY_IN_SEARCH
                    //     ]
                    // ]);
                    // $productCount = $collection->getSize();

                    // Number of product by index status
                    $productCount = $this->getCategoryProductCount($categoryId);
                    $pages = ceil($productCount / $pageSize);
                    
                    if ($pages == 0) $pages = 1;

                    for ($i = 1; $i <= $pages; $i++) {
                        $info = $categoryId . ',' . $i;
                        $this->enqueueEntity($storeId, WarmTypes::CATEGORIES, $info, $priority);
                    }
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * Enqueue Products
     */
    private function enqueueProducts($stores, $data, $priority)
    {
        $stores = $this->extractStores($stores);
        $productIds = $this->extractIds(WarmTypes::PRODUCTS, $data);
        foreach ($stores as $storeId) {
            $useCategoryPath = $this->helperConfig->useCategoryPathInProductUrl($storeId);
            foreach ($productIds as $productId) {
                if ($useCategoryPath) {
                    $categoryIds = $this->productResource->getCategoryIds($productId);
                    foreach ($categoryIds as $categoryId) {
                        $info = $productId . ',' . $categoryId;
                        $this->enqueueEntity($storeId, WarmTypes::PRODUCTS, $info, $priority);
                    }
                }
                
                // Always add product without category (or only one if useCategoryPath is false)
                $info = $productId . ',0';
                $this->enqueueEntity($storeId, WarmTypes::PRODUCTS, $info, $priority);
            }
        }
    }

    /**
     * Enqueue specific URLs
     */
    private function enqueueUrl($stores, $data, $priority)
    {
        $stores = $this->extractStores($stores);
        $urls = is_array($data) ? $data : explode(',', $data ?? '');
        foreach ($stores as $storeId) {
            foreach ($urls as $url) {
                $this->enqueueEntity($storeId, WarmTypes::DIRECT_URL, trim($url), $priority);
            }
        }
    }

    /**
     * Create and save a WarmItem if not duplicated
     */
    private function enqueueEntity($storeId, $type, $info, $priority)
    {
        // Create key for checking duplicates
        $key = $storeId . '-' . $type . '-' . $info;

        // If already to be inserted, check priority and update if needed
        if (array_key_exists($key, $this->inserts)) {
            if ($this->inserts[$key]['priority'] < $priority) {
                $this->inserts[$key]['priority'] = $priority;
            }
            return;
        }

        // If already in DB, check priority
        $duplicate = $this->checkDuplicated($storeId, $type, $info);
        if ($duplicate !== null) {

            // Check if the item is already set for update
            if (array_key_exists($duplicate['entity_id'], $this->updates)) {

                // If the new priority is higher than the pending update, update the priority
                if ($this->updates[$duplicate['entity_id']]['priority'] < $priority) {
                    $this->updates[$duplicate['entity_id']]['priority'] = $priority;
                }
            }
            // The item is not already set for update, but it is in the DB ($duplicate) 
            // Check the priority in DB and the new priority. If new is higher, set for update
            elseif ($duplicate['priority'] < $priority) {
                $this->updates[$duplicate['entity_id']] = [
                    'entity_id' => $duplicate['entity_id'],
                    'priority' => $priority
                ];
            }
            return;
        }

        // Not already in inserts and not in DB => insert new item for warming
        $this->inserts[$key] = [
            'store_id' => $storeId,
            'type' => $type,
            'info' => $info,
            'priority' => $priority,
            'status' => WarmStatus::NEW
        ];
    }

    /**
     * Check if a record with same parameters already exists
     */
    private function checkDuplicated($storeId, $type, $info)
    {
        $connection = $this->warmItemResource->getConnection();
        $table = $this->warmItemResource->getMainTable();

        $select = $connection->select()
            ->from($table, ['entity_id', 'priority'])
            ->where('store_id = ?', (int)$storeId)
            ->where('type = ?', $type)
            ->where('info = ?', $info)
            ->where('status = ?', WarmStatus::NEW)
            ->limit(1);

        $result = $connection->fetchRow($select);
        return $result ? $result : null;
    }

    /**
     * Process accumulated inserts and updates
     */
    private function processQueueArrays()
    {
        $connection = $this->warmItemResource->getConnection();
        $table = $this->warmItemResource->getMainTable();

        if (!empty($this->updates)) {
            $chunks = array_chunk(array_values($this->updates), 1000);
            foreach ($chunks as $chunk) {
                try {
                    foreach ($chunk as $updateData) {
                        $connection->update(
                            $table,
                            ['priority' => $updateData['priority']],
                            ['entity_id = ?' => $updateData['entity_id']]
                        );
                    }
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        }

        if (!empty($this->inserts)) {
            $chunks = array_chunk(array_values($this->inserts), 1000);
            foreach ($chunks as $chunk) {
                try {
                    $connection->insertMultiple($table, $chunk);
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * @param $type
     * @return int[]|mixed
     */
    private function validateType($type)
    {
        if (!in_array($type, WarmTypes::getAllTypes())) {
            throw new LocalizedException(__("Unsupported operation type"));
        }
        return $type;
    }

    /**
     * @param $stores
     * @return int[]|mixed
     */
    private function extractStores($stores)
    {
        if ($stores == self::ALL) {
            $storeIds = [];
            foreach ($this->storeManager->getStores() as $store) {
                if ($store->getIsActive() && $this->helperConfig->isEnabled($store->getId())) {
                    $storeIds[] = $store->getId();
                }
            }
            return $storeIds;
        }

        // Ensure ids is an array
        $stores = is_array($stores) ? $stores : explode(',', $stores);

        return $stores;
    }

    /**
     * @param $type
     * @param $ids
     * @return array|mixed
     */
    private function extractIds($type, $ids)
    {
        // Check if all categories or all products
        if ($ids == self::ALL) {
            if ($type == WarmTypes::CATEGORIES) {
                $ids = $this->extractAllCategoryIds();
            }
            else if ($type == WarmTypes::PRODUCTS) {
                $ids = $this->extractAllProductIds();
            }
        }

        // Ensure ids is an array
        $ids = is_array($ids) ? $ids : explode(',', $ids);

        return $ids;
    }

    /**
     * Extract page IDs, handling 'all'
     */
    private function extractPageIds($ids)
    {
        if ($ids == self::ALL) {
            return $this->extractAllPageIds();
        }
        return is_array($ids) ? $ids : explode(',', $ids);
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function extractAllCategoryIds() {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('entity_id');
        $collection->addAttributeToFilter('entity_id', ['neq' => 2]);
        $collection->addIsActiveFilter();
        return $collection->getAllIds();
    }

    /**
     * @return array
     */
    private function extractAllProductIds() {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('id');
        $collection->addAttributeToFilter('status', ['eq' => ProductStatus::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', [
            'in' => [
                ProductVisibility::VISIBILITY_IN_CATALOG,
                ProductVisibility::VISIBILITY_IN_SEARCH,
                ProductVisibility::VISIBILITY_BOTH
            ]
        ]);
        return $collection->getAllIds();
    }

    /**
     * @return array
     */
    private function extractAllPageIds() {
        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToSelect('page_id');
        $collection->addFieldToFilter('is_active', 1);
        return $collection->getAllIds();
    }
    
    /**
     * Retrieve count products of category
     *
     * @return int
     */
    public function getCategoryProductCount($categoryId)
    {
        $productTable = $this->categoryResource->getTable('catalog_category_product');

        $select = $this->categoryResource->getConnection()->select()->from(
            ['main_table' => $productTable],
            [new \Zend_Db_Expr('COUNT(main_table.product_id)')]
        )->where(
            'main_table.category_id = :category_id'
        );

        $bind = ['category_id' => (int)$categoryId];
        $counts = $this->categoryResource->getConnection()->fetchOne($select, $bind);

        return (int) $counts;
    }

}
