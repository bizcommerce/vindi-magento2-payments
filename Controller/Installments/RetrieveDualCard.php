<?php

/**
 * Controller para recuperar parcelas no contexto de pagamento com dois cartões
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Controller\Installments;

use Vindi\VP\Helper\Data as HelperData;
use Vindi\VP\Helper\Installments;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Psr\Log\LoggerInterface;

class RetrieveDualCard extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var HelperData */
    protected $helperData;

    /** @var Json */
    protected $json;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /** @var Session */
    protected $checkoutSession;

    /** @var Installments */
    private $helperInstallments;

    /** @var SessionManagerInterface */
    protected $session;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param Context $context
     * @param Json $json
     * @param Session $checkoutSession
     * @param SessionManagerInterface $session
     * @param JsonFactory $resultJsonFactory
     * @param Installments $helperInstallments
     * @param HelperData $helperData
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Json $json,
        Session $checkoutSession,
        SessionManagerInterface $session,
        JsonFactory $resultJsonFactory,
        Installments $helperInstallments,
        HelperData $helperData,
        LoggerInterface $logger
    ) {
        $this->json = $json;
        $this->checkoutSession = $checkoutSession;
        $this->session = $session;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helperData = $helperData;
        $this->helperInstallments = $helperInstallments;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode(401);

        try {
            $content = $this->getRequest()->getContent();
            $bodyParams = ($content) ? $this->json->unserialize($content) : [];

            $this->logger->info('[DUAL_CARD] Requisição recebida:', ['body' => $bodyParams]);

            // Validar estrutura da requisição
            if (!$this->validateRequestStructure($bodyParams)) {
                $this->logger->error('[DUAL_CARD] Estrutura da requisição inválida');
                $result->setHttpResponseCode(400);
                $result->setJsonData($this->json->serialize(['error' => 'Invalid request structure']));
                return $result;
            }

            $installmentsData = $this->getInstallmentsForDualCard($bodyParams);

            $this->logger->info('[DUAL_CARD] Parcelas calculadas:', ['installments' => $installmentsData]);

            $result->setJsonData($this->json->serialize($installmentsData));
            $result->setHttpResponseCode(200);

        } catch (\Exception $e) {
            $this->logger->error('[DUAL_CARD] Erro no controller:', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $result->setHttpResponseCode(500);
            $result->setJsonData($this->json->serialize(['error' => 'Internal server error']));
        }

        return $result;
    }

    /**
     * Valida a estrutura da requisição
     *
     * @param array $bodyParams
     * @return bool
     */
    private function validateRequestStructure(array $bodyParams): bool
    {
        // Verificar se é contexto de dois cartões
        if (empty($bodyParams['payment_context']) || $bodyParams['payment_context'] !== 'dual_card') {
            return false;
        }

        // Verificar se tem array de cartões
        if (empty($bodyParams['cards']) || !is_array($bodyParams['cards'])) {
            return false;
        }

        // Verificar se tem valor total
        if (!isset($bodyParams['total_order_value']) || !is_numeric($bodyParams['total_order_value'])) {
            return false;
        }

        // Validar cada cartão
        foreach ($bodyParams['cards'] as $card) {
            // Só exigir valor e índice do cartão - tipo não é obrigatório
            if (empty($card['amount']) || !is_numeric($card['amount'])) {
                return false;
            }

            if (!isset($card['card_index']) || !in_array($card['card_index'], [1, 2])) {
                return false;
            }

            // Se cc_type não estiver presente, usar valor padrão
            if (empty($card['cc_type'])) {
                $bodyParams['cards'][array_search($card, $bodyParams['cards'])]['cc_type'] = 'VI';
            }
        }

        return true;
    }

    /**
     * Obtém as parcelas para pagamento com dois cartões
     *
     * @param array $bodyParams
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getInstallmentsForDualCard(array $bodyParams): array
    {
        $cards = $bodyParams['cards'];
        $totalOrderValue = $bodyParams['total_order_value'];
        $storeId = $this->checkoutSession->getQuote()->getStoreId();

        $installmentsData = [
            'context' => 'dual_card',
            'total_order_value' => $totalOrderValue,
            'cards' => []
        ];

        foreach ($cards as $cardData) {
            $cardIndex = $cardData['card_index'];
            $ccType = $cardData['cc_type'];
            $amount = $cardData['amount'];
            $context = $cardData['context'] ?? "card_{$cardIndex}";

            $this->logger->info("[DUAL_CARD] Processando cartão {$cardIndex}:", [
                'cc_type' => $ccType,
                'amount' => $amount,
                'context' => $context
            ]);

            // Salvar informações na sessão para cada cartão
            $this->session->setData("vindi_cc_type_card_{$cardIndex}", $ccType);
            $this->session->setData("vindi_amount_card_{$cardIndex}", $amount);

            // Obter parcelas específicas para este cartão
            $installments = $this->helperInstallments->getAllInstallments($amount, $ccType, $storeId);

            $installmentsData['cards'][] = [
                'card_index' => $cardIndex,
                'cc_type' => $ccType,
                'amount' => $amount,
                'context' => $context,
                'installments' => $installments,
                'installments_count' => count($installments)
            ];

            $this->logger->info("[DUAL_CARD] Cartão {$cardIndex} processado:", [
                'installments_count' => count($installments)
            ]);
        }

        // Salvar contexto geral na sessão
        $this->session->setData('vindi_payment_context', 'dual_card');
        $this->session->setData('vindi_dual_card_data', $installmentsData);

        return $installmentsData;
    }

    /**
     * Criar exceção de validação CSRF
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(403);
        return new InvalidRequestException($result);
    }

    /**
     * Validar CSRF (desabilitado para este endpoint)
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
