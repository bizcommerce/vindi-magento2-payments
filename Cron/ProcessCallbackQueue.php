<?php
declare(strict_types=1);

namespace Vindi\VP\Cron;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\App\ResourceConnection;
use Vindi\VP\Logger\Logger;
use Vindi\VP\Helper\Order as HelperOrder;
use Vindi\VP\Model\Webhook\MultiPaymentHandler;
use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\CancellationService;

class ProcessCallbackQueue
{
    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var HelperOrder
     */
    protected $helperOrder;

    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * @var MultiPaymentHandler
     */
    private $multiPaymentHandler;

    /**
     * @var MultiPaymentQueueService
     */
    private $multiPaymentQueueService;

    /**
     * @var CancellationService
     */
    private $cancellationService;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param Logger $logger
     * @param HelperOrder $helperOrder
     * @param FileDriver $fileDriver
     * @param MultiPaymentHandler $multiPaymentHandler
     * @param MultiPaymentQueueService $multiPaymentQueueService
     * @param CancellationService $cancellationService
     */
    public function __construct(
        ResourceConnection $resource,
        Logger $logger,
        HelperOrder $helperOrder,
        FileDriver $fileDriver,
        MultiPaymentHandler $multiPaymentHandler,
        MultiPaymentQueueService $multiPaymentQueueService,
        CancellationService $cancellationService
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
        $this->helperOrder = $helperOrder;
        $this->fileDriver = $fileDriver;
        $this->multiPaymentHandler = $multiPaymentHandler;
        $this->multiPaymentQueueService = $multiPaymentQueueService;
        $this->cancellationService = $cancellationService;
    }

    /**
     * Execute Cron Job to process callback queue.
     * Processes only ONE callback per execution to avoid performance issues.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->logger->info(__('Starting callback processing - one record per execution.'));

        try {
            $connection = $this->resource->getConnection();
            $tableName = $this->resource->getTableName('vindi_vp_callback');

            // Get only ONE pending callback ordered by creation date (FIFO)
            $select = $connection->select()
                ->from($tableName)
                ->where('queue_status = ?', 'pending')
                ->where('attempts < ?', 3)
                ->order(['created_at ASC', 'entity_id ASC'])
                ->limit(1);

            $callback = $connection->fetchRow($select);
            
            if (!$callback) {
                $this->logger->info(__('No pending callbacks found to process.'));
                return;
            }

            $this->logger->info(__('Processing single callback ID: %1', $callback['entity_id']));
            $this->processSingleCallback($connection, $tableName, $callback);

        } catch (\Exception $e) {
            $this->logger->error(__('Error executing cron job: %1', $e->getMessage()));
        }
    }

    /**
     * Process a single callback record.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $tableName
     * @param array $callback
     * @return void
     */
    private function processSingleCallback($connection, string $tableName, array $callback): void
    {
        $callbackId = $callback['entity_id'];
        $attempts = (int)$callback['attempts'];

        // Update attempts counter
        $connection->update(
            $tableName,
            ['attempts' => $attempts + 1],
            ['entity_id = ?' => $callbackId]
        );

        try {
            $params = json_decode($callback['payload'], true);
            if (!is_array($params)) {
                throw new \Exception((string) __('Invalid JSON payload for callback ID %1', $callbackId));
            }

            if (isset($params['transaction'])) {
                $transaction = $params['transaction'];
                $transactionId = $transaction['order_number'] ?? ($transaction['free'] ?? '');
                $statusId = $transaction['status_id'];

                if (preg_match('/(.*?)-(\d{2})$/', $transactionId, $matches)) {
                    $orderIncrementId = $matches[1];
                    $this->logger->info(__('Multi-payment webhook detected for order %1.', $orderIncrementId));

                    $order = $this->helperOrder->loadOrder($orderIncrementId);
                    if (!$order || !$order->getId()) {
                        throw new \Exception((string) __('Order %1 not found for multi-payment callback.', $orderIncrementId));
                    }

                    if ($statusId == HelperOrder::STATUS_APPROVED) {
                        $this->multiPaymentHandler->processSuccess($order, $transaction);
                    } elseif ($statusId == HelperOrder::STATUS_REFUNDED) {
                        try {
                            $this->cancellationService->processWebhookCancellation($params);
                        } catch (\Exception $cancelException) {
                            $this->logger->error(__('Cancellation error: %1', $cancelException->getMessage()));
                        }
                    } elseif ($statusId == HelperOrder::STATUS_DENIED) {
                        try {
                            $this->cancellationService->processWebhookCancellation($params);
                        } catch (\Exception $cancelException) {
                            $this->logger->error(__('Cancellation error: %1', $cancelException->getMessage()));
                        }
                    } else {
                        $this->multiPaymentHandler->processFailure($order, $transaction);
                    }

                } else {
                    $order = $this->helperOrder->loadOrder($transactionId);
                    if ($order && $order->getId()) {
                        if ($statusId == HelperOrder::STATUS_REFUNDED) {
                            $this->logger->info(__('Processing refund webhook for transaction %1.', $transactionId));
                            try {
                                $this->cancellationService->processWebhookCancellation($params);
                            } catch (\Exception $cancelException) {
                                $this->logger->error(__('Failed to process cancellation for transaction %1: %2', $transactionId, $cancelException->getMessage()));
                            }
                        } else {
                            $this->helperOrder->updateOrder(
                                $order,
                                (string)$statusId,
                                $transaction,
                                (float)($transaction['transaction_total_value'] ?? $order->getGrandTotal()),
                                true
                            );
                        }
                        $this->logger->info(__('Callback ID %1 processed successfully. Order %2 updated.', $callbackId, $transactionId));
                    } else {
                        $this->logger->warning(__('Order %1 not found for callback ID %2.', $transactionId, $callbackId));
                    }
                }
            } else {
                $this->logger->warning(__('Transaction data missing in callback ID %1.', $callbackId));
            }

            // Mark as executed
            $connection->update(
                $tableName,
                ['queue_status' => 'executed'],
                ['entity_id = ?' => $callbackId]
            );
            
            $this->logger->info(__('Callback ID %1 processed successfully.', $callbackId));

        } catch (\Exception $e) {
            $this->logger->error(__('Error processing callback ID %1: %2', $callbackId, $e->getMessage()));

            // Mark as failed if max attempts reached
            if (($attempts + 1) >= 3) {
                $connection->update(
                    $tableName,
                    ['queue_status' => 'failed'],
                    ['entity_id = ?' => $callbackId]
                );
                $this->logger->error(__('Callback ID %1 marked as failed after 3 attempts.', $callbackId));
            }
        }
    }
}
