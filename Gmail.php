<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Typecho 插件 CommentReminder 附属类
 *
 * @author Wis Chu
 * @link https://wischu.com/
 */

class CommentReminder_Gmail extends CommentReminder_Common
{

    protected $pluginOpt;
    protected $siteOpt;
    protected $tokenPath;
    protected $profilePath;

    public function __construct($plugin_opt)
    {
        $this->pluginOpt = $plugin_opt;
        $this->siteOpt = Typecho_Widget::widget('Widget_Options');
        $this->tokenPath = $this->getTokenPath('gmail_access_token');
        $this->profilePath = $this->getTokenPath('gmail_user_profile');
    }

    /**
     * 检查 Token 文件是否存在
     * *
     * @access public
     * @return boolean
     */
    public function checkTokenFileExists(){
        if($this->tokenPath == false || $this->profilePath == false) return false;
        return true;
    }

    /**
     * 更新 Access Token
     * *
     * @access public
     * @return void
     */
    protected function refreshAccessToken()
    {
        $param = array(
            'refresh_token' => $this->pluginOpt->refreshToken,
            'client_id'     => $this->pluginOpt->clientId,
            'client_secret' => $this->pluginOpt->clientSecret,
            'redirect_uri'  => $this->pluginOpt->redirectUri,
            'grant_type'    => 'refresh_token',
        );

        $feedback = $this->post_request('https://www.googleapis.com/oauth2/v4/token', $param);
        
        $tk_arr = json_decode($feedback, true);

        //~ 检查返回的 access_token 信息合法性
        if(!isset($tk_arr['access_token']) || !isset($tk_arr['expires_in']) || !isset($tk_arr['id_token'])) return false;

        $pf_feedback = @file_get_contents('https://www.googleapis.com/oauth2/v3/tokeninfo?id_token='. $tk_arr['id_token']);
        $pf_arr = json_decode($pf_feedback, true);
        //~ 检查用户 Email 信息合法性
        if(!isset($pf_arr['email']) || $pf_arr['email_verified'] != 'true') return false;

        //~ 写文件
        file_put_contents($this->tokenPath, $feedback);
        file_put_contents($this->profilePath, $pf_feedback);

        return true;
    }

    /**
     * 检查本地 Token 是否存在或过期
     * *
     * @access public
     * @return boolean
     */
    protected function checkAccessToken()
    {
        if($this->checkTokenFileExists() == false) return false;

        // 对 token 文件进行独占锁定
        // 利用文件锁实现队列机制以防止多人同时读写授权信息文件
        $fp = fopen($this->tokenPath, 'r');
        flock($fp, LOCK_EX);

        $tk_str = file_get_contents($this->tokenPath);
        $tk_arr = json_decode($tk_str, true);

        //~ 若 access token 过期则更新授权文件
        if(filemtime($this->tokenPath) + $tk_arr['expires_in'] <= time()){
            return $this->refreshAccessToken();
        }

        // 解除 token 文件锁定
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * 获取本地储存的 Access Token
     * *
     * @access public
     * @return mix [string|boolean]
     */
    protected function getLocalAccessToken()
    {
        $at_content = file_get_contents($this->tokenPath);
        $at_arr = json_decode($at_content, true);

        return isset($at_arr['access_token']) ? $at_arr['access_token'] : false;
    }

    /**
     * 获取本地储存的发件用邮箱地址
     * *
     * @access public
     * @return mix [string|boolean]
     */
    protected function getLocalEmailAddress()
    {
        $up_content = file_get_contents($this->profilePath);
        $up_arr = json_decode($up_content, true);

        return isset($up_arr['email']) ? $up_arr['email'] : false;
    }

    /**
     * 组合邮件 Header 及正文，编码邮件内容并调用发件API
     * *
     * @access public
     * @return mix [string|boolean]
     */
    public function sendMail(array $main_arr, $rawMessage)
    {
        if($this->checkAccessToken() == false) return false; // 文件检查不通过则中断操作

        // 构造邮件头并组合为字符串
        $rawHeader = array(
                'From' => $this->encodeStr($this->siteOpt->title) .' <'. $this->getLocalEmailAddress() .'>',
                'To' => $this->encodeStr($main_arr['to_name']) .' <'. $main_arr['to_email'] .'>',
                'Return-Path' => isset($main_arr['return_email']) ? $this->encodeStr($main_arr['return_name']) .' <'. $main_arr['return_email'] .'>' : NULL,
                'Subject' => $this->encodeStr($main_arr['subject']),
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Transfer-Encoding' => 'base64',
            );
        $rawHeaderStr = '';
        foreach($rawHeader as $rhk => $rhv){
            $rawHeaderStr .= $rhk .': '. $rhv ."\r\n";
        }
        // 组合邮件主体
        $finalMessage = rtrim(strtr(base64_encode($rawHeaderStr ."\r\n". $rawMessage), '+/', '-_'), '=');

        return $this->post_json('https://www.googleapis.com/gmail/v1/users/me/messages/send', array('raw' => $finalMessage));
    }

    /**
     * JSON 接口调用方法
     * *
     * @access public
     * @return mix [string|boolean]
     */
    protected function post_json($remote_server, array $post_array)
    {
        $post_string = json_encode($post_array);
        $context = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-type: application/json; charset=UTF-8'. "\r\n".
                            'Content-length:'. strlen($post_string) ."\r\n".
                            'Authorization: Bearer '. $this->getLocalAccessToken(),
                'content' => $post_string,
            ),
        );
        $stream_context = stream_context_create($context);
        $data = @file_get_contents($remote_server, false, $stream_context);
        return $data;
    }
}
