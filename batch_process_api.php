<?php
/**
 * 記事改善管理システム - APIバッチ処理
 * 表示回数の少ない記事を自動的に取得・分析・改善してスプレッドシートに追記する
 * 
 * このファイルは単一の記事を処理するAPIエンドポイントとして機能します
 */

// 設定ファイルの読み込み
require_once 'config.php';
require_once 'classes/GoogleSheetsManager.php';
require_once 'classes/ArticleImprover.php';
require_once 'extract_article.php'; // 記事取得関数を読み込み

// エラー出力を無効化し、JSON形式のレスポンスのみを返す
ini_set('display_errors', 0);
error_reporting(0);

// エラーハンドラーを登録
function handleError($errno, $errstr, $errfile, $errline) {
    global $result;
    $result['success'] = false;
    $result['message'] = "PHPエラー: {$errstr} in {$errfile} on line {$errline}";
    echo json_encode($result);
    exit;
}
set_error_handler('handleError');

// 例外ハンドラーを登録
function handleException($e) {
    global $result;
    $result['success'] = false;
    $result['message'] = "例外が発生しました: " . $e->getMessage();
    echo json_encode($result);
    exit;
}
set_exception_handler('handleException');

// タイムアウト設定を増やす
ini_set('max_execution_time', 1800); // 30分のタイムアウト
set_time_limit(1800);

// メモリ制限も増やす
ini_set('memory_limit', '1G');

// レスポンスヘッダー設定
header('Content-Type: application/json');

// パラメータ取得
$url = isset($_GET['url']) ? $_GET['url'] : '';
$rowIndex = isset($_GET['row']) ? (int)$_GET['row'] : 0;

// 結果配列の初期化
$result = [
    'success' => false,
    'message' => '',
    'data' => null
];

// パラメータチェック
if (empty($url) || $rowIndex <= 0) {
    $result['message'] = 'URLまたは行番号が指定されていません。';
    echo json_encode($result);
    exit;
}

try {
    // Google Sheetsマネージャーの初期化
    $sheetsManager = new GoogleSheetsManager(GOOGLE_SHEETS_API_KEY, SPREADSHEET_ID, SHEET_NAME);
    
    // スプレッドシートからデータを取得
    $sheetData = $sheetsManager->getSheetData();
    
    // 指定された行のデータを取得
    if (!isset($sheetData[$rowIndex])) {
        $result['message'] = '指定された行が見つかりません。';
        echo json_encode($result);
        exit;
    }
    
    $row = $sheetData[$rowIndex];
    $url = $row[0] ?? '';
    $clicks = $row[1] ?? '0'; // B列がクリック数
    $impressions = $row[2] ?? '0'; // C列が表示回数
    $ctr = $row[3] ?? '0';
    $position = $row[4] ?? '0';
    
    // 記事を取得
    try {
        // URLから記事コンテンツを取得
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
                'timeout' => 30
            ]
        ]);
        
        $html = @file_get_contents($url, false, $context);
        if ($html === false) {
            $result['message'] = "URL「{$url}」にアクセスできませんでした。";
            echo json_encode($result);
            exit;
        }
        
        // 記事コンテンツを抽出する関数を使用
        $extractedContent = extractArticleContent($html);
        $title = $extractedContent['title'];
        $body = $extractedContent['body'];
        
        // デバッグ用
        error_log("batch_process_api.php: 記事本文を取得しました。長さ: " . strlen($body));
        
        // タイトルまたは本文が見つからない場合はスキップ
        if (empty($title) && empty($body)) {
            $result['message'] = "URL「{$url}」から.article_titleまたは.article_bodyクラスが見つかりませんでした。スキップします。";
            $result['success'] = false;
            echo json_encode($result);
            exit;
        }
        
        // タイトルと本文を結合
        $articleContent = '';
        if (!empty($title)) {
            $articleContent .= "\n\n" . $title . "\n\n";
        }
        if (!empty($body)) {
            $articleContent .= $body;
        }
        
        // 余分な空白や特殊文字を整理
        $articleContent = trim(preg_replace('/\s+/', ' ', $articleContent));
        
        if (empty($articleContent)) {
            $result['message'] = "URL「{$url}」から記事コンテンツを抽出できませんでした。";
            echo json_encode($result);
            exit;
        }
        
        // タイムアウトを防止するためにテキストを短く制限
        $maxContentLength = 3000; // 最大文字数を制限
        if (mb_strlen($articleContent) > $maxContentLength) {
            $articleContent = mb_substr($articleContent, 0, $maxContentLength) . "...(略)"; 
        }
        
        // サーチコンソールデータを取得
        $searchConsoleData = [
            'url' => $url,
            'impressions' => $impressions, // C列が表示回数
            'clicks' => $clicks, // B列がクリック数
            'ctr' => $ctr,
            'position' => $position
        ];
        
        // ArticleImproverの初期化
        $articleImprover = new ArticleImprover(OPENAI_API_KEY, OPENAI_MODEL);
        
        // 問題点の分析
        $issues = $articleImprover->analyzeArticleIssues($articleContent, $searchConsoleData);
        
        // 記事の改善
        $improvedArticle = $articleImprover->improveArticle($articleContent, $issues);
        
        // 現在の日時
        $timestamp = date('Y-m-d H:i:s');
        
        // スプレッドシートに追加するデータ
        $newData = [$articleContent, $issues, $timestamp, $improvedArticle];
        
        // スプレッドシートに書き込み
        $sheetsManager->appendRowData($rowIndex, $newData);
        
        // 成功レスポンス
        $result['success'] = true;
        $result['message'] = "URL「{$url}」の記事を取得、分析、改善しました。";
        $result['data'] = [
            'url' => $url,
            'original' => $articleContent,
            'issues' => $issues,
            'improved' => $improvedArticle,
            'timestamp' => $timestamp
        ];
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        $result['message'] = "URL「{$url}」の処理中にエラーが発生しました: " . $e->getMessage();
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    $result['message'] = 'エラーが発生しました: ' . $e->getMessage();
    echo json_encode($result);
}
