<?php
/**
 * 記事改善管理システム - 記事取得ヘルパー関数
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
    // 方法1: article_bodyクラスを持つdiv全体を取得
    if (preg_match('/<div[^>]*class=["\'].*?article_body.*?["\'][^>]*>([\s\S]*?)<\/div>/is', $html, $bodyMatches)) {
        // HTMLタグを除去
        $body = strip_tags($bodyMatches[0]);
        
        // デバッグ用
        error_log("article_bodyの内容を取得しました（方法１）。長さ: " . strlen($body));
    } else {
        // 方法2: DOMDocumentを使用して取得
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        
        // article_bodyクラスを持つ要素を取得
        $articleBodyElements = $xpath->query("//*[contains(@class, 'article_body')]");
        
        if ($articleBodyElements->length > 0) {
            $body = '';
            foreach ($articleBodyElements as $element) {
                $body .= $dom->saveHTML($element);
            }
            $body = strip_tags($body);
            
            // デバッグ用
            error_log("article_bodyの内容を取得しました（方法２）。長さ: " . strlen($body));
        } else {
            // 方法3: preg_match_allですべての.article_body要素を取得
            preg_match_all('/<div[^>]*class=["\'].*?article_body.*?["\'][^>]*>([\s\S]*?)<\/div>/i', $html, $allBodyMatches);
            if (!empty($allBodyMatches[1])) {
                $body = '';
                foreach ($allBodyMatches[1] as $match) {
                    $body .= strip_tags($match) . "\n\n";
                }
                
                // デバッグ用
                error_log("article_bodyの内容を取得しました（方法３）。長さ: " . strlen($body));
            } else {
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
        }
    }
    
    return [
        'title' => $title,
        'body' => $body
    ];
}
