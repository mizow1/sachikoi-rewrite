<?php
/**
 * サービスアカウントのJSONファイルを修正するスクリプト
 */

// 元のJSONファイルのパス
$sourceFile = 'curious-subject-423402-k5-a94bf9c8c963.json';
$targetFile = 'curious-subject-423402-k5-a94bf9c8c963-fixed.json';

// JSONファイルが存在するか確認
if (!file_exists($sourceFile)) {
    die('元のJSONファイルが見つかりません: ' . $sourceFile);
}

// JSONファイルの内容を読み込む
$content = file_get_contents($sourceFile);
echo "元のJSONファイルを読み込みました。\n";

// JSONとして解析
$json = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSONの解析に失敗しました: " . json_last_error_msg() . "\n";
    
    // 改行コードの問題を修正して再試行
    $content = str_replace('\n', "\\n", $content);
    $json = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "修正後もJSONの解析に失敗しました: " . json_last_error_msg() . "\n";
        
        // 直接ファイルを作成
        echo "手動でJSONファイルを作成します...\n";
        
        // 必要な情報を取得
        preg_match('/"type"\s*:\s*"([^"]+)"/', $content, $typeMatches);
        preg_match('/"project_id"\s*:\s*"([^"]+)"/', $content, $projectIdMatches);
        preg_match('/"private_key_id"\s*:\s*"([^"]+)"/', $content, $privateKeyIdMatches);
        preg_match('/"private_key"\s*:\s*"([^"]*)"/', $content, $privateKeyMatches);
        preg_match('/"client_email"\s*:\s*"([^"]+)"/', $content, $clientEmailMatches);
        preg_match('/"client_id"\s*:\s*"([^"]+)"/', $content, $clientIdMatches);
        
        $type = $typeMatches[1] ?? 'service_account';
        $projectId = $projectIdMatches[1] ?? 'curious-subject-423402-k5';
        $privateKeyId = $privateKeyIdMatches[1] ?? '';
        $privateKey = $privateKeyMatches[1] ?? '';
        $clientEmail = $clientEmailMatches[1] ?? 'mizy-372@curious-subject-423402-k5.iam.gserviceaccount.com';
        $clientId = $clientIdMatches[1] ?? '';
        
        // 秘密鍵の改行コードを修正
        $privateKey = str_replace('\\n', "\n", $privateKey);
        
        // 新しいJSONを作成
        $newJson = [
            'type' => $type,
            'project_id' => $projectId,
            'private_key_id' => $privateKeyId,
            'private_key' => $privateKey,
            'client_email' => $clientEmail,
            'client_id' => $clientId,
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => "https://www.googleapis.com/robot/v1/metadata/x509/{$clientEmail}",
            'universe_domain' => 'googleapis.com'
        ];
        
        // 新しいJSONファイルを書き込む
        file_put_contents($targetFile, json_encode($newJson, JSON_PRETTY_PRINT));
        echo "新しいJSONファイルを作成しました: {$targetFile}\n";
        
        // .envファイルのパスを更新するための指示
        echo "\n.envファイルの SERVICE_ACCOUNT_JSON の値を {$targetFile} に更新してください。\n";
        die();
    }
}

echo "JSONの解析に成功しました。\n";

// 秘密鍵の改行コードを修正
if (isset($json['private_key'])) {
    $json['private_key'] = str_replace('\\n', "\n", $json['private_key']);
    echo "秘密鍵の改行コードを修正しました。\n";
} else {
    echo "秘密鍵が見つかりません。\n";
}

// 修正したJSONを書き込む
file_put_contents($targetFile, json_encode($json, JSON_PRETTY_PRINT));
echo "修正したJSONファイルを作成しました: {$targetFile}\n";

// .envファイルのパスを更新するための指示
echo "\n.envファイルの SERVICE_ACCOUNT_JSON の値を {$targetFile} に更新してください。\n";
