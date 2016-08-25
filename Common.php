<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Typecho 插件 CommentReminder 附属类
 *
 * @author Wis Chu
 * @link https://wischu.com/
 */

class CommentReminder_Common extends Typecho_Widget
{

    /**
     * 对邮件头字符串进行 base64 编码
     * *
     * @access public
     * @return string
     */
    protected function encodeStr($str){
        return '=?UTF-8?B?' . base64_encode(str_replace(array("\r", "\n"), '', $str)) . '?=';
    }

    /**
     * 获取 token 目录内文件路径
     * *
     * @access public
     * @return mix [string|boolean]
     */
    protected function getTokenPath($prefix)
    {
        $tokenDir = dirname(__FILE__) .'/token';
        $dir = scandir($tokenDir);
        foreach($dir as $val){
            if(stripos($val, $prefix) === 0){
                return $tokenDir .'/'. $val;
            }
        }

        return false;
    }

    /**
     * POST 方法
     * *
     * @access public
     * @return mix [string|boolean]
     */
    protected function post_request($remote_server, array $post_array)
    {
        $post_string = http_build_query($post_array);
        $context = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded; charset=UTF-8'. '\r\n'.
                            'User-Agent: Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/38.0.2125.122 Safari/537.36'. '\r\n'.
                            'Content-length:'. strlen($post_string),
                'content' => $post_string,
            ),
        );
        $stream_context = stream_context_create($context);
        $data = @file_get_contents($remote_server, false, $stream_context);
        return $data;
    }
}
