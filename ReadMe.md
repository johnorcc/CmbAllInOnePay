### 招商银行一网通H5支付SDK

本sdk包封装了H5招商银行一网通支付的部分接口

使用方法 
    
    /**
     * @var $logger LoggerInterface
     */
    $logger = $this->get('logger');
    $cmb = new  \Cmb\Application([
        'branchNo' => '0218',  //分行号
        'merchantNo' => '015311', //商户号
        'sMerchantKey' => '112222212xx', //密钥
        'pubKey' => 'MIGfMA0GCSqGSIb3n8MmxVE3nfdXzjx6d3v3guygR54i3QAB', //公钥：通过接口获取
        'env' => 'test' //环境 prod 生产环境 test 测试环境
    ], $logger);
    
    $cmb->queryPubKey();
            
sdk内部已实现了签名和验签的方法，且提供了常用的接口

1.  queryPubKey 获取公钥
2.  eUserPayConfig 一网通支付
3.  refundOrder 退款
4.  queryAgree 查询协议
5.  cancelAgree 取消协议 
6.  queryOrder 查询单笔订单
7.  queryOrderByTransactionID 通过银行流水号查询订单
8.  queryRefundOrder 查询单笔退款订单
9.  queryRefundByOrderNo 按照商户订单号查询
10. queryRefundByBankRefundSerialNo 按照银行退款流水号查询
11. queryRefundByBankOrderNoAndMerchantRefundSerialNo 按照银行退款流水号查询
12. downloadBill 下载对账文件