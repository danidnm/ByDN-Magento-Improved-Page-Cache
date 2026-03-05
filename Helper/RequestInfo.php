<?php

namespace Bydn\ImprovedPageCache\Helper;

use Magento\Framework\App\Helper\Context;

class RequestInfo extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * Current cron job running
     *
     * @var null
     */
    private $currentCronCode = null;

    /**
     * Current command running
     *
     * @var null
     */
    private $currentCommandCode = null;

    /**
     * Indexer currently running
     */
    private $currentIndexerCode = null;

    /**
     * Webapi method processing the request
     */
    private $currentWebapiMethod = null;

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Returns current cron job code
     *
     * @return null
     */
    public function isCronRunning() {
        return ($this->currentCronCode !== null);
    }

    /**
     * Returns current cron job code
     *
     * @return null
     */
    public function getCurrentCronCode() {
        return $this->currentCronCode;
    }

    /**
     * @param $code
     * @return void
     */
    public function setCurrentCronCode($code) {
        $this->currentCronCode = $code;
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function isCommandRunning() {
        return ($this->currentCommandCode !== null);
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function getCurrentCommandCode() {
        return $this->currentCommandCode;
    }

    /**
     * @param $code
     * @return void
     */
    public function setCurrentCommandCode($code) {
        $this->currentCommandCode = $code;
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function isIndexerRunning() {
        return ($this->currentIndexerCode !== null);
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function getCurrentIndexerCode() {
        return $this->currentIndexerCode;
    }

    /**
     * @param $code
     * @return void
     */
    public function setCurrentIndexerCode($code) {
        $this->currentIndexerCode = $code;
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function isWebapiRunning() {
        return ($this->currentWebapiMethod !== null);
    }

    /**
     * Returns current command code
     *
     * @return null
     */
    public function getCurrentWebapiMethod() {
        return $this->currentWebapiMethod;
    }

    /**
     * @param $code
     * @return void
     */
    public function setCurrentWebapiMethod($code) {
        $this->currentWebapiMethod = $code;
    }
}
