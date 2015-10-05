<?php
/**
 * Created by PhpStorm.
 * User: Lich
 * Date: 15/10/5
 * Time: 20:50
 * 基于ThinkPHP, 参考腾讯微信支付官方example, 轮子上的轮子, 调用微信Api
 */

namespace Home\Controller;
use Org\Util\String;
use Think\Controller;

class ToolsController extends Controller {

    private $appid = '';//公众号/订阅号的appid
    private $appsecret = '';//公众号/订阅号的appsecret

    /*
     * 获取openid/用户资料
     * $param boolean $scope true获取所有用户信息(非订阅号/公众号会弹出授权页面), false仅获取openid(静默)
     */
    public function GetOpenid($scope = false){
        //通过code获得openid
        if (!isset($_GET['code'])){
            //触发微信返回code码
            $qs = $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING']:$_SERVER['QUERY_STRING'];
            $baseUrl = urlencode('http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].$qs);
            $scope = $scope ? 'snsapi_userinfo' : 'snsapi_base';
            $url = $this->__CreateOauthUrlForCode($baseUrl, $scope);
            header("Location: $url");
            exit();
        } else {
            //获取code码，以获取openid
            $code = $_GET['code'];
            $token = $this->getOpenidFromMp($code);
            $userInfo = $this->__GetUserInfo($token);
            return $userInfo;
        }
    }

    //从微信服务器获取access_token存入数据库, 全局缓存, 所有接口都需要用, 有效期7200秒
    public function GetAccessToken(){
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=$this->appid&secret=$this->appsecret";
        $data = $this->__CurlGet($url);
        M('token')->where(['type' => 'access_token'])->save(['token' => $data['access_token'], 'time' => time()]);
        return true;
    }

    /**
     * 从微信服务获取js_ticket凭证存入数据库, 用于自定义微信分享内容, 全局缓存, 所有接口都需要用, 有效期7200秒
     */
    public function GetJSTicket(){
        $access_token = M('ticket')->where(['type' => 'access_token'])->getField('token');
        $url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=$access_token&type=jsapi";
        $data = $this->__CurlGet($url);
        M('token')->where(['type' => 'js_ticket'])->save(['token' => $data['ticket'], 'time' => time()]);
        return true;
    }

    /**
     *
     * 通过code从工作平台获取openid及其access_token
     * @param string $code 微信跳转回来带上的code
     * @return array
     */
    public function GetOpenidFromMp($code){
        $url = $this->__CreateOauthUrlForOpenid($code);
        return $this->__CurlGet($url);
    }

    /**
     * 生成JSSDK签名
     * @retrun array $data JSSDK签名所需参数
     */
    public function JSSDKSignature(){
        $string = new String();
        $jsapi_ticket =  M('token')->where(['type' => 'js_ticket'])->getField('token');
        $data['jsapi_ticket'] = $jsapi_ticket;
        $data['noncestr'] = $string->randString();
        $data['timestamp'] = time();
        $data['url'] = 'http://'.$_SERVER['HTTP_HOST'].__SELF__;//生成当前页面url
        $data['signature'] = sha1($this->ToUrlParams($data));
        return $data;
    }

    /**
     *
     * 构造获取code的url连接
     * @param string $redirectUrl 微信服务器回跳的url，需要url编码
     * @param string $scope 应用授权作用域，snsapi_base （不弹出授权页面，直接跳转，只能获取用户openid），snsapi_userinfo （弹出授权页面，可通过openid拿到昵称、性别、所在地。并且，即使在未关注的情况下，只要用户授权，也能获取其信息）
     * @return 返回构造好的url
     */
    private function __CreateOauthUrlForCode($redirectUrl, $scope){
        $urlObj["appid"] = $this->appid;
        $urlObj["redirect_uri"] = "$redirectUrl";
        $urlObj["response_type"] = "code";
        $urlObj["scope"] = $scope;
        $urlObj["state"] = "STATE"."#wechat_redirect";
        $bizString = $this->ToUrlParams($urlObj);
        return "https://open.weixin.qq.com/connect/oauth2/authorize?".$bizString;
    }

    /**
     *
     * 拼接签名字符串
     * @param array $urlObj
     * @return 返回已经拼接好的字符串
     */
    private function ToUrlParams($urlObj){
        $buff = "";
        foreach ($urlObj as $k => $v) {
            if($k != "signature") {
                $buff .= $k . "=" . $v . "&";
            }
        }
        $buff = trim($buff, "&");
        return $buff;
    }

    /**
     * 构造获取open和access_toke的url地址
     * @param string $code，微信跳转带回的code
     * @return 请求的url
     */
    private function __CreateOauthUrlForOpenid($code){
        $urlObj["appid"] = $this->appid;
        $urlObj["secret"] = $this->appsecret;
        $urlObj["code"] = $code;
        $urlObj["grant_type"] = "authorization_code";
        $bizString = $this->ToUrlParams($urlObj);
        return "https://api.weixin.qq.com/sns/oauth2/access_token?".$bizString;
    }

    /**
     * 获取用户信息
     * @param string $token
     * @return array 用户信息
     */
    private function __GetUserInfo($token){
        $url = "https://api.weixin.qq.com/sns/userinfo?access_token=".$token['access_token']."&openid=".$token['openid']."&lang=zh_CN";
        return $this->__CurlGet($url);
    }

    /**
     * 通过get方式获取数据, 支持https
     * @param string $url 地址
     * @return array|mixed
     */
    private function __CurlGet($url){
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        //运行curl，结果以json形式返回
        $res = curl_exec($ch);
        curl_close($ch);
        //取出openid
        return json_decode($res,true);
    }

    /**
     * 通过post方式获取数据, 支持https, 未测试233
     * @param string $url
     * @param array $data
     * @return array|mixed
     */
    private function __CurlPost($url, $data = array()){
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        //运行curl，结果以json形式返回
        $res = curl_exec($ch);
        curl_close($ch);
        //取出openid
        return json_decode($res, true);
    }
}