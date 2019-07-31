<?php
/**
 * Sessionクラス
 *
 * Database型のSession処理を提供するクラス。
 *
 * 関連ファイル：
 *  DB.php
 *
 * セッションテーブル：
 * CREATE TABLE IF NOT EXISTS `session` (
 *   `session_id` varchar(128) NOT NULL,
 *   `value` text NOT NULL,
 *   `deleted` tinyint(4) NOT NULL DEFAULT '0',
 *   `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *   `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`session_id`),
 *   KEY `index1` (`created_at`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
 *
 * アプリケーションからの利用方法：
 * 下記の２行を追加することで$_SESSION, getSessionなどから扱うセッション情報の参照先がDBになる。
 * $handler = $this->getClass('Session');
 * session_set_save_handler($handler, true);
 *
 * @access public
 * @author Genies, Inc.
 * @version 1.0.0
 */

Class Session implements SessionHandlerInterface
{
    private $_app;
	private $_db;
    private $_table;
    private $_key;
    private $_value;


    /**
     * コンストラクタ
     */
	function __construct()
    {
        // アプリケーションオブジェクト
        $this->_app = FEGG_getInstance();

        // データベース
        $this->_db = $this->_app->getClass('DB');

        // セッション情報テーブル
        $this->_store = 'session';
        $this->_key = 'session_id';
        $this->_value = 'value';
        $this->_datetime = 'created_at';
    }


    /**
     * クローズ
     */
    public function close()
    {
		return true;
    }


    /**
     * セッションID生成
     */
    public function create_sid()
    {
		return hash('sha256', uniqid(dechex(random_int(0, 255))));
    }


    /**
     * セッション破棄
     */
    public function destroy($key)
    {
        $this->_db->where("{$this->_key} = ?", $key)->delete($this->_store);
		return true;
    }


    /**
     * ガベージコレクション
     */
    public function gc($maxlifetime)
    {
        $dateTime = date('Y-m-d H:i:s', strtotime("-{$maxlifetime} seconds", strtotime('now')));
        $this->_db->where("{$this->_datetime} <= ?", $dateTime)->delete($this->_store);

	    return true;
    }


    /**
     * オープン
     */
    public function open($savePath, $sessionName)
    {
		return true;
    }


    /**
     * 読み込み
     */
    public function read($key)
    {
        $session = $this->_db->where("{$this->_key} = ?", $key)->select($this->_store)->one();
		return $session[$this->_value] ?? '';
	}


    /**
     * 書込み
     */
    public function write($key, $value)
    {
        if (!$session = $this->_db->where("{$this->_key} = ?", $key)->select($this->_store)->one()) {

            // keyに該当するデータが無いときは追加
            $this->_db->item("{$this->_key}, {$this->_value}", [$key, $value])->insert($this->_store);

        } else {

            // keyに該当するデータがあるときは上書き
            $this->_db->item("{$this->_key}, {$this->_value}", [$key, $value])->where("{$this->_key} = ?", $key)->update($this->_store);

        }

        return true;
	}
}
