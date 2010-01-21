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

    public $exceptionRecipients = array();
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
        $mail->to($this->exceptionRecipients);
        $mail->subject('[' . $this->_getSeverityAsString() . '] ' . $this->_exception->getMessage());
        $mail->text($this->_getText());
        $mail->from(array('exception.notifier@default.com', 'Exception Notifier'));
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

    public function observe()
    {
        if (Configure::read('debug') > 0) return;

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
                     '* Parameters: ' . trim(print_r($this->_controller->params, true)),
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
                     trim(print_r($_SESSION, true)),
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