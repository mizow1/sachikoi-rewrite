<?php
/**
 * Google Sheetsとの連携を管理するクラス
 */
class GoogleSheetsManager {
    private $apiKey;
    private $spreadsheetId;
    private $sheetName;
    
    /**
     * コンストラクタ
     */
    public function __construct($apiKey, $spreadsheetId, $sheetName) {
        $this->apiKey = $apiKey;
        $this->spreadsheetId = $spreadsheetId;
        $this->sheetName = $sheetName;
    }
    
    /**
     * スプレッドシートからデータを取得
     * 
     * @return array 取得したデータ
     */
    public function getSheetData() {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$this->sheetName}?key={$this->apiKey}";
        
        $response = file_get_contents($url);
        if ($response === false) {
            throw new Exception('スプレッドシートからデータを取得できませんでした。');
        }
        
        $data = json_decode($response, true);
        if (!isset($data['values'])) {
            throw new Exception('スプレッドシートのデータ形式が不正です。');
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
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$this->sheetName}!{$range}?valueInputOption=RAW&key={$this->apiKey}";
        
        $data = [
            'values' => $values
        ];
        
        $options = [
            'http' => [
                'method' => 'PUT',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('スプレッドシートへのデータ書き込みに失敗しました。');
        }
        
        return true;
    }
    
    /**
     * 特定の行にデータを追加
     * 
     * @param int $rowIndex 行番号（0から始まる）
     * @param array $rowData 追加するデータ
     * @return bool 成功したかどうか
     */
    public function appendRowData($rowIndex, $rowData) {
        // 現在のデータを取得して、指定された行のデータを更新
        $data = $this->getSheetData();
        
        // 行が存在しない場合はエラー
        if (!isset($data[$rowIndex])) {
            throw new Exception('指定された行が存在しません。');
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
