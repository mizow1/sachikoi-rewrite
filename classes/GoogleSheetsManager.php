<?php
/**
 * Google Sheetsとの連携を管理するクラス
 * サービスアカウントを使用した認証方式に対応
 */

// サービスアカウントクライアントの読み込み
require_once __DIR__ . '/GoogleServiceClient.php';

class GoogleSheetsManager {
    private $apiKey;
    private $spreadsheetId;
    private $sheetName;
    private $serviceClient = null;
    private $useServiceAccount = false;
    
    /**
     * コンストラクタ
     */
    public function __construct($apiKey, $spreadsheetId, $sheetName) {
        $this->apiKey = $apiKey;
        $this->spreadsheetId = $spreadsheetId;
        $this->sheetName = $sheetName;
        
        // サービスアカウントの設定があれば使用する
        if (defined('USE_SERVICE_ACCOUNT') && USE_SERVICE_ACCOUNT && defined('SERVICE_ACCOUNT_JSON')) {
            try {
                $this->serviceClient = new GoogleServiceClient(SERVICE_ACCOUNT_JSON);
                $this->useServiceAccount = true;
                error_log('Google Sheets API: サービスアカウントを使用します');
            } catch (Exception $e) {
                error_log('Google Sheets API: サービスアカウントの初期化に失敗しました: ' . $e->getMessage());
                // エラーが発生した場合は通常のAPIキーにフォールバック
            }
        }
    }
    
    /**
     * スプレッドシートからデータを取得
     * 
     * @return array 取得したデータ
     */
    public function getSheetData() {
        // URLエンコードされたシート名を使用
        $encodedSheetName = urlencode($this->sheetName);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$encodedSheetName}";
        
        // サービスアカウントを使用する場合
        if ($this->useServiceAccount && $this->serviceClient) {
            try {
                $response = $this->serviceClient->request('GET', $url);
                
                if (!isset($response['values'])) {
                    throw new Exception('スプレッドシートのデータ形式が不正です: ' . json_encode($response));
                }
                
                return $response['values'];
            } catch (Exception $e) {
                error_log('サービスアカウントでの取得に失敗しました。APIキーにフォールバックします: ' . $e->getMessage());
                // エラーが発生した場合は通常のAPIキーにフォールバック
            }
        }
        
        // 通常のAPIキーを使用する場合
        $url .= "?key={$this->apiKey}";
        
        // ユーザーエージェントとタイムアウトを設定
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                          "Accept: application/json\r\n",
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            // エラーの詳細を取得
            $error = error_get_last();
            throw new Exception('スプレッドシートからデータを取得できませんでした。: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        $data = json_decode($response, true);
        if (!isset($data['values'])) {
            throw new Exception('スプレッドシートのデータ形式が不正です: ' . json_encode($data));
        }
        
        return $data['values'];
    }
    
    /**
     * スプレッドシートにデータを書き込む
     * 
     * @param string $range 書き込む範囲（例: 'F2:J2'）
     * @param array $values 書き込むデータ
     * @return bool 成功したかどうか
     */
    public function updateSheetData($range, $values) {
        try {
            // ログに書き込み情報を記録
            error_log("Google Sheets 書き込み試行: 範囲={$range}, データ=" . json_encode($values, JSON_UNESCAPED_UNICODE));
            
            // サービスアカウントを使用する場合
            if ($this->useServiceAccount && $this->serviceClient) {
                try {
                    // サービスアカウントJSONファイルのパスを確認
                    error_log("Google Sheets API: サービスアカウントを使用して書き込みを試行します");
                    
                    // URLエンコードされたシート名と範囲を使用
                    $encodedRange = urlencode($this->sheetName . '!' . $range);
                    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$encodedRange}?valueInputOption=USER_ENTERED";
                    
                    error_log("Google Sheets API: 書き込みURL: {$url}");
                    
                    $requestData = [
                        'values' => $values
                    ];
                    
                    error_log("Google Sheets API: リクエストデータ: " . json_encode($requestData));
                    $response = $this->serviceClient->request('PUT', $url, $requestData);
                    
                    if (isset($response['updatedCells']) && $response['updatedCells'] > 0) {
                        error_log('Google Sheets API: データの書き込み成功 - 更新されたセル数: ' . $response['updatedCells']);
                        return true;
                    } else {
                        error_log('Google Sheets API: データの書き込み失敗 - ' . json_encode($response, JSON_UNESCAPED_UNICODE));
                    }
                } catch (Exception $e) {
                    error_log('Google Sheets API: サービスアカウントでの書き込みに失敗しました: ' . $e->getMessage());
                    // エラーが発生した場合はログに出力し、フォールバック処理を続ける
                }
            } else {
                error_log("Google Sheets API: サービスアカウントが使用できません");
            }
            
            // サービスアカウントが使用できない場合はローカルファイルに保存
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            
            $logFile = $logDir . '/sheet_updates_' . date('Y-m-d') . '.log';
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - 範囲: {$range}\nデータ: " . json_encode($values, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);
            
            // ログには保存したが、APIでの書き込みは失敗
            return false;
        } catch (Exception $e) {
            error_log('スプレッドシートへのデータ書き込みに失敗しました: ' . $e->getMessage());
            // エラーをログに記録するが、外部には例外を投げない
            return false;
        }
    }
    
    /**
     * 特定の行にデータを追加（エラーハンドリング強化）
     * 
     * @param int $rowIndex 行番号（0から始まる）
     * @param array $rowData 追加するデータ
     * @return bool 成功したかどうか
     */
    public function appendRowData($rowIndex, $rowData) {
        try {
            // 現在のデータを取得して、指定された行のデータを更新
            $data = $this->getSheetData();
            
            // 行が存在しない場合はエラー
            if (!isset($data[$rowIndex])) {
                throw new Exception('指定された行が存在しません。行インデックス: ' . $rowIndex);
            }
            
            // 既存の行データの末尾に新しいデータを追加
            $existingRowData = $data[$rowIndex];
            $columnIndex = count($existingRowData);
            
            // 更新範囲を計算（例: F2:J2）
            $startColumn = $this->columnIndexToLetter($columnIndex);
            $endColumn = $this->columnIndexToLetter($columnIndex + count($rowData) - 1);
            $rowNum = $rowIndex + 1;
            $range = $startColumn . $rowNum . ":" . $endColumn . $rowNum;
            
            return $this->updateSheetData($range, [$rowData]);
        } catch (Exception $e) {
            // エラーをより詳細に記録
            error_log('GoogleSheetsManager::appendRowData エラー: ' . $e->getMessage());
            throw $e; // 元の例外を再スロー
        }
    }
    
    /**
     * 数値のカラムインデックスをエクセルの列文字（A, B, C...）に変換
     * 
     * @param int $index カラムインデックス（0から始まる）
     * @return string 列文字
     */
    private function columnIndexToLetter($index) {
        $letter = '';
        
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = (int)floor($index / 26) - 1;
        }
        
        return $letter;
    }
}
