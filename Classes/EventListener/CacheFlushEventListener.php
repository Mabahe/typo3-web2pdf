<?php

namespace Mittwald\Web2pdf\EventListener;

use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

class CacheFlushEventListener
{
    public function __invoke(CacheFlushEvent $event): void
    {
        $directory = Environment::getVarPath() . '/web2pdf';
        if (is_link($directory)) {
            $directory = (string)realpath($directory);
        }

        if (is_dir($directory)) {
            $temporaryDirectory = rtrim($directory, '/') . '.' . StringUtility::getUniqueId('remove');
            if (rename($directory, $temporaryDirectory)) {
                GeneralUtility::mkdir($directory);
                clearstatcache();
                GeneralUtility::rmdir($temporaryDirectory, true);
            }
        }
    }
}
