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
use Magento\Framework\UrlInterface;
use Magento\Framework\HTTP\Client\Curl;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\WarmItem\Status as WarmStatus;
use Bydn\ImprovedPageCache\Model\WarmItem\Types as WarmTypes;
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
     * @param UrlInterface $urlBuilder
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
        UrlInterface $urlBuilder,
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
        $this->urlBuilder = $urlBuilder;
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
                $status = isset($results[$index]) ? $results[$index] : false;
                $item->setStatus($status ? WarmStatus::DONE : WarmStatus::ERROR);
                $this->warmItemResource->save($item);
            }
        }
        else {
            $item = array_shift($this->batchItems);
            $url = array_shift($this->batchUrls);
            $status = $this->warmUrl($url);
            $item->setStatus($status ? WarmStatus::DONE : WarmStatus::ERROR);
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
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];

        foreach ($urls as $index => $url) {
            $this->logger->info('Warming Parallel: ' . $url);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Magento-Cache-Refresh: 1']);
            
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$index] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multiHandle) != -1) {
                do {
                    $mrc = curl_multi_exec($multiHandle, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        foreach ($curlHandles as $index => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$index] = ($httpCode == 200);
            
            if ($httpCode != 200) {
                $this->logger->error(sprintf('Error warming %s (Status: %s)', $urls[$index], $httpCode));
            } else {
                $this->logger->info(sprintf('Done: %s', $urls[$index]));
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
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
