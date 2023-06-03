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

        $model['img_url'] = $this->config['img_url'] ?? '';

        $obtainNonce = new ObtainNonce($request->getModel());
        $obtainNonce->setModel($model);

        if (!$model->offsetExists('intentId')) {
            // Create Intent
            $intent = $this->doPostRequest('/api/v1/pa/payment_intents/create', [
                'request_id' => $request->getToken()->getHash(),
                'amount' => $model['amount'],
                'currency' => $model['currency'],
                'merchant_order_id' => $model['merchant_order_id'],
                'return_url' => $request->getToken()->getTargetUrl(),
            ]);

            $model['intentId'] = $intent['id'];
            $model['clientSecret'] = $intent['client_secret'];

            $this->gateway->execute($obtainNonce);
        }
        if (!$model->offsetExists('status')) {
            $checkedIntent = $this->retrievePaymentIntent($model['intentId']);

            if ($checkedIntent['status'] != 'REQUIRES_CAPTURE') {
                $model['status'] = 'failed';
                $model['error'] = 'Expected REQUIRES_CAPTURE got ' . $checkedIntent['status'];
            } else {
                $checkedIntent = $this->confirmPaymentIntent($model['intentId'], $request->getToken()->getHash());

                if (array_key_exists('status', $checkedIntent) && $checkedIntent['status'] == 'SUCCEEDED') {
                    $model['status'] = 'success';
                    $model['transactionReference'] = $checkedIntent['id'];
                    $model['result'] = $checkedIntent['latest_payment_attempt'] ?? [];
                } else {
                    $model['status'] = 'failed';
                    $model['error'] = $checkedIntent['message'] ?? ($checkedIntent['status'] . ' ' . ($checkedIntent['failure_code'] ?? ''));
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

    public function retrievePaymentIntent($intentId) {
        return $this->doGetRequest('/api/v1/pa/payment_intents/' . $intentId);
    }

    public function confirmPaymentIntent($intentId, $request_id) {
        return $this->doPostRequest('/api/v1/pa/payment_intents/' . $intentId . '/confirm', [
            'id' => $intentId,
            'request_id' => $request_id,
        ]);
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

    /**
     * Perform GET request to Airwallex servers
     * @param string $url relative path
     * @return array
     */
    public function doGetRequest($url) {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseurl() . $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                'Authorization: Bearer ' . $this->getAuthToken(),
                "Content-Type: application/json"
            ],
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
