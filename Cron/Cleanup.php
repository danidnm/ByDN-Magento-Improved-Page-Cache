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
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Status;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Psr\Log\LoggerInterface;

class Cleanup
{
    /**
     * Items deleted per query
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * @var Config
     */
    private $config;

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
     * @param WarmItemResource $warmItemResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        WarmItemResource $warmItemResource,
        LoggerInterface $logger
    ) {
        $this->config = $config;
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

        // Delete in chunks by id: the table may hold hundreds of thousands of old items, and loading
        // and deleting them one by one through the resource model took too long
        $connection = $this->warmItemResource->getConnection();
        $table = $this->warmItemResource->getMainTable();
        $select = $connection->select()
            ->from($table, ['entity_id'])
            ->where('status IN (?)', [Status::DONE, Status::ERROR])
            ->where('created_at < ?', $formattedDate)
            ->limit(self::DELETE_CHUNK_SIZE);

        $count = 0;
        try {
            while ($ids = $connection->fetchCol($select)) {
                $deleted = $connection->delete($table, ['entity_id IN (?)' => $ids]);
                if ($deleted === 0) {
                    break;
                }
                $count += $deleted;
            }
        } catch (\Exception $e) {
            $this->logger->error('[Bydn_ImprovedPageCache] - Error cleaning up old warm items: ' . $e->getMessage());
        }

        if ($count > 0) {
            $this->logger->info(sprintf('[Bydn_ImprovedPageCache] - Cleaned up %d old records.', $count));
        }
    }
}
