<?php namespace Wpstudio\Sber\Classes;

use OFFLINE\Mall\Classes\Payments\PaymentProvider;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Models\OrderProduct;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Models\OrderState;
use OFFLINE\Mall\Models\Order;
use Omnipay\Omnipay;
use Omnipay\Sberbank\Gateway;
use Omnipay\Sberbank\Message\AbstractRequest;
use Omnipay\Sberbank\Message\AbstractResponse;
use Omnipay\Sberbank\Message\AuthorizeRequest;
use Omnipay\Sberbank\Message\AuthorizeResponse;
use Throwable;
use Session;
use Lang;


class SberCheckout extends PaymentProvider
{
    /**
     * The order that is being paid.
     *
     * @var Order
     */
    public $order;
    /**
     * Data that is needed for the payment.
     * Card numbers, tokens, etc.
     *
     * @var array
     */
    public $data;

    /**
     * Return the display name of your payment provider.
     *
     * @return string
     */
    final public function name(): string
    {
        return Lang::get('wpstudio.sber::lang.settings.sber_checkout');
    }

    /**
     * Return a unique identifier for this payment provider.
     *
     * @return string
     */
    final public function identifier(): string
    {
        return 'sber';
    }

    /**
     * Validate the given input data for this payment.
     *
     * @return bool
     * @throws \October\Rain\Exception\ValidationException
     */
    final public function validate(): bool
    {
        return true;
    }


    /**
     * Process the payment.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    final public function process(PaymentResult $result): PaymentResult
    {
        $gateway = $this->getGateway();

        try {
            $request = $gateway->authorize([
                'orderNumber' => $this->order->id,
                'amount' => $this->order->total_in_currency,
                'returnUrl' => $this->returnUrl(),
                'failUrl'     => $this->cancelUrl(),
                'description'   => Lang::get('wpstudio.sber::lang.messages.order_number').$this->order->order_number,
            ]);

            assert($request instanceof AuthorizeRequest);

            // hacking private method
            $reflectionMethod = new \ReflectionMethod(AbstractRequest::class, 'setParameter');
            $reflectionMethod->setAccessible(true);
            $reflectionMethod->invoke($request, 'orderBundle', $this->getOrderBundle());

            $response = $request->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        assert($response instanceof AuthorizeResponse);

        Session::put('mall.payment.callback', self::class);

        $this->setOrder($result->order);

        $result->order->payment_transaction_id = $response->getOrderId();

        $result->order->save();

        return $result->redirect($response->getRedirectResponse()->getTargetUrl());
    }

    /**
     * Gerenate sberbank orderBundle
     * @see https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:registerpreauth_cart#orderbundle
     *
     * @return array
     *
     */
    final public function getOrderBundle(): array
    {
        $orderCreationDateFormatted = $this->order->created_at->format('Y-m-dTH:m:s');

        $cartItems = $this->getOrderCartItems();

        if ($cartItems) {
            return [
                'orderCreationDate' => $orderCreationDateFormatted ?? '',
                'cartItems' => $cartItems
            ];
        }

        return [];
    }

    /**
     * Create order cartitems for order bundle
     *
     * @return array
     */
    final public function getOrderCartItems(): array
    {
        $cartItems = [];

        foreach ($this->order->products as $positionId => $product) {
            assert($product instanceof OrderProduct);

            $cartItems['items'] = [
                'positionId' => $positionId,
                'name' => $product->name,
                'quantity' => [
                    'value' => $product->quantity,
                    'measure' => ''
                ],
                'itemCode' => $product->variant_id ?? $product->product_id,
                'itemPrice' => $product->pricePostTaxes()->float,
            ];
        }

        return $cartItems;
    }

    /**
     * Y.K. has processed the payment and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    final public function complete(PaymentResult $result): PaymentResult
    {
        $this->setOrder($result->order);

        $gateway = $this->getGateway();

        try {
            /**
             * It will be similar to calling methods `completeAuthorize()` and `completePurchase()`
             */
            $response = $gateway->orderStatus(
                [
                    'orderId' => $result->order->payment_transaction_id, // gateway order number
                    'language' => 'ru'
                ]
            )->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        assert($response instanceof AbstractResponse);

        $data = (array)$response->getData();

        if ( ! $response->isSuccessful()) {
            return $result->fail($data, $response);
        }

        return $result->success($data, $response);
    }

    /**
     * Build the Omnipay Gateway for PayPal.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    final protected function getGateway(): Gateway
    {
        $gateway = Omnipay::create('Sberbank');

        $gateway->setUserName(PaymentGatewaySettings::get('username'));
        $gateway->setPassword(decrypt(PaymentGatewaySettings::get('password')));

        if (PaymentGatewaySettings::get('sber_test_mode')) {
            $gateway->setTestMode(true);
        }


        return $gateway;
    }

    /**
     * Return any custom backend settings fields.
     *
     * These fields will be rendered in the backend
     * settings page of your provider.
     *
     * @return array
     */
    final public function settings(): array
    {
        return [
            'sber_test_mode'     => [
                'label'   => 'wpstudio.sber::lang.settings.sber_test_mode',
                'comment' => 'wpstudio.sber::lang.settings.sber_test_mode_label',
                'span'    => 'left',
                'type'    => 'switch',
            ],
            'username'     => [
                'label'   => Lang::get('wpstudio.sber::lang.settings.username'),
                'comment' => Lang::get('wpstudio.sber::lang.settings.username_label'),
                'span'    => 'left',
                'type'    => 'text',
            ],
            'password' => [
                'label'   => Lang::get('wpstudio.sber::lang.settings.password'),
                'comment' => Lang::get('wpstudio.sber::lang.settings.password_label'),
                'span'    => 'left',
                'type'    => 'text',
            ],
            'setPayedVirtualOrderAsComplete' => [
                'label'   => Lang::get('wpstudio.sber::lang.settings.set_payed_virtual_order_as_complete'),
                'span'    => 'left',
                'type'    => 'checkbox',
            ],
        ];
    }

    /**
     * Setting keys returned from this method are stored encrypted.
     *
     * Use this to store API tokens and other secret data
     * that is needed for this PaymentProvider to work.
     *
     * @return array
     */
    final public function encryptedSettings(): array
    {
        return ['password'];
    }

    /**
     * Getting order state id by flag
     *
     * @param $orderStateFlag
     * @return int
     */
    final protected function getOrderStateId($orderStateFlag): int
    {
        $orderStateModel = OrderState::where('flag', $orderStateFlag)->first();

        return $orderStateModel->id;
    }
}
