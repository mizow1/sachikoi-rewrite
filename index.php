<?php
/**
 * 記事改善管理システム - メインページ
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

try {
    // Google Sheetsマネージャーの初期化
    $sheetsManager = new GoogleSheetsManager(GOOGLE_SHEETS_API_KEY, SPREADSHEET_ID, SHEET_NAME);
    
    // スプレッドシートからデータを取得
    $sheetData = $sheetsManager->getSheetData();
    
    // ヘッダー行を取得
    $headers = $sheetData[0] ?? [];
    
    // データ行を取得（ヘッダー行を除く）
    $dataRows = array_slice($sheetData, 1);
    
    // 表示回数でソート（昇順）
    usort($dataRows, function($a, $b) {
        $impressionsA = isset($a[1]) ? (int)$a[1] : 0;
        $impressionsB = isset($b[1]) ? (int)$b[1] : 0;
        return $impressionsA - $impressionsB;
    });
    
    // 記事の改善処理
    if (isset($_POST['improve_article'])) {
        // フォームから送信されたデータを取得
        $rowIndex = (int)$_POST['row_index'];
        $articleContent = $_POST['article_content'];
        $url = $_POST['url'];
        
        // サーチコンソールデータを取得
        $searchConsoleData = [
            'url' => $url,
            'impressions' => $_POST['impressions'],
            'clicks' => $_POST['clicks'],
            'ctr' => $_POST['ctr'],
            'position' => $_POST['position']
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
        $dataRows = array_slice($sheetData, 1);
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
    <title>記事改善管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 20px;
            padding-bottom: 40px;
        }
        .article-content {
            max-height: 200px;
            overflow-y: auto;
        }
        .table-responsive {
            max-height: 70vh;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">記事改善管理システム</h1>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
        
        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo $successMessage; ?></div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h5>表示回数の少ない記事一覧</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>URL</th>
                                        <th>表示回数</th>
                                        <th>クリック数</th>
                                        <th>CTR</th>
                                        <th>平均掲載順位</th>
                                        <th>改善履歴</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dataRows as $index => $row): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($row[0] ?? ''); ?>" target="_blank">
                                                    <?php echo htmlspecialchars($row[0] ?? ''); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($row[1] ?? '0'); ?></td>
                                            <td><?php echo htmlspecialchars($row[2] ?? '0'); ?></td>
                                            <td><?php echo htmlspecialchars($row[3] ?? '0'); ?></td>
                                            <td><?php echo htmlspecialchars($row[4] ?? '0'); ?></td>
                                            <td>
                                                <?php
                                                $historyCount = 0;
                                                for ($i = 5; $i < count($row); $i += 4) {
                                                    if (isset($row[$i]) && !empty($row[$i])) {
                                                        $historyCount++;
                                                    }
                                                }
                                                echo $historyCount . '回';
                                                ?>
                                            </td>
                                            <td>
                                                <a href="article_detail.php?row=<?php echo $index + 1; ?>" class="btn btn-primary btn-sm">詳細</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
