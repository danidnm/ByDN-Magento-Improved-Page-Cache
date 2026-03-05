<?php

namespace Bydn\ImprovedPageCache\Plugin\Magento\CacheInvalidate\Model;

class PurgeCache
{
    /**
     * @var \Magento\Framework\App\State
     */
    private $appState;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private $request;

    /**
     * @var \Bydn\ImprovedPageCache\Helper\RequestInfo
     */
    private $requestInfo;

    /**
     * @var \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher
     */
    private $cacheWarmer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * List of product IDs to refresh
     * @var array
     */
    private $productIds = [];

    /**
     * List of product IDs to refresh
     * @var array
     */
    private $categoryIds = [];

    /**
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Bydn\ImprovedPageCache\Helper\RequestInfo $requestInfo
     * @param \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $cacheWarmer
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Magento\Framework\App\Request\Http $request,
        \Bydn\ImprovedPageCache\Helper\RequestInfo $requestInfo,
        \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $cacheWarmer,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->appState = $appState;
        $this->request = $request;
        $this->requestInfo = $requestInfo;
        $this->cacheWarmer = $cacheWarmer;
        $this->logger = $logger;
    }

    /**
     * Removes some cache tags to be refreshed
     *
     * @param \Magento\CacheInvalidate\Model\PurgeCache $subject
     * @param $tags
     * @return array[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function beforeSendPurgeRequest(\Magento\CacheInvalidate\Model\PurgeCache $subject, $tags)
    {
        // Ensure tags is array
        $tags = is_string($tags) ? [$tags] : $tags;

        // Filtered result
        $newTags = [];

        // Show original tags
        $this->logger->debug('Original tags: ');
        $this->logger->debug($tags);

        try {
            // Area code is not set on setup:upgrade
            $areaCode = $this->appState->getAreaCode();
        }
        catch (\Exception $e) {
            $this->logger->error('Area code not set');
            return [$tags];
        }

        // Check if it is product save to remove category tags
        $cronRunning = $this->requestInfo->getCurrentCronCode();
        $commandRunning = $this->requestInfo->getCurrentCommandCode();
        $indexerRunning = $this->requestInfo->getCurrentIndexerCode();
        $webapiMethodRunning = $this->requestInfo->getCurrentWebapiMethod();
        $controllerModule = $this->request->getControllerModule();
        $moduleName = $this->request->getModuleName();
        $controllerName = $this->request->getControllerName();
        $actionName = $this->request->getActionName();

        // // Log request informacion for debug purposes
        // if (!empty($areaCode))            { $this->logger->debug('Area: ' . $areaCode);                      }
        // if (!empty($cronRunning))         { $this->logger->debug('Cron running: ' . $cronRunning);           }
        // if (!empty($commandRunning))      { $this->logger->debug('Command running: ' . $commandRunning);     }
        // if (!empty($indexerRunning))      { $this->logger->debug('Indexer running: ' . $indexerRunning);     }
        // if (!empty($webapiMethodRunning)) { $this->logger->debug('WebApi method: ' . $webapiMethodRunning);  }
        // if (!empty($controllerModule))    { $this->logger->debug('Controller module: ' . $controllerModule); }
        // if (!empty($moduleName))          { $this->logger->debug('Module: ' . $moduleName);                  }
        // if (!empty($controllerName))      { $this->logger->debug('Controller: ' . $controllerName);          }
        // if (!empty($actionName))          { $this->logger->debug('Action: ' . $actionName);                  }

        // Extract product and category tags
        $this->extractProductIds($tags);
        $this->extractCategoryIds($tags);

        // Process tags and skip some of them
        foreach ($tags as $currentTag) {

            // Flag to avoid this tag
            $skipTag = false;

            // Avoid all when indexing and crontab
            if ($indexerRunning || $cronRunning) {
                $skipTag = true;
            }

            // Avoid all tags with category and products
            if (stripos($currentTag, 'cat_c')) {
                $skipTag = true;
            }
            if (stripos($currentTag, 'cat_p')) {
                $skipTag = true;
            }

            // Filter all cms blocks as it invalidate most of the products and categories
            if (stripos($currentTag, 'cms_b')) {$this->enqueueCommonPages(true);
                $skipTag = true;
            }

            // Add to tags if not skipped
            if ($skipTag) {
                $this->logger->debug('Avoid cleaning: ' . $currentTag);
                continue;
            }

            // Add tag
            $newTags[] = $currentTag;
        }

        // Enqueue all products and categories
        // Categories will be outdated longer but it is not a good idea to refresh all of the category pages because one product has changed
        $this->enqueueProductIdsWithPriority(true);
        $this->enqueueCategoryIdsWithPriority(false);

        $this->logger->debug('Cache tags invalidating');
        $this->logger->debug($newTags);

        return [$newTags];
    }

    /**
     * Enqueues category and product pages that have been cleared from the cache
     *
     * @param \Magento\CacheInvalidate\Model\PurgeCache $subject
     * @param $result
     * @param $tags
     * @return mixed
     * @throws \Magento\Setup\Exception
     */
    public function afterSendPurgeRequest(\Magento\CacheInvalidate\Model\PurgeCache $subject, $result, $tags)
    {
        return $result;
    }

    /**
     * Return product Ids to refresh
     *
     * @param $tags
     * @return array|void
     */
    private function extractProductIds($tags) {

        // Si no hay tags de borrado de caché, no podemos hacer nada
        if (!is_array($tags)) return [];

        // As string for the regex processing
        $tags = implode('#', $tags);

        // Restart result array
        $this->productIds = [];

        $productMatches = [];
        preg_match_all('/cat_p_([0-9]+)/', $tags, $productMatches);
        foreach ($productMatches as $match) {
            foreach ($match as $id) {
                if (is_numeric($id)) {
                    $this->productIds[] = $id;
                }
            }
        }

        $this->logger->debug('Product matches:');
        $this->logger->debug(json_encode($this->productIds));

        return $this->productIds;
    }

    /**
     * Return category Ids to refresh
     *
     * @param $tags
     * @return array|void
     */
    private function extractCategoryIds($tags) {

        // Si no hay tags de borrado de caché, no podemos hacer nada
        if (!is_array($tags)) return [];

        // As string for the regex processing
        $tags = implode('#', $tags);

        // Restart result array
        $this->categoryIds = [];

        $categoryMatches = [];
        preg_match_all('/cat_c_([0-9]+)/', $tags, $categoryMatches);
        foreach ($categoryMatches as $match) {
            foreach ($match as $id) {
                if (is_numeric($id)) {
                    $this->categoryIds[] = $id;
                }
            }
        }

        $this->logger->debug('Category matches:');
        $this->logger->debug(json_encode($this->categoryIds));

        return $this->categoryIds;
    }

    /**
     * Enqueue extracted product IDs with high priority
     * @return void
     * @throws \Magento\Setup\Exception
     */
    private function enqueueProductIdsWithPriority($priority) {
        if (!empty($this->productIds)) {
            $this->cacheWarmer->sendEntitiesToQueue(
                \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_ENQUEUE_ALL,
                \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_PRODUCTS,
                $this->productIds,
                $priority);
        }
    }

    /**
     * Enqueue extracted category IDs with high priority
     * @return void
     * @throws \Magento\Setup\Exception
     */
    private function enqueueCategoryIdsWithPriority($priority) {
        if (!empty($this->categoryIds)) {
            $this->cacheWarmer->sendEntitiesToQueue(
                \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_ENQUEUE_ALL,
                \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_CATEGORIES,
                $this->categoryIds,
                $priority);
        }
    }

    /**
     * Enqueue common pages (promos for example)
     * @return void
     * @throws \Magento\Setup\Exception
     */
    private function enqueueCommonPages($priority) {
        $this->cacheWarmer->sendEntitiesToQueue(
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_ENQUEUE_ALL,
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_DIRECT_URL,
            [
                '',
                'promocion',
            ],
            $priority);
    }
}
