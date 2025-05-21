<?php
/**
 * 記事改善管理システム - 記事詳細ページ
 */

// 設定ファイルの読み込み
require_once 'config.php';
require_once 'classes/GoogleSheetsManager.php';
require_once 'classes/ArticleImprover.php';

// セッション開始
session_start();

// エラーメッセージの初期化
$errorMessage = '';
$successMessage = '';

// 行番号の取得
$rowIndex = isset($_GET['row']) ? (int)$_GET['row'] : 0;

if ($rowIndex <= 0) {
    header('Location: index.php');
    exit;
}

try {
    // Google Sheetsマネージャーの初期化
    $sheetsManager = new GoogleSheetsManager(GOOGLE_SHEETS_API_KEY, SPREADSHEET_ID, SHEET_NAME);
    
    // スプレッドシートからデータを取得
    $sheetData = $sheetsManager->getSheetData();
    
    // ヘッダー行を取得
    $headers = $sheetData[0] ?? [];
    
    // 指定された行のデータを取得
    $rowData = $sheetData[$rowIndex] ?? null;
    
    if (!$rowData) {
        throw new Exception('指定された行のデータが見つかりませんでした。');
    }
    
    // 基本データの取得
    $url = $rowData[0] ?? '';
    $impressions = $rowData[1] ?? '0';
    $clicks = $rowData[2] ?? '0';
    $ctr = $rowData[3] ?? '0';
    $position = $rowData[4] ?? '0';
    
    // 改善履歴の取得
    $histories = [];
    for ($i = 5; $i < count($rowData); $i += 4) {
        if (isset($rowData[$i]) && !empty($rowData[$i])) {
            $histories[] = [
                'original' => $rowData[$i] ?? '',
                'issues' => $rowData[$i + 1] ?? '',
                'timestamp' => $rowData[$i + 2] ?? '',
                'improved' => $rowData[$i + 3] ?? ''
            ];
        }
    }
    
    // 最新の記事内容を取得
    $latestArticle = '';
    if (!empty($histories)) {
        $latestArticle = end($histories)['improved'];
    }
    
    // 記事の改善処理
    if (isset($_POST['improve_article'])) {
        // フォームから送信されたデータを取得
        $articleContent = $_POST['article_content'];
        
        // サーチコンソールデータを取得
        $searchConsoleData = [
            'url' => $url,
            'impressions' => $impressions,
            'clicks' => $clicks,
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
        
        $successMessage = '記事の分析と改善が完了しました。';
        
        // 最新のデータを再取得
        $sheetData = $sheetsManager->getSheetData();
        $rowData = $sheetData[$rowIndex] ?? null;
        
        // 改善履歴の再取得
        $histories = [];
        for ($i = 5; $i < count($rowData); $i += 4) {
            if (isset($rowData[$i]) && !empty($rowData[$i])) {
                $histories[] = [
                    'original' => $rowData[$i] ?? '',
                    'issues' => $rowData[$i + 1] ?? '',
                    'timestamp' => $rowData[$i + 2] ?? '',
                    'improved' => $rowData[$i + 3] ?? ''
                ];
            }
        }
        
        // 最新の記事内容を更新
        if (!empty($histories)) {
            $latestArticle = end($histories)['improved'];
        }
    }
} catch (Exception $e) {
    $errorMessage = 'エラーが発生しました: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>記事詳細 - 記事改善管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 20px;
            padding-bottom: 40px;
        }
        .article-content {
            white-space: pre-wrap;
        }
        .history-card {
            margin-bottom: 20px;
        }
        .history-timestamp {
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>記事詳細</h1>
            <a href="index.php" class="btn btn-secondary">戻る</a>
        </div>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
        
        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo $successMessage; ?></div>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>記事情報</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12">
                        <p><strong>URL:</strong> <a href="<?php echo htmlspecialchars($url); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <p><strong>表示回数:</strong> <?php echo htmlspecialchars($impressions); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>クリック数:</strong> <?php echo htmlspecialchars($clicks); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>CTR:</strong> <?php echo htmlspecialchars($ctr); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>平均掲載順位:</strong> <?php echo htmlspecialchars($position); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>新しい改善を作成</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="article_content" class="form-label">記事内容</label>
                        <textarea class="form-control" id="article_content" name="article_content" rows="10" required><?php echo htmlspecialchars($latestArticle); ?></textarea>
                    </div>
                    <button type="submit" name="improve_article" class="btn btn-primary">分析して改善する</button>
                </form>
            </div>
        </div>
        
        <h2 class="mb-3">改善履歴</h2>
        
        <?php if (empty($histories)): ?>
            <div class="alert alert-info">まだ改善履歴がありません。</div>
        <?php else: ?>
            <?php foreach (array_reverse($histories) as $index => $history): ?>
                <div class="card history-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>改善 #<?php echo count($histories) - $index; ?></h5>
                        <span class="history-timestamp"><?php echo htmlspecialchars($history['timestamp']); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6>元の記事</h6>
                                <div class="border p-3 article-content"><?php echo nl2br(htmlspecialchars($history['original'])); ?></div>
                            </div>
                            <div class="col-md-6">
                                <h6>問題点</h6>
                                <div class="border p-3 article-content"><?php echo nl2br(htmlspecialchars($history['issues'])); ?></div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <h6>改善された記事</h6>
                                <div class="border p-3 article-content"><?php echo nl2br(htmlspecialchars($history['improved'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
