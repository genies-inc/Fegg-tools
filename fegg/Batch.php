<?php
/**
 * Batchクラス
 * 
 * Bach処理の基本機能を提供するクラス。
 * 
 * 関連ファイル： settings.php
 * 
 * @access public
 * @author Genies, Inc.
 * @version 1.0.0
 */
// システム定数定義
$realpath = realpath(dirname(__FILE__) . '/../');
define('FEGG_CODE_DIR', $realpath . '/code');
define('FEGG_HTML_DIR', $realpath . '/htdocs');
define('FEGG_DIR', $realpath . '/fegg');

// コンフィグファイル読み込み
if (file_exists(FEGG_CODE_DIR . '/config/define.php')) {
    require_once FEGG_CODE_DIR . '/config/define.php';
}

// アプリケーションクラス読み込み
require_once FEGG_DIR . '/Application.php';
$classInstance = new Application();

class Batch
{
    private $_processingDatetime = 0;
    private $_startTime = 0;
    private $_endTime = 0;

    protected $app;

    
    function __construct()
    {
        // アプリケーションクラス
        global $classInstance;
        $this->app = $classInstance;
        
        // 処理時刻
        $this->_processingDatetime = date('Y-m-d H:i:s');
    }


    function getProcessingTime()
    {
        if ($this->_endTime <> 0) {
            
            return $this->_endTime - $this->_startTime;
            
        } else {
            
            return microtime(true) - $this->_startTime;
            
        }
    }
    
    
    function getProcessingDatetime()
    {
        return $this->_processingDatetime;
    }
    
    
    function getStartTime()
    {
        return $this->_startTime;
    }
    
    
    function startTimer()
    {
        $this->_startTime = microtime(true);
        $this->_endTime = 0;
    }
    
    
    function stopTimer()
    {
        $this->_endTime = microtime(true);
    }
}


/**
 * Applicationクラスのインスタンス取得
 */
function &FEGG_getInstance()
{
    global $classInstance;
    $instance = $classInstance->getInstance();
    return $instance;
}