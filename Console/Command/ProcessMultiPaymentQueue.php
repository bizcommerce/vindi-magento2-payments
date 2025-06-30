<?php
declare(strict_types=1);

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Console\Command;

use Vindi\VP\Cron\ProcessMultiPaymentQueue as ProcessMultiPaymentQueueCron;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;
use Vindi\VP\Model\MultiPaymentQueueService;

/**
 * Command to execute the vindi_vp_process_multi_payment_queue cron job manually.
 */
class ProcessMultiPaymentQueue extends Command
{
    /**
     * @var ProcessMultiPaymentQueueCron
     */
    private $processMultiPaymentQueue;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var MultiPaymentQueueService
     */
    private $queueService;

    /**
     * Constructor.
     *
     * @param ProcessMultiPaymentQueueCron $processMultiPaymentQueue
     * @param LoggerInterface $logger
     * @param MultiPaymentQueueService $queueService
     */
    public function __construct(
        ProcessMultiPaymentQueueCron $processMultiPaymentQueue,
        LoggerInterface $logger,
        MultiPaymentQueueService $queueService
    ) {
        $this->processMultiPaymentQueue = $processMultiPaymentQueue;
        $this->logger = $logger;
        $this->queueService = $queueService;
        parent::__construct();
    }

    /**
     * Configure the command options and description.
     */
    protected function configure()
    {
        $this->setName('vindi:process-multi-payment-queue')
            ->setDescription('Executes the vindi_vp_process_multi_payment_queue cron job manually')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show items that would be processed without actually processing them'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of items to process',
                50
            );
        parent::configure();
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        try {
            // Get pending items
            $pendingItems = $this->queueService->getPendingItems($limit);

            if (empty($pendingItems)) {
                $output->writeln('<comment>No pending multi-payment queue items found.</comment>');
                return Cli::RETURN_SUCCESS;
            }

            $output->writeln(sprintf('<info>Found %d pending queue item(s):</info>', count($pendingItems)));
            $output->writeln('');

            // Display items in table format
            $this->displayQueueItems($pendingItems, $output);

            if ($dryRun) {
                $output->writeln('<comment>Dry run mode - no items were actually processed.</comment>');
                return Cli::RETURN_SUCCESS;
            }

            // Process the queue
            $this->processMultiPaymentQueue->execute();
            $output->writeln('<info>Multi-payment queue processing executed successfully.</info>');
            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $this->logger->error('Error executing multi-payment queue processing: ' . $e->getMessage());
            $output->writeln('<error>Error executing multi-payment queue processing: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Display queue items in a table format
     *
     * @param array $queueItems
     * @param OutputInterface $output
     */
    private function displayQueueItems(array $queueItems, OutputInterface $output): void
    {
        $output->writeln('+---------+---------------+--------+--------+-----------+----------+');
        $output->writeln('| Queue   | Order         | Method | Type   | Amount    | Status   |');
        $output->writeln('| ID      | ID            |        |        |           |          |');
        $output->writeln('+---------+---------------+--------+--------+-----------+----------+');

        foreach ($queueItems as $queueItem) {
            $output->writeln(sprintf(
                '| %-7s | %-13s | %-6s | %-6s | $%-8.2f | %-8s |',
                $queueItem->getId(),
                $queueItem->getIncrementId(),
                substr($queueItem->getPaymentMethod(), -6), // Show last 6 chars
                $queueItem->getSecondaryMethodType(),
                $queueItem->getSecondaryAmount(),
                $queueItem->getStatus()
            ));
        }

        $output->writeln('+---------+---------------+--------+--------+-----------+----------+');
        $output->writeln('');
    }
}
