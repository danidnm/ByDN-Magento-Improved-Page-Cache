<?php

namespace Bydn\ImprovedPageCache\Plugin\Magento\CacheInvalidate\Observer;

class FlushAllCacheObserver
{
    /**
     * Flash Varnish cache only if is c:c full_page
     *
     * @param \Magento\CacheInvalidate\Observer\FlushAllCacheObserver $subject
     * @param callable $callable
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function aroundExecute(
        $subject,
        $callable,
        $observer)
    {
        // By default, Magento clears varnish whenever any cache type is cleared. Avoid that behaviour and only clean
        // varnish is full_page is explicitly selected (see Magento\Backend\Console\Command\CacheCleanCommand)
        if ($observer->getData('event')->getData('name') !== 'adminhtml_cache_refresh_type') {
            return;
        }
        $callable($observer);
    }
}
