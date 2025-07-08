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

                        if (preg_match('/(.*?)-(\d{2})$/', $transactionId, $matches)) {
                            $orderIncrementId = $matches[1];
                            $this->logger->info(__('Multi-payment webhook detected for order %1.', $orderIncrementId));

                            file_put_contents('/tmp/vindi_multipayment.log', date('Y-m-d H:i:s') . ' - Multi-payment detected: ' . $orderIncrementId . ' - Status: ' . $statusId . PHP_EOL, FILE_APPEND);

                            $order = $this->helperOrder->loadOrder($orderIncrementId);
                            if (!$order || !$order->getId()) {
                                throw new \Exception((string) __('Order %1 not found for multi-payment callback.', $orderIncrementId));
                            }

                            file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Multi-payment status check - Order: ' . $orderIncrementId . ', Status ID: ' . $statusId . ', STATUS_APPROVED: ' . HelperOrder::STATUS_APPROVED . ', STATUS_REFUNDED: ' . HelperOrder::STATUS_REFUNDED . ', STATUS_DENIED: ' . HelperOrder::STATUS_DENIED . PHP_EOL, FILE_APPEND);

                            if ($statusId == HelperOrder::STATUS_APPROVED) {
                                file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Processing approval webhook for multi-payment order ' . $orderIncrementId . PHP_EOL, FILE_APPEND);
                                $this->multiPaymentHandler->processSuccess($order, $transaction);
                            } elseif ($statusId == HelperOrder::STATUS_REFUNDED) {
                                file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Processing refund/cancellation webhook for multi-payment order ' . $orderIncrementId . PHP_EOL, FILE_APPEND);
                                try {
                                    $this->cancellationService->processWebhookCancellation($params);
                                } catch (\Exception $cancelException) {
                                    file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Failed to process cancellation for order ' . $orderIncrementId . ': ' . $cancelException->getMessage() . PHP_EOL, FILE_APPEND);
                                }
                            } elseif ($statusId == HelperOrder::STATUS_DENIED) {
                                file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Processing denial/cancellation webhook for multi-payment order ' . $orderIncrementId . PHP_EOL, FILE_APPEND);
                                try {
                                    $this->cancellationService->processWebhookCancellation($params);
                                } catch (\Exception $cancelException) {
                                    file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Failed to process denial cancellation for order ' . $orderIncrementId . ': ' . $cancelException->getMessage() . PHP_EOL, FILE_APPEND);
                                }
                            } else {
                                file_put_contents('/tmp/vindi_callback_debug.log', date('Y-m-d H:i:s') . ' - Processing failure webhook for multi-payment order ' . $orderIncrementId . '. Status ID: ' . $statusId . PHP_EOL, FILE_APPEND);
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
