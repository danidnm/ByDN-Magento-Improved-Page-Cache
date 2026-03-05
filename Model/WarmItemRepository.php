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

namespace Bydn\ImprovedPageCache\Model;

use Bydn\ImprovedPageCache\Api\WarmItemRepositoryInterface;
use Bydn\ImprovedPageCache\Api\Data\WarmItemInterface;
use Bydn\ImprovedPageCache\Api\Data\WarmItemInterfaceFactory;
use Bydn\ImprovedPageCache\Api\Data\WarmItemSearchResultsInterface;
use Bydn\ImprovedPageCache\Api\Data\WarmItemSearchResultsInterfaceFactory;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem as WarmItemResource;
use Bydn\ImprovedPageCache\Model\ResourceModel\WarmItem\CollectionFactory as WarmItemCollectionFactory;
use Psr\Log\LoggerInterface as Logger;
use Bydn\ImprovedPageCache\Model\WarmItemFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class WarmItemRepository implements WarmItemRepositoryInterface
{
    /**
     * @var CollectionProcessorInterface
     */
    private $collectionProcessor;

    /**
     * @var WarmItemResource
     */
    private $resource;

    /**
     * @var WarmItemCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var WarmItemFactory
     */
    private $warmItemFactory;

    /**
     * @var WarmItemInterfaceFactory
     */
    private $warmItemInterfaceFactory;

    /**
     * @var WarmItemSearchResultsInterfaceFactory
     */
    private $searchResultsFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param WarmItemResource $resource
     * @param WarmItemFactory $warmItemFactory
     * @param WarmItemInterfaceFactory $warmItemInterfaceFactory
     * @param WarmItemCollectionFactory $collectionFactory
     * @param WarmItemSearchResultsInterfaceFactory $searchResultsFactory
     * @param CollectionProcessorInterface $collectionProcessor
     * @param Logger $logger
     */
    public function __construct(
        WarmItemResource $resource,
        WarmItemFactory $warmItemFactory,
        WarmItemInterfaceFactory $warmItemInterfaceFactory,
        WarmItemCollectionFactory $collectionFactory,
        WarmItemSearchResultsInterfaceFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor,
        Logger $logger
    ) {
        $this->resource = $resource;
        $this->warmItemFactory = $warmItemFactory;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->warmItemInterfaceFactory = $warmItemInterfaceFactory;
        $this->collectionProcessor = $collectionProcessor;
        $this->logger = $logger;
    }

    /**
     * Retrieve entity.
     *
     * @param int $id
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($id)
    {
        $entity = $this->warmItemFactory->create();
        $this->resource->load($entity, $id);
        if (!$entity->getEntityId()) {
            throw new NoSuchEntityException(__('Could not find entity with id "%1"', $id));
        }
        return $entity;
    }

    /**
     * Retrieve WarmItems matching the specified criteria.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemSearchResultsInterface
     * @throws LocalizedException
     */
    public function getList(SearchCriteriaInterface $searchCriteria)
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());
        return $searchResults;
    }

    /**
     * Save WarmItem entry
     *
     * @param \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface $warmItem
     * @return \Bydn\ImprovedPageCache\Api\Data\WarmItemInterface
     * @throws LocalizedException
     */
    public function save(WarmItemInterface $warmItem): WarmItemInterface
    {
        try {
            $this->resource->save($warmItem);
        } catch (LocalizedException $exception) {
            throw new CouldNotSaveException(
                __('Could not save the warm item %1', $exception->getMessage()),
                $exception
            );
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('Could not save the warm item: %1', $exception->getMessage()),
                $exception
            );
        }
        return $warmItem;
    }
}
