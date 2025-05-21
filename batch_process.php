<?php
/**
 * 記事改善管理システム - バッチ処理
 * 表示回数の少ない記事を自動的に取得・分析・改善してスプレッドシートに追記する
 */

// 設定ファイルの読み込み
require_once 'config.php';
require_once 'classes/GoogleSheetsManager.php';
require_once 'classes/ArticleImprover.php';

// エラーメッセージの初期化
$errorMessages = [];
$successMessages = [];

// 処理対象の記事数
$processLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

// 処理の開始位置と一度に処理する数
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$batchSize = 1; // 一度に処理する記事数。1件ずつ処理してタイムアウトを防止

// 実行モード
$isAjaxMode = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// プログレストラッキング用のセッション変数
// 初回実行時に初期化
session_start();
if ($offset == 0) {
    $_SESSION['batch_errors'] = [];
    $_SESSION['batch_success'] = [];
    $_SESSION['batch_total'] = 0;
}

// 処理開始
try {
    // Google Sheetsマネージャーの初期化
    $sheetsManager = new GoogleSheetsManager(GOOGLE_SHEETS_API_KEY, SPREADSHEET_ID, SHEET_NAME);
    
    // スプレッドシートからデータを取得
    $sheetData = $sheetsManager->getSheetData();
    
    // ヘッダー行を取得
    $headers = $sheetData[0] ?? [];
    
    // データ行を取得（ヘッダー行を除く）
    $dataRows = array_slice($sheetData, 1);
    
    // 表示回数が少ない記事を優先的に表示
    usort($dataRows, function($a, $b) {
        // 正しいカラムの認識: A列=URL, B列=クリック数, C列=表示回数
        $impressionsA = isset($a[2]) ? (int)$a[2] : 0;
        $impressionsB = isset($b[2]) ? (int)$b[2] : 0;
        
        // 表示回数で昇順にソート（表示回数が少ない順）
        return $impressionsA - $impressionsB;
    });
    
    // 処理対象の記事を制限
    $allTargetRows = array_slice($dataRows, 0, $processLimit);
    $_SESSION['batch_total'] = count($allTargetRows);
    
    // 現在のバッチで処理する記事を取得
    $currentBatchRows = array_slice($allTargetRows, $offset, $batchSize);
    
    // ArticleImproverの初期化
    $articleImprover = new ArticleImprover(OPENAI_API_KEY, OPENAI_MODEL);
    
    // 各記事を処理
    foreach ($currentBatchRows as $index => $row) {
        $rowIndex = array_search($row, $dataRows) + 1;
        $url = $row[0] ?? '';
        $clicks = $row[1] ?? '0';
        $impressions = $row[2] ?? '0';
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
                $errorMessage = "URL「{$url}」にアクセスできませんでした。";
                $errorMessages[] = $errorMessage;
                $_SESSION['batch_errors'][] = $errorMessage;
                continue;
            }
            
            // .article_titleと.article_bodyクラスを持つ要素を抽出
            $title = '';
            $body = '';
            
            // タイトルの抽出
            preg_match('/<[^>]*class=["\'].*?article_title.*?["\'][^>]*>(.*?)<\/[^>]*>/is', $html, $titleMatches);
            if (!empty($titleMatches[1])) {
                $title = strip_tags($titleMatches[1]);
            }
            
            // 本文の抽出
            preg_match('/<[^>]*class=["\'].*?article_body.*?["\'][^>]*>(.*?)<\/[^>]*>/is', $html, $bodyMatches);
            if (!empty($bodyMatches[1])) {
                // HTMLタグを除去
                $body = strip_tags($bodyMatches[1]);
            }
            
            // タイトルまたは本文が見つからない場合はスキップ
            if (empty($title) && empty($body)) {
                $errorMessage = "URL「{$url}」から.article_titleまたは.article_bodyクラスが見つかりませんでした。スキップします。";
                $errorMessages[] = $errorMessage;
                $_SESSION['batch_errors'][] = $errorMessage;
                continue;
            }
            
            // タイトルと本文を結合
            $articleContent = '';
            if (!empty($title)) {
                $articleContent .= "\n\n" . $title . "\n\n";
            }
            if (!empty($body)) {
                $articleContent .= $body;
            }
            
            $articleContent = trim(preg_replace('/\s+/', ' ', $articleContent));
            
            if (empty($articleContent)) {
                $errorMessage = "URL「{$url}」から記事コンテンツを抽出できませんでした。";
                $errorMessages[] = $errorMessage;
                $_SESSION['batch_errors'][] = $errorMessage;
                continue;
            }
            
            // サーチコンソールデータを取得
            $searchConsoleData = [
                'url' => $url,
                'impressions' => $impressions, // C列が表示回数
                'clicks' => $clicks, // B列がクリック数
                'ctr' => $ctr,
                'position' => $position
            ];
            
            // 問題点の分析
            // タイムアウトを防止するためにテキストを短く制限
            $maxContentLength = 3000; // 最大文字数を制限
            if (mb_strlen($articleContent) > $maxContentLength) {
                $articleContent = mb_substr($articleContent, 0, $maxContentLength) . "...(略)"; 
            }
            
            // タイムアウト設定を増やす
            ini_set('max_execution_time', 1800); // 3分のタイムアウト
            set_time_limit(1800); // 別の方法でもタイムアウトを設定
            
            // メモリ制限も増やす
            ini_set('memory_limit', '1G');
            
            $issues = $articleImprover->analyzeArticleIssues($articleContent, $searchConsoleData);
            
            // 記事の改善
            $improvedArticle = $articleImprover->improveArticle($articleContent, $issues);
            
            // 現在の日時
            $timestamp = date('Y-m-d H:i:s');
            
            // スプレッドシートに追加するデータ
            $newData = [$articleContent, $issues, $timestamp, $improvedArticle];
            
            // スプレッドシートに書き込み
            $sheetsManager->appendRowData($rowIndex, $newData);
            
            $successMessage = "URL「{$url}」の記事を取得、分析、改善しました。";
            $successMessages[] = $successMessage;
            $_SESSION['batch_success'][] = $successMessage;
            
            // APIレート制限を考慮して少し待機
            sleep(2);
            
        } catch (Exception $e) {
            $errorMessage = "URL「{$url}」の処理中にエラーが発生しました: " . $e->getMessage();
            $errorMessages[] = $errorMessage;
            $_SESSION['batch_errors'][] = $errorMessage;
        }
    }
    
} catch (Exception $e) {
    $errorMessage = 'エラーが発生しました: ' . $e->getMessage();
    $errorMessages[] = $errorMessage;
    $_SESSION['batch_errors'][] = $errorMessage;
}

// 次のバッチがあるかどうか確認
$nextOffset = $offset + $batchSize;
$hasMoreBatches = $nextOffset < $processLimit && $nextOffset < count($allTargetRows);

// Ajaxモードの場合はジャソンで結果を返す
// または次のバッチにリダイレクト
// 通常モードでは次のバッチがあれば自動的にリダイレクト
if ($isAjaxMode) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => count($successMessages),
        'errors' => count($errorMessages),
        'hasMore' => $hasMoreBatches,
        'nextOffset' => $nextOffset,
        'processLimit' => $processLimit,
        'processed' => $offset + count($currentBatchRows),
        'total' => $_SESSION['batch_total'],
        'successMessages' => $successMessages,
        'errorMessages' => $errorMessages
    ]);
    exit;
} elseif ($hasMoreBatches) {
    // 次のバッチにリダイレクト
    header("Location: batch_process.php?offset={$nextOffset}&limit={$processLimit}");
    exit;
}

// 全てのバッチが完了した場合は結果を表示
$allSuccessMessages = $_SESSION['batch_success'] ?? [];
$allErrorMessages = $_SESSION['batch_errors'] ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>バッチ処理 - 記事改善管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>バッチ処理結果</h1>
            <a href="index.php" class="btn btn-secondary">記事一覧に戻る</a>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>処理概要</h5>
            </div>
            <div class="card-body">
                <p>処理対象記事数: <?php echo $_SESSION['batch_total']; ?></p>
                <p>成功: <?php echo count($allSuccessMessages); ?></p>
                <p>失敗: <?php echo count($allErrorMessages); ?></p>
                <p>処理完了: <?php echo count($allSuccessMessages) + count($allErrorMessages); ?> / <?php echo $_SESSION['batch_total']; ?></p>
            </div>
        </div>
        
        <?php if (!empty($allSuccessMessages)): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5>成功メッセージ</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group">
                        <?php foreach ($allSuccessMessages as $message): ?>
                            <li class="list-group-item"><?php echo htmlspecialchars($message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($allErrorMessages)): ?>
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5>エラーメッセージ</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group">
                        <?php foreach ($allErrorMessages as $message): ?>
                            <li class="list-group-item"><?php echo htmlspecialchars($message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
