<?php

namespace Bydn\ImprovedPageCache\Plugin\Amasty\Shopby\Helper;

class UrlBuilder
{
    /**
     * @var \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher
     */
    private $cacheWarmer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    public function __construct(
        \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $cacheWarmer,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->cacheWarmer = $cacheWarmer;
        $this->logger = $logger;
    }

    public function afterbuildUrl(\Amasty\Shopby\Helper\UrlBuilder $subject, $result, $filter, $optionValue)
    {
        return $result;
    }
}