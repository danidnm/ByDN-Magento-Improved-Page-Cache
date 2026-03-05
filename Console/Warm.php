<?php

namespace Bydn\ImprovedPageCache\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Bydn\ImprovedPageCache\Model\Queue\Consumer;

class Warm extends \Symfony\Component\Console\Command\Command
{
    const PARAM_PRIORITY = 'priority';

    /**
     * @var Consumer
     */
    private $consumer;

    /**
     * @param Consumer $consumer
     * @param string|null $name
     */
    public function __construct(
        Consumer $consumer,
        ?string $name = null
    ) {
        $this->consumer = $consumer;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('bydn:cache:warm');
        $this->setDescription('Directly warms Varnish cache by processing items from the queue');
        $this->addOption(
            self::PARAM_PRIORITY,
            'p',
            InputOption::VALUE_OPTIONAL,
            'Process items with priority equal or greater than this value'
        );
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $priority = $input->getOption(self::PARAM_PRIORITY);
        
        $output->writeln('Starting cache warming process...');
        
        if ($priority !== null) {
            $output->writeln(sprintf('Filtering by priority >= %s', $priority));
        }

        try {
            $this->consumer->execute($priority);
            $output->writeln('Cache warming process completed.');
        } catch (\Exception $e) {
            $output->writeln(sprintf('Error during cache warming: %s', $e->getMessage()));
            return \Symfony\Component\Console\Command\Command::FAILURE;
        }

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
