<?php

namespace Bydn\ImprovedPageCache\Model\Queue\Warm;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;

use Bydn\ImprovedPageCache\Helper\Config as HelperConfig;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\WarmItemFactory;
use Bydn\ImprovedPageCache\Model\WarmItem\Types as WarmTypes;
use Bydn\ImprovedPageCache\Model\WarmItem\Priority as WarmPriority;
use Bydn\ImprovedPageCache\Model\WarmItem\Status as WarmStatus;

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
     * @var WarmItemResource
     */
    private $warmItemResource;

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
     * @param StoreManagerInterface $storeManager
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param PageCollectionFactory $pageCollectionFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ProductResource $productResource
     * @param WarmItemResource $warmItemResource
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
        WarmItemResource $warmItemResource,
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
        $this->warmItemResource = $warmItemResource;
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
    public function sendEntitiesToQueue($stores, $type, $data, $priority = WarmPriority::LOWEST) {

        // Validate type
        $type = $this->validateType($type);

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
                    $category = $this->categoryRepository->get($categoryId, $storeId);
                    $productCount = $category->getProductCount();
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
        if ($this->checkDuplicated($storeId, $type, $info, $priority)) {
            return;
        }

        /** @var \Bydn\ImprovedPageCache\Model\WarmItem $warmItem */
        $warmItem = $this->warmItemFactory->create();
        $warmItem->setStoreId($storeId);
        $warmItem->setType($type);
        $warmItem->setInfo($info);
        $warmItem->setPriority($priority);
        $warmItem->setStatus(WarmStatus::NEW);

        try {
            $this->warmItemResource->save($warmItem);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Check if a record with same parameters already exists
     */
    private function checkDuplicated($storeId, $type, $info, $priority)
    {
        $connection = $this->warmItemResource->getConnection();
        $select = $connection->select()
            ->from($this->warmItemResource->getMainTable())
            ->where('store_id = ?', $storeId)
            ->where('type = ?', $type)
            ->where('info = ?', $info)
            ->where('status = ?', WarmStatus::NEW)
            ->where('priority >= ?', $priority);

        return (bool)$connection->fetchOne($select);
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
                if ($store->getIsActive()) {
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
        $collection->addAttributeToSelect('id');
        $collection->addIsActiveFilter();
        return $collection->getAllIds();
    }

    /**
     * @return array
     */
    private function extractAllProductIds() {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('id');
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
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
}
