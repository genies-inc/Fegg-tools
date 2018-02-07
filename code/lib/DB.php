<?php
/**
 * DBクラス
 *
 * Databaseの操作に必要な処理を提供するクラス。
 *
 * 関連ファイル：
 *  db_config.php
 *  db_regular_query.php
 *
 * @access public
 * @author Genies, Inc.
 * @version 1.4.2
 */

class DB
{
    private $_app;

    private $_connect;
    private $_connectFlag;
    private $_query;
    private $_parameter;
    private $_record;
    private $_returnCode;
    private $_affectedRows;
    private $_lastInsertId;

    private $_items;
    private $_table;
    private $_where;
    private $_whereValues;
    private $_group;
    private $_order;
    private $_limit;
    private $_regularUseQueryFlag;
    private $_regularUseQueryFlagForTable;


    /**
     *  constructor
     */
    function __construct()
    {
        // アプリケーションオブジェクト
        $this->_app = FEGG_getInstance();

        // コンフィグ取得
        $this->_app->loadConfig('db_config');
        $this->_app->loadConfig('db_regular_query');

        // 初期化
        $this->_initQuery();
    }


    /**
     * クエリー構築
     * @param string $queryType
     */
    function _buildQuery($queryType) {

        $this->_query = '';
        $this->_parameter = array();

        // 常用クエリーの設定
        if ($this->_regularUseQueryFlag) {
            $this->_setRegularUseQuery($queryType);
        }

        $queryType = strtoupper($queryType);
        $query = '';
        switch ($queryType) {
            case 'COUNT';
                $query .= 'Select Count(*) as number_of_records ';
                $query .= ' From `' . $this->_table . '` ';
                $query .= isset($this->_where) ? 'Where ' . $this->_where : '';
                $query .= isset($this->_group) ? ' Group By ' . $this->_group : '';
                $this->_query = $query;
                $this->_parameter = $this->_whereValues;
                break;

            case 'SELECT':
                $query .= 'Select ';
                $tempQuery = '';
                if (is_array($this->_items)) {
                    foreach($this->_items as $key => $value) {
                        if ($tempQuery) { $tempQuery .= ", "; }
                        $tempQuery .= $key;
                    }
                    $query .= $tempQuery;
                } else {
                    $query .= '*';
                }

                $query .= ' From `' . $this->_table . '` ';
                $query .= isset($this->_where) ? 'Where ' . $this->_where : '';
                $query .= isset($this->_group) ? ' Group By ' . $this->_group : '';
                $query .= isset($this->_order) ? ' Order By ' . $this->_order : '';
                $query .= isset($this->_limit) ? ' Limit ' . $this->_limit : '';

                $this->_query = $query;
                $this->_parameter = $this->_whereValues;
                break;

            case 'INSERT':
            case 'REPLACE':
                $query .= $queryType . ' Into `' . $this->_table . '` ';
                $tempQuery1 = '';
                $tempQuery2 = '';
                foreach($this->_items as $key => $value) {
                    if (preg_match('/([^=]+)\s*=\s*([\w\(\)\s\+]+)/i', $key, $match)) {

                        // 代入形式
                        switch (true) {
                            case (preg_match('/^now/i', $match[2])):
                                if ($tempQuery1) { $tempQuery1 .= ", "; }
                                $tempQuery1 .= "`" . trim($match[1]) . "`";
                                if ($tempQuery2) { $tempQuery2 .= ", "; }
                                $tempQuery2 .= '?';
                                $this->_parameter[] = $this->_app->getDatetime();
                                break;

                            default:
                                if ($tempQuery1) { $tempQuery1 .= ", "; }
                                $tempQuery1 .= "`" . trim($match[1]) . "`";
                                if ($tempQuery2) { $tempQuery2 .= ", "; }
                                $tempQuery2 .= '?';
                                $this->_parameter[] = $match[2];
                                break;
                        }

                    } else {

                        // 項目名のみ
                        if ($tempQuery1) { $tempQuery1 .= ", "; }
                        $tempQuery1 .= "`" . trim($key) . "`";

                        if ($tempQuery2) { $tempQuery2 .= ", "; }
                        $tempQuery2 .= '?';

                        $this->_parameter[] = $value;
                    }
                }

                $query .= '(' . $tempQuery1 . ') Values (' . $tempQuery2 . ')';

                $this->_query = $query;
                break;

            case 'UPDATE':
                $query .= 'Update `' . $this->_table . '` Set ';
                $tempQuery1 = '';
                foreach($this->_items as $key => $value) {
                    if (preg_match('/([^=]+)\s*=\s*([\w\(\)\+0-9\s\']+)/i', $key, $match)) {

                        // 代入形式
                        if ($tempQuery1) { $tempQuery1 .= ", "; }

                        switch ($match[2]) {
                            case 'now()':
                                $tempQuery1 .= $match[1] . '= ?';
                                $this->_parameter[] = $this->_app->getDatetime();
                                break;

                            default:
                                $tempQuery1 .= $match[0];
                                break;
                        }

                    } else {

                        // 項目名のみ
                        if ($tempQuery1) { $tempQuery1 .= ", "; }
                        $tempQuery1 .= $key . '= ?';

                        $this->_parameter[] = $value;
                    }
                }
                $query .= $tempQuery1 . ' ';

                $query .= $this->_where ? 'Where ' . $this->_where : '';
                if ($this->_whereValues) {
                    foreach ($this->_whereValues as $key => $value) {
                        $this->_parameter[] = $value;
                    }
                }

                $this->_query = $query;
                break;

            case 'DELETE':

                $query = 'Delete ';
                $query .= 'From ' . $this->_table . ' ';
                $query .= $this->_where ? 'Where ' . $this->_where : '';
                foreach ($this->_whereValues as $key => $value) {
                    $this->_parameter[] = $value;
                }

                $this->_query = $query;
                break;

            case 'TRUNCATE':

                $query = 'Truncate ' . $this->_table . ' ';

                $this->_query = $query;
                break;
        }

        $returnArray = array();
        $returnArray[0] = $this->_query;
        $returnArray[1] = $this->_parameter;

        return $returnArray;
    }


    /**
     * DBサーバーとの接続切断
     */
    function _close()
    {
        if ($this->_connect) {
            $this->_connect = null;
        }
    }


    /**
     * DBサーバーへの接続確立
     * @param string $dsn データソース名
     * @param string $user ユーザー
     * @param string $password パスワード
     */
    function _connect($dsn, $user, $password)
    {
        // 接続
        try {
            if (defined('PDO::MYSQL_ATTR_READ_DEFAULT_FILE')) {
                $options = array(
                    PDO::MYSQL_ATTR_READ_DEFAULT_FILE  => '/etc/my.cnf',
                );
                $this->_connect = new PDO($dsn, $user, $password, $options);
            } else {
                $this->_connect = new PDO($dsn, $user, $password);
            }

            // 例外をスロー
            $this->_connect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 静的プレースホルダを指定
            $this->_connect->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        } catch(PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
            exit;
        }
    }


    /**
     * エラー処理
     * @param string $query 実行したクエリー
     */
    private function _error($query, $parameter = array())
    {
        if (FEGG_DEVELOPER) {
            if (!empty($this->_connect)) {
                $error = $this->_connect->errorInfo();
                echo "[Error] " . $error[2] . "<br/>\n";
            }
            echo "[Query] " . $query . "<br/>\n";
            if (!empty($parameter)) {
                echo "[Parameters] \n";
                var_dump($parameter);
            }
        }
        $this->_close();

        throw new Exception($this->_connect->errorInfo());
    }


    /**
     * クエリ実行
     * @param string $query SQL文（パラメーター部分は?で表記）
     * @param array $parameter パラメーター配列（SQL中の?の順序に合わせる）
     * @return boolean 正常時: True 異常時: False
     */
    function _executeQuery($query, $parameter)
    {
        // 結果格納変数の初期化
        $this->_affectedRows = 0;

        // クエリー実行
        try {
            $pdoStatement = $this->_connect->prepare($query);

            if ($pdoStatement) {
                if (!is_array($parameter)) {
                    $result = $pdoStatement->execute();
                } else {
                    $result = $pdoStatement->execute($parameter);
                }
                $this->_lastInsertId = $this->_connect->lastInsertId();
            } else {
                echo "No PDOStatemant. (_executeQuery: 1).<br />\n";
                $this->_error($query, $parameter);
            }

        } catch(PDOException $e) {
            echo $e->getMessage( ) . "<br />\n";
            echo "PDOException. (_executeQuery: 2).<br />\n";
            $this->_error($query, $parameter);
        }

        if ($result) {

            // 結果行数の格納
            $this->_affectedRows = $pdoStatement->rowCount();

        } else {
            echo "No Result. (_executeQuery: 3).<br />\n";
            $this->_error($query);
         }

        return $this->_affectedRows;
    }


    /**
     * データ取得
     * @param string $query SQL文（パラメーター部分は?で表記）
     * @param array $parameter パラメーター配列（SQL中の?の順序に合わせる）
     * @return array 結果を配列で返す。項目名による連想配列。
     */
    function _fetchAll($query, $parameter)
    {
        // 結果格納変数の初期化
        $this->_affectedRows = 0;
        $record = array();

        // クエリー実行
        try {
            $pdoStatement = $this->_connect->prepare($query);

            if ($pdoStatement) {
                if (!is_array($parameter)) {
                    $result = $pdoStatement->execute();
                } else {
                    $result = $pdoStatement->execute($parameter);
                }
            } else {
                echo 'No PDOException. Checkpoint 1.';
                $this->_error($query);
            }

        } catch(PDOException $e) {
            $this->_error($query);
        }

        if ($result) {

            // 結果行数の格納
            $this->_affectedRows = $pdoStatement->rowCount();

            // 取得行数文繰り返し$recordに格納
            $record = $pdoStatement->fetchAll(PDO::FETCH_ASSOC);

        } else { $this->_error($query); }

        // メモリを解放
        $pdoStatement->closeCursor();

        return $record;
    }


    /**
     * 初期化
     */
    function _initQuery()
    {
        // クエリー用変数
        $this->_items = null;
        $this->_table = null;
        $this->_where = null;
        $this->_whereValues = null;
        $this->_group = null;
        $this->_order = null;
        $this->_limit = null;

        // 接続フラグ
        $this->_connectFlag = false;

        // 常用クエリーフラグ
        $this->_regularUseQueryFlag = true;
        $this->_regularUseQueryFlagForTable = true;
    }


    /**
     * 連想配列判定
     * @param array 判定対象の配列
     * @return boolean true: 連想配列 false: 配列
     */
    function _isHash($array)
    {
        // 連想配列の先頭キーに0は使えず、配列の先頭は0という前提
        reset($array);
        list($key) = each($array);

        return $key !== 0;
    }


    /**
     * 常用クエリーの設定
     * @param string $queryType
     */
    function _setRegularUseQuery($queryType)
    {
        // テーブルに応じて付加するクエリー
        if ($this->_regularUseQueryFlagForTable) {
            // 項目
            if (isset($this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['item']) && $this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['item']) {
                $item = explode(",", $this->_app->config['db_regular_query']['regular_use'][$queryType]['item']);
                foreach ($item as $value) {
                    $this->item(trim($value));
                }
            }

            // 条件
            if (isset($this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['where']) && $this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['where']) {
                $conjunction = '';
                if ($this->_where) {
                    $conjunction = ' And ';
                }
                $this->where($conjunction . $this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['where']);
            }

            // 並び順
            if (isset($this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['order']) && $this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['order']) {
                $conjunction = '';
                if ($this->_order) {
                    $conjunction = ' ,';
                }
                $this->order($conjunction . $this->_app->config['db_regular_query']['table'][$this->_table][$queryType]['order']);
            }
        }

        // テーブルに関わらず付加するクエリー
        if ($this->_regularUseQueryFlag) {

            // 項目
            if (isset($this->_app->config['db_regular_query']['regular_use'][$queryType]['item']) && $this->_app->config['db_regular_query']['regular_use'][$queryType]['item']) {
                $this->item($this->_app->config['db_regular_query']['regular_use'][$queryType]['item']);
            }

            // 条件
            if (isset($this->_app->config['db_regular_query']['regular_use'][$queryType]['where']) && $this->_app->config['db_regular_query']['regular_use'][$queryType]['where']) {
                $conjunction = '';
                if ($this->_where) {
                    $conjunction = ' And ';
                }
                $this->where($conjunction . $this->_app->config['db_regular_query']['regular_use'][$queryType]['where']);
            }

            // 並び順
            if (isset($this->_app->config['db_regular_query']['regular_use'][$queryType]['order']) && $this->_app->config['db_regular_query']['regular_use'][$queryType]['order']) {
                $conjunction = '';
                if ($this->_order) {
                    $conjunction = ' ,';
                }
                $this->order($conjunction . $this->_app->config['db_regular_query']['regular_use'][$queryType]['order']);
            }
        }
    }


    /**
     * 取得したレコードを返す
     * @param string $index 配列のキーにする項目ID
     * @return array
     */
    function all($index = '')
    {
        $record = '';
        if ($index) {
            if ($this->_record) {
                $record = array_column($this->_record, null, $index);
            }
        } else {
            $record = $this->_record;
        }

        return $record;
    }


    /**
     * トランザクションコミット
     */
    function commit()
    {
        if (!$this->_connect->inTransaction()) {
            throw new Exception('called rollback but not in-transaction');
        }
        $this->_connect->commit();
    }


    /**
     * データ件数カウント
     * @param string $table 指定時：各メソッドで指定された値でquery構築、省略時：queryメソッドによるquery設定
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function count($table)
    {
        // データベースが明示的に指定されていなければ Slave へ接続
        if (!$this->_connectFlag) {
            $this->slaveServer();
        }

        // テーブル名が指定されているときはメソッドで指定された値でquery構築
        $this->_table = $table;
        $this->_buildQuery('count');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_record = $this->_fetchAll($this->_query, $this->_parameter);

        return $this;
    }


    /**
     * データ削除
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function delete($table = '')
    {
        // データベースが明示的に指定されていなければ Slave へ接続
        if (!$this->_connectFlag) {
            $this->masterServer();
        }

        // query構築
        $this->_table = $table;
        $this->_buildQuery('delete');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * Like検索キーワードのエスケープ
     * Like検索で[%][_]などを含む文字を部分一致で検索できるように置換する
     * @param  String $keyword 置換前検索文字列
     * @param  String $front 前方一致パラメータ
     * @param  String $back 後方一致パラメータ
     * @param  String $escapeLetter エスケープ文字
     * @return String 置換後検索文字列
     */
    function escapeLikeKey($keyword, $front = '', $back = '')
    {
        $replacedKeyword = preg_replace('/(?=[!_%])/', '!', $keyword);
        $replacedKeyword = $front . $replacedKeyword . $back;

        return $replacedKeyword;
    }


    /**
     * クエリー実行
     * @param chainFlag True:メソッドチェーンに対応するための変換をする false:そのままQueryを実行
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function execute()
    {
        // クエリ種類の判定
        if (preg_match('/^\s*select.+/i', $this->_query)) {

            // データベースが明示的に指定されていなければ Slave へ接続
            if (!$this->_connectFlag) {
                $this->slaveServer();
            }

            // クエリーを実行して、論理的に非接続状態にする
            $this->_record = $this->_fetchAll($this->_query, $this->_parameter);

        } else {

            // データベースが明示的に指定されていなければ Master へ接続
            if (!$this->_connectFlag) {
                $this->masterServer();
            }

            // クエリーを実行して、論理的に非接続状態にする
            $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);

        }
        $this->_initQuery();

        return $this;
    }


    /**
     * 取得行数、結果行数の取得
     * @return integer 結果行数
     */
    function getAffectedRow()
    {
        $this->_affectedRows;
    }


    /**
     * 直近で登録されたオートナンバーの取得
     * @return Integer 取得できなかったときは0を返す
     */
    function getLastIndexId()
    {
        return $this->_lastInsertId;
    }


    /**
     * 最後に実行したクエリーの取得
     */
    function getLastQuery()
    {
        $query = str_replace('?', '%s', $this->_query);
        $query = vsprintf($query, $this->_parameter);

        return $query;
    }


    /**
     * リターンコード取得
     * @return int
     */
    function getReturnCode()
    {
        return $this->_returnCode;
    }


    /**
     * グループ設定
     * @param string $query
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function group($query)
    {
        $this->_group .= $query;

        return $this;
    }


    /**
     * 指定した項目だけの配列を取得
     * @param string $index
     * @return array
     */
    function id($index)
    {
        $ids = '';
        if ($this->_record) {
            $ids = array_column($this->_record, $index, $index);
        }

        return $ids;
    }


    /**
     * データ追加
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function insert($table)
    {
        // データベースが明示的に指定されていなければ Master へ接続
        if (!$this->_connectFlag) {
            $this->masterServer();
        }

        // query構築
        $this->_table = $table;
        $this->_buildQuery('insert');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * 操作項目設定
     *
     * Select, Updateなどの対象項目を設定するためのメソッドで３通りの指定が可能
     * １：文字列のみでの指定
     *     item('item1, item2')
     *     item('item1 = 1, item2 = 2')
     *
     * ２：文字列とパラメーターによる指定
     *     item('item1, item2', array('item1' => 1, 'item2' => 2))
     *     item('item1, item2', array(1, 2))
     *
     * @param mixed $query 複数項目の場合カンマ区切り、連想配列にも対応（パラメーターは使用されない）
     * @param mixed $parameter 連想配列の場合は$queryで指定した項目名と一致するもの、配列の場合は左から順に値を使用
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
    */
    function item($query, $parameter = '')
    {
        if ($parameter) {
            if ($this->_isHash($parameter)) {

                // パラメーターが連想配列の場合は要素名で一致させる
                $items = explode(',', $query);
                foreach ($items as $value) {
                    $value = trim($value);
                    if (isset($parameter[$value])) {
                        $this->_items[$value] = $parameter[$value];
                    } else {
                        $this->_items[$value] = '';
                    }
                }

            } else {

                // パラメーターが配列の場合は順番に一致させる
                $items = explode(',', $query);
                foreach ($items as $key => $value) {
                    $value = trim($value);
                    if (isset($parameter[$key])) {
                        $this->_items[$value] = $parameter[$key];
                    } else {
                        $this->_items[$value] = '';
                    }
                }

            }
        } else {

            // パラメーター省略時は項目名のみ処理
            $items = explode(',', $query);
            foreach ($items as $value) {
                $value = trim($value);
                $this->_items[$value] = '';
            }
        }

        return $this;
    }


    /**
     * 取得件数設定
     * @param int $limit
     * @param int $offset
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function limit($limit, $offset = 0)
    {
        $this->_limit = $offset . ',' . $limit;

        return $this;
    }


    /**
     * マスターデータベースへの接続
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function masterServer()
    {
        // トランザクションが開始されている場合は処理しない
        if (!$this->_connect || !$this->_connect->inTransaction()) {

            // 接続
            $this->_connect($this->_app->config['db_config']['master']['dsn'],
                            $this->_app->config['db_config']['master']['username'],
                            $this->_app->config['db_config']['master']['password']
            );
            $this->_connectFlag = true;
        }

        return $this;
    }


    /**
     * 取得したレコードの１件目を返す
     * @param string $item 指定されている場合はその値のみ返す
     * @return mixed $item省略時：レコードの配列 $item指定時：値
     */
    function one($item = '')
    {
        $record = array();
        if (is_array($this->_record)) {
            $record = $this->_record;
            $record = array_shift($record);
        }
        if ($item) {
            $record = isset($record[$item]) ? $record[$item] : '';
        }

        return $record;
    }


    /**
     * ソート順設定
     * @param string $query
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function order($query)
    {
        $this->_order .= $query;

        return $this;
    }


    /**
     * クエリー設定
     * @param string $query
     * @param array $parameter
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function query($query, $parameter = array())
    {
        $this->_query = $query;
        $this->_parameter = $parameter;

        return $this;
    }


    /**
     * データリプレース
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function replace($table)
    {
        // データベースが明示的に指定されていなければ Master へ接続
        if (!$this->_connectFlag) {
            $this->masterServer();
        }

        // query構築
        $this->_table = $table;
        $this->_buildQuery('replace');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * トランザクションロールバック
     */
    function rollback()
    {
        if (!$this->_connect->inTransaction()) {
            throw new Exception('called rollback but not in-transaction');
        }
        $this->_connect->rollback();
    }


    /**
     * データ取得
     * @param string $table 指定時：各メソッドで指定された値でquery構築、省略時：queryメソッドによるquery設定
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function select($table)
    {
        // データベースが明示的に指定されていなければ Slave へ接続
        if (!$this->_connectFlag) {
            $this->slaveServer();
        }

        // テーブル名が指定されているときはメソッドで指定された値でquery構築
        $this->_table = $table;
        $this->_buildQuery('select');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_record = $this->_fetchAll($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * 1次元配列での取得
     * @return array
     */
    function simpleArray($keyName, $valueName)
    {
        $tempRecord = $this->_record;
        $record = array();
        foreach ($tempRecord as $key => $value) {
            $record[$value[$keyName]] = $value[$valueName];
        }

        return $record;
    }


    /**
     * スレーブサーバーへの接続
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function slaveServer()
    {
        // トランザクションが開始されている場合は処理しない
        if (!$this->_connect || !$this->_connect->inTransaction()) {

            // 接続先のサーバーを決定（ランダム）
            $maxServer = count($this->_app->config['db_config']['slave']) - 1;

            $serverNo = 0;
            if ($maxServer > 0) {
                mt_srand();
                $serverNo = mt_rand(0, $maxServer);
            }

            // 接続
            $this->_connect($this->_app->config['db_config']['slave'][$serverNo]['dsn'],
                            $this->_app->config['db_config']['slave'][$serverNo]['username'],
                            $this->_app->config['db_config']['slave'][$serverNo]['password']
                    );
            $this->_connectFlag = true;
        }

        return $this;
    }


    /**
     * トランザクション開始
     */
    function startTransaction()
    {
        // マスターサーバーを対象
        $this->masterServer();

        // トランザクション分離レベル指定
        $pdoStatement = $this->_connect->prepare('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;');
        $pdoStatement->execute();

        // トランザクション開始
        $this->_connect->beginTransaction();
        if (!$this->_connect->inTransaction()) {
            throw new Exception('Fail to call begin-transaction');
        }
    }


    function truncate($table)
    {
        // データベースが明示的に指定されていなければ Slave へ接続
        if (!$this->_connectFlag) {
            $this->slaveServer();
        }

        // テーブル名が指定されているときはメソッドで指定された値でquery構築
        $this->_table = $table;
        $this->_buildQuery('truncate');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * 常用クエリーを設定しない
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function unsetRegularUseQuery()
    {
        $this->_regularUseQueryFlag = false;

        return $this;
    }


    /**
     * 各テーブルの常用クエリーを設定しない
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function unsetRegularUseQueryForTable()
    {
        $this->_regularUseQueryFlagForTable = false;

        return $this;
    }


    /**
     * データ取得
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function update($table)
    {
        // データベースが明示的に指定されていなければ Slave へ接続
        if (!$this->_connectFlag) {
            $this->masterServer();
        }

        // query構築
        $this->_table = $table;
        $this->_buildQuery('update');

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->_executeQuery($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
    }


    /**
     * 条件式設定
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function where()
    {
        // 引数取得
        $numberOfArgs = func_num_args();
        $parameters = func_get_args();

        // クエリ取得
        $query = array_shift($parameters);

        // ?とパラメーター数が不一致
        if (mb_substr_count($query, '?', FEGG_DEFAULT_CHARACTER_CODE) <> count($parameters)) {
            echo "? And Parameters Are Unmatch.<br />\n";
            $this->_error($query, $parameters);
        }

        // パラメータ処理
        if ($numberOfArgs == 1) {

            // クエリのみ
            $this->_where .= ' ' . $query;

        } else {

            // パラメーターあり
            $index = 0;
            foreach ($parameters as $parameter) {
                if (!is_array($parameter)) {

                    $this->_whereValues[] = $parameter;
                    $index = $index + 1;

                } else {

                    // パラメーターが配列の場合以下の変換を行う
                    // = --> in,
                    // in --> カンマ区切り
                    // <> --> not in
                    // like --> or 区切り

                    // 変換位置の確定
                    preg_match_all('/(\w+\s*(=|<|>|<=|>=|<>|like|in)\s*\(?\s*\?\s*\)?)/i', $query, $matches, PREG_OFFSET_CAPTURE);
                    $position = $matches[0][$index][1];

                    // 演算子の確定
                    preg_match_all('/(\w+\s*(=|<|>|<=|>=|<>|like|in)\s*\(?\s*\?\s*\)?)/i', $query, $matches, PREG_PATTERN_ORDER);
                    $operator = $matches[2][$index];

                    // 対象箇所までのクエリー取得
                    $convertedQueryFrontPart = substr($query, 0, $position);
                    if ($position > 0) {
                        $convertedQuery = substr($query, $position);
                    } else {
                        $convertedQuery = $query;
                    }

                    // 項目名取得
                    $pattern = '/^\s*\w+/i';
                    preg_match($pattern, $convertedQuery, $matches);
                    $itemName = $matches[0];
                    $itemName = '`' . $itemName . '`';

                    // 対象箇所からのクエリー取得
                    $convertedQuery = preg_replace('/^\s*\w+\s*' . $operator . '\s*\(?\s*\?\s*\)?(.*)/', '$1', $convertedQuery);

                    $tempQuery = '';
                    $operator = strtolower($operator);
                    switch ($operator) {
                        case '=':
                        case 'in':
                            foreach ($parameter as $key => $value) {
                                if ($tempQuery) {
                                    $tempQuery .= ',';
                                }
                                $tempQuery .= '?';
                                $this->_whereValues[] = $value;
                            }
                            $convertedQuery = $convertedQueryFrontPart . $itemName . ' in (' . $tempQuery . ') ' . $convertedQuery;
                            $index = $index + 1;
                            break;

                        case '<>':
                            foreach ($parameter as $key => $value) {
                                if ($tempQuery) {
                                    $tempQuery .= ',';
                                }
                                $tempQuery .= '?';
                                $this->_whereValues[] = $value;
                            }
                            $convertedQuery = $convertedQueryFrontPart . $itemName . ' not in (' . $tempQuery . ') ' . $convertedQuery;
                            $index = $index + 1;
                            break;

                        case 'like':
                            $tempQuery = '';
                            foreach ($parameter as $key => $value) {
                                if ($tempQuery) {
                                    $tempQuery .= 'or ';
                                }
                                $tempQuery .= $itemName . ' Like ? Escape \'!\'';
                                $this->_whereValues[] = $value;
                            }
                            $convertedQuery = $convertedQueryFrontPart . '(' . $tempQuery . ') ' . $convertedQuery;
                            $index = $index + 1;
                            break;

                    }
                    $query = $convertedQuery;
                }
            }
            $this->_where .= ' ' . $query;
        }

        return $this;
    }
}
