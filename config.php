<?php
/**
 * 設定ファイル
 */

// .envファイルから環境変数を読み込む
function loadEnv($path) {
    if (!file_exists($path)) {
        throw new Exception('.envファイルが見つかりません: ' . $path);
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // コメント行をスキップ
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // 引用符を削除
        if (strpos($value, '"') === 0 && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }
        
        // 環境変数を設定
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// .envファイルを読み込む
try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Google Sheets API設定
define('GOOGLE_SHEETS_API_KEY', getenv('GOOGLE_SHEETS_API_KEY')); // Google Sheets APIキー
define('SPREADSHEET_ID', getenv('SPREADSHEET_ID')); // スプレッドシートID
define('SHEET_NAME', getenv('SHEET_NAME')); // シート名

// サービスアカウント設定
define('SERVICE_ACCOUNT_JSON', getenv('SERVICE_ACCOUNT_JSON')); // サービスアカウントのJSONファイルパス
define('USE_SERVICE_ACCOUNT', getenv('USE_SERVICE_ACCOUNT') === 'true'); // サービスアカウントを使用するかどうか

// OpenAI API設定
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY')); // OpenAI APIキー
define('OPENAI_MODEL', getenv('OPENAI_MODEL')); // 使用するモデル

// データベース設定（必要な場合）
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// エラー表示設定
ini_set('display_errors', 1);
error_reporting(E_ALL);
