<?php

namespace Bydn\ImprovedPageCache\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * php -dxdebug.remote_autostart=1 bin/magento vivo:cache:warm --stores 1 --type home
 * php -dxdebug.remote_autostart=1 bin/magento vivo:cache:warm --stores 1 --type cat --ids all
 * php -dxdebug.remote_autostart=1 bin/magento vivo:cache:warm --stores 1 --type prod --ids all
 * php -dxdebug.remote_autostart=1 bin/magento vivo:cache:warm --stores 1 --type url --ids "http://local.fsvivo.com/belleza"
 */

/**
 *
 */
class Warm extends \Symfony\Component\Console\Command\Command
{
    const PARAM_TYPE = 'type';
    const PARAM_STORES = 'stores';
    const PARAM_IDS = 'ids';

    /**
     * @var \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher
     */
    private $warmer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $warmer
     * @param \Psr\Log\LoggerInterface $logger
     * @param string|null $name
     */
    public function __construct(
        \Bydn\ImprovedPageCache\Model\Queue\Warm\Publisher $warmer,
        \Psr\Log\LoggerInterface $logger,
        string $name = null
    )
    {
        $this->warmer = $warmer;
        $this->logger = $logger;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('vivo:cache:warm');
        $this->setDescription('Warms varnish cachee');
        $this->setDefinition([
            new InputOption(
                self::PARAM_STORES,
                null,
                InputOption::VALUE_REQUIRED,
                'Type of operation. Ex: --' . self::PARAM_STORES . '=1,2,3'
            ),
            new InputOption(
                self::PARAM_TYPE,
                null,
                InputOption::VALUE_REQUIRED,
                'Type of operation. Ex: --' . self::PARAM_TYPE . '=cat,prod'
            ),
            new InputOption(
                self::PARAM_IDS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Entity ID or "all". Ex: --' . self::PARAM_IDS . '=1234,all'
            )
        ]);
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->debug('ini');

        // Get parameters and test
        $stores = $input->getOption(self::PARAM_STORES);
        $type = $input->getOption(self::PARAM_TYPE);
        $ids = $input->getOption(self::PARAM_IDS);

        // Send required entities to warm queue
        $this->warmer->sendEntitiesToQueue($stores, $type, $ids);

        $this->logger->debug('end');
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
