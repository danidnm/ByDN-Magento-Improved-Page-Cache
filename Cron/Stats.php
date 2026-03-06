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

use Bydn\ImprovedPageCache\Model\WarmStatsFactory;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmStats as WarmStatsResource;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Status;
use Psr\Log\LoggerInterface;

class Stats
{
    /**
     * @var WarmStatsFactory
     */
    protected $warmStatsFactory;

    /**
     * @var WarmStatsResource
     */
    protected $warmStatsResource;

    /**
     * @var WarmItemCollectionFactory
     */
    protected $warmItemCollectionFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param WarmStatsFactory $warmStatsFactory
     * @param WarmStatsResource $warmStatsResource
     * @param WarmItemCollectionFactory $warmItemCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        WarmStatsFactory $warmStatsFactory,
        WarmStatsResource $warmStatsResource,
        WarmItemCollectionFactory $warmItemCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->warmStatsFactory = $warmStatsFactory;
        $this->warmStatsResource = $warmStatsResource;
        $this->warmItemCollectionFactory = $warmItemCollectionFactory;
        $this->logger = $logger;
    }

    /**
     * Execute the cron job
     *
     * @return void
     */
    public function execute()
    {
        try {
            $pendingCount = $this->getCountByStatus([Status::NEW, Status::PROCESSING]);
            $doneCount = $this->getCountByStatus([Status::DONE]);
            $errorCount = $this->getCountByStatus([Status::ERROR]);

            /** @var \Bydn\ImprovedPageCache\Model\WarmStats $stats */
            $stats = $this->warmStatsFactory->create();
            $stats->setPendingItems($pendingCount);
            $stats->setDoneItems($doneCount);
            $stats->setErrorItems($errorCount);

            $this->warmStatsResource->save($stats);
        } catch (\Exception $e) {
            $this->logger->error('Error in Bydn\ImprovedPageCache\Cron\Stats: ' . $e->getMessage());
        }
    }

    /**
     * Get item count by status
     *
     * @param array $statuses
     * @return int
     */
    protected function getCountByStatus(array $statuses): int
    {
        $collection = $this->warmItemCollectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => $statuses]);
        return (int)$collection->getSize();
    }
}
