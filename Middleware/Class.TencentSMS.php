<?php
/*

腾讯云短信对接库
Ver 1.0.0.1 20211214
SendSMS.php
Code by Jason

*/
namespace anim210System;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Sms\V20210111\SmsClient;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;

class TencentSMS {
    
    public function sendCaptcha($captcha, $phone)
    {
        global $_config;
        
        try {
        
            $cred = new Credential($_config['tcode']['SecretID'], $_config['tcode']['SecretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, "ap-guangzhou", $clientProfile);
        
            $req = new SendSmsRequest();
            
            $params = array(
                "PhoneNumberSet" => array( $phone ),
                "SmsSdkAppId" => "",
                "SignName" => "",
                "TemplateId" => "",
                "TemplateParamSet" => array( $captcha )
            );
            $req->fromJsonString(json_encode($params));
        
            $resp = $client->SendSms($req);
        
            return($resp->toJsonString());
        }
        catch(TencentCloudSDKException $e) {
            exit ($e);
        }
    }

    public function sendVerifyRejectNotification($msg, $phone)
    {
        global $_config;
        
        try {
        
            $cred = new Credential($_config['tcode']['SecretID'], $_config['tcode']['SecretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, "ap-guangzhou", $clientProfile);
        
            $req = new SendSmsRequest();
            
            $params = array(
                "PhoneNumberSet" => array( $phone ),
                "SmsSdkAppId" => "",
                "SignName" => "",
                "TemplateId" => "",
                "TemplateParamSet" => $msg
            );
            $req->fromJsonString(json_encode($params));
        
            $resp = $client->SendSms($req);
        
            return($resp->toJsonString());
        }
        catch(TencentCloudSDKException $e) {
            exit ($e);
        }
    }

    public function sendVerifySuccessNotification($msg, $phone)
    {
        global $_config;
        
        try {
        
            $cred = new Credential($_config['tcode']['SecretID'], $_config['tcode']['SecretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, "ap-guangzhou", $clientProfile);
        
            $req = new SendSmsRequest();
            
            $params = array(
                "PhoneNumberSet" => array( $phone ),
                "SmsSdkAppId" => "",
                "SignName" => "",
                "TemplateId" => "",
                "TemplateParamSet" => $msg
            );
            $req->fromJsonString(json_encode($params));
        
            $resp = $client->SendSms($req);
        
            return($resp->toJsonString());
        }
        catch(TencentCloudSDKException $e) {
            exit ($e);
        }
    }

    public function sendActiveSuccessNotification($msg, $phone)
    {
        global $_config;
        
        try {
        
            $cred = new Credential($_config['tcode']['SecretID'], $_config['tcode']['SecretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, "ap-guangzhou", $clientProfile);
        
            $req = new SendSmsRequest();
            
            $params = array(
                "PhoneNumberSet" => array( $phone ),
                "SmsSdkAppId" => "",
                "SignName" => "",
                "TemplateId" => "",
                "TemplateParamSet" => $msg
            );
            $req->fromJsonString(json_encode($params));
        
            $resp = $client->SendSms($req);
        
            return($resp->toJsonString());
        }
        catch(TencentCloudSDKException $e) {
            exit ($e);
        }
    }
}
