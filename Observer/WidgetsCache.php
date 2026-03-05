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

namespace Bydn\ImprovedPageCache\Observer;

use Bydn\ImprovedPageCache\Helper\Config as HelperConfig;

class WidgetsCache implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Framework\App\Cache\Type\FrontendPool
     */
    private $cachePool;

    /**
     * @var \Magento\Framework\App\Cache\StateInterface
     */
    private $cacheState;

    /**
     * @var \Magento\CacheInvalidate\Model\PurgeCache
     */
    private $cacheInvalidator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var HelperConfig
     */
    private $helperConfig;

    /**
     * @param \Magento\CacheInvalidate\Model\PurgeCache $cacheInvalidator
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Cache\Type\FrontendPool $cachePool,
        \Magento\Framework\App\Cache\StateInterface $cacheState,
        \Magento\CacheInvalidate\Model\PurgeCache $cacheInvalidator,
        \Psr\Log\LoggerInterface $logger,
        HelperConfig $helperConfig
    ) {
        $this->cachePool = $cachePool;
        $this->cacheState = $cacheState;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->logger = $logger;
        $this->helperConfig = $helperConfig;
    }

    /**
     * @param \Magento\Widget\Model\Widget\Instance $widget
     * @return array
     */
    public function extractProducts($widget)
    {
        // Products to refresh
        $productIds = [];

        // Extract products (old)
        $pageGroups = $widget->getOrigData('page_groups') ?? [];
        foreach ($pageGroups as $pageGroup) {
            if  (
                isset($pageGroup['group']) &&
                stripos($pageGroup['group'], 'product') !== false
            ) {
                if  (isset($pageGroup['entities'])) {
                    $entities = $pageGroup['entities'];
                    $entities = explode(',', $entities);
                    $productIds = array_merge($productIds, $entities);
                }
            }
            if  (
                isset($pageGroup['page_group']) &&
                stripos($pageGroup['page_group'], 'product') !== false
            ) {
                if  (isset($pageGroup['entities'])) {
                    $entities = $pageGroup['entities'];
                    $entities = explode(',', $entities);
                    $productIds = array_merge($productIds, $entities);
                }
            }
        }

        // Extract products (new)
        $pageGroups = $widget->getPageGroups() ?? [];
        foreach ($pageGroups as $pageGroup) {
            if  (
                isset($pageGroup['group']) &&
                stripos($pageGroup['group'], 'product') !== false
            ) {
                if  (isset($pageGroup['entities'])) {
                    $entities = $pageGroup['entities'];
                    $entities = explode(',', $entities);
                    $productIds = array_merge($productIds, $entities);
                }
            }
            if  (
                isset($pageGroup['page_group']) &&
                stripos($pageGroup['page_group'], 'product') !== false
            ) {
                if  (isset($pageGroup['entities'])) {
                    $entities = $pageGroup['entities'];
                    $entities = explode(',', $entities);
                    $productIds = array_merge($productIds, $entities);
                }
            }
        }

        return array_unique($productIds);
    }

    /**
     * @param array $ids
     * @return void
     */
    private function refreshProductPages($ids)
    {
        // Calculate tags
        $tags = [];
        $pattern = '((^|,)%s(,|$))';
        foreach ($ids as $id) {
            $this->logger->debug('Refresh: ' . $id);
            $tag = 'cat_p_' . $id;
            $tags[] = sprintf($pattern, $tag);
        }

        // Refresh varnish
        $this->cacheInvalidator->sendPurgeRequest(array_unique($tags));
    }

    /**
     * @return void
     */
    private function refreshLayoutAndBlockCache() {
        if (!$this->helperConfig->isEnabled()) {
            return;
        }
        $cacheType = 'block_html';
        if ($this->cacheState->isEnabled($cacheType)) {
            $this->logger->debug('Refresh block cache');
            $this->cachePool->get($cacheType)->clean();
        }
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->helperConfig->isEnabled()) {
            return;
        }

        /** @var \Magento\Widget\Model\Widget\Instance */
        $widget = $observer->getDataObject();

        // Log widget ID
        $this->logger->debug('Widget changed: ' . $widget->getTitle() . ' / ' . $widget->getId());

        // Extract products to refresh
        $productIds = $this->extractProducts($widget);
        if (!empty($productIds)) {
            $this->refreshProductPages($productIds);
        }

        // Refresh layout and block caché
        $this->refreshLayoutAndBlockCache();
    }
}

