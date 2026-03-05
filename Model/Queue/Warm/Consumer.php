<?php

namespace Bydn\ImprovedPageCache\Model\Queue\Warm;

/*
 * const MESSAGE_STATUS_NEW = 2;
 * const MESSAGE_STATUS_IN_PROGRESS = 3;
 * const MESSAGE_STATUS_COMPLETE= 4;
 * const MESSAGE_STATUS_RETRY_REQUIRED = 5;
 * const MESSAGE_STATUS_ERROR = 6;
 * const MESSAGE_STATUS_TO_BE_DELETED = 7;
 *
 * php -dxdebug.remote_autostart=1 bin/magento vivo:cron:run consumers_runner --area crontab
 * php -dxdebug.remote_autostart=1 bin/magento queue:consumers:start bydn_improvedpagecache
 * php -dxdebug.remote_autostart=1 bin/magento queue:consumers:start bydn_improvedpagecache_priority
 *
 * select status, count(*) from queue_message_status group by status;
 * select * from queue_message m left join queue_message_status s on m.id=s.message_id order by m.id desc;
 */

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;

Class Consumer
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    private $curl;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private  $json;

    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    private $emulation;

    /**
     * @var \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher
     */
    private $cacheWarmer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Store\Model\App\Emulation $emulation,
        \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $cacheWarmer,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->storeManager = $storeManager;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->emulation = $emulation;
        $this->cacheWarmer = $cacheWarmer;
        $this->logger = $logger;
    }

    /**
     * @param $data
     * @return void
     */
    public function process($data)
    {
        try{
            // Extract data and check
            $data = $this->json->unserialize($data);
            $operationType = $data['type'];
            $store = $data['store'];
            $entities = $data['data'];

            // Store emulation
            $this->startEmulation($store);
            {
                // Check operation to know what to do...
                switch ($operationType) {
                    case \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_HOME:
                        $urls = $this->getHomeUrl();
                        break;
                    case \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_PRODUCTS:
                        $urls = $this->getProductUrls($entities);
                        break;
                    case \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_CATEGORIES:
                        $urls = $this->getCategoryUrls($entities);
                        break;
                    case \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_DIRECT_URL:
                        $urls = $this->getDirectUrls($entities);
                        break;
                }

                $this->warmUrls($urls);
            }

            $this->stopEmulation();
        }
        catch (\Exception $e){
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Starts store emulation
     * @param $store
     * @return void
     */
    private function startEmulation($store)
    {
        $this->emulation->startEnvironmentEmulation($store);
    }

    /**
     * Stops store emulation
     * @return void
     */
    private function stopEmulation()
    {
        $this->emulation->stopEnvironmentEmulation();
    }

    /**
     * Returns home URL for the current store
     * @return void
     */
    private function getHomeUrl()
    {
        $urls = [];
        $urls[] = $this->storeManager->getStore()->getBaseUrl();
        return $urls;
    }

    /**
     * Returns a list of product URLs based on entity_ids
     * @param $ids
     * @return array
     */
    private function getProductUrls($ids)
    {
        // Get product collection
        $fields = ['sku', 'url_key', 'url_path'];
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect($fields);
        $collection->addAttributeToFilter('entity_id', $ids);

        // Extract URLs
        $urls = [];
        foreach ($collection as $product) {
            $urls[] = $product->getProductUrl();
        }

        return $urls;
    }

    /**
     * Returns a list of category urls based on entity_ids
     * @param $ids
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getCategoryUrls($ids)
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $limit = $this->getProductsPerPage();

        // Get category collection
        $fields = ['url_key', 'url_path'];
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect($fields);
        $collection->addAttributeToFilter('entity_id', $ids);

        // Extract URLs
        $urls = [];
        foreach ($collection as $category) {

            // Add base URL of the category (first page)
            $urls[] = $category->getUrl();

            // Calculate number of pages and add infinite scroll pager URLs
            $totalPages = ceil($category->getProductCollection()->count() / $limit);
            for ($pageNum = 2; $pageNum <= $totalPages; $pageNum++) {
                $urls[] = $baseUrl . 'categories/scroll/pager?p=' . $pageNum . '&limit=' . $limit . '&category_id=' . $category->getId();
            }
        }

        return $urls;
    }

    /**
     * Return full urls from relative ones
     * @param $urls
     * @return array|string
     */
    private function getDirectUrls($urls) {
        $fullUrls = [];
        foreach ($urls as $url) {
            $fullUrls[] = $this->storeManager->getStore()->getBaseUrl() . $url;
        }
        return $fullUrls;
    }

    /**
     * Get the number of product per page
     * @return int
     */
    private function getProductsPerPage()
    {
        return (int) $this->scopeConfig->getValue(
            'catalog/frontend/grid_per_page',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Make curl calls to the URLs requested
     * @param $urls
     * @return void
     */
    private function warmUrls($urls)
    {
        // Iterate URLs making curl calls
        foreach ($urls as $url) {
            $this->logger->debug('Warming: ' . $url);
            $start_time = microtime(true);
            $this->curl->addHeader('X-Magento-Cache-Refresh', '1');
            $this->curl->get($url);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time);
            $this->logger->debug('Done in ' . $execution_time . ' seconds');
        }
    }
}
