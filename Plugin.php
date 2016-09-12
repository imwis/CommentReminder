<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 基于 Google API 的评论邮件提醒插件
 * 
 * @package CommentReminder
 * @author Wis Chu
 * @version 1.0.0
 * @link https://wischu.com/
 */

class CommentReminder_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentReminder_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentReminder_Plugin', 'parse');

        if(self::initFileSystemSecurity() == false) throw new Typecho_Plugin_Exception(_t('插件启用失败，请检查并确保 token 目录可写。')); // 初始化文件系统安全
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        self::cleanTokenDir();

        return _t('插件已被禁用，Google 密钥数据清理完成');
    }
    
    /**
     * 获取插件配置面板
     * *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {

        /** Client ID */
        $clientId = new Typecho_Widget_Helper_Form_Element_Text('clientId', NULL, NULL, _t('Client ID'), _t('请填写 Google APIs 的客户端 ID.'));
        $form->addInput($clientId->addRule('required', _t('Client ID 不能为空')));

        /** Client Secret */
        $clientSecret = new Typecho_Widget_Helper_Form_Element_Text('clientSecret', NULL, NULL, _t('Client Secret'), _t('请填写 Google APIs 的客户端密钥.'));
        $form->addInput($clientSecret->addRule('required', _t('Client Secret 不能为空')));

        /** Redirect URI */
        $redirectUri = new Typecho_Widget_Helper_Form_Element_Text('redirectUri', NULL, NULL, _t('Redirect URI'), _t('请填写 Google API 的授权回调 URI.'));
        $form->addInput($redirectUri->addRule('required', _t('Redirect URI 不能为空')));

        /** Refresh Token */
        $refreshToken = new Typecho_Widget_Helper_Form_Element_Text('refreshToken', NULL, NULL, _t('Refresh Token'), _t('请填写 Google API 的 Refresh Token.'));
        $form->addInput($refreshToken->addRule('required', _t('Refresh Token 不能为空')));

        /** 博主提醒邮件标题 */
        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner', NULL, '《{title}》 一文有新的评论', _t('博主提醒邮件标题'), _t('请填写用于博主接收的提醒邮件标题.'));
        $form->addInput($titleForOwner->addRule('required', _t('博主提醒邮件标题 不能为空')));

        /** 访客提醒邮件标题 */
        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest', NULL, '您在 《{title}》 的评论有新回复！', _t('访客提醒邮件标题'), _t('请填写用于访客接收的提醒邮件标题.'));
        $form->addInput($titleForGuest->addRule('required', _t('访客提醒邮件标题 不能为空')));

        /** 博主提示类型 */
        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '博主提醒类型',_t('设定博主将接收到新评论提醒的评论状态类型.'));
        $form->addInput($status);

        /** 其他设置 */
        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有新评论时通知博主',
                    'to_guest' => '评论被回复时通知评论者',
                    'to_log' => '记录邮件发送日志'),
                array('to_owner','to_guest'), '其他设置', _t('选中该选项插件会在 log/mailer_log.txt 文件中记录发送日志.'));
        $form->addInput($other->multiMode());
    }
    
    /**
     * 个人用户的配置面板
     * *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 插件实现方法
     * *
     * @access public
     * @return void
     */
    public static function parse($comment)
    {
        $opt = Typecho_Widget::widget('Widget_Options')->plugin('CommentReminder');

        // 初始化 Gmail 对象
        $gmail = new CommentReminder_Gmail($opt);
        if($gmail->checkTokenFileExists() == false) return false;

        // 初始化评论信息
        $commentArr = self::buildCommentArray($comment);

        // 获取作者信息
        Typecho_Widget::widget('Widget_Users_Author@temp' . $commentArr['{cid}'], array('uid' => $commentArr['{ownerId}']))->to($user);

        $search = array_keys($commentArr);
        $replace = array_values($commentArr);

        $templateDir = dirname(__FILE__) .'/templates';

        //~ 检查访客邮件规则并发送邮件
        if($commentArr['{status}'] == 'approved' && in_array('to_guest', $opt->other) && $commentArr['{mail_p}'] != $commentArr['{mail}']){
            $guestStr = file_get_contents($templateDir .'/guest.html');
            $guestStr = str_replace($search, $replace, $guestStr);
            $titleForGuest = str_replace($search, $replace, $opt->titleForGuest);

            $gmail->sendMail(array(
                'to_name'      => $commentArr['{author_p}'],
                'to_email'     => $commentArr['{mail_p}'],
                'return_name'  => $user->screenName,
                'return_email' => $user->mail,
                'subject'      => $titleForGuest,
                ), $guestStr);
        }

        //~ 检查博主邮件规则并发送邮件
        if(in_array($commentArr['{status}'], $opt->status) && in_array('to_owner', $opt->other) && $user->mail != $commentArr['{mail}']){
            $ownerStr = file_get_contents($templateDir .'/owner.html');
            $ownerStr = str_replace($search, $replace, $ownerStr);
            $titleForOwner = str_replace($search, $replace, $opt->titleForOwner);

            $gmail->sendMail(array(
                'to_name'     => $user->screenName,
                'to_email'    => $user->mail,
                'subject'     => $titleForOwner,
                ), $ownerStr);
        }
    }

    /**
     * 构造评论信息数组
     * *
     * @access public
     * @return array
     */
    public static function buildCommentArray($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $db = Typecho_Db::get();

        $tmp = array(
            '{siteTitle}' => $options->title,     // 网站标题
            '{timezone}'  => $options->timezone,  // 时区(秒)
            '{cid}'       => $comment->cid,       // 文章id
            '{coid}'      => $comment->coid,      // 评论id
            '{created}'   => $comment->created,   // 评论发表时间戳
            '{author}'    => $comment->author,    // 评论者名
            '{authorId}'  => $comment->authorId,  // 评论者 ID
            '{ownerId}'   => $comment->ownerId,   // 文章作者 ID
            '{mail}'      => $comment->mail,      // 评论者邮箱
            '{ip}'        => $comment->ip,        // 评论者 IP 地址
            '{title}'     => $comment->title,     // 文章标题
            '{text}'      => $comment->text,      // 评论正文
            '{permalink}' => $comment->permalink, // 评论 URL
            '{status}'    => $comment->status,    // 评论状态
            '{parent}'    => $comment->parent,    // 父评论 ID
            '{manage}'    => $options->siteUrl . "admin/manage-comments.php" // 评论管理页 URL
        );

        $parent = $db->fetchRow($db->select('author', 'mail', 'text')
                                                       ->from('table.comments')
                                                       ->where('coid = ?', $comment->parent));

        $Date = new Typecho_Date($tmp['{created}']);
        $tmp['{createdDate}'] = $Date->format('Y/m/d H:i:s');

        // 追加父评论信息及日期
        $tmp['{author_p}'] = $parent['author']; // 父评论作者名
        $tmp['{mail_p}'] = $parent['mail'];     // 父评论作者邮箱
        $tmp['{text_p}'] = $parent['text'];     // 父评论正文

        return $tmp;
    }

    /**
     * 插件激活时初始化文件系统安全
     * *
     * @access public
     * @return void
     */
    public static function initFileSystemSecurity()
    {
        $tokenDir = dirname(__FILE__) .'/token';

        // 检查目录是否可写
        if(is_writeable($tokenDir) == false) return; 

        // 创建随机文件名文件防止被下载
        file_put_contents($tokenDir .'/gmail_access_token_'. Typecho_Common::randString(20) .'.json', '', LOCK_EX);
        file_put_contents($tokenDir .'/gmail_user_profile_'. Typecho_Common::randString(20) .'.json', '', LOCK_EX);
        // 创建 index.html 防止目录结构泄露
        file_put_contents($tokenDir .'/index.html', 'Access Denied!', LOCK_EX);
        // 尝试设置 .htaccess 禁止访问规则
        @file_put_contents($tokenDir .'/.htaccess', 'order allow,deny'."\n".'deny from all', LOCK_EX);

        return true;
    }

    /**
     * 插件禁用时清理 token 目录内文件
     * *
     * @access public
     * @return void
     */
    public static function cleanTokenDir()
    {
        $tokenDir = dirname(__FILE__) .'/token';
        $dir = scandir($tokenDir);
        foreach($dir as $val){
            @unlink($tokenDir .'/'. $val);
        }
    }
}
