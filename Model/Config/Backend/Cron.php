<?php
/**
 * @package     Bydn_ImprovedPageCache
 * @author      Daniel Navarro <https://github.com/danidnm>
 * @license     GPL-3.0-or-later
 * @copyright   Copyright (c) 2025 Daniel Navarro
 */

namespace Bydn\ImprovedPageCache\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Backend model for cron expression validation
 */
class Cron extends Value
{
    /**
     * Validate cron expression before saving
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function beforeSave()
    {
        $value = (string)$this->getValue();
        if ($value) {
            $parts = preg_split('#\s+#', $value, -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) !== 5) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('The cron expression "%1" is invalid. It must contain exactly 5 parts (e.g., * * * * *).', $value)
                );
            }

            foreach ($parts as $part) {
                // Basic validation for cron part characters: numbers, *, /, -, ,
                if (!preg_match('/^[0-9\*\/\-\,]+$/', $part)) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('The cron expression "%1" contains invalid characters in part "%2". Only numbers and *, /, -, , are allowed.', $value, $part)
                    );
                }
            }
        }
        return parent::beforeSave();
    }
}
