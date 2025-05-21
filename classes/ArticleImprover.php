<?php
/**
 * 記事の改善を行うクラス
 */
class ArticleImprover {
    private $apiKey;
    private $model;
    
    /**
     * コンストラクタ
     */
    public function __construct($apiKey, $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }
    
    /**
     * 記事の問題点を分析
     * 
     * @param string $articleContent 記事の内容
     * @param array $searchConsoleData サーチコンソールデータ
     * @return string 問題点の分析結果
     */
    public function analyzeArticleIssues($articleContent, $searchConsoleData) {
        $prompt = $this->createAnalysisPrompt($articleContent, $searchConsoleData);
        return $this->callOpenAI($prompt);
    }
    
    /**
     * 記事を改善
     * 
     * @param string $articleContent 元の記事の内容
     * @param string $issues 問題点の分析結果
     * @return string 改善された記事
     */
    public function improveArticle($articleContent, $issues) {
        $prompt = $this->createImprovementPrompt($articleContent, $issues);
        return $this->callOpenAI($prompt);
    }
    
    /**
     * 分析用のプロンプトを作成
     * 
     * @param string $articleContent 記事の内容
     * @param array $searchConsoleData サーチコンソールデータ
     * @return string プロンプト
     */
    private function createAnalysisPrompt($articleContent, $searchConsoleData) {
        $url = $searchConsoleData['url'] ?? '';
        $impressions = $searchConsoleData['impressions'] ?? 0;
        $clicks = $searchConsoleData['clicks'] ?? 0;
        $ctr = $searchConsoleData['ctr'] ?? 0;
        $position = $searchConsoleData['position'] ?? 0;
        
        return <<<PROMPT
あなたはSEOと記事改善の専門家です。以下の記事を分析し、表示回数が少ない理由と改善すべき点を詳細に説明してください。

【URL】
{$url}

【サーチコンソールデータ】
表示回数: {$impressions}
クリック数: {$clicks}
CTR: {$ctr}
平均掲載順位: {$position}

【記事内容】
{$articleContent}

【分析依頼】
1. この記事の問題点（構成、内容、キーワード選定、ユーザー意図への対応など）
2. 検索エンジンでの表示回数が少ない理由
3. 具体的な改善点

箇条書きで簡潔に回答してください。
PROMPT;
    }
    
    /**
     * 改善用のプロンプトを作成
     * 
     * @param string $articleContent 元の記事の内容
     * @param string $issues 問題点の分析結果
     * @return string プロンプト
     */
    private function createImprovementPrompt($articleContent, $issues) {
        return <<<PROMPT
あなたはSEOと記事改善の専門家です。以下の記事を分析結果に基づいて改善してください。

【元の記事内容】
{$articleContent}

【分析された問題点】
{$issues}

【改善依頼】
上記の問題点を解決し、より検索エンジンに評価され、ユーザーにとって価値のある記事に書き直してください。
元の記事の良い部分は残しつつ、必要に応じて内容を追加・修正してください。
見出し構成も適切に修正してください。

改善した記事全文を出力してください。
PROMPT;
    }
    
    /**
     * OpenAI APIを呼び出す
     * 
     * @param string $prompt プロンプト
     * @return string APIからの応答
     */
    private function callOpenAI($prompt) {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'あなたはSEOと記事改善の専門家です。日本語で回答してください。'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey
                ],
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('OpenAI APIからの応答を取得できませんでした。');
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('OpenAI APIからの応答形式が不正です。');
        }
        
        return $result['choices'][0]['message']['content'];
    }
}