<?php
/**
 * MySQL接続先情報
 *
 * PHP5.3.6より前のバージョンはcharsetが有効でないため
 * 接続時に/etc/my.cnfから文字コードが設定される
 *
 * 関連クラス：DB.php
 */
// 本番環境
if (false) {

    // Database(Master)設定（１台を想定）
    $db_config['master'] = array(
        'dsn'   => 'mysql:host=127.0.0.1;dbname=db_name;charset=utf8',
        'username' => 'db_user',
        'password' => 'db_password'
    );

    // Database(Slave)設定（複数台を想定）
    $db_config['slave'][] = array(
        'dsn'   => 'mysql:host=127.0.0.1;dbname=db_name;charset=utf8',
        'username' => 'db_user',
        'password' => 'db_password'
    );

// 開発環境
} else {

    // Database(Master)設定（１台を想定）
    $db_config['master'] = array(
        'dsn'   => 'mysql:host=127.0.0.1;dbname=db_name;charset=utf8',
        'username' => 'db_user',
        'password' => 'db_password'
    );

    // Database(Slave)設定（複数台を想定）
    $db_config['slave'][] = array(
        'dsn'   => 'mysql:host=127.0.0.1;dbname=db_name;charset=utf8',
        'username' => 'db_user',
        'password' => 'db_password'
    );
}
/* End of file db_config.php */
