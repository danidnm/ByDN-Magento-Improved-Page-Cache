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

namespace Bydn\ImprovedPageCache\Cron;

use Bydn\ImprovedPageCache\Helper\Config;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory;
use Bydn\ImprovedPageCache\Model\WarmItem\Status;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Psr\Log\LoggerInterface;

class Cleanup
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var WarmItemResource
     */
    private $warmItemResource;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param CollectionFactory $collectionFactory
     * @param WarmItemResource $warmItemResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CollectionFactory $collectionFactory,
        WarmItemResource $warmItemResource,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->collectionFactory = $collectionFactory;
        $this->warmItemResource = $warmItemResource;
        $this->logger = $logger;
    }

    /**
     * Cleanup old warm item records
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $days = $this->config->getCleanupDays();
        $date = new \DateTime();
        $date->modify("-" . $days . " days");
        $formattedDate = $date->format('Y-m-d H:i:s');

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [Status::DONE, Status::ERROR]]);
        $collection->addFieldToFilter('created_at', ['lt' => $formattedDate]);

        $count = 0;
        foreach ($collection as $item) {
            try {
                $this->warmItemResource->delete($item);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error('Error deleting warm item ID ' . $item->getId() . ': ' . $e->getMessage());
            }
        }

        if ($count > 0) {
            $this->logger->info(sprintf('Bydn_ImprovedPageCache: Cleaned up %d old records.', $count));
        }
    }
}
