<?php

namespace Bydn\ImprovedPageCache\Plugin\Magento\Framework\App\Http;

use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory;

class Context
{
    /**
     * @var bool|null
     */
    private $hasAffectingRules = null;
    /**
     * @var \Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory CollectionFactory
     */
    private $catalogRuleCollectionuleFactory;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    private $date;

    /**
     * @param CollectionFactory $catalogRuleCollectionuleFactory
     * @param \Magento\Framework\Serialize\Serializer\Json $serializer
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     */
    public function __construct(
        \Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory $catalogRuleCollectionuleFactory,
        \Magento\Framework\Serialize\Serializer\Json $serializer,
        \Magento\Framework\Stdlib\DateTime\DateTime $date
    ) {
        $this->hasAffectingRules = null;
        $this->catalogRuleCollectionuleFactory = $catalogRuleCollectionuleFactory;
        $this->serializer = $serializer;
        $this->date = $date;
    }

    /**
     * @param \Magento\Framework\App\Http $subject
     * @param null|string $result
     * @return string|null
     */
    public function afterGetVaryString($subject, $result)
    {
        // If any affecting catalog rule is active, we should cache different pages for each customer group
        // so let the default magento configuration run
        if ($this->affectingCatalogRules()) {
            return $result;
        }

        // Make vary independant of customer group or login info
        $data = $subject->getData();
        unset($data['customer_group']);
        unset($data['customer_logged_in']);
        unset($data['tax_rates']);
        if (!empty($data)) {
            ksort($data);
            return sha1($this->serializer->serialize($data));
        }
        return null;
    }

    private function affectingCatalogRules() : bool {

        // Only calculate once
        if  ($this->hasAffectingRules !== null) {
            return $this->hasAffectingRules;
        }

        // Find active catalog rules
        $this->hasAffectingRules = false;
        $collection = $this->catalogRuleCollectionuleFactory->create();
        $collection->addFieldToFilter('is_active', true);
        foreach ($collection as $rule) {
            if (
                (
                    $rule->getToDate() === null ||
                    $rule->getToDate() > $this->date->gmtDate('Y-m-d')
                ) &&
                (
                    !in_array(0, $rule->getCustomerGroupIds())
                )
            ) {
                $this->hasAffectingRules =  true;
            }
        }

        return $this->hasAffectingRules;
    }
}
