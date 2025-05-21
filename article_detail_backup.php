<?php
/**
 * 記事改善管理システム - 記事詳細ページ
 */

// 設定ファイルの読み込み
require_once 'config.php';
require_once 'classes/GoogleSheetsManager.php';
require_once 'classes/ArticleImprover.php';
require_once 'extract_article.php'; // 記事取得関数を読み込み

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
    
    // デバッグログ
    error_log("article_detail.php: 行データを取得しました。行番号: {$rowIndex}");
    
    // 基本データの取得
    $url = $rowData[0] ?? '';
    $clicks = $rowData[1] ?? '0';
    $impressions = $rowData[2] ?? '0';
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
    
    // 記事の自動取得機能
    $autoFetchArticle = isset($_GET['auto_fetch']) && $_GET['auto_fetch'] == '1';
    $articleContent = '';
    $articleFetched = false;
    
    // 記事の自動改善機能
    $autoImprove = isset($_GET['auto_improve']) && $_GET['auto_improve'] == '1';
    
    // 自動処理機能
    $autoProcess = isset($_GET['auto_process']) && $_GET['auto_process'] == '1';
    
    // 自動処理がリクエストされた場合
    if ($autoProcess && !empty($url)) {
        try {
            // batch_process_api.phpと同じ処理を実行
            // URLから記事コンテンツを取得
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
                    'timeout' => 30
                ]
            ]);
            
            $html = @file_get_contents($url, false, $context);
            if ($html === false) {
                throw new Exception('記事のURLにアクセスできませんでした。');
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
            
            // タイトルまたは本文が見つからない場合はエラー
            if (empty($title) && empty($body)) {
                throw new Exception('URL「' . $url . '」から.article_titleまたは.article_bodyクラスが見つかりませんでした。');
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
                throw new Exception("URL「{$url}」から記事コンテンツを抽出できませんでした。");
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
            
            $successMessage = "URL「{$url}」の記事を取得、分析、改善しました。";
            
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
            
            // 最新の記事内容を取得
            if (!empty($histories)) {
                $latestArticle = end($histories)['improved'];
            }
            
        } catch (Exception $e) {
            $errorMessage = '自動処理中にエラーが発生しました: ' . $e->getMessage();
        }
    }
    
    // 記事の自動取得処理
    if ($autoFetchArticle && !empty($url)) {
        try {
            // URLから記事コンテンツを取得
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
                ]
            ]);
            
            $html = @file_get_contents($url, false, $context);
            if ($html === false) {
                throw new Exception('記事のURLにアクセスできませんでした。');
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
            
            // タイトルまたは本文が見つからない場合はエラー
            if (empty($title) && empty($body)) {
                throw new Exception('URL「' . $url . '」から.article_titleまたは.article_bodyクラスが見つかりませんでした。');
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
            
            if (!empty($articleContent)) {
                $articleFetched = true;
                $successMessage = 'URLから記事を取得しました。';
                
                // 自動改善が有効な場合は、自動的に改善処理を実行
                if ($autoImprove) {
                    // 自動改善処理を即時実行
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
                    
                    $successMessage = '記事の自動取得、分析、改善が完了しました。';
                    
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
                    
                    // 最新の記事内容を取得
                    if (!empty($histories)) {
                        $latestArticle = end($histories)['improved'];
                        $articleContent = $latestArticle; // 改善後の記事を表示
                    }
                }
            } else {
                $errorMessage = 'URLから記事コンテンツを抽出できませんでした。';
            }
        } catch (Exception $e) {
            $errorMessage = 'URLからの記事取得中にエラーが発生しました: ' . $e->getMessage();
        }
    }
    
    // 記事の改善処理
    if (isset($_POST['improve_article'])) {
        try {
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
            
            // 最新の記事内容を取得
            if (!empty($histories)) {
                $latestArticle = end($histories)['improved'];
            }
        } catch (Exception $e) {
            $errorMessage = '記事の改善中にエラーが発生しました: ' . $e->getMessage();
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
        .history-card {
            margin-bottom: 20px;
        }
        .history-timestamp {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .article-content {
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>記事詳細</h1>
            <div>
                <a href="index.php" class="btn btn-outline-secondary me-2">一覧に戻る</a>
                <a href="?row=<?php echo $rowIndex; ?>&auto_process=1" class="btn btn-primary">この記事を自動処理</a>
            </div>
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
                <div class="row mb-3">
                    <div class="col-md-12">
                        <p><strong>URL:</strong> <a href="<?php echo htmlspecialchars($url); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <p><strong>クリック数:</strong> <?php echo htmlspecialchars($clicks); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>表示回数:</strong> <?php echo htmlspecialchars($impressions); ?></p>
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
                <div class="mb-3">
                    <div class="d-flex gap-2 mb-3">
                        <a href="?row=<?php echo $rowIndex; ?>&auto_fetch=1" class="btn btn-info">記事をURLから自動取得</a>
                        <a href="?row=<?php echo $rowIndex; ?>&auto_fetch=1&auto_improve=1" class="btn btn-success">取得して自動改善</a>
                    </div>
                </div>
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="article_content" class="form-label">記事内容</label>
                        <textarea class="form-control" id="article_content" name="article_content" rows="10" required><?php echo htmlspecialchars($articleFetched ? $articleContent : $latestArticle); ?></textarea>
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
