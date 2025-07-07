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

namespace Vindi\VP\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Magento\Sales\Model\Order;
use Vindi\VP\Model\CancellationService;
use Vindi\VP\Model\CancellationResult;
use Vindi\VP\Model\Exception\CancellationException;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Helper\Data;
use Vindi\VP\Helper\Order as HelperOrder;
use Vindi\VP\Helper\Logger;
use Vindi\VP\Model\MultiPaymentQueueService;

class CancellationServiceTest extends TestCase
{
    /**
     * @var CancellationService
     */
    private $cancellationService;

    /**
     * @var Api|MockObject
     */
    private $apiMock;

    /**
     * @var Data|MockObject
     */
    private $helperDataMock;

    /**
     * @var HelperOrder|MockObject
     */
    private $helperOrderMock;

    /**
     * @var Logger|MockObject
     */
    private $loggerMock;

    /**
     * @var MultiPaymentQueueService|MockObject
     */
    private $multiPaymentQueueServiceMock;

    /**
     * @var Order|MockObject
     */
    private $orderMock;

    protected function setUp(): void
    {
        $this->apiMock = $this->createMock(Api::class);
        $this->helperDataMock = $this->createMock(Data::class);
        $this->helperOrderMock = $this->createMock(HelperOrder::class);
        $this->loggerMock = $this->createMock(Logger::class);
        $this->multiPaymentQueueServiceMock = $this->createMock(MultiPaymentQueueService::class);
        $this->orderMock = $this->createMock(Order::class);

        $this->cancellationService = new CancellationService(
            $this->apiMock,
            $this->helperDataMock,
            $this->helperOrderMock,
            $this->loggerMock,
            $this->multiPaymentQueueServiceMock
        );
    }

    public function testProcessWebhookCancellationWithMissingTransactionId(): void
    {
        $webhookData = [
            'transaction' => []
        ];

        $this->expectException(CancellationException::class);
        $this->expectExceptionMessage('Missing transaction ID in webhook data');

        $this->cancellationService->processWebhookCancellation($webhookData);
    }

    public function testProcessWebhookCancellationWithValidSinglePayment(): void
    {
        $webhookData = [
            'transaction' => [
                'order_number' => 'ORDER123',
                'status_id' => HelperOrder::STATUS_REFUNDED,
                'id' => 'TXN123'
            ]
        ];

        $this->helperOrderMock->expects($this->once())
            ->method('loadOrder')
            ->with('ORDER123')
            ->willReturn($this->orderMock);

        $this->orderMock->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $this->apiMock->expects($this->once())
            ->method('getCancel')
            ->willReturn($this->createMock(\Vindi\VP\Gateway\Http\Client\Api\Cancel::class));

        $result = $this->cancellationService->processWebhookCancellation($webhookData);

        $this->assertInstanceOf(CancellationResult::class, $result);
    }

    public function testCancelTransactionWithValidData(): void
    {
        $transactionId = 'TXN123';
        $refundAmount = null;

        $cancelClientMock = $this->createMock(\Vindi\VP\Gateway\Http\Client\Api\Cancel::class);
        $this->apiMock->expects($this->once())
            ->method('getCancel')
            ->willReturn($cancelClientMock);

        $cancelClientMock->expects($this->once())
            ->method('cancel')
            ->with($transactionId, $refundAmount)
            ->willReturn(['status' => 'success', 'message' => 'Transaction cancelled']);

        $result = $this->cancellationService->cancelTransaction($transactionId, $refundAmount);

        $this->assertInstanceOf(CancellationResult::class, $result);
        $this->assertEquals(CancellationService::CANCELLATION_SUCCESS, $result->getStatus());
    }

    public function testCancelTransactionWithApiFailure(): void
    {
        $transactionId = 'TXN123';

        $cancelClientMock = $this->createMock(\Vindi\VP\Gateway\Http\Client\Api\Cancel::class);
        $this->apiMock->expects($this->once())
            ->method('getCancel')
            ->willReturn($cancelClientMock);

        $cancelClientMock->expects($this->once())
            ->method('cancel')
            ->with($transactionId, null)
            ->willThrowException(new \Exception('API Error'));

        $this->expectException(CancellationException::class);
        $this->expectExceptionMessage('Failed to cancel transaction TXN123: API Error');

        $this->cancellationService->cancelTransaction($transactionId);
    }
}
