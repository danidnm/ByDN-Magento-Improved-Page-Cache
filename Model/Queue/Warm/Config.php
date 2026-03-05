<?php

namespace Bydn\ImprovedPageCache\Model\Queue\Warm;

class Config
{
    const TOPIC_NAME =  'bydn_improvedpagecache';
    const PRIORITY_QUEUE_NAME =  'bydn_improvedpagecache_priority';
    const QUEUE_OP_TYPE_HOME = 'home';
    const QUEUE_OP_TYPE_CATEGORIES = 'cat';
    const QUEUE_OP_TYPE_PRODUCTS = 'prod';
    const QUEUE_OP_TYPE_DIRECT_URL = 'url';
    const QUEUE_ENQUEUE_ALL =  'all';
}
