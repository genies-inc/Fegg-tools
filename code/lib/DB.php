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
 * @version 3.1.0
 */

class DB
{
    private $_app;

    private $_dbEngine;
    private $_dbConfig;
    private $_connect;
    private $_pdoStatement;
    private $_connectFlag;
    private $_query;
    private $_parameter;
    private $_bulk;
    private $_returnCode;
    private $_record;
    private $_affectedRows;
    private $_lastInsertId;
    private $_itemIdentifier;
    private $_tableIdentifier;
    private $_schema;

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
    function __construct($dbConfig = 'db_config')
    {
        // アプリケーションオブジェクト
        $this->_app = FEGG_getInstance();

        // コンフィグ取得
        $this->_dbConfig = $dbConfig;
        $this->_app->loadConfig($this->_dbConfig);
        $this->_app->loadConfig('db_regular_query');

        // 初期化
        $this->_initQuery();
    }


    /**
     * クエリー構築
     * @param string $queryType クエリタイプ（select, insert, update, delete, replace, delete, truncate）
     * @param string $table テーブル名
     */
    function _buildQuery($queryType, $table) {

        $this->_table = $table;
        $this->_query = '';
        $this->_parameter = array();

        // PostgreSQLはクエリ構築時にスキーマを確定させる必要があるためデータベースが明示的に指定されていなければ接続
        if (!$this->_connectFlag) {
            if (preg_match('/(select|count)/i', $queryType)) {
                $this->slaveServer();
            } else {
                $this->masterServer();
            }
        }

        // 常用クエリーの設定
        if ($this->_regularUseQueryFlag) {
            $this->_setRegularUseQuery($queryType);
        }

        $queryType = strtoupper($queryType);
        $query = '';
        $tableName = $this->_schema ? "{$this->_schema}.{$this->_table}" : $this->_table;
        switch ($queryType) {
            case 'COUNT';
                $query .= 'Select Count(*) as number_of_records ';
                $query .= " From {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ";
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

                $query .= " From {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ";
                $query .= isset($this->_where) ? 'Where ' . $this->_where : '';
                $query .= isset($this->_group) ? ' Group By ' . $this->_group : '';
                $query .= isset($this->_order) ? ' Order By ' . $this->_order : '';
                $query .= isset($this->_limit) ? $this->_limit : '';

                $this->_query = $query;
                $this->_parameter = $this->_whereValues;
                break;

            case 'INSERT':
                // BulkInertのみ処理（通常のInsertはReplaceと統合）
                if ($this->_bulk) {

                    $item = '';
                    foreach ($this->_bulk['items'] as $key => $value) {
                        if ($item) {
                            $item .= ',';
                        }
                        $item .= $this->_itemIdentifier . trim($value) . $this->_itemIdentifier;
                    }

                    $tempQuery1 = '';
                    foreach ($this->_bulk['values'] as $key => $value) {
                        if ($tempQuery1) {
                            $tempQuery1 .= ', ';
                        }
                        $tempQuery2 = '';
                        foreach ($value as $key2 => $value2) {
                            if ($tempQuery2) {
                                $tempQuery2 .= ', ';
                            }
                            $tempQuery2 .= '?';
                            $this->_parameter[] = $value2;
                        }
                        $tempQuery1 .= "($tempQuery2)";
                    }

                    $this->_query = "{$queryType} Into {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ({$item}) Values {$tempQuery1} ";
                    break;
                }

            case 'REPLACE':
                // Insert, Replaceを処理
                $query .= "{$queryType} Into {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ";
                $tempQuery1 = '';
                $tempQuery2 = '';
                foreach($this->_items as $key => $value) {
                    if (preg_match('/([^=]+)\s*=\s*([\w\(\)\s\+]+)/i', $key, $match)) {

                        // 代入形式
                        switch (true) {
                            case (preg_match('/^now/i', $match[2])):
                                if ($tempQuery1) { $tempQuery1 .= ", "; }
                                $tempQuery1 .= $this->_itemIdentifier . trim($match[1]) . $this->_itemIdentifier;
                                if ($tempQuery2) { $tempQuery2 .= ", "; }
                                $tempQuery2 .= '?';
                                $this->_parameter[] = $this->_app->getDatetime();
                                break;

                            default:
                                if ($tempQuery1) { $tempQuery1 .= ", "; }
                                $tempQuery1 .= $this->_itemIdentifier . trim($match[1]) . $this->_itemIdentifier;
                                if ($tempQuery2) { $tempQuery2 .= ", "; }
                                $tempQuery2 .= '?';
                                $this->_parameter[] = $match[2];
                                break;
                        }

                    } else {

                        // 項目名のみ
                        if ($tempQuery1) { $tempQuery1 .= ", "; }
                        $tempQuery1 .= $this->_itemIdentifier . trim($key) . $this->_itemIdentifier;

                        if ($tempQuery2) { $tempQuery2 .= ", "; }
                        $tempQuery2 .= '?';

                        $this->_parameter[] = $value;
                    }
                }

                $query .= '(' . $tempQuery1 . ') Values (' . $tempQuery2 . ')';

                $this->_query = $query;
                break;

            case 'UPDATE':
                $query .= "Update {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} Set ";
                $tempQuery1 = '';
                foreach($this->_items as $key => $value) {
                    if (preg_match('/([^=]+)\s*=\s*([\w\(\)\+\-0-9\s\']+)/i', $key, $match)) {

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
                        $tempQuery1 .= $this->_itemIdentifier . trim($key) . $this->_itemIdentifier . '= ?';

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
                $query .= " From {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ";
                $query .= $this->_where ? 'Where ' . $this->_where : '';
                foreach ($this->_whereValues as $key => $value) {
                    $this->_parameter[] = $value;
                }

                $this->_query = $query;
                break;

            case 'TRUNCATE':

                $query = "Truncate {$this->_tableIdentifier}{$tableName}{$this->_tableIdentifier} ";

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
        $this->_result = null;
        $this->_pdoStatement = null;
        $this->_connect = null;
    }


    /**
     * DBサーバーへの接続確立
     * @param string $dsn データソース名
     * @param string $user ユーザー
     * @param string $password パスワード
     * @param string $schema スキーマ（PostgreSQL用）
     */
    function _connect($dsn, $user, $password, $schema)
    {
        // データベース判定
        if (preg_match('/^([a-z]+):/', $dsn, $matches)) {
            $this->_dbEngine = strtolower($matches[1]);
        }
        if($this->_dbEngine != 'mysql' && $this->_dbEngine != 'pgsql') {
            echo "Connection failed: Non-Suported DB Engline.";
            exit;
        }

        // 接続
        try {
            switch ($this->_dbEngine) {
                case 'mysql':
                    if (defined('PDO::MYSQL_ATTR_READ_DEFAULT_FILE')) {
                        $options = array(
                            PDO::MYSQL_ATTR_READ_DEFAULT_FILE  => '/etc/my.cnf',
                        );
                        $this->_connect = new PDO($dsn, $user, $password, $options);
                    } else {
                        $this->_connect = new PDO($dsn, $user, $password);
                    }

                    // バッファ無効
                    $this->_connect->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

                    // 項目区切り文字
                    $this->_itemIdentifier = '`';
                    $this->_tableIdentifier = '`';

                    break;

                case 'pgsql':
                    $this->_connect = new PDO($dsn, $user, $password);

                    // 項目区切り文字
                    $this->_itemIdentifier = '"';
                    $this->_tableIdentifier = '';
                    $this->_schema = $schema;

                    break;
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
        $error = '';

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

        throw new Exception($error);
    }


    /**
     * 結果全件を保持
     */
    function _fetchAll()
    {
        $this->_record = !$this->_record ? $this->_pdoStatement->fetchAll(PDO::FETCH_ASSOC) : $this->_record;
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
        $this->_bulk = null;

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
    function _isHash($param)
    {
        // 連想配列の先頭キーに0は使えず、配列の先頭は0という前提
        return array_key_first($param) !== 0;
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
        $this->_fetchAll();

        return !$index ? $this->_record : array_column($this->_record, null, $index);
    }


    /**
     * 1次元配列での取得
     * @param string $keyname 配列のキーにする項目名
     * @param string $valueName 配列の値にする項目名
     * @return array
     */
    function arr($keyName, $valueName)
    {
        $this->_fetchAll();

        $record = [];
        foreach ($this->_record as $key => $value) {
            $record[$value[$keyName]] = $value[$valueName];
        }

        return $record;
    }


    /**
     * 操作項目設定
     *
     * Bulk Insert専用のメソッド
     * item() メソッドのような複数回呼び出しはできず insert() メソッドと常にペアになる
     *
     *  item('item1, item2', [['item1' => 1, 'item2' => 2], ['item1' => 3, 'item2' => 4]])
     *  item('item1, item2', [[1, 2], [3, 4]])
     *
     * @param mixed $query 複数項目の場合カンマ区切り
     * @param mixed $parameter 連想配列の場合は$queryで指定した項目名と一致するもの、配列の場合は左から順に値を使用
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
    */
    function bulk($query, $parameters)
    {
        $this->_bulk = [];
        $this->_bulk['items'] = explode(',', $query);

        foreach ($parameters as $parmKey => $parameter) {

            if ($this->_isHash($parameter)) {

                // パラメーターが連想配列の場合は要素名で一致させる
                $value = [];
                foreach ($this->_bulk['items'] as $itemId) {
                    $itemId = trim($itemId);
                    if (array_key_exists($itemId, $parameter)) {
                        $value[] = $parameter[$itemId];
                    } else {
                        $value[] = '';
                    }
                }
                $this->_bulk['values'][] = $value;

            } else {

                // パラメーターが配列の場合は順番に一致させる
                $this->_bulk['values'][] = $parameter;

            }
        }

        return $this;
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
     * @return int 件数
     */
    function count($table)
    {
        // query構築
        $this->_buildQuery('count', $table);

        // 件数を取得
        return $this->query($this->_query, $this->_parameter)->one('number_of_records');
    }


    /**
     * DBサーバーとの接続切断
     */
    function close()
    {
        $this->_close();
    }


    /**
     * データ削除
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function delete($table = '')
    {
        // query構築
        $this->_buildQuery('delete', $table);

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->query($this->_query, $this->_parameter);
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
        $replacedKeyword = preg_replace('/(?=[!_%])/', '\\', $keyword);
        $replacedKeyword = $front . $replacedKeyword . $back;

        return $replacedKeyword;
    }


    /**
     * １レコードの取得
     * @return Array カーソル行のレコード配列
     */
    function fetch()
    {
        while ($row = $this->_pdoStatement->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
        $this->_pdoStatement->closeCursor();
    }


    /**
     * 取得行数、結果行数の取得
     * @return integer 結果行数
     */
    function getAffectedRow()
    {
        return $this->_affectedRows;
    }


    /**
     * 直近で登録されたオートナンバーの取得
     * @return Integer 取得できなかったときは0を返す
     */
    function getLastId()
    {
        return $this->_lastInsertId;
    }


    /**
     * 最後に実行したクエリーの取得
     */
    function getQuery()
    {
        $query = str_replace('?', '%s', $this->_query);
        if ($this->_parameter) {
            $query = vsprintf($query, $this->_parameter);
        }

        return $query;
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
        $this->_fetchAll();

        $ids = [];
        foreach ($this->_record as $key => $value) {
            $ids[$value[$index]] = $value[$index];
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
        // query構築
        $this->_buildQuery('insert', $table);

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->query($this->_query, $this->_parameter);
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
                    if (array_key_exists($value, $parameter)) {
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
                    if (array_key_exists($key, $parameter)) {
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
     * @param int $offset MySQLは1始まりでPostgreSQLは0始まり差異があるためPostgresSQLの場合は-1する
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function limit($limit, $offset = 0)
    {
        $this->_limit = " Limit {$limit} Offset {$offset}";

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
            $this->_connect($this->_app->config[$this->_dbConfig]['master']['dsn'],
                            $this->_app->config[$this->_dbConfig]['master']['username'],
                            $this->_app->config[$this->_dbConfig]['master']['password'],
                            $this->_app->config[$this->_dbConfig]['master']['schema'] ?? ''
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
        $record = $this->_pdoStatement->fetch(PDO::FETCH_ASSOC);
        $this->_pdoStatement->closeCursor();

        return !$item ? $record : $record[$item] ?? '';
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
    function query($query, $parameter = [])
    {
        $this->_query = $query;
        $this->_parameter = $parameter;
        $this->_record = [];
        $this->_affectedRows = 0;
        $this->_lastInsertId = 0;

        try {

            // データベースが明示的に指定されていなければ接続
            if (!$this->_connectFlag) {
                if (preg_match('/^\s*(select|count).+/i', $this->_query)) {
                    $this->slaveServer();
                } else {
                    $this->masterServer();
                }
            }

            if ($this->_pdoStatement = $this->_connect->prepare($query)) {

                // クエリ実行
                if (!is_array($parameter) || !$parameter) {
                    $this->_result = $this->_pdoStatement->execute();
                } else {
                    $this->_result = $this->_pdoStatement->execute($parameter);
                }

                // 結果処理
                if ($this->_result) {

                    switch (true) {
                        case preg_match('/^\s*(insert).+/i', $this->_query):
                            $this->_lastInsertId = $this->_connect->lastInsertId();
                            break;

                        case preg_match('/^\s*(update).+/i', $this->_query):
                            $this->_affectedRows = $this->_pdoStatement->rowCount();
                            break;
                    }

                    return $this;

                } else {
                    echo "No Result.<br />\n";
                    $this->_error($query);
                }

            } else {
                echo "No PDOStatemant.<br />\n";
                $this->_error($query, $parameter);
            }

        } catch(PDOException $e) {
            echo $e->getMessage( ) . "<br />\n";
            echo "PDO Exception.<br />\n";
            $this->_error($query, $parameter);
        }
    }


    /**
     * データリプレース
     * @param string $table
     * @return DB メソッドチェーンに対応するため自身のオブジェクト($this)を返す
     */
    function replace($table)
    {
        // query構築
        $this->_buildQuery('replace', $table);

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->query($this->_query, $this->_parameter);
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
        // query構築
        $this->_buildQuery('select', $table);

        // クエリーを実行
        $this->query($this->_query, $this->_parameter);
        $this->_initQuery();

        return $this;
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
            $maxServer = count($this->_app->config[$this->_dbConfig]['slave']) - 1;

            $serverNo = 0;
            if ($maxServer > 0) {
                mt_srand();
                $serverNo = mt_rand(0, $maxServer);
            }

            // 接続
            $this->_connect($this->_app->config[$this->_dbConfig]['slave'][$serverNo]['dsn'],
                            $this->_app->config[$this->_dbConfig]['slave'][$serverNo]['username'],
                            $this->_app->config[$this->_dbConfig]['slave'][$serverNo]['password'],
                            $this->_app->config[$this->_dbConfig]['slave'][$serverNo]['schema'] ?? ''
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
        // query構築
        $this->_table = $table;
        $this->_buildQuery('truncate', $table);

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->query($this->_query, $this->_parameter);
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
        // query構築
        $this->_buildQuery('update', $table);

        // クエリーを実行して、論理的に非接続状態にする
        $this->_returnCode = $this->query($this->_query, $this->_parameter);
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
                    preg_match_all("/([\w{$this->_itemIdentifier}]+\s*(=|<|>|<=|>=|<>|like|in)\s*\(?\s*[\?,]+\s*\)?)/i", $query, $matches, PREG_OFFSET_CAPTURE);
                    $position = $matches[0][$index][1];

                    // 演算子の確定
                    $operator = $matches[2][$index][0];

                    // 対象箇所までのクエリー取得
                    $convertedQueryFrontPart = substr($query, 0, $position);
                    if ($position > 0) {
                        $convertedQuery = substr($query, $position);
                    } else {
                        $convertedQuery = $query;
                    }

                    // 項目名取得
                    $pattern = "/^\s*{$this->_itemIdentifier}?(\w+){$this->_itemIdentifier}?/i";
                    preg_match($pattern, $convertedQuery, $matches);
                    $itemName = $matches[1];
                    $itemName = $this->_itemIdentifier . $itemName . $this->_itemIdentifier;

                    // 対象箇所からのクエリー取得
                    $convertedQuery = preg_replace("/^\s*[\w{$this->_itemIdentifier}]+\s*" . $operator . '\s*\(?\s*\?\s*\)?(.*)/', '$1', $convertedQuery);

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
                                $tempQuery .= $itemName . ' Like ?';
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
