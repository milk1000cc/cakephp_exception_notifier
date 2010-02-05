<?php
require_once 'qdmail.php';

class ExceptionNotifierComponent extends Object
{
    public $ERR_TYPE = array(
                             E_ERROR => 'FATAL',
                             E_WARNING => 'WARNING',
                             E_NOTICE => 'NOTICE',
                             E_STRICT => 'STRICT'
                             );

    // Mail configuration
    public $useSmtp = false;
    public $smtpParams = array(
                               'host'=>'smtp.default.com',
                               'port'=>'25',
                               'from'=>'exception.notifier@default.com',
                               'protocol'=>'SMTP',
                               );
    public $exceptionFrom = array('exception.notifier@default.com', 'Exception Notifier');// exception mail from
    public $exceptionRecipients = array();// exception mail to

    // Exception error configuration
    public $observeNotice = true;
    public $observeWarning = true;
    public $observeStrict = false;

    private $_controller;
    private $_exception;

    public function initialize($controller)
    {
        $this->_controller = $controller;
    }

    public function handleShutdown()
    {
        $error = error_get_last();
        switch ($error['type']) {
        case E_ERROR:
        case E_PARSE:
        case E_CORE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_ERROR:
        case E_COMPILE_WARNING:
            $this->handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    }

    public function handleException($e)
    {
        $this->_exception = $e;

        $mail = new Qdmail();

        $mail->smtp($this->useSmtp);
        $mail->smtpServer($this->smtpParams);

        $mail->to($this->exceptionRecipients);
        $mail->subject('['. date('Ymd H:i:s') . '][' . $this->_getSeverityAsString() . '][' . $this->_getUrl() . '] ' . $this->_exception->getMessage());
        $mail->text($this->_getText());
        $mail->from($this->exceptionFrom);
        $mail->send();
    }

    public function handleError($errno, $errstr, $errfile, $errline, $errcontext)
    {
        $cakePath = CAKE_CORE_INCLUDE_PATH . DS . CAKE;
        if (error_reporting() && !preg_match('!^' . $cakePath . '!', $errfile)) {
            $this->handleException(new ErrorException($errstr, 0, $errno, $errfile, $errline));
        }
        return false;
    }

    public function observe($force = false)
    {
        if (!$force && Configure::read('debug') > 0) return;

        // error_reporting(E_ALL) and don't display errors
        if (Configure::read('debug') == 0) {
            error_reporting(E_ALL);
            ini_set('display_errors', 0);
        }

        register_shutdown_function(array($this, 'handleShutdown'));
        set_exception_handler(array($this, 'handleException'));

        $errTypes = 0;
        if ($this->observeNotice) $errTypes = $errTypes | E_NOTICE;
        if ($this->observeWarning) $errTypes = $errTypes | E_WARNING;
        if ($this->observeStrict) $errTypes = $errTypes | E_STRICT;
        if ($errTypes) set_error_handler(array($this, 'handleError'), $errTypes);
    }

    private function _getText()
    {
        $e = $this->_exception;
        $params = method_exists($this->_controller, 'params') ? $this->_controller->params : array();
        $session = isset($_SESSION) ? $_SESSION : array();

        $msg = array(
                     $e->getMessage(),
                     $e->getFile() . '(' . $e->getLine() . ')',
                     '',
                     '-------------------------------',
                     'Request:',
                     '-------------------------------',
                     '',
                     '* URL       : ' . $this->_getUrl(),
                     '* IP address: ' . env('REMOTE_ADDR'),
                     '* Parameters: ' . trim(print_r($params, true)),
                     '* Cake root : ' . APP,
                     '',
                     '-------------------------------',
                     'Environment:',
                     '-------------------------------',
                     '',
                     trim(print_r($_SERVER, true)),
                     '',
                     '-------------------------------',
                     'Session:',
                     '-------------------------------',
                     '',
                     trim(print_r($session, true)),
                     '',
                     '-------------------------------',
                     'Cookie:',
                     '-------------------------------',
                     '',
                     trim(print_r($_COOKIE, true)),
                     '',
                     '-------------------------------',
                     'Backtrace:',
                     '-------------------------------',
                     '',
                     $this->_exception->getTraceAsString()
                     );

        return join("\n", $msg);
    }

    private function _getSeverityAsString()
    {
        if (!method_exists($this->_exception, 'getSeverity')) return 'ERROR';

        $errNo = $this->_exception->getSeverity();
        return array_key_exists($errNo, $this->ERR_TYPE) ? $this->ERR_TYPE[$errNo] : "(errno: {$errNo})";
    }

    private function _getUrl()
    {
        $protocol = array_key_exists('HTTPS', $_SERVER) ? 'https' : 'http';
        return $protocol . '://' . env('HTTP_HOST') . env('REQUEST_URI');
    }
}