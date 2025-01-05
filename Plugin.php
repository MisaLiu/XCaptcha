<?php

/**
 * 设置评论验证码
 *
 * @package XCaptcha
 * @author CairBin
 * @version 1.1.1
 * @link https://cairbin.top
 */

include 'lib/class.geetestlib.php';
include_once 'includes/XCaptcha_Config.php';
include_once 'includes/XCaptcha_Validator.php';
include_once 'includes/XCaptcha_Render.php';

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class XCaptcha_Plugin implements Typecho_Plugin_Interface
{
    /**
     * Activate the plugin
     */
    public static function activate()
    {
		// comments hook
        Typecho_Plugin::factory('Widget_Feedback')->comment 	= [__CLASS__, 'filter'];
		Typecho_Plugin::factory('Widget_Feedback')->trackback 	= [__CLASS__, 'filter'];
        Typecho_Plugin::factory('Widget_XmlRpc')->pingback 		= [__CLASS__, 'filter'];

		// Login page hook
        Typecho_Plugin::factory('admin/footer.php')->end        = [__CLASS__,  'renderLoginCaptcha'];
        Typecho_Plugin::factory('Widget_User')->loginSucceed    = [__CLASS__, 'verifyLoginCaptcha'];
		Typecho_Plugin::factory('XCaptcha')->responseGeetest    = [__CLASS__, 'responseGeetest'];
        
        Helper::addAction('xcaptcha', 'XCaptcha_Action');
    }

    /**
     * Deactivate the plugin
     */
    public static function deactivate() 
	{
		Helper::removeAction('xcaptcha');
	}
    /**
     * Config panel
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        XCaptcha_Config::config($form);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        XCaptcha_Config::personalConfig($form);
    }

    /**
     * 验证登陆验证码
     */    
    public static function verifyLoginCaptcha()
    {
        $config = new XCaptcha_Config();
        if(!$config->isCaptchaEnabledOnPage('login')) return;
        if(!$config->checkKeys()){
            Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys.'), 'error');
            return;
        }

        if($config->getCaptchaChoosen() == 'geetest'){
            if(!XCaptcha_Validator::verifyGeetest($config)){
                Typecho_Widget::widget('Widget_Notice')->set(_t('Captcha verification failed.'), 'error');
                Typecho_Widget::widget('Widget_User')->logout();
                Typecho_Widget::widget('Widget_Options')->response->goBack();
                return;
            }
            return;
        }

        if (!XCaptcha_Validator::verifyOtherCaptcha($config)) {
            Typecho_Widget::widget('Widget_Notice')->set(_t('Captcha verification failed.'), 'error');
            Typecho_Widget::widget('Widget_User')->logout();
            Typecho_Widget::widget('Widget_Options')->response->goBack();
            return;
        }
    }


    /**
     * 过滤评论
     * @param {*} $comment
     * @return {*}
     */    
    public static function filter($comment)
    {
        $config = new XCaptcha_Config();
        // 没启动评论区验证码则直接通过
        if(!$config->isCaptchaEnabledOnPage('comments')) return $comment;
        // 未填写密钥则通过
		if(!$config->checkKeys()){
			Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys.'), 'error');
            return $comment;
        }
        // 是否开启登录用户不校验 且 用户处于登录状态 且 为administrator，都符合则不校验
        $user = Typecho_Widget::widget('Widget_User');
        if($config->isAuthorUncheck() && $user->hasLogin() && $user->pass('administrator', true))
            return $comment;

        // 如果是Geetest v3
        if($config->getCaptchaChoosen() == 'geetest'){
            if(!XCaptcha_Validator::verifyGeetest($config)){
                throw new Typecho_Widget_Exception(_t('Geetest:Invalid verification code.'));
                return;
            }
            return $comment;
        }

        // 其他验证码
		if (!XCaptcha_Validator::verifyOtherCaptcha($config)) {
			throw new Typecho_Widget_Exception(_t('Invalid verification code.'));
            return;
        }
        return $comment;
    }

    /**
     * 评论区渲染验证码
     */    
    public static function showCaptcha()
    {
        $config = new XCaptcha_Config();
        XCaptcha_Render::renderCommentPage($config);
    }

    public static function renderLoginCaptcha()
    {
        $config = new XCaptcha_Config();
        XCaptcha_Render::renderLoginPage($config);
    }


    /**
     * 给Action使用
     */    
    public static function responseGeetest()
	{
        $config = new XCaptcha_Config();
		@session_start();
		$geetestSdk = new GeetestLib(
            $config->getCaptchaId(), 
            $config->getSecretKey(), 
        );

		$widgetRequest = Typecho_Widget::widget('Widget_Options')->request;
		$agent = $widgetRequest->getAgent();

		$data = [
			'user_id' => rand(1000, 9999),
			'client_type' => XCaptcha_Utils::isMobile($agent) ? 'h5' : 'web',
			'ip_address'=> $widgetRequest->getIp()
		];

		$_SESSION['gt_server_ok'] = $geetestSdk->pre_process($data, 1);
        $_SESSION['gt_user_id'] = $data['user_id'];

        echo $geetestSdk->get_response_str();
	}
}