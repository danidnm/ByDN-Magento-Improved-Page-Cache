<?php

namespace Bydn\ImprovedPageCache\Model\Queue\Warm;

use Magento\MysqlMq\Model\ResourceModel\MessageCollection;
use Magento\Setup\Exception;

Class Publisher
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private  $json;

    /**
     * @var \Magento\Framework\MessageQueue\PublisherInterface
     */
    private $queuePublisher;

    /**
     * @var \Magento\MysqlMq\Model\ResourceModel\MessageCollection
     */
    private  $messageCollection;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Magento\Framework\MessageQueue\PublisherInterface $queuePublisher,
        \Magento\MysqlMq\Model\ResourceModel\MessageCollection $messageCollection,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->json = $json;
        $this->queuePublisher = $queuePublisher;
        $this->messageCollection = $messageCollection;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * @param $type
     * @param $data
     * @return void
     * @throws Exception
     */
    public function sendEntitiesToQueue($stores, $type, $data, $highPriority = false) {

        // Validate params
        $this->validateParams($stores, $type, $data);

        // Extract stores if needed
        $stores = $this->extractStores($stores);

        // Extract IDs if needed
        if (in_array($type, array(
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_CATEGORIES,
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_PRODUCTS,
        ))) {
            $data = $this->extractIds($type, $data);
        }

        // Log and send to the queue
        $topicName = ($highPriority) ?
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::PRIORITY_QUEUE_NAME :
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::TOPIC_NAME;

        // Ensure data is an array
        $data = is_array($data) ? $data : explode(',', $data ?? '');

        // Split for the queue
        $chunks = $this->getChunks($data);

        // Send to the queue
        foreach ($stores as $store) {
            foreach ($chunks as $chunk) {

                // Prepare data
                $rawData = [
                    'store' => $store,
                    'type' => $type,
                    'data' => $chunk
                ];

                // Encode message body
                $messageBody = $this->json->serialize($rawData);
                $messageBodyForSelect = str_replace('\/', '\\\\\\\\\\\\/', $messageBody);
                $messageBodyForSelect = str_replace('"', '\\\\"', $messageBodyForSelect);

                // Check for duplicates
                $sql = "SELECT main_table.*, status_table.status
FROM queue_message AS main_table
INNER JOIN queue_message_status AS status_table ON main_table.id = status_table.message_id
WHERE (topic_name = '{$topicName}') AND (status = '2') AND (body = '\"{$messageBodyForSelect}\"')";
                $rows = $this->resourceConnection->getConnection()->fetchAll($sql);
                if (count($rows) > 0) {
                    $this->logger->debug('Skip duplicated');
                    continue;
                }

                $this->logger->debug('Queueing for warming');
                $this->logger->debug($rawData);
                $this->queuePublisher->publish($topicName, $messageBody);
            }
        }
    }

    /**
     * @param $type
     * @param $ids
     * @return void
     * @throws Exception
     */
    private function validateParams($stores, $type, $ids)
    {
        // Validate params
        if (!in_array($type, array(
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_HOME,
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_CATEGORIES,
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_PRODUCTS,
            \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_DIRECT_URL,
        ))) {
            $msg = "Unsupported operation type";
            $this->logger->writeError(__METHOD__, __LINE__,  $msg);
            throw new Exception($msg);
        }
    }

    /**
     * @param $stores
     * @return int[]|mixed
     */
    private function extractStores($stores)
    {
        // Check if all categories or all products
        if ($stores == \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_ENQUEUE_ALL) {
            $stores = [1];  // FIXME: Need to find store ids in database
        }

        // Ensure ids is an array
        $stores = is_array($stores) ? $stores : explode(',', $stores);

        return $stores;
    }

    /**
     * @param $type
     * @param $ids
     * @return array|mixed
     */
    private function extractIds($type, $ids)
    {
        // Check if all categories or all products
        if ($ids == \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_ENQUEUE_ALL) {
            if ($type == \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_CATEGORIES) {
                $ids = $this->extractAllCategoryIds();
            }
            else if ($type == \Bydn\ImprovedPageCache\Model\Queue\Warm\Config::QUEUE_OP_TYPE_PRODUCTS) {
                $ids = $this->extractAllProductIds();
            }
        }

        // Ensure ids is an array
        $ids = is_array($ids) ? $ids : explode(',', $ids);

        return $ids;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function extractAllCategoryIds() {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('id');
        $collection->addIsActiveFilter();
        return $collection->getAllIds();
    }

    /**
     * @return array
     */
    private function extractAllProductIds() {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('id');
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        return $collection->getAllIds();
    }

    private function getChunks($ids) {
        return array_chunk($ids, 1);
    }
}
