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
    protected $use_sandbox;

    /**
     * @param string $templateName
     */
    public function __construct(string $templateName, bool $use_sandbox) {
        $this->templateName = $templateName;
        $this->use_sandbox = $use_sandbox;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($request) {
        /** @var ObtainNonce $request */

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
            $model['details'] = $getHttpRequest->request['detail'];
            return;
        }

        // Create Payment Intent

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
            'countryCode' => $model['countryCode'] ?? 'AU',
            'intentId' => $model['intentId'],
            'clientSecret' => $model['clientSecret'],
            'actionUrl' => $getHttpRequest->uri,
            'imgUrl' => $model['img_url'],
            'use_sandbox' => $this->use_sandbox ? 1 : 0,
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
