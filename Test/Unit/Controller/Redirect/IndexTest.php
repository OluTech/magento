<?php
/**
 * Unit tests for the Index redirect controller
 * Covers what happens to the order when a saved payment method cannot be charged
 */

namespace Fortispay\Fortis\Test\Unit\Controller\Redirect;

use Fortispay\Fortis\Controller\Redirect\Index;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The Order, session, API and repository doubles are shared; each test sets expectations on only some of them
#[AllowMockObjectsWithoutExpectations]
class IndexTest extends TestCase
{
    /**
     * @var Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $order;

    /**
     * @var CheckoutSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $checkoutSession;

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisApi;

    /**
     * @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepository;

    /**
     * @var ResultInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cart;

    /**
     * @var PaymentTokenInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $token;

    protected function setUp(): void
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $payment->setData('additional_information', ['fortis-vault-method' => str_repeat('a', 32)]);

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId', 'getPayment', 'getTotalDue', 'canCancel', 'cancel',
                'addCommentToStatusHistory'])
            ->getMock();
        $this->order->method('getId')->willReturn(42);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getTotalDue')->willReturn(25.0);
        $this->order->setData('customer_id', 7);
        $this->order->setData('subtotal', 25.0);

        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLastRealOrder', 'restoreQuote'])
            ->getMock();
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->order);

        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->cart = $this->createStub(ResultInterface::class);
        $this->token = $this->createStub(PaymentTokenInterface::class);
        $this->token->method('getGatewayToken')->willReturn('deleted-token');
    }

    /**
     * Build the controller with the token type the test needs.
     *
     * @param string $tokenType
     * @return Index
     */
    private function buildController(string $tokenType): Index
    {
        $this->token->method('getTokenDetails')->willReturn(json_encode(['type' => $tokenType]));

        $tokens = $this->createStub(PaymentTokenManagementInterface::class);
        $tokens->method('getByPublicHash')->willReturn($this->token);

        $config = $this->createStub(Config::class);
        $config->method('isCurrencySupported')->willReturn(true);
        $config->method('orderAction')->willReturn('sale');

        $checkoutProcessor = $this->createStub(CheckoutProcessor::class);
        $checkoutProcessor->method('getCheckoutCurrency')->willReturn('USD');
        $checkoutProcessor->method('getRedirectToCartObject')->willReturn($this->cart);

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($this->createStub(Redirect::class));

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://shop.test/fortis/redirect/success');

        return new Index(
            $this->createStub(PageFactory::class),
            $this->checkoutSession,
            $this->createStub(LoggerInterface::class),
            $urlBuilder,
            $config,
            $resultFactory,
            $this->createStub(ManagerInterface::class),
            $tokens,
            $this->fortisApi,
            $checkoutProcessor,
            $this->createStub(FortisMethodService::class),
            $this->orderRepository
        );
    }

    /**
     * A saved card that Fortis no longer knows cancels the order that was placed for it.
     */
    public function testDeletedSavedCardCancelsTheOrderAndReturnsToTheCart(): void
    {
        $this->fortisApi->method('doTokenisedTransaction')
            ->willThrowException(new LocalizedException(__('Token not found')));
        $this->order->method('canCancel')->willReturn(true);
        $this->order->expects($this->once())->method('cancel');
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->orderRepository->expects($this->once())->method('save')->with($this->order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->assertSame($this->cart, $this->buildController('credit_card')->execute());
    }

    /**
     * A saved bank account that is refused cancels the order the same way.
     */
    public function testRefusedSavedBankAccountCancelsTheOrderAndReturnsToTheCart(): void
    {
        $this->fortisApi->method('doAchTokenisedTransaction')
            ->willThrowException(new LocalizedException(__('Token not found')));
        $this->order->method('canCancel')->willReturn(true);
        $this->order->expects($this->once())->method('cancel');
        $this->orderRepository->expects($this->once())->method('save')->with($this->order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->assertSame($this->cart, $this->buildController('ach')->execute());
    }

    /**
     * An error that is not a Magento exception is handled the same way.
     */
    public function testUnexpectedErrorAlsoCancelsTheOrder(): void
    {
        $this->fortisApi->method('doTokenisedTransaction')->willThrowException(new \RuntimeException('boom'));
        $this->order->method('canCancel')->willReturn(true);
        $this->order->expects($this->once())->method('cancel');
        $this->orderRepository->expects($this->once())->method('save');

        $this->assertSame($this->cart, $this->buildController('credit_card')->execute());
    }

    /**
     * An order that Magento will not cancel is left alone, but the customer still gets their cart back.
     */
    public function testOrderThatCannotBeCancelledIsLeftAlone(): void
    {
        $this->fortisApi->method('doTokenisedTransaction')
            ->willThrowException(new LocalizedException(__('Token not found')));
        $this->order->method('canCancel')->willReturn(false);
        $this->order->expects($this->never())->method('cancel');
        $this->orderRepository->expects($this->never())->method('save');
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->assertSame($this->cart, $this->buildController('credit_card')->execute());
    }

    /**
     * A saved card that Fortis accepts goes on to the success page and the order is not touched.
     */
    public function testAcceptedSavedCardDoesNotCancelTheOrder(): void
    {
        $this->fortisApi->method('doTokenisedTransaction')
            ->willReturn(json_encode(['data' => ['id' => 'txn-1']]));
        $this->order->expects($this->never())->method('cancel');
        $this->orderRepository->expects($this->never())->method('save');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $result = $this->buildController('credit_card')->execute();

        $this->assertNotSame($this->cart, $result);
    }
}
