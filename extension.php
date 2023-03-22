<?php

class ImageCacheExtension extends Minz_Extension
{
    // Defaults
    const DEFAULT_CACHE_URL = "https://example.com/pic?url=";
    const DEFAULT_CACHE_POST_URL = "https://example.com/prepare";
    const DEFAULT_CACHE_ACCESS_TOKEN = "";
    const DEFAULT_URL_ENCODE = "1";

    public function init(): void
    {
        $this->registerHook("entry_before_display", array($this, "content_modification_hook"));
        $this->registerHook("entry_before_insert", array($this, "image_upload_hook"));

        // Defaults
        $save = false;
        FreshRSS_Context::$user_conf->image_cache_url = html_entity_decode(FreshRSS_Context::$user_conf->image_cache_url);
        if (is_null(FreshRSS_Context::$user_conf->image_cache_url)) {
            FreshRSS_Context::$user_conf->image_cache_url = self::DEFAULT_CACHE_URL;
            $save = true;
        }
        if (is_null(FreshRSS_Context::$user_conf->image_cache_post_url)) {
            FreshRSS_Context::$user_conf->image_cache_post_url = self::DEFAULT_CACHE_POST_URL;
            $save = true;
        }
        if (is_null(FreshRSS_Context::$user_conf->image_cache_post_url)) {
            FreshRSS_Context::$user_conf->image_cache_access_token = self::DEFAULT_CACHE_ACCESS_TOKEN;
            $save = true;
        }
        if (is_null(FreshRSS_Context::$user_conf->image_cache_url_encode)) {
            FreshRSS_Context::$user_conf->image_cache_url_encode = self::DEFAULT_URL_ENCODE;
            $save = true;
        }

        if ($save) {
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::$user_conf->image_cache_url = Minz_Request::param("image_cache_url", self::DEFAULT_CACHE_URL);
            FreshRSS_Context::$user_conf->image_cache_post_url = Minz_Request::param("image_cache_post_url", self::DEFAULT_CACHE_POST_URL);
            FreshRSS_Context::$user_conf->image_cache_access_token = Minz_Request::param("image_cache_access_token", self::DEFAULT_CACHE_ACCESS_TOKEN);
            FreshRSS_Context::$user_conf->image_cache_url_encode = Minz_Request::param("image_cache_url_encode", "");
            FreshRSS_Context::$user_conf->save();
        }
    }

    public static function content_modification_hook($entry)
    {
        $entry->_content(self::swapUrls($entry->content()));
        return $entry;
    }

    public static function image_upload_hook($entry)
    {
        self::uploadUrls($entry->content());
        return $entry;
    }

    private static function swapUrls(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $doc = self::loadContentAsDOM($content);

        self::handleImages($doc, "self::getCachedUrl", "self::getCachedSetUrl", "self::getCachedUrl");

        return $doc->saveHTML();
    }

    private static function uploadUrls(string $content): void
    {
        if (empty($content)) {
            return;
        }

        $doc = self::loadContentAsDOM($content);

        self::handleImages($doc, "self::uploadUrl", "self::uploadSetUrls", "self::uploadUrl");
    }

    private static function handleImages(DOMDocument $doc, callable $imgCallback, callable $imgSetCallback, callable $videoCallback)
    {
        $images = $doc->getElementsByTagName("img");
        foreach ($images as $image) {
            if ($image->hasAttribute("src")) {
                $result = $imgCallback($image->getAttribute("src"));
                if ($result) {
                    $image->setAttribute("src", $result);
                }
            }
            if ($image->hasAttribute("srcset")) {
                $result = preg_replace_callback("/(?:([^\s,]+)(\s*(?:\s+\d+[wx])(?:,\s*)?))/", $imgSetCallback, $image->getAttribute("srcset"));
                $result = array_filter($result);
                if ($result) {
                    $image->setAttribute("srcset", $result);
                }
            }
        }

        $videos = $doc->getElementsByTagName("video");
        foreach ($videos as $video) {
            foreach ($video->childNodes as $source) {
                if ($source->nodeName != 'source') {
                    continue;
                }

                if ($video->hasAttribute("src")) {
                    $result = $videoCallback($video->getAttribute("src"));
                    if ($result) {
                        $video->setAttribute("src", $result);
                    }
                }
            }
        }
    }

    private static function getCachedSetUrl(array $matches): string
    {
        return str_replace($matches[1], self::getCachedUrl($matches[1]), $matches[0]);
    }

    private static function getCachedUrl(string $url): string
    {
        $url = rawurlencode($url);
        return FreshRSS_Context::$user_conf->image_cache_url . $url;
    }

    private static function uploadSetUrls(array $matches): void
    {
        self::uploadUrl($matches[1]);
    }

    private static function uploadUrl(string $to_cache_cache_url): void
    {
        self::postUrl(FreshRSS_Context::$user_conf->image_cache_post_url, [
            "access_token" => FreshRSS_Context::$user_conf->image_cache_access_token,
            "url" => $to_cache_cache_url
        ]);
    }

    private static function postUrl(string $url, array $data): void
    {
        $data = json_encode($data);
        $dataLength = strlen($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Content-Type: application/json;charset='utf-8'",
                "Content-Length: $dataLength",
                "Accept: application/json")
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_exec($curl);
        curl_close($curl);
    }

    private static function loadContentAsDOM(string $content): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // prevent tag soup errors from showing
        $doc->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        return $doc;
    }
}
