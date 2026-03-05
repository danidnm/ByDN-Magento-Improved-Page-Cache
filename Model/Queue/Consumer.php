<?php

namespace Bydn\ImprovedPageCache\Model\Queue;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\HTTP\Client\Curl;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\WarmItem\Status as WarmStatus;
use Bydn\ImprovedPageCache\Model\WarmItem\Types as WarmTypes;
use Psr\Log\LoggerInterface;

class Consumer
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var PageRepositoryInterface
     */
    private $pageRepository;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var WarmItemCollectionFactory
     */
    private $warmItemCollectionFactory;

    /**
     * @var WarmItemResource
     */
    private $warmItemResource;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param PageRepositoryInterface $pageRepository
     * @param UrlInterface $urlBuilder
     * @param Curl $curl
     * @param WarmItemCollectionFactory $warmItemCollectionFactory
     * @param WarmItemResource $warmItemResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        PageRepositoryInterface $pageRepository,
        UrlInterface $urlBuilder,
        Curl $curl,
        WarmItemCollectionFactory $warmItemCollectionFactory,
        WarmItemResource $warmItemResource,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->pageRepository = $pageRepository;
        $this->urlBuilder = $urlBuilder;
        $this->curl = $curl;
        $this->warmItemCollectionFactory = $warmItemCollectionFactory;
        $this->warmItemResource = $warmItemResource;
        $this->logger = $logger;
    }

    /**
     * Execute the processing logic
     *
     * @param int|null $priority
     * @return void
     */
    public function execute($priority = null)
    {
        $collection = $this->warmItemCollectionFactory->create();
        $collection->addFieldToFilter('status', WarmStatus::NEW);

        if ($priority !== null) {
            $collection->addFieldToFilter('priority', ['gteq' => $priority]);
        }

        $collection->setOrder('priority', 'DESC');
        $collection->setOrder('created_at', 'ASC');
        $collection->setPageSize(500);

        foreach ($collection as $item) {
            $url = $this->generateUrl($item);
            if ($url) {
                $status = $this->warmUrl($url);
                $item->setStatus($status ? WarmStatus::DONE : WarmStatus::ERROR);
            } else {
                $item->setStatus(WarmStatus::ERROR);
            }
            $this->warmItemResource->save($item);
        }
    }

    /**
     * Generate URL based on item type and info
     *
     * @param \Bydn\ImprovedPageCache\Model\WarmItem $item
     * @return string|null
     */
    private function generateUrl($item)
    {
        $type = $item->getType();
        $info = $item->getInfo();
        $storeId = $item->getStoreId();

        try {
            $store = $this->storeManager->getStore($storeId);
            
            switch ($type) {
                case WarmTypes::HOME:
                    return $store->getBaseUrl();

                case WarmTypes::PAGES:
                    $page = $this->pageRepository->getById($info);
                    return $store->getBaseUrl() . $page->getIdentifier();

                case WarmTypes::PRODUCTS:
                    $parts = explode(',', $info);
                    $productId = $parts[0];
                    $categoryId = isset($parts[1]) ? $parts[1] : 0;
                    
                    /** @var \Magento\Catalog\Model\Product $product */
                    $product = $this->productRepository->getById($productId, false, $storeId);
                    if ($categoryId > 0) {
                        /** @var \Magento\Catalog\Model\Category $category */
                        $category = $this->categoryRepository->get($categoryId, $storeId);
                        $product->setCategory($category);
                    }
                    return $product->setStoreId($storeId)->getProductUrl();

                case WarmTypes::CATEGORIES:
                    $parts = explode(',', $info);
                    $categoryId = $parts[0];
                    $pageNumber = isset($parts[1]) ? $parts[1] : 1;
                    
                    /** @var \Magento\Catalog\Model\Category $category */
                    $category = $this->categoryRepository->get($categoryId, $storeId);
                    $url = $category->getUrl();
                    if ($pageNumber > 1) {
                        $url .= (strpos($url, '?') === false ? '?' : '&') . 'p=' . $pageNumber;
                    }
                    return $url;

                case WarmTypes::DIRECT_URL:
                    return $store->getBaseUrl() . ltrim($info, '/');
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    /**
     * Warm the given URL
     *
     * @param string $url
     * @return bool
     */
    private function warmUrl($url)
    {
        try {
            $this->logger->info('Warming: ' . $url);

            $start_time = microtime(true);
            $this->curl->addHeader('X-Magento-Cache-Refresh', '1');
            $this->curl->get($url);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time);

            $this->logger->info('Done in ' . $execution_time . ' seconds');

            return $this->curl->getStatus() == 200;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }
}
