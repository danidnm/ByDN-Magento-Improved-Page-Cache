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
     * @param StoreManagerInterface $storeManager
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
     * @param int|null $priority
     * @return void
     */
    public function execute($priority = null)
    {
        if (!$this->helperConfig->isEnabled()) {
            return;
        }

        $collection = $this->warmItemCollectionFactory->create();
        $collection->addFieldToFilter('status', WarmStatus::NEW);

        if ($priority !== null) {
            $collection->addFieldToFilter('priority', ['gteq' => $priority]);
        }

        $collection->setOrder('priority', 'DESC');
        $collection->setOrder('created_at', 'ASC');
        $collection->setPageSize(500);

        $itemsBatch = [];
        $urlsBatch = [];
        $concurrency = $this->helperConfig->getConcurrency();

        foreach ($collection as $item) {

            // Get URL for this item
            $url = $this->generateUrl($item);

            // No URL => error
            if (!$url) {
                $item->setStatus(WarmStatus::ERROR);
                $this->warmItemResource->save($item);
                continue;
            }

            // No concurrency => Launch inmediatelly
            if ($concurrency == 1) {
                $status = $this->warmUrl($url);
                $item->setStatus($status ? WarmStatus::DONE : WarmStatus::ERROR);
                $this->warmItemResource->save($item);
            }
            else {
                $itemsBatch[] = $item;
                $urlsBatch[] = $url;
                if (count($urlsBatch) >= $concurrency) {
                    $this->processBatch($itemsBatch, $urlsBatch);
                    $itemsBatch = [];
                    $urlsBatch = [];
                }
            }
        }

        if (!empty($urlsBatch)) {
            $this->processBatch($itemsBatch, $urlsBatch);
        }
    }

    /**
     * Process a batch of URLs
     *
     * @param array $items
     * @param array $urls
     * @return void
     */
    private function processBatch($items, $urls)
    {
        $results = $this->warmUrlsParallel($urls);
        foreach ($items as $index => $item) {
            $status = isset($results[$index]) ? $results[$index] : false;
            $item->setStatus($status ? WarmStatus::DONE : WarmStatus::ERROR);
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
