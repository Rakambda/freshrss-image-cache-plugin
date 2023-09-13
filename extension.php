<?php

class ImageCacheExtension extends Minz_Extension
{
    // Defaults
    const DEFAULT_CACHE_URL = "https://example.com/pic?url=";
    const DEFAULT_CACHE_POST_URL = "https://example.com/prepare";
    const DEFAULT_CACHE_DISABLED_URL = "";
    const DEFAULT_CACHE_ACCESS_TOKEN = "";
    const DEFAULT_URL_ENCODE = "1";

    public function init(): void
    {
        $this->registerHook("entry_before_display", [$this, "content_modification_hook"]);
        $this->registerHook("entry_before_insert", [$this, "image_upload_hook"]);

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
        if (is_null(FreshRSS_Context::$user_conf->image_cache_disabled_url)) {
            FreshRSS_Context::$user_conf->image_cache_disabled_url = self::DEFAULT_CACHE_DISABLED_URL;
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
            FreshRSS_Context::$user_conf->image_cache_disabled_url = Minz_Request::param("image_cache_disabled_url", self::DEFAULT_CACHE_DISABLED_URL);
            FreshRSS_Context::$user_conf->image_cache_access_token = Minz_Request::param("image_cache_access_token", self::DEFAULT_CACHE_ACCESS_TOKEN);
            FreshRSS_Context::$user_conf->image_cache_url_encode = Minz_Request::param("image_cache_url_encode", "");
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function content_modification_hook($entry)
    {
        $entry->_content(self::swapUrls($entry->content()));
        return $entry;
    }

    public function image_upload_hook($entry)
    {
        self::uploadUrls($entry->content());
        return $entry;
    }

    private function swapUrls(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc,
            [$this, "getCachedUrl"],
            [$this, "getCachedSetUrl"],
            [$this, "getCachedUrl"]
        );

        return $doc->saveHTML();
    }

    private function uploadUrls(string $content): void
    {
        if (empty($content)) {
            return;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc,
            [$this, "uploadUrl"],
            [$this, "uploadSetUrls"],
            [$this, "uploadUrl"]
        );
    }

    private function append_after(DOMNode $node, DOMNode $add): DOMNode|false
    {
        try {
            return $node->parentNode->insertBefore($add, $node->nextSibling);
        } catch (\Exception $e) {
            return $node->parentNode->appendChild($add);
        }
    }

    private function handleImages(DOMDocument $doc, callable $imgCallback, callable $imgSetCallback, callable $videoCallback)
    {
        $images = $doc->getElementsByTagName("img");
        foreach ($images as $image) {
            if ($image->hasAttribute("src")) {
                $src = $image->getAttribute("src");
                if ($this->isDisabled($src)) {
                    continue;
                }

                Minz_Log::debug("ImageCache: found image $src");
                $result = $imgCallback($src);
                if ($result) {
                    $image->setAttribute("previous-src", $src);
                    $image->setAttribute("src", $result);
                    Minz_Log::debug("ImageCache: replaced with $result");
                }
            }
            if ($image->hasAttribute("srcset")) {
                $srcSet = $image->getAttribute("srcset");
                Minz_Log::debug("ImageCache: found image set $srcSet");
                $result = preg_replace_callback("/(?:([^\s,]+)(\s*(?:\s+\d+[wx])(?:,\s*)?))/", $imgSetCallback, $srcSet);
                $result = array_filter($result);
                if ($result) {
                    $image->setAttribute("previous-srcset", $srcSet);
                    $image->setAttribute("srcset", $result);
                    Minz_Log::debug("ImageCache: replaced with $result");
                }
            }
        }

        $videos = $doc->getElementsByTagName("video");
        foreach ($videos as $video) {
            Minz_Log::debug("ImageCache: found video");

            if (!$video->hasAttribute("controls")) {
                $video->setAttribute('controls', 'true');
            }

            foreach ($video->childNodes as $source) {
                if ($source->nodeName != 'source') {
                    continue;
                }

                if (!$source->hasAttribute("src")) {
                    continue;
                }
                
                $src = $source->getAttribute("src");
                Minz_Log::debug("ImageCache: found video source $src");
                $result = $videoCallback($src);
                if ($result) {
                    $source->setAttribute("previous-src", $src);
                    $source->setAttribute("src", $result);
                    Minz_Log::debug("ImageCache: replaced with $result");
                }
            }
        }

        $links = $doc->getElementsByTagName("a");
        foreach ($links as $link) {
            if (!$link->hasAttribute("href")) {
                continue;
            }
            $href = $link->getAttribute("href");
            
            if (!$this->isImageLink($href) && !$this->isVideoLink($href)) {
                continue;
            }
            
            Minz_Log::debug("ImageCache: found link $href");
            $result = $imgCallback($href);
            if (!$result) {
                continue;
            }
            
            if($this->isVideoLink($href)) {
                $this->append_video($doc, $link, $href, $result);
            }
            else {
                $this->append_image($doc, $link, $href, $result);
            }
        }
    }

    private function append_image(DOMDocument $doc, DOMNode $node, string $originalLink, string $newLink)
    {
        try {
            $image = $doc->createElement('img');
            $image->setAttribute('src', $newLink);
            $image->setAttribute('previous-src', $originalLink);
            $image->setAttribute('class', 'reddit-image');

            $this->append_after($node, $this->wrap_element($doc, $image));
            Minz_Log::debug("ImageCache: added image link with $result");
        } catch (Exception $e) {
            Minz_Log::error("Failed to create new DOM element $e");
        }
    }

    private function append_video(DOMDocument $doc, DOMNode $node, string $originalLink, string $newLink)
    {
        try {
            $source = $doc->createElement('source');
            $source->setAttribute('src', $newLink);
            $source->setAttribute('previous-src', $originalLink);

            $video = $doc->createElement('video');
            $video->setAttribute('controls', 'true');
            $video->setAttribute('class', 'reddit-image');
            $video->appendChild($source);

            $this->append_after($node, $this->wrap_element($doc, $video));
            Minz_Log::debug("ImageCache: added video link with $result");
        } catch (Exception $e) {
            Minz_Log::error("Failed to create new DOM element $e");
        }
    }

    /**
     * @throws DOMException
     */
    private function wrap_element(DOMDocument $doc, DOMNode $node): DOMNode
    {
        $div = $doc->createElement('div');
        $div->appendChild($node);
        return $div;
    }

    private function getCachedSetUrl(array $matches): string
    {
        return str_replace($matches[1], self::getCachedUrl($matches[1]), $matches[0]);
    }

    private function getCachedUrl(string $url): string
    {
        $url = rawurlencode($url);
        $cache_url = FreshRSS_Context::$user_conf->image_cache_url . $url;

        //$ch = curl_init();
        //curl_setopt($ch, CURLOPT_URL, $cache_url);
        //curl_setopt($ch, CURLOPT_NOBODY, true);
        //curl_exec($ch);

        //$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //curl_close($ch);

        //if ($code == 404) {
        //    $this->uploadUrl($url);
        //}

        return $cache_url;
    }

    private function uploadSetUrls(array $matches): void
    {
        self::uploadUrl($matches[1]);
    }

    private function uploadUrl(string $to_cache_cache_url): void
    {
        self::postUrl(FreshRSS_Context::$user_conf->image_cache_post_url, [
            "access_token" => FreshRSS_Context::$user_conf->image_cache_access_token,
            "url" => $to_cache_cache_url
        ]);
    }

    private function postUrl(string $url, array $data): void
    {
        $data = json_encode($data);
        $dataLength = strlen($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json;charset='utf-8'",
            "Content-Length: $dataLength",
            "Accept: application/json"
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_exec($curl);
        curl_close($curl);
    }

    private function loadContentAsDOM(string $content): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // prevent tag soup errors from showing
        $newContent = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        $doc->loadHTML($newContent);
        return $doc;
    }

    private function isDisabled(string $src): bool
    {
        $disabledStr = FreshRSS_Context::$user_conf->image_cache_disabled_url;
        if (!$disabledStr) {
            return false;
        }

        $disabledEntries = explode(',', $disabledStr);
        foreach ($disabledEntries as $disabledEntry) {
            if (str_contains($src, $disabledEntry)) {
                return true;
            }
        }
        return false;
    }

    private function isVideoLink(string $src): bool
    {
        $parsed_url = parse_url($src);
        if(str_contains($parsed_url['host'], 'redgifs.com')) {
            return true;
        }
        if(str_ends_with($parsed_url['path'], ".gifv")){
            return true;
        }
        return false;
    }

    private function isImageLink(string $src): bool
    {
        $parsed_url = parse_url($src);
        if (str_contains($parsed_url['host'], 'imgur.com')) {
            return true;
        }
        if (str_contains($parsed_url['host'], 'i.redd.it')) {
            return true;
        }
        return false;
    }
}
