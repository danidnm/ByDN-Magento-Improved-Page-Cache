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
use Magento\Store\Model\App\Emulation;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\HTTP\Client\Curl;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Status as WarmStatus;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Type as WarmTypes;
use Bydn\ImprovedPageCache\Helper\Config as HelperConfig;
use Psr\Log\LoggerInterface;

class Consumer
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var Emulation
     */
    private $emulation;

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
     * @var HelperConfig
     */
    private $helperConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Items collection to process
     */
    private $itemCollection;

    /**
     * Currently emulated store id
     */
    private $currentEmulatedStoreId = null;

    /**
     * Concurrency level
     */
    private $concurrency = 1;

    /**
     * Current batch of items
     */
    private $batchItems = [];

    /**
     * Current batch of URLs
     */
    private $batchUrls = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param PageRepositoryInterface $pageRepository
     * @param Curl $curl
     * @param WarmItemCollectionFactory $warmItemCollectionFactory
     * @param WarmItemResource $warmItemResource
     * @param HelperConfig $helperConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Emulation $emulation,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        PageRepositoryInterface $pageRepository,
        Curl $curl,
        WarmItemCollectionFactory $warmItemCollectionFactory,
        WarmItemResource $warmItemResource,
        HelperConfig $helperConfig,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->emulation = $emulation;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->pageRepository = $pageRepository;
        $this->curl = $curl;
        $this->warmItemCollectionFactory = $warmItemCollectionFactory;
        $this->warmItemResource = $warmItemResource;
        $this->helperConfig = $helperConfig;
        $this->logger = $logger;
    }

    /**
     * Execute the processing logic
     *
     * @param int|null $minPriority
     * @param int|null $maxPriority
     * @return void
     */
    public function execute($minPriority = null, $maxPriority = null)
    {
        $this->logger->info('Consumer execution started', ['min_priority' => $minPriority, 'max_priority' => $maxPriority]);
        
        // Check module is enabled
        if (!$this->helperConfig->isEnabled()) {
            return;
        }

        // Get pending items with priority limits
        $collection = $this->warmItemCollectionFactory->create();
        $collection->addFieldToFilter('status', WarmStatus::NEW);
        if ($minPriority !== null) {
            $collection->addFieldToFilter('priority', ['gteq' => $minPriority]);
        }
        if ($maxPriority !== null) {
            $collection->addFieldToFilter('priority', ['lteq' => $maxPriority]);
        }
        $collection->setOrder('priority', 'DESC');
        $collection->setOrder('created_at', 'ASC');
        $collection->setPageSize(250);

        // Items collection ARRAY
        $this->itemCollection = $collection->getItems();

        // No store for the moment
        $this->currentEmulatedStoreId = null;

        // Set concurrency level
        $this->concurrency = $this->helperConfig->getConcurrency();

        // Iterate until no more batches
        while ($this->extractNextBatchWithEmulation()) {
            $this->processBatch();
        }

        // Stop emulation
        $this->manageEmulation(null);
    }

    /**
     * Extract next batch of items from queue (up to concurrency level)
     */
    private function extractNextBatchWithEmulation()
    {
        // Prepare vars for batches
        $this->batchItems = [];
        $this->batchUrls = [];

        // Extact items until max concurrency reached
        while ($item = array_shift($this->itemCollection)) {

            // Get item store id and check module enbled for store. If not, mark as disabled.
            $storeId = $item->getStoreId();
            if (!$this->helperConfig->isEnabled($storeId)) {
                $item->setStatus(WarmStatus::DISABLED);
                $this->warmItemResource->save($item);
                continue;
            }

            // Start emulation if not started or store changed
            $this->currentEmulatedStoreId = $this->manageEmulation($storeId);

            // Generate URL
            $url = $this->generateUrl($item);

            // No URL => error
            if (!$url) {
                $item->setStatus(WarmStatus::ERROR);
                $this->warmItemResource->save($item);
                continue;
            }

            // Save URL and set status to PROCESSING
            $item->setUrl($url);
            $item->setStatus(WarmStatus::PROCESSING);
            $this->warmItemResource->save($item);

            // Add to batch
            $this->batchItems[] = $item;
            $this->batchUrls[] = $url;

            // If batch is full, break
            if (count($this->batchItems) >= $this->concurrency) {
                break;
            }
        }

        return count($this->batchItems) > 0;
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
                    $identifier = $page->getIdentifier();
                    return $store->getBaseUrl() . ($identifier == 'home' ? '' : $identifier);

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
     * Manages emulation changing only when store changes
     */
    private function manageEmulation($storeId)
    {
        // Manage stop emulation
        if ($storeId == 0) {
            if ($this->currentEmulatedStoreId !== null) {
                $this->emulation->stopEnvironmentEmulation();
            }
            return null;
        }
        
        // Manage start or change emulation
        if ($this->currentEmulatedStoreId === null) {
            $this->emulation->startEnvironmentEmulation($storeId);
        } 
        else if ($this->currentEmulatedStoreId != $storeId) {
            $this->emulation->stopEnvironmentEmulation();
            $this->emulation->startEnvironmentEmulation($storeId);
        }
        return $storeId;
    }
    /**
     * Process a batch of URLs
     *
     * @return void
     */
    private function processBatch()
    {
        if ($this->concurrency > 1) {
            $results = $this->warmUrlsParallel($this->batchUrls);
            foreach ($this->batchItems as $index => $item) {
                $result = isset($results[$index]) ? $results[$index] : ['status' => false, 'http_code' => 0, 'time' => 0];
                $item->setStatus($result['status'] ? WarmStatus::DONE : WarmStatus::ERROR);
                $item->setTime($result['time']);
                $item->setResultCode($result['http_code']);
                $this->warmItemResource->save($item);
            }
        }
        else {
            $item = array_shift($this->batchItems);
            $url = array_shift($this->batchUrls);
            $result = $this->warmUrl($url);
            $item->setStatus($result['status'] ? WarmStatus::DONE : WarmStatus::ERROR);
            $item->setTime($result['time']);
            $item->setResultCode($result['http_code']);
            $this->warmItemResource->save($item);
        }
    }

    /**
     * Warm several URLs in parallel
     *
     * @param array $urls
     * @return array
     */
    private function warmUrlsParallel($urls)
    {
        $start_time = microtime(true);

        // Initialize a cURL multi handle to manage multiple requests simultaneously
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];

        // Setup individual cURL handles for each URL and add them to the multi handle
        foreach ($urls as $index => $url) {

            $this->logger->info('Warming Parallel: ' . $url);

            // Create handle and add to multiple
            $curlHandles[$index] = curl_init();
            curl_setopt($curlHandles[$index], CURLOPT_URL, $url);
            curl_setopt($curlHandles[$index], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlHandles[$index], CURLOPT_HEADER, false);
            curl_setopt($curlHandles[$index], CURLOPT_TIMEOUT, 30);
            curl_setopt($curlHandles[$index], CURLOPT_HTTPHEADER, ['X-Magento-Cache-Refresh: 1']);
            curl_multi_add_handle($multiHandle, $curlHandles[$index]);
        }

        // Start executing the requests. curl_multi_exec is non-blocking. It starts the requests and returns immediately.
        $active = null;
        do {
            curl_multi_exec($multiHandle, $active);
            curl_multi_select($multiHandle);
        } while ($active > 0);

        $end_time = microtime(true);
        $execution_time = ($end_time - $start_time);

        // All requests are done. Now extract results and clean up.
        foreach ($curlHandles as $index => $ch) {

            // Extract result for handle
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            
            $results[$index] = [
                'status' => ($httpCode == 200),
                'http_code' => $httpCode,
                'time' => $totalTime
            ];
            
            // Log result
            if ($httpCode != 200) {
                $this->logger->error('Error warming ' . $urls[$index] . ' (Status: ' . $httpCode . ')');
            } else {
                $this->logger->info('Done: ' . $urls[$index] . ' in ' . $totalTime . 's');
            }

            // Remove the handle from the multi handle and close it
            curl_multi_remove_handle($multiHandle, $ch);
        }

        // Close the multi handle itself
        curl_multi_close($multiHandle);

        $this->logger->info('Batch done in: ' . $execution_time . ' seconds');

        return $results;
    }

    /**
     * Warm the given URL
     *
     * @param string $url
     * @return array
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
            $httpCode = $this->curl->getStatus();

            $this->logger->info('Done in ' . $execution_time . ' seconds');

            return [
                'status' => ($httpCode == 200),
                'http_code' => $httpCode,
                'time' => $execution_time
            ];
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return [
                'status' => false,
                'http_code' => 0,
                'time' => 0
            ];
        }
    }
}
