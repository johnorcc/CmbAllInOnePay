<?php
/**
 * Created by PhpStorm.
 * User: johnor
 * Date: 2020/8/12
 * Time: 10:05
 */

namespace Cmb;


use Cmb\Exception\InvalidConfigException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Cmb\Http as HttpClient;

class Application
{

    private $config;

    private $httpClient;

    private $env = 'prod'; //prod，test 正式环境，测试环境

    private $version = '1.0'; //接口版本号,固定为“1.0”

    private $charset = 'UTF-8'; // 参数编码,固定为“UTF-8”

    private $signType = 'SHA-256'; //签名算法,固定为“SHA-256”

    private $branchNo; //分行号4位数字

    private $merchantNo; //商户号，6位数字

    private $sMerchantKey; //商户密钥 签名时使用 111Blue1999122xx

    private $pubKey; //通知公钥


    public function __construct(array $config, LoggerInterface $logger)
    {
        Log::setLogger($logger);
        $this->config = $config;

        //分行号
        if (empty($config['branchNo'])) {
            return new InvalidConfigException("缺少分行号branchNo");
        }
        $this->branchNo = $config['branchNo'];

        //商户号
        if (empty($config['merchantNo'])) {
            return new InvalidConfigException("缺少商户号merchantNo");
        }
        $this->merchantNo = $config['merchantNo'];

        //签名密钥
        if (empty($config['sMerchantKey'])) {
            return new InvalidConfigException("缺少签名密钥sMerchantKey");
        }
        $this->sMerchantKey = $config['sMerchantKey'];


        //签名密钥
        if (empty($config['pubKey'])) {
            return new InvalidConfigException("缺少签名密钥sMerchantKey");
        }
        $this->pubKey = $config['pubKey'];

        if (isset($config['env'])) {
            $this->env = $config['env'];
        }

    }


    /**
     * Set GuzzleHttp\Client.
     * @param Http $client
     * @return Application
     */
    public function setHttpClient(HttpClient $client)
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * 表示初始化的不用生成，有需求的时候生产
     * @return Http
     */
    public function getHttpClient()
    {
        if (!($this->httpClient instanceof HttpClient)) {
            $this->httpClient = new HttpClient();
        }

        return $this->httpClient;
    }

    /**
     * Test
     * @param $name
     * @return string
     */
    public function setHello($name)
    {
        return 'hello1' . $name;
    }


    /**
     * 签名
     * @param $reqData
     * @return mixed
     */
    private function sign(array $reqData)
    {
        ksort($reqData);
        // 假设已排序的待签名字符串为strToSign
        $strToSign = '';
        foreach ($reqData as $k => $v) {
            $strToSign .= $k . '=' . $v . '&';
        }
        //拼接支付密钥
        $strToSign .= $this->sMerchantKey;
        //print_r($strToSign);die;
        //SHA-256签名
        $baSrc = mb_convert_encoding($strToSign, "UTF-8");
        return hash('sha256', $baSrc);
    }


    /**
     * 验签
     * @param array $respData
     * @param string $signStr 签名结果
     * @return bool
     */
    public function verifySign(array $respData, string $signStr)
    {
        //待验证签名字符串
        $toSignStr = '';
        ksort($respData);
        foreach ($respData as $k => $v) {
            $toSignStr .= $k . '=' . $v . '&';
        }

        $toSignStr = rtrim($toSignStr,'&');
        //处理证书
        $pem = chunk_split($this->pubKey, 64, "\n");
        $pem = "-----BEGIN PUBLIC KEY-----\n" . $pem . "-----END PUBLIC KEY-----\n";
        $pkid = openssl_pkey_get_public($pem);
        if (empty($pkid)) {
            return false;
        }
        //验证
        $ok = openssl_verify($toSignStr, base64_decode($signStr), $pkid, OPENSSL_ALGO_SHA1);
        return $ok === 1;
    }


    /**
     * 查询招商银行公钥
     * @return bool|mixed
     */
    public function queryPubKey()
    {
        $actionUrl = "https://b2b.cmbchina.com/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        if ($this->env === 'test') {
            $actionUrl = "http://mobiletest.cmburl.cn/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'txCode' => 'FBPK'
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() == 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
            return $e->getMessage();
        }
        return false;
    }


    /**
     * 招商银行一网通支付
     * 生成jsonData
     * 返回签名后的data
     * 和支付的Url
     * @param $params
     *  orderNo 订单号
     *  amount  订单金额，格式：xxxx.xx  0.01
     *  expireTimeSpan 过期时间跨度，必须为大于零的整数，单位为分钟
     *  payNoticeUrl 商户接收成功支付结果通知的地址 异步回调地址
     *  returnUrl    返回商户地址，支付成功页面、支付失败页面上“返回商户”按钮跳转地址 同步返回地址
     *  agrNo 上送协议号  如商户上送协议号，客户再次支付无需进行登录操作,推荐上送
     *  merchantSerialNo 如果是开通协议，必填
     *  userID 商户用户id 选填
     *  signNoticeUrl 成功签约结果通知地址，上送协议号且首次签约，必填。商户接收成功签约结果通知的地址
     * @return array
     */
    public function eUserPayConfig($params)
    {
        $actionUrl = "https://netpay.cmbchina.com/netpayment/BaseHttp.dll?MB_EUserPay";
        if ($this->env === 'test') {
            $actionUrl = "http://121.15.180.66:801/netpayment/BaseHttp.dll?MB_EUserPay";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'date' => $toDay->format('Ymd')
        ];
        $reqData = array_merge($reqData, $params);
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $jsonRequestData = json_encode($jsonRequestData);
        return compact('actionUrl', 'jsonRequestData');
    }

    /**
     * 退款接口
     * @param $orderNo string 原支付订单号
     * @param $date string 支付时订单日期 yyyyMMdd
     * @param $refundSerialNo string 退款流水号
     * @param $amount string 退款金额
     * @return bool|mixed
     */
    public function refundOrder($orderNo,$date, $refundSerialNo, $amount)
    {
        $actionUrl = "https://merchserv.netpay.cmbchina.com/merchserv/BaseHttp.dll?DoRefundV2";
        if ($this->env === 'test') {
            $actionUrl = "http://121.15.180.66:801/netpayment_directlink_nosession/BaseHttp.dll?DoRefundV2";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'date' => $date,
            'orderNo' => $orderNo,
            'refundSerialNo' => $refundSerialNo,
            'amount' => $amount,
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }


    /**
     * 查询协议
     * @param $agrNo string 客户签约的协议号
     * @param $merchantSerialNo string 商户做此查询请求的流水号
     * @return bool|mixed
     */
    public function queryAgree($agrNo, $merchantSerialNo)
    {
        $actionUrl = "https://b2b.cmbchina.com/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        if ($this->env === 'test') {
            $actionUrl = "http://mobiletest.cmburl.cn/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'txCode' => 'CMCX',
            'merchantSerialNo' => $merchantSerialNo,
            'agrNo' => $agrNo,
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }


    /**
     * 取消协议
     * @param $agrNo string 客户签约的协议号
     * @param $merchantSerialNo string 商户做此查询请求的流水号
     * @return bool|mixed
     */
    public function cancelAgree($agrNo, $merchantSerialNo)
    {
        $actionUrl = "https://b2b.cmbchina.com/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        if ($this->env === 'test') {
            $actionUrl = "http://mobiletest.cmburl.cn/CmbBank_B2B/UI/NetPay/DoBusiness.ashx";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'txCode' => 'CMQX',
            'merchantSerialNo' => $merchantSerialNo,
            'agrNo' => $agrNo,
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }


    /**
     * 查询单笔订单
     * @param string $type string 查询类型，A：按银行订单流水号查 B：按商户订单日期和订单号查询；
     * @param $date string 商户订单日期，格式：yyyyMMdd
     * @param string $bankSerialNo 银行订单流水号,type=A时必填
     * @param string $orderNo type=B时必填商户订单号
     * @return bool|mixed
     */
    public function queryOrder($type = 'A', $bankSerialNo = '', $date = '', $orderNo = '')
    {
        $actionUrl = "https://merchserv.netpay.cmbchina.com/merchserv/BaseHttp.dll?QuerySingleOrder";
        if ($this->env === 'test') {
            $actionUrl = "http://121.15.180.66:801/netpayment_directlink_nosession/BaseHttp.dll?QuerySingleOrder";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'date' => $date,
            'type' => $type,
            'bankSerialNo' => $bankSerialNo,
            'orderNo' => $orderNo
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }


    /**
     * 通过银行流水号查询订单
     * @param $transactionID
     * @param $date
     * @return bool|mixed
     */
    public function queryOrderByTransactionID($transactionID,$date)
    {
        return $this->queryOrder('A', $transactionID,$date);
    }

    /**
     * 查询单笔退款订单
     * @param string $type 查询类型
     * A：按银行退款流水号查单笔
     * B：按商户订单号+商户退款流水号查单笔
     * C: 按商户订单号查退款
     * @param string $bankSerialNo 银行退款流水号长度不超过20位
     * @param string $merchantSerialNo 商户退款流水号长度不超过20位  1.商户送商户退款流水号查单笔； 2.商户不送商户退款流水号，查退款订单；
     * @param string $date 商户订单日期，格式：yyyyMMdd
     * @param string $orderNo 商户订单号
     * @return bool|mixed
     */
    public function queryRefundOrder($type = 'A', $bankSerialNo = '', $merchantSerialNo = '', $date = '', $orderNo = '')
    {
        $actionUrl = "https://merchserv.netpay.cmbchina.com/merchserv/BaseHttp.dll?QuerySettledRefundV2";
        if ($this->env === 'test') {
            $actionUrl = "http://121.15.180.66:801/netpayment_directlink_nosession/BaseHttp.dll?QuerySettledRefundV2";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'date' => $date,
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'type' => $type,
            'merchantSerialNo' => $merchantSerialNo,
            'bankSerialNo' => $bankSerialNo,
            'orderNo' => $orderNo
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }

    /**
     * 按照商户订单号查询
     * @param string $orderNo 商户订单号
     * @return bool|mixed
     */
    public function queryRefundByOrderNo($orderNo)
    {
        return $this->queryRefundOrder('C', '', '', '', $orderNo);
    }


    /**
     * 按照银行退款流水号查询
     * @param string $bankSerialNo 银行退款流水号
     * @return bool|mixed
     */
    public function queryRefundByBankRefundSerialNo($bankSerialNo)
    {
        return $this->queryRefundOrder('A', $bankSerialNo, '', '', '');
    }


    /**
     * 按照银行退款流水号查询
     * @param string $orderNo 商户订单号
     * @param string $merchantSerialNo 商户退款流水号长度不超过20位  1.商户送商户退款流水号查单笔； 2.商户不送商户退款流水号，查退款订单；
     * @return bool|mixed
     */
    public function queryRefundByBankOrderNoAndMerchantRefundSerialNo($orderNo, $merchantSerialNo)
    {
        return $this->queryRefundOrder('B', '', $merchantSerialNo, '', $orderNo);
    }


    /**
     * 下载对账文件接口
     * 账单中返回下载地址，需要再次从下载地址下载文件
     * @param $date
     * @return bool
     */
    public function downloadBill($date)
    {

        $actionUrl = "https://merchserv.netpay.cmbchina.com/merchserv/BaseHttp.dll?GetDownloadURL";
        if ($this->env === 'test') {
            $actionUrl = "http://121.15.180.66:801/netpayment_directlink_nosession/BaseHttp.dll?GetDownloadURL";
        }
        $toDay = new \DateTime();
        $reqData = [
            'dateTime' => $toDay->format('YmdHis'),
            'branchNo' => $this->branchNo,
            'merchantNo' => $this->merchantNo,
            'transactType' => '4001',
            'fileType' => 'YWT',
            'messageKey' => '30899919311001620181017',
            'date' => $date
        ];
        $jsonRequestData = [
            'version' => $this->version,
            'charset' => $this->charset,
            'signType' => $this->signType,
            'sign' => $this->sign($reqData),
            'reqData' => $reqData
        ];
        $httpClient = $this->getHttpClient();
        try {
            $resp = $httpClient->request("POST", $actionUrl, [
                'form_params' => [
                    'jsonRequestData' => json_encode($jsonRequestData),
                    'charset' => 'UTF-8'
                ]
            ]);
            if ($resp->getStatusCode() === 200) {
                $jsonBody = strval($resp->getBody());
                $json = json_decode($jsonBody,true);
                return $json;
            }
        } catch (GuzzleException $e) {
        }
        return false;
    }

}