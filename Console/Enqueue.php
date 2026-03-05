<?php

namespace Bydn\ImprovedPageCache\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Bydn\ImprovedPageCache\Model\WarmItem\Types as WarmTypes;
use Bydn\ImprovedPageCache\Model\WarmItem\Priority as WarmPriority;

/*
 * php bin/magento bydn:cache:enqueue --stores 1 --type home
 * php bin/magento bydn:cache:enqueue --stores 1 --type pages --ids all
 * php bin/magento bydn:cache:enqueue --stores 1 --type categories --ids 1,2,3
 * php bin/magento bydn:cache:enqueue --stores 1 --type products --ids all
 * php bin/magento bydn:cache:enqueue --stores 1 --type url --url "http://www.my-magento.com/my-url"
 */
/**
 *
 */
class Enqueue extends \Symfony\Component\Console\Command\Command
{
    const PARAM_TYPE = 'type';
    const PARAM_STORES = 'stores';
    const PARAM_IDS = 'ids';
    const PARAM_URL = 'url';
    const PARAM_PRIORITY = 'priority';

    /**
     * @var \Bydn\ImprovedPageCache\Model\Queue\Publisher
     */
    private $warmer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Bydn\ImprovedPageCache\Model\Queue\Publisher $warmer
     * @param \Psr\Log\LoggerInterface $logger
     * @param string|null $name
     */
    public function __construct(
        \Bydn\ImprovedPageCache\Model\Queue\Publisher $warmer,
        \Psr\Log\LoggerInterface $logger,
        ?string $name = null
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
        $serializedTypes = implode(', ', WarmTypes::getAllTypes());
        $this->setName('bydn:cache:enqueue');
        $this->setDescription('Enqueues Varnish cache for specific types (' . $serializedTypes . ')');
        $this->setDefinition([
            new InputOption(
                self::PARAM_STORES,
                null,
                InputOption::VALUE_REQUIRED,
                'Store IDs comma separated. Ex: --' . self::PARAM_STORES . '=1,2,3'
            ),
            new InputOption(
                self::PARAM_TYPE,
                null,
                InputOption::VALUE_REQUIRED,
                'Type of operation. Allowed: ' . $serializedTypes
            ),
            new InputOption(
                self::PARAM_IDS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Entity IDs comma separated or "all". Ex: --' . self::PARAM_IDS . '=123,all'
            ),
            new InputOption(
                self::PARAM_URL,
                null,
                InputOption::VALUE_OPTIONAL,
                'Specific URL to warm. Required if type is url. Ex: --' . self::PARAM_URL . '="http://example.com"'
            ),
            new InputOption(
                self::PARAM_PRIORITY,
                null,
                InputOption::VALUE_OPTIONAL,
                'Priority level (1-5). Default: 1. Ex: --' . self::PARAM_PRIORITY . '=5',
                WarmPriority::LOWEST
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
        $warmData = null;
        $stores = $input->getOption(self::PARAM_STORES) ?: 'all';
        $type = $input->getOption(self::PARAM_TYPE);
        $ids = $input->getOption(self::PARAM_IDS);
        $url = $input->getOption(self::PARAM_URL);
        $priority = (int) $input->getOption(self::PARAM_PRIORITY);

        // Priority validation
        if ($priority < WarmPriority::LOWEST || $priority > WarmPriority::HIGHEST) {
            throw new \InvalidArgumentException('Priority must be a number between 1 and 5.');
        }

        // Required Type validation
        if (!$type) {
            throw new \InvalidArgumentException('The "--type" option is mandatory.');
        }

        $allowedTypes = WarmTypes::getAllTypes();
        if (!in_array($type, $allowedTypes)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid type "%s". Allowed values: %s', $type, implode(', ', $allowedTypes))
            );
        }

        // Conditional validation
        switch ($type) {
            case 'home':
                if ($ids || $url) {
                    throw new \InvalidArgumentException('Type "home" does not accept "--ids" or "--url" parameters.');
                }
                break;

            case 'pages':
            case 'categories':
            case 'products':
                if (!$ids) {
                    throw new \InvalidArgumentException(sprintf('Type "%s" requires the "--ids" parameter.', $type));
                }
                if ($ids !== 'all') {
                    $idArray = explode(',', $ids);
                    foreach ($idArray as $id) {
                        if (!is_numeric(trim($id))) {
                            throw new \InvalidArgumentException('The "--ids" parameter must be numeric or "all".');
                        }
                    }
                }
                $warmData = $ids;
                break;

            case 'url':
                if (!$url) {
                    throw new \InvalidArgumentException('Type "url" requires the "--url" parameter.');
                }
                $warmData = $url;
                break;
        }

        // Send required entities to warm queue
        $this->warmer->sendEntitiesToQueue($stores, $type, $warmData, $priority);

        $output->writeln(sprintf('Successfully added "%s" to the warming queue.', $type));

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
