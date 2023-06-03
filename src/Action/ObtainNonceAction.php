<?php

namespace Cognito\PayumAirwallex\Action;

use Cognito\PayumAirwallex\Request\Api\ObtainNonce;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\RenderTemplate;

class ObtainNonceAction implements ActionInterface, GatewayAwareInterface {
    use GatewayAwareTrait;


    /**
     * @var string
     */
    protected $templateName;
    protected $use_afterpay;
    protected $use_sandbox;

    /**
     * @param string $templateName
     */
    public function __construct(string $templateName, bool $use_sandbox, bool $use_afterpay) {
        $this->templateName = $templateName;
        $this->use_afterpay = $use_afterpay;
        $this->use_sandbox = $use_sandbox;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($request) {
        /** @var $request ObtainNonce */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if ($model['card']) {
            throw new LogicException('The token has already been set.');
        }
        $uri = \League\Uri\Http::createFromServer($_SERVER);

        $getHttpRequest = new GetHttpRequest();
        $this->gateway->execute($getHttpRequest);
        // Received payment information from Airwallex
        if (isset($getHttpRequest->request['payment_intent'])) {
            $model['nonce'] = $getHttpRequest->request['payment_intent'];
            $model['verificationToken'] = $getHttpRequest->request['verification_token'];
            return;
        }

        $billingContact = [
            'email' => $model['email'] ?? '',
        ];
        $this->gateway->execute($renderTemplate = new RenderTemplate($this->templateName, array(
            'merchant_reference' => $model['merchant_reference'] ?? '',
            'amount' => $model['currencySymbol'] . ' ' . number_format($model['amount'], $model['currencyDigits']),
            'verificationDetails' => json_encode([
                'amount' => number_format($model['amount'], 2, '.', ''),
                'billingContact' => $billingContact,
                'currencyCode' => $model['currency'],
                'intent' => 'CHARGE',
            ]),
            'numeric_amount' => $model['amount'],
            'currencyCode' => $model['currency'],
            'appId' => $model['app_id'],
            'locationId' => $model['location_id'],
            'actionUrl' => $getHttpRequest->uri,
            'imgUrl' => $model['img_url'],
            'use_afterpay' => $this->use_afterpay ? 1 : 0,
            'billing' => $model['billing'] ?? [],
            'shipping' => $model['shipping'] ?? [],
            'country' => $model['country'] ?? 'AU',
            'use_sandbox' => $this->use_sandbox ? 1 : 0,
            'ship_item' => $model['ship_item'] ?? false,
            'pickupContact' => json_encode($model['pickup_contact'] ?? null),
            'afterpay_addresschange_url' => $model['afterpay_addresschange_url'] ?? false,
            'afterpay_shippingchange_url' => $model['afterpay_shippingchange_url'] ?? false,
            'afterpay_shipping_options' => json_encode($model['afterpay_shipping_options'] ?? []),
        )));

        throw new HttpResponse($renderTemplate->getResult());
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request) {
        return
            $request instanceof ObtainNonce &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
