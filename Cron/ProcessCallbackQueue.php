<?php
declare(strict_types=1);

namespace Vindi\VP\Cron;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\App\ResourceConnection;
use Vindi\VP\Logger\Logger;
use Vindi\VP\Helper\Order as HelperOrder;
use Vindi\VP\Model\Webhook\MultiPaymentHandler;
use Vindi\VP\Model\MultiPaymentQueueService;

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
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param Logger $logger
     * @param HelperOrder $helperOrder
     * @param FileDriver $fileDriver
     * @param MultiPaymentHandler $multiPaymentHandler
     * @param MultiPaymentQueueService $multiPaymentQueueService
     */
    public function __construct(
        ResourceConnection $resource,
        Logger $logger,
        HelperOrder $helperOrder,
        FileDriver $fileDriver,
        MultiPaymentHandler $multiPaymentHandler,
        MultiPaymentQueueService $multiPaymentQueueService
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
        $this->helperOrder = $helperOrder;
        $this->fileDriver = $fileDriver;
        $this->multiPaymentHandler = $multiPaymentHandler;
        $this->multiPaymentQueueService = $multiPaymentQueueService;
    }

    /**
     * Execute Cron Job to process callback queue.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->logger->info(__('Starting callback processing without lock mechanism.'));

        try {
            $connection = $this->resource->getConnection();
            $tableName = $this->resource->getTableName('vindi_vp_callback');

            $select = $connection->select()
                ->from($tableName)
                ->where('queue_status = ?', 'pending')
                ->where('attempts < ?', 3);

            $callbacks = $connection->fetchAll($select);
            $this->logger->info(__('Found %1 pending callbacks to process.', count($callbacks)));

            foreach ($callbacks as $callback) {
                $callbackId = $callback['entity_id'];
                $this->logger->info(__('Processing callback ID: %1', $callbackId));
                $attempts = (int)$callback['attempts'];

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

                        // Check if it's a multi-payment webhook
                        if (preg_match('/(.*?)-(\d{2})$/', $transactionId, $matches)) {
                            $orderIncrementId = $matches[1];
                            $this->logger->info(__('Multi-payment webhook detected for order %1.', $orderIncrementId));

                            $order = $this->helperOrder->loadOrder($orderIncrementId);
                            if (!$order || !$order->getId()) {
                                throw new \Exception((string) __('Order %1 not found for multi-payment callback.', $orderIncrementId));
                            }

                            if ($statusId == HelperOrder::STATUS_APPROVED) {
                                $this->multiPaymentHandler->processSuccess($order, $transaction);
                            } else {
                                $this->multiPaymentHandler->processFailure($order, $transaction);
                            }

                        } else {
                            // Standard payment webhook logic
                            $order = $this->helperOrder->loadOrder($transactionId);
                            if ($order && $order->getId()) {
                                $this->helperOrder->updateOrder(
                                    $order,
                                    (string)$statusId,
                                    $transaction,
                                    (float)($transaction['transaction_total_value'] ?? $order->getGrandTotal()),
                                    true
                                );
                                $this->logger->info(__('Callback ID %1 processed successfully. Order %2 updated.', $callbackId, $transactionId));
                            } else {
                                $this->logger->warning(__('Order %1 not found for callback ID %2.', $transactionId, $callbackId));
                            }
                        }
                    } else {
                        $this->logger->warning(__('Transaction data missing in callback ID %1.', $callbackId));
                    }

                    $connection->update(
                        $tableName,
                        ['queue_status' => 'executed'],
                        ['entity_id = ?' => $callbackId]
                    );
                } catch (\Exception $e) {
                    $this->logger->error(__('Error processing callback ID %1: %2', $callbackId, $e->getMessage()));

                    if (($attempts + 1) >= 3) {
                        $connection->update(
                            $tableName,
                            ['queue_status' => 'failed'],
                            ['entity_id = ?' => $callbackId]
                        );
                    }
                }
            }
            $this->logger->info(__('Finished processing callbacks.'));
        } catch (\Exception $e) {
            $this->logger->error(__('Error executing cron job: %1', $e->getMessage()));
        }
    }
}
