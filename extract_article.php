<?php
/**
 * 記事改善管理システム - 記事取得ユーティリティ
 * article_bodyクラスを持つ要素から記事本文を完全に取得するための関数
 */

/**
 * HTMLから記事本文を抽出する関数
 * 複数の方法を試して確実に記事本文を取得する
 * 
 * @param string $html HTML内容
 * @return array タイトルと本文の配列
 */
function extractArticleContent($html) {
    $title = '';
    $body = '';
    
    // タイトルの抽出
    preg_match('/<[^>]*class=["\'].*?article_title.*?["\'][^>]*>(.*?)<\/[^>]*>/is', $html, $titleMatches);
    if (!empty($titleMatches[1])) {
        $title = strip_tags($titleMatches[1]);
    }
    
    // 本文の抽出 - 複数の方法を試す
    // 方法1: DOMDocumentを使用して記事本文のみを取得
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    
    // article_bodyクラスを持つ要素を取得
    $articleBodyElements = $xpath->query("//*[contains(@class, 'article_body')]");
    
    if ($articleBodyElements->length > 0) {
        // article_body要素内のパラグラフ要素のみを取得
        $body = '';
        foreach ($articleBodyElements as $element) {
            // パラグラフ要素を取得
            $paragraphs = $xpath->query(".//p", $element);
            if ($paragraphs->length > 0) {
                foreach ($paragraphs as $p) {
                    // リンク要素などの関連記事を除外するためのチェック
                    $pText = trim(strip_tags($dom->saveHTML($p)));
                    
                    // 空のパラグラフや「関連の夢」などのキーワードを除外
                    if (!empty($pText) && strpos($pText, '関連の夢') === false && $pText !== '&nbsp;') {
                        $body .= $pText . "\n\n";
                    }
                }
            } else {
                // パラグラフが見つからない場合は、テキストノードを取得
                $bodyText = trim(strip_tags($dom->saveHTML($element)));
                if (!empty($bodyText)) {
                    // 余分な空白を除去
                    $bodyText = preg_replace('/\s+/', ' ', $bodyText);
                    $body .= $bodyText . "\n\n";
                }
            }
        }
        
        // デバッグ用
        error_log("article_bodyの内容を取得しました（方法１）。長さ: " . strlen($body));
    }
    
    // 方法1が失敗した場合、方法2を試す
    if (empty($body)) {
        // 方法2: 正規表現でパラグラフを抽出
        preg_match('/<div[^>]*class=["\'].*?article_body.*?["\'][^>]*>([\s\S]*?)<\/div>/is', $html, $bodyMatches);
        if (!empty($bodyMatches[1])) {
            // パラグラフ要素を抽出
            preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $bodyMatches[1], $paragraphs);
            if (!empty($paragraphs[1])) {
                $body = '';
                foreach ($paragraphs[1] as $p) {
                    $pText = trim(strip_tags($p));
                    // 空のパラグラフや「関連の夢」などのキーワードを除外
                    if (!empty($pText) && strpos($pText, '関連の夢') === false && $pText !== '&nbsp;') {
                        $body .= $pText . "\n\n";
                    }
                }
            } else {
                // パラグラフが見つからない場合は、テキストを取得
                $body = strip_tags($bodyMatches[1]);
            }
            
            // デバッグ用
            error_log("article_bodyの内容を取得しました（方法２）。長さ: " . strlen($body));
        }
    }
    
    // 方法2も失敗した場合、方法3を試す
    if (empty($body)) {
        // 方法3: preg_match_allですべての.article_body要素を取得
        preg_match_all('/<div[^>]*class=["\'].*?article_body.*?["\'][^>]*>([\s\S]*?)<\/div>/i', $html, $allBodyMatches);
        if (!empty($allBodyMatches[1])) {
            $body = '';
            foreach ($allBodyMatches[1] as $match) {
                // パラグラフ要素を抽出
                preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $match, $paragraphs);
                if (!empty($paragraphs[1])) {
                    foreach ($paragraphs[1] as $p) {
                        $pText = trim(strip_tags($p));
                        // 空のパラグラフや「関連の夢」などのキーワードを除外
                        if (!empty($pText) && strpos($pText, '関連の夢') === false && $pText !== '&nbsp;') {
                            $body .= $pText . "\n\n";
                        }
                    }
                } else {
                    // パラグラフが見つからない場合は、テキストを取得
                    $bodyText = trim(strip_tags($match));
                    if (!empty($bodyText)) {
                        $body .= $bodyText . "\n\n";
                    }
                }
            }
            
            // デバッグ用
            error_log("article_bodyの内容を取得しました（方法３）。長さ: " . strlen($body));
        }
    }
    
    // すべての方法が失敗した場合のバックアップ方法
    if (empty($body)) {
        // 方法4: 別の方法を試す
        preg_match('/<div[^>]*class=["\'].*?article_body.*?["\'][^>]*>/s', $html, $startTag);
        if (!empty($startTag[0])) {
            $startPos = strpos($html, $startTag[0]);
            $endPos = strpos($html, '</div>', $startPos);
            
            if ($startPos !== false && $endPos !== false) {
                $bodyHtml = substr($html, $startPos, $endPos - $startPos + 6); // 6 = '</div>'の長さ
                $body = strip_tags($bodyHtml);
                
                // デバッグ用
                error_log("article_bodyの内容を取得しました（方法４）。長さ: " . strlen($body));
            }
        }
    }
    
    // 余分な空白や特殊文字を整理
    $body = trim(preg_replace('/\s+/', ' ', $body));
    
    // 「関連の夢」以降のテキストを除外
    $relatedPos = strpos($body, '関連の夢');
    if ($relatedPos !== false) {
        $body = substr($body, 0, $relatedPos);
    }
    
    // 「あなたは他にどんな夢を見ましたか？」以降のテキストを除外
    $otherDreamsPos = strpos($body, 'あなたは他にどんな夢を見ましたか？');
    if ($otherDreamsPos !== false) {
        $body = substr($body, 0, $otherDreamsPos);
    }
    
    return [
        'title' => $title,
        'body' => $body
    ];
}
