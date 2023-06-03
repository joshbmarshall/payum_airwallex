<?php

namespace Cognito\PayumAirwallex;

use Cognito\PayumAirwallex\Action\ConvertPaymentAction;
use Cognito\PayumAirwallex\Action\CaptureAction;
use Cognito\PayumAirwallex\Action\ObtainNonceAction;
use Cognito\PayumAirwallex\Action\StatusAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

class AirwallexGatewayFactory extends GatewayFactory {
    /**
     * {@inheritDoc}
     */
    protected function populateConfig(ArrayObject $config) {
        $config->defaults([
            'payum.factory_name' => 'square',
            'payum.factory_title' => 'square',

            'payum.template.obtain_nonce' => "@PayumAirwallex/Action/obtain_nonce.html.twig",

            'payum.action.capture' => function (ArrayObject $config) {
                return new CaptureAction($config);
            },
            'payum.action.status' => new StatusAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.obtain_nonce' => function (ArrayObject $config) {
                return new ObtainNonceAction($config['payum.template.obtain_nonce'], $config['sandbox'], $config['type'] == 'square_afterpay');
            },
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = array(
                'sandbox' => true,
            );
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [];

            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api((array) $config, $config['payum.http_client'], $config['httplug.message_factory']);
            };
        }
        $config['use_afterpay'] = $config['type'] == 'square_afterpay';
        $payumPaths = $config['payum.paths'];
        $payumPaths['PayumAirwallex'] = __DIR__ . '/Resources/views';
        $config['payum.paths'] = $payumPaths;
    }
}
