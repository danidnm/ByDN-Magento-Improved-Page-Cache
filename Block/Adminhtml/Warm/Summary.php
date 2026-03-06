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

namespace Bydn\ImprovedPageCache\Block\Adminhtml\Warm;

use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\Source\WarmItem\Status;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class Summary extends Template
{
    /**
     * @var WarmItemResource
     */
    private $warmItemResource;

    /**
     * @var \Bydn\ImprovedPageCache\Model\ResourceModel\WarmStats
     */
    private $warmStatsResource;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var array|null
     */
    private $countsByStatus = null;

    /**
     * @param WarmItemResource $warmItemResource
     * @param \Bydn\ImprovedPageCache\Model\ResourceModel\WarmStats $warmStatsResource
     * @param TimezoneInterface $timezone
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        WarmItemResource $warmItemResource,
        \Bydn\ImprovedPageCache\Model\ResourceModel\WarmStats $warmStatsResource,
        TimezoneInterface $timezone,
        Context $context,
        array $data = []
    ) {
        $this->warmItemResource = $warmItemResource;
        $this->warmStatsResource = $warmStatsResource;
        $this->timezone = $timezone;
        parent::__construct($context, $data);
    }

    /**
     * Get chart data based on period
     *
     * @param string $period
     * @return array
     */
    public function getChartData(string $period = '24h'): array
    {
        $connection = $this->warmStatsResource->getConnection();
        $select = $connection->select()
            ->from($this->warmStatsResource->getMainTable());

        $now = new \DateTime();
        if ($period === 'today') {
            $date = clone $now;
            $date->setTime(0, 0, 0);
            $select->where('created_at >= ?', $date->format('Y-m-d H:i:s'));
        } elseif ($period === '7days') {
            $date = clone $now;
            $date->sub(new \DateInterval('P7D'));
            $select->where('created_at >= ?', $date->format('Y-m-d H:i:s'));
        } else { // 24h
            $date = clone $now;
            $date->sub(new \DateInterval('PT24H'));
            $select->where('created_at >= ?', $date->format('Y-m-d H:i:s'));
        }

        $select->order('created_at ASC');
        $rows = $connection->fetchAll($select);

        $data = [];
        foreach ($rows as $row) {
            $createdAt = $this->timezone->date(new \DateTime($row['created_at']));
            $data[] = [
                'date' => $createdAt->format('H:i'),
                'full_date' => $createdAt->format('Y-m-d H:i:s'),
                'pending' => (int)$row['pending_items'],
                'done' => (int)$row['done_items'],
                'error' => (int)$row['error_items']
            ];
        }

        return $data;
    }

    /**
     * Returns counts grouped by status as [status_value => count]
     *
     * @return array
     */
    private function getCountsByStatus(): array
    {
        if ($this->countsByStatus === null) {
            $connection = $this->warmItemResource->getConnection();
            $select = $connection->select()
                ->from($this->warmItemResource->getMainTable(), ['status', 'total' => new \Zend_Db_Expr('COUNT(*)')])
                ->group('status');

            $rows = $connection->fetchAll($select);

            $this->countsByStatus = [];
            foreach ($rows as $row) {
                $this->countsByStatus[(int)$row['status']] = (int)$row['total'];
            }
        }

        return $this->countsByStatus;
    }

    /**
     * @return int
     */
    public function getNewCount(): int
    {
        return $this->getCountsByStatus()[Status::NEW] ?? 0;
    }

    /**
     * @return int
     */
    public function getProcessingCount(): int
    {
        return $this->getCountsByStatus()[Status::PROCESSING] ?? 0;
    }

    /**
     * @return int
     */
    public function getDoneCount(): int
    {
        return $this->getCountsByStatus()[Status::DONE] ?? 0;
    }

    /**
     * @return int
     */
    public function getErrorCount(): int
    {
        return $this->getCountsByStatus()[Status::ERROR] ?? 0;
    }

    /**
     * Returns the most recent updated_at among DONE items, formatted in store timezone.
     * Returns null if no DONE items exist.
     *
     * @return string|null
     */
    public function getLastRunDate(): ?string
    {
        $connection = $this->warmItemResource->getConnection();
        $select = $connection->select()
            ->from($this->warmItemResource->getMainTable(), ['date' => new \Zend_Db_Expr('MAX(updated_at)')])
            ->where('status = ?', Status::DONE);

        $date = $connection->fetchOne($select);

        if (!$date) {
            return null;
        }

        return $this->timezone->formatDateTime(
            new \DateTime($date),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * Returns the oldest created_at among NEW items, formatted in store timezone.
     * Returns null if no NEW items exist.
     *
     * @return string|null
     */
    public function getOldestNewDate(): ?string
    {
        $connection = $this->warmItemResource->getConnection();
        $select = $connection->select()
            ->from($this->warmItemResource->getMainTable(), ['date' => new \Zend_Db_Expr('MIN(created_at)')])
            ->where('status = ?', Status::NEW);

        $date = $connection->fetchOne($select);

        if (!$date) {
            return null;
        }

        return $this->timezone->formatDateTime(
            new \DateTime($date),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * Returns the average time among DONE items.
     *
     * @return float
     */
    public function getAverageTime(): float
    {
        $connection = $this->warmItemResource->getConnection();
        $select = $connection->select()
            ->from($this->warmItemResource->getMainTable(), ['avg_time' => new \Zend_Db_Expr('AVG(time)')])
            ->where('status = ?', Status::DONE);

        return (float)$connection->fetchOne($select);
    }
}
