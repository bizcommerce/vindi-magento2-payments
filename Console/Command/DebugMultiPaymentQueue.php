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

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\MultiPaymentQueue;
use Vindi\VP\Helper\Logger as VindiLogger;

/**
 * Command to debug multi-payment queue items.
 */
class DebugMultiPaymentQueue extends Command
{
    /**
     * @var MultiPaymentQueueService
     */
    private $queueService;

    /**
     * @var VindiLogger
     */
    private $vindiLogger;

    /**
     * Constructor.
     *
     * @param MultiPaymentQueueService $queueService
     * @param VindiLogger $vindiLogger
     */
    public function __construct(
        MultiPaymentQueueService $queueService,
        VindiLogger $vindiLogger
    ) {
        $this->queueService = $queueService;
        $this->vindiLogger = $vindiLogger;
        parent::__construct();
    }

    /**
     * Configure the command options and description.
     */
    protected function configure()
    {
        $this->setName('vindi:debug-multi-payment-queue')
            ->setDescription('Debug multi-payment queue items and their data')
            ->addArgument(
                'queue_id',
                InputArgument::OPTIONAL,
                'Specific queue item ID to debug'
            )
            ->addOption(
                'increment-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Filter by order increment ID'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_OPTIONAL,
                'Filter by status (pending, processing, executed, failed)'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of items to show',
                10
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
        $queueId = $input->getArgument('queue_id');
        $incrementId = $input->getOption('increment-id');
        $status = $input->getOption('status');
        $limit = (int) $input->getOption('limit');

        try {
            if ($queueId) {
                $this->debugSpecificItem($queueId, $output);
            } else {
                $this->debugMultipleItems($incrementId, $status, $limit, $output);
            }

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Error debugging queue: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Debug specific queue item
     *
     * @param string $queueId
     * @param OutputInterface $output
     */
    private function debugSpecificItem(string $queueId, OutputInterface $output): void
    {
        $output->writeln("<info>Debugging queue item ID: {$queueId}</info>");
    }

    /**
     * Debug multiple items with filters
     *
     * @param string|null $incrementId
     * @param string|null $status
     * @param int $limit
     * @param OutputInterface $output
     */
    private function debugMultipleItems(?string $incrementId, ?string $status, int $limit, OutputInterface $output): void
    {
        if ($status === 'pending') {
            $items = $this->queueService->getPendingItems($limit);
        } else {
            $items = $this->queueService->getPendingItems(1000);
        }

        if (empty($items)) {
            $output->writeln('<comment>No queue items found with the specified criteria.</comment>');
            return;
        }

        $output->writeln(sprintf('<info>Found %d queue item(s):</info>', count($items)));
        $output->writeln('');

        foreach ($items as $item) {
            $this->displayItemDetails($item, $output);
            $output->writeln('');
        }
    }

    /**
     * Display detailed information about a queue item
     *
     * @param MultiPaymentQueue $item
     * @param OutputInterface $output
     */
    private function displayItemDetails(MultiPaymentQueue $item, OutputInterface $output): void
    {
        $output->writeln(sprintf('<info>Queue Item ID:</info> %s', $item->getId()));
        $output->writeln(sprintf('<info>Order ID:</info> %s', $item->getOrderId()));
        $output->writeln(sprintf('<info>Increment ID:</info> %s', $item->getIncrementId()));
        $output->writeln(sprintf('<info>Payment Method:</info> %s', $item->getPaymentMethod()));
        $output->writeln(sprintf('<info>Secondary Method:</info> %s', $item->getSecondaryMethodType()));
        $output->writeln(sprintf('<info>Secondary Amount:</info> $%.2f', $item->getSecondaryAmount()));
        $output->writeln(sprintf('<info>Status:</info> %s', $item->getStatus()));
        $output->writeln(sprintf('<info>Attempts:</info> %d/%d', $item->getAttempts(), $item->getMaxAttempts()));
        
        if ($item->getNextAttemptAt()) {
            $output->writeln(sprintf('<info>Next Attempt:</info> %s', $item->getNextAttemptAt()));
        }
        
        if ($item->getErrorMessage()) {
            $output->writeln(sprintf('<error>Error Message:</error> %s', $item->getErrorMessage()));
        }

        $requestData = $item->getRequestData();
        if (!empty($requestData)) {
            $output->writeln('<info>Request Data Keys:</info> ' . implode(', ', array_keys($requestData)));
        }

        $responseData = $item->getResponseData();
        if (!empty($responseData)) {
            $output->writeln('<info>Response Data Keys:</info> ' . implode(', ', array_keys($responseData)));
            
            switch ($item->getSecondaryMethodType()) {
                case MultiPaymentQueue::SECONDARY_METHOD_PIX:
                    $output->writeln(sprintf('<info>PIX Code Present:</info> %s', 
                        !empty($responseData['pix_code']) ? 'Yes' : 'No'));
                    $output->writeln(sprintf('<info>PIX URL Present:</info> %s', 
                        !empty($responseData['pix_url']) ? 'Yes' : 'No'));
                    break;
                    
                case MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX:
                    $output->writeln(sprintf('<info>Bankslip URL Present:</info> %s', 
                        !empty($responseData['bankslip_url']) ? 'Yes' : 'No'));
                    $output->writeln(sprintf('<info>PIX Code Present:</info> %s', 
                        !empty($responseData['pix_code']) ? 'Yes' : 'No'));
                    break;
            }

            $statusId = $responseData['transaction']['status_id'] ?? $responseData['status_id'] ?? 'N/A';
            $output->writeln(sprintf('<info>Response Status ID:</info> %s', $statusId));
        }
    }
}
