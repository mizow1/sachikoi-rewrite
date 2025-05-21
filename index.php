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
    
    // 表示回数が少ない記事を優先的に表示
    usort($dataRows, function($a, $b) {
        // 正しいカラムの認識: A列=URL, B列=クリック数, C列=表示回数
        // クリック数が少ない順
        $clicksA = isset($a[1]) ? (int)$a[1] : 0;
        $clicksB = isset($b[1]) ? (int)$b[1] : 0;
        
        // 表示回数が多い順
        $impressionsA = isset($a[2]) ? (int)$a[2] : 0;
        $impressionsB = isset($b[2]) ? (int)$b[2] : 0;
        
        // CTR計算（クリック数÷表示回数）
        $ctrA = ($impressionsA > 0) ? $clicksA / $impressionsA : 0;
        $ctrB = ($impressionsB > 0) ? $clicksB / $impressionsB : 0;
        
        // まず表示回数で昇順にソート（表示回数が少ない順）
        if ($impressionsA != $impressionsB) {
            return $impressionsA - $impressionsB;
        }
        
        // 表示回数が同じ場合はCTRの小さい順にソート
        return $ctrA - $ctrB;
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>記事改善管理システム</h1>
            <div>
                <a href="batch_process.php" class="btn btn-primary mb-3">自動改善バッチ処理実行</a>
                <a href="batch_process.php?limit=10" class="btn btn-outline-primary mb-3">自動改善（10件）</a>
                <button onclick="startApiBatchProcess()" class="btn btn-success mb-3">タイムアウト対策版バッチ処理</button>
                <button onclick="startApiBatchProcess(5)" class="btn btn-outline-success mb-3">タイムアウト対策版（5件）</button>
            </div>
        </div>
        
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
                                        <th>クリック数</th>
                                        <th>表示回数</th>
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
    // APIを使用したバッチ処理の実行
    function startApiBatchProcess(limit) {
        // 対象行の取得
        const rows = [];
        $('table tbody tr').each(function(index) {
            const url = $(this).find('td:first-child a').attr('href');
            const rowIndex = index + 2; // スプレッドシートの行番号は2から始まる
            rows.push({ url, rowIndex });
        });
        
        // 処理対象を制限
        const targetRows = limit ? rows.slice(0, limit) : rows;
        
        // プログレス表示用の要素を追加
        $('.container').prepend(`
            <div id="batch-progress" class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5>バッチ処理の進行状況</h5>
                </div>
                <div class="card-body">
                    <div class="progress mb-3">
                        <div class="progress-bar" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                    <p>処理中: <span id="current-item">-</span></p>
                    <p>進行状況: <span id="progress-status">0</span> / <span id="total-items">${targetRows.length}</span></p>
                    <div id="result-messages" class="mt-3">
                        <div class="alert alert-info">処理を開始します...</div>
                    </div>
                </div>
            </div>
        `);
        
        // 順番に処理する
        processNextItem(targetRows, 0);
    }
    
    // 次のアイテムを処理
    function processNextItem(items, currentIndex) {
        // 全てのアイテムを処理完了した場合
        if (currentIndex >= items.length) {
            $('#result-messages').prepend(`<div class="alert alert-success">全ての処理が完了しました。</div>`);
            return;
        }
        
        const item = items[currentIndex];
        const progress = Math.round((currentIndex / items.length) * 100);
        
        // 進行状況を更新
        $('.progress-bar').css('width', progress + '%').attr('aria-valuenow', progress).text(progress + '%');
        $('#current-item').text(item.url);
        $('#progress-status').text(currentIndex);
        
        // APIを呼び出し
        $('#result-messages').prepend(`<div class="alert alert-info">${item.url} の処理を開始します...</div>`);
        
        $.ajax({
            url: 'batch_process_api.php',
            data: {
                url: item.url,
                row: item.rowIndex
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    $('#result-messages').prepend(`<div class="alert alert-success">${response.message}</div>`);
                } else {
                    const message = response && response.message ? response.message : '不明なエラーが発生しました';
                    $('#result-messages').prepend(`<div class="alert alert-danger">${message}</div>`);
                }
                
                // 2秒後に次のアイテムを処理
                setTimeout(function() {
                    processNextItem(items, currentIndex + 1);
                }, 2000);
            },
            error: function(xhr, status, error) {
                let errorMessage = error || 'エラーが発生しました';
                
                // レスポンステキストからエラーメッセージを抽出する試み
                try {
                    const responseText = xhr.responseText;
                    if (responseText) {
                        // HTMLが返ってきた場合はパースしない
                        if (!responseText.includes('<!DOCTYPE html>') && !responseText.includes('<html')) {
                            try {
                                const jsonResponse = JSON.parse(responseText);
                                if (jsonResponse && jsonResponse.message) {
                                    errorMessage = jsonResponse.message;
                                }
                            } catch (e) {
                                // JSONパースエラーの場合は、レスポンステキストをそのまま表示
                                if (responseText.length < 100) { // 短いメッセージのみ表示
                                    errorMessage = responseText;
                                }
                            }
                        } else {
                            errorMessage = 'HTMLエラーレスポンスが返されました。サーバーエラーの可能性があります。';
                        }
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                }
                
                $('#result-messages').prepend(`<div class="alert alert-danger">エラーが発生しました: ${errorMessage}</div>`);
                
                // 5秒後に再試行
                setTimeout(function() {
                    processNextItem(items, currentIndex);
                }, 5000);
            },
            timeout: 300000 // 5分のタイムアウトに延長
        });
    }
    </script>
</body>
</html>
