<?php

namespace anim210System;

use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use Alipay\EasySDK\Kernel\Config;

class AlipaySDK {

    public function __construct() {
        Factory::setOptions($this->getOptions());
    }

    public function beginMobilePay($productName, $orderId, $price) {

        global $_config;

        $returnMsg = [

            'status' => false,
            'msg' => ''

        ];

        try {
            $quitUrl = $_config['alipay']['quitUrl'];
            $result = Factory::payment()->wap()->pay($productName, $orderId, $price, $quitUrl, '');
            $responseChecker = new ResponseChecker();
            if ($responseChecker->success($result)) {
                $returnMsg['status'] = true;
                $returnMsg['msg'] = [
                    'msg' => '请求成功，正在跳转支付...',
                    'body' => $result->body
                ];
                return $returnMsg;
            } else {
                $returnMsg['status'] = false;
                $returnMsg['msg'] = [
                    'msg' => '请求失败：' . $result->msg . '(' . $result->subMsg . ')',
                    'body' => ''
                ];
                return $returnMsg;
            }
        } catch (Exception $e) {
            $returnMsg['status'] = false;
            $returnMsg['msg'] = [
                'msg' => '请求失败：' . $e->getMessage(),
                'body' => ''
            ];
            return $returnMsg;
        }

    }

    public function beginPCPay($productName, $orderId, $price) {

        global $_config;

        $returnMsg = [

            'status' => false,
            'msg' => ''

        ];

        try {
            $quitUrl = $_config['alipay']['quitUrl'];
            $result = Factory::payment()->page()->pay($productName, $orderId, $price, $quitUrl, '');
            $responseChecker = new ResponseChecker();
            if ($responseChecker->success($result)) {
                $returnMsg['status'] = true;
                $returnMsg['msg'] = [
                    'msg' => '请求成功，正在跳转支付...',
                    'body' => $result->body
                ];
                return $returnMsg;
            } else {
                $returnMsg['status'] = false;
                $returnMsg['msg'] = [
                    'msg' => '请求失败：' . $result->msg . '(' . $result->subMsg . ')',
                    'body' => ''
                ];
                return $returnMsg;
            }
        } catch (Exception $e) {
            $returnMsg['status'] = false;
            $returnMsg['msg'] = [
                'msg' => '请求失败：' . $e->getMessage(),
                'body' => ''
            ];
            return $returnMsg;
        }

    }

    function getOptions() {

        global $_config;

        $options = new Config();
        $options->protocol = 'https';
        $options->gatewayHost = 'openapi.alipay.com';
        $options->signType = 'RSA2';

        $options->appId = $_config['alipay']['appId'];

        $options->merchantPrivateKey = $_config['alipay']['appPrivateKey'];

        $options->alipayPublicKey = $_config['alipay']['appPublicKey'];

        return $options;

    }

}