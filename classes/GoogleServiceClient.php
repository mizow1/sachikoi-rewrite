<?php
/**
 * Google APIとのサービスアカウント認証を管理するクラス
 */
class GoogleServiceClient {
    private $client;
    private $accessToken;
    private $tokenExpires;
    
    /**
     * コンストラクタ
     * 
     * @param string $serviceAccountFile サービスアカウントのJSONファイルパス
     */
    public function __construct($serviceAccountFile) {
        error_log("GoogleServiceClient: サービスアカウントファイルのパス: {$serviceAccountFile}");
        
        if (!file_exists($serviceAccountFile)) {
            error_log("GoogleServiceClient: ファイルが存在しません: {$serviceAccountFile}");
            throw new Exception('サービスアカウントのJSONファイルが見つかりません: ' . $serviceAccountFile);
        } else {
            error_log("GoogleServiceClient: ファイルが存在します: {$serviceAccountFile}");
        }
        
        $this->initClient($serviceAccountFile);
    }
    
    /**
     * クライアントの初期化
     * 
     * @param string $serviceAccountFile サービスアカウントのJSONファイルパス
     */
    private function initClient($serviceAccountFile) {
        error_log("GoogleServiceClient: JSONファイルの内容を読み込みます");
        $serviceAccount = json_decode(file_get_contents($serviceAccountFile), true);
        
        if (!$serviceAccount) {
            error_log("GoogleServiceClient: JSONの解析に失敗しました: " . json_last_error_msg());
            throw new Exception('サービスアカウントのJSONファイルの解析に失敗しました: ' . json_last_error_msg());
        }
        
        error_log("GoogleServiceClient: クライアントメール: " . ($serviceAccount['client_email'] ?? 'なし'));
        
        if (!isset($serviceAccount['client_email']) || !isset($serviceAccount['private_key'])) {
            error_log("GoogleServiceClient: 必要なフィールドがJSONファイルにありません");
            throw new Exception('サービスアカウントのJSONファイルが不正です。必要なフィールドがありません。');
        }
        
        error_log("GoogleServiceClient: サービスアカウント情報の読み込みに成功しました");
        $this->getAccessToken($serviceAccount);
    }
    
    /**
     * アクセストークンを取得
     * 
     * @param array $serviceAccount サービスアカウント情報
     */
    private function getAccessToken($serviceAccount) {
        error_log("GoogleServiceClient: アクセストークンの取得を開始します");
        
        // トークンが有効期限内かチェック
        if ($this->accessToken && $this->tokenExpires > time()) {
            error_log("GoogleServiceClient: 有効なトークンが存在します。再利用します");
            return $this->accessToken;
        }
        
        // JWTの作成
        $now = time();
        $expiry = $now + 3600; // 1時間の有効期限
        
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        $claim = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $expiry,
            'iat' => $now
        ];
        
        error_log("GoogleServiceClient: JWTクレームを生成しました: " . json_encode($claim));
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $claimEncoded = $this->base64UrlEncode(json_encode($claim));
        
        $signature = '';
        $dataToSign = $headerEncoded . '.' . $claimEncoded;
        
        error_log("GoogleServiceClient: JWTに署名します");
        
        // 秘密鍵が正しい形式か確認
        error_log("GoogleServiceClient: 秘密鍵の長さ: " . strlen($serviceAccount['private_key']));
        
        // 秘密鍵で署名を試みる
        try {
            // 修正した方法で署名を行う
            $key = $serviceAccount['private_key'];
            $key = str_replace('\\n', "\n", $key);  // Windowsでの改行コードの問題を修正
            
            error_log("GoogleServiceClient: 秘密鍵の先頭部分: " . substr($key, 0, 50));
            
            if (!openssl_sign($dataToSign, $signature, $key, 'SHA256')) {
                $error = openssl_error_string();
                error_log("GoogleServiceClient: JWTの署名に失敗しました: {$error}");
                throw new Exception('JWTの署名に失敗しました: ' . $error);
            }
        } catch (Exception $e) {
            error_log("GoogleServiceClient: 署名例外発生: " . $e->getMessage());
            throw $e;
        }
        
        error_log("GoogleServiceClient: JWTの署名に成功しました");
        
        $signatureEncoded = $this->base64UrlEncode($signature);
        $jwt = $headerEncoded . '.' . $claimEncoded . '.' . $signatureEncoded;
        
        // アクセストークンの取得
        error_log("GoogleServiceClient: トークンリクエストを送信します");
        $response = $this->makeTokenRequest($jwt);
        
        if (!isset($response['access_token'])) {
            error_log("GoogleServiceClient: アクセストークンの取得に失敗しました: " . json_encode($response));
            throw new Exception('アクセストークンの取得に失敗しました: ' . json_encode($response));
        }
        
        error_log("GoogleServiceClient: アクセストークンの取得に成功しました");
        $this->accessToken = $response['access_token'];
        $this->tokenExpires = $now + $response['expires_in'];
        
        return $this->accessToken;
    }
    
    /**
     * トークンリクエストを送信
     * 
     * @param string $jwt JWT
     * @return array レスポンス
     */
    private function makeTokenRequest($jwt) {
        error_log("GoogleServiceClient: トークンリクエストを開始します");
        
        $url = 'https://oauth2.googleapis.com/token';
        $data = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ];
        
        error_log("GoogleServiceClient: トークンリクエストURL: {$url}");
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        
        $context = stream_context_create($options);
        error_log("GoogleServiceClient: トークンリクエストを送信します");
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log("GoogleServiceClient: トークンリクエストに失敗しました: " . ($error ? $error['message'] : '不明なエラー'));
            throw new Exception('トークンリクエストに失敗しました: ' . ($error ? $error['message'] : '不明なエラー'));
        }
        
        $decoded = json_decode($response, true);
        error_log("GoogleServiceClient: トークンレスポンス: " . json_encode($decoded));
        
        return $decoded;
    }
    
    /**
     * Base64 URL エンコード
     * 
     * @param string $data エンコードするデータ
     * @return string エンコードされたデータ
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * アクセストークンの取得
     * 
     * @return string アクセストークン
     */
    public function getToken() {
        return $this->accessToken;
    }
    
    /**
     * Google Sheets APIにリクエストを送信
     * 
     * @param string $method HTTPメソッド
     * @param string $url リクエストURL
     * @param array $data リクエストデータ
     * @return array レスポンス
     */
    public function request($method, $url, $data = null) {
        error_log("GoogleServiceClient: APIリクエストを開始します: {$method} {$url}");
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        $options = [
            'http' => [
                'header' => implode("\r\n", $headers),
                'method' => $method,
                'ignore_errors' => true
            ]
        ];
        
        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
            error_log("GoogleServiceClient: リクエストデータ: " . json_encode($data));
        }
        
        $context = stream_context_create($options);
        error_log("GoogleServiceClient: APIリクエストを送信します");
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log("GoogleServiceClient: APIリクエストに失敗しました: " . ($error ? $error['message'] : '不明なエラー'));
            throw new Exception('APIリクエストに失敗しました: ' . ($error ? $error['message'] : '不明なエラー'));
        }
        
        $decoded = json_decode($response, true);
        error_log("GoogleServiceClient: APIレスポンス: " . json_encode($decoded));
        
        return $decoded;
    }
}
