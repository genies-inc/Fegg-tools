<?php
/**
 * 共通クエリー
 *
 * MySQLクラスが自動的に付加するクエリーを定義
 * 関連クラス：MySQL.php
 */
$db_regular_query = array(
    // テーブルに関わらず付加されるクエリー
    'regular_use' => array(
        'count' => array(
            'where' => 'deleted = 0',
            'order' => '',
        ),
        'select' => array(
            'where' => 'deleted = 0',
            'order' => '',
        ),
        'insert' => array(
            'item' => '',
        ),
        'update' => array(
            'item' => '',
            'where' => '',
        ),
    ),

    // テーブルに応じて付加されるクエリー
    'table' => array(
        'table_name' => array(
            'select' => array(
                'where' => '',
                'order' => '',
            ),
            'insert' => array(
                'item' => '',
            ),
            'update' => array(
                'item' => '',
                'where' => '',
            ),
        ),
    ),
);
