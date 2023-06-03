<?php

namespace Cognito\PayumAirwallex\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Cognito\PayumAirwallex\Request\Api\ObtainNonce;

class CaptureAction implements ActionInterface, GatewayAwareInterface {
    use GatewayAwareTrait;

    private $config;

    /**
     * @param string $templateName
     */
    public function __construct(ArrayObject $config) {
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     *
     * @param Capture $request
     */
    public function execute($request) {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        if ($model['status']) {
            return;
        }

        $model['client_id'] = $this->config['client_id'];
        $model['api_key'] = $this->config['api_key'];
        $model['img_url'] = $this->config['img_url'] ?? '';

        $obtainNonce = new ObtainNonce($request->getModel());
        $obtainNonce->setModel($model);

        // Create Intent
        $intent = $this->doPostRequest('/api/v1/pa/payment_intents/create', [
            'request_id' => uniqid(),
            'amount' => $model['amount'],
            'currency' => $model['currency'],
            'merchant_order_id' => $model['merchant_order_id'],
            'return_url' => $request->getToken()->getTargetUrl(),
        ]);

        $model['intentId'] = $intent['id'];
        $model['clientSecret'] = $intent['client_secret'];

        $this->gateway->execute($obtainNonce);
        if (!$model->offsetExists('status')) {
            $model['status'] = 'success';
            $model['transactionReference'] = 'test';
            $model['result'] = 'result';

            $client = new \Square\SquareClient([
                'accessToken' => $this->config['access_token'],
                'environment' => $this->config['sandbox'] ? \Square\Environment::SANDBOX : \Square\Environment::PRODUCTION,
            ]);

            $amount_money = new \Square\Models\Money();
            $amount_money->setAmount($model['amount'] * 100);
            $amount_money->setCurrency($model['currency']);

            $body = new \Square\Models\CreatePaymentRequest(
                $model['nonce'],
                $request->getToken()->getHash(),
            );
            $body->setAmountMoney($amount_money);

            $line_item = $model['square_item_name'] ?? false;

            if ($line_item) {
                // Add Order
                $order = new \Square\Models\Order($model['location_id']);
                $order_line_item = new \Square\Models\OrderLineItem('1');
                $order_line_item->setName($line_item);
                $order_line_item->setBasePriceMoney($amount_money);
                $order->setLineItems([$order_line_item]);

                $orderbody = new \Square\Models\CreateOrderRequest();
                $orderbody->setOrder($order);
                $orderbody->setIdempotencyKey(uniqid());
                $order_api_response = $client->getOrdersApi()->createOrder($orderbody);

                if ($order_api_response->isSuccess()) {
                    $result = $order_api_response->getResult();
                    $order_id = $result->getOrder()->getId();
                } else {
                    $order_id = false;
                    $errors = $order_api_response->getErrors();
                    $model['status'] = 'failed';
                    $model['error'] = 'failed';
                    foreach ($errors as $error) {
                        $model['error'] = $error->getDetail();
                    }
                }

                if ($order_id) {
                    $body->setOrderId($order_id);
                }
            }

            $body->setAutocomplete(true);
            $body->setVerificationToken($model['verificationToken']);
            $body->setCustomerId($model['customer_id'] ?? null);
            $body->setLocationId($model['location_id']);
            $body->setReferenceId($model['reference_id'] ?? null);
            $body->setNote($model['description']);

            $api_response = $client->getPaymentsApi()->createPayment($body);

            if ($api_response->isSuccess()) {
                $result = $api_response->getResult();
                $model['status'] = 'success';
                $model['transactionReference'] = $result->getPayment()->getId();
                $model['result'] = $result->getPayment();
            } else {
                $errors = $api_response->getErrors();
                $model['status'] = 'failed';
                $model['error'] = 'failed';
                foreach ($errors as $error) {
                    $model['error'] = $error->getDetail();
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request) {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess;
    }

    /**
     * Get the site to use
     * @return string
     */
    public function baseurl() {
        if ($this->config['sandbox']) {
            return 'https://pci-api.airwallex.com'; // TODO remove
            return 'https://pci-api-demo.airwallex.com';
        } else {
            return 'https://pci-api.airwallex.com';
        }
    }

    /**
     * Get authentication token for bearer string
     * @return string
     */
    public function getAuthToken() {
        static $token = '';

        if (!$token) {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->baseurl() . '/api/v1/authentication/login',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => [
                    "Accept: application/json",
                    'x-client-id: ' . $this->config['client_id'],
                    'x-api-key: ' . $this->config['api_key'],
                    "Content-Type: application/json"
                ],
            ]);
            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if ($err) {
                throw new \Exception($err);
            }
            $responseData = json_decode($response, true);
            $token = $responseData['token'];
        }
        return $token;
    }

    /**
     * Perform POST request to Airwallex servers
     * @param string $url relative path
     * @param string $data json encoded data
     * @return array
     */
    public function doPostRequest($url, $data) {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseurl() . $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                'Authorization: Bearer ' . $this->getAuthToken(),
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
        ]);
        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new \Exception($err);
        }
        return json_decode($response, true);
    }
}
