<?php

class ImageCacheExtension extends Minz_Extension
{
    // Defaults
    const DEFAULT_CACHE_URL = "https://example.com/pic?url=";
    const DEFAULT_CACHE_POST_URL = "https://example.com/prepare";
    const DEFAULT_CACHE_DISABLED_URL = "";
    const DEFAULT_RECACHE_URL = "";
    const DEFAULT_CACHE_ACCESS_TOKEN = "";
    const DEFAULT_URL_ENCODE = "1";

    const MAX_CACHED = 500;
    public array $CACHE = [];

    /**
     * @throws Minz_ConfigurationParamException
     * @throws FreshRSS_Context_Exception
     */
    public function init(): void
    {
        $this->registerCss();

        $this->registerHook("entry_before_display", [$this, "content_modification_hook"]);
        $this->registerHook("entry_before_insert", [$this, "image_upload_hook"]);

        // Defaults
        $save = false;
        FreshRSS_Context::userConf()->_param("image_cache_url", html_entity_decode(FreshRSS_Context::userConf()->param("image_cache_url")));
        if (is_null(FreshRSS_Context::userConf()->param("image_cache_url"))) {
            FreshRSS_Context::userConf()->_param("image_cache_url", self::DEFAULT_CACHE_URL);
            $save = true;
        }
        if (is_null(FreshRSS_Context::userConf()->param("image_cache_post_url"))) {
            FreshRSS_Context::userConf()->_param("image_cache_post_url", self::DEFAULT_CACHE_POST_URL);
            $save = true;
        }
        if (is_null(FreshRSS_Context::userConf()->param("image_cache_disabled_url"))) {
            FreshRSS_Context::userConf()->_param("image_cache_disabled_url", self::DEFAULT_CACHE_DISABLED_URL);
            $save = true;
        }
        if (is_null(FreshRSS_Context::userConf()->param("image_recache_url"))) {
            FreshRSS_Context::userConf()->_param("image_recache_url", self::DEFAULT_RECACHE_URL);
            $save = true;
        }
        if (is_null(FreshRSS_Context::userConf()->param("image_cache_post_url"))) {
            FreshRSS_Context::userConf()->_param("image_cache_access_token", self::DEFAULT_CACHE_ACCESS_TOKEN);
            $save = true;
        }
        if (is_null(FreshRSS_Context::userConf()->param("image_cache_url_encode"))) {
            FreshRSS_Context::userConf()->_param("image_cache_url_encode", self::DEFAULT_URL_ENCODE);
            $save = true;
        }

        if ($save) {
            FreshRSS_Context::userConf()->save();
        }
    }

    private function registerCss(): void
    {
        $current_user = Minz_Session::paramString('currentUser');
        $css_file_name = "style.{$current_user}.css";
        $css_file_path = join(DIRECTORY_SEPARATOR, [$this->getPath(), 'static', $css_file_name]);
        file_put_contents($css_file_path, <<<EOT
img.cache-image, video.cache-image {
    min-width: 100px;
    min-height: 100px;
    max-height: 100vh;
    background-color: red;
}
EOT);

        if (file_exists($css_file_path)) {
            Minz_View::appendStyle($this->getFileUrl($css_file_name, 'css'));
        }
    }

    /**
     * @throws Minz_ConfigurationParamException
     * @throws FreshRSS_Context_Exception
     */
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::userConf()->param("image_cache_url", Minz_Request::paramString("image_cache_url") ?: self::DEFAULT_CACHE_URL);
            FreshRSS_Context::userConf()->param("image_cache_post_url", Minz_Request::paramString("image_cache_post_url") ?: self::DEFAULT_CACHE_POST_URL);
            FreshRSS_Context::userConf()->param("image_recache_url", Minz_Request::paramString("image_recache_url") ?: self::DEFAULT_RECACHE_URL);
            FreshRSS_Context::userConf()->param("image_cache_disabled_url", Minz_Request::paramString("image_cache_disabled_url") ?: self::DEFAULT_CACHE_DISABLED_URL);
            FreshRSS_Context::userConf()->param("image_cache_access_token", Minz_Request::paramString("image_cache_access_token") ?: self::DEFAULT_CACHE_ACCESS_TOKEN);
            FreshRSS_Context::userConf()->param("image_cache_url_encode", Minz_Request::paramString("image_cache_url_encode") ?: "");
            FreshRSS_Context::userConf()->save();
        }
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    public function content_modification_hook($entry)
    {
        $entry->_content(self::swapUrls($entry->content()));
        return $entry;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    public function image_upload_hook($entry)
    {
        self::uploadUrls($entry->content());
        return $entry;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function swapUrls(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc,
            "display",
            [$this, "getCachedUrl"],
            [$this, "getCachedSetUrl"],
            [$this, "getCachedUrl"]
        );

        return $doc->saveHTML();
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function uploadUrls(string $content): void
    {
        if (empty($content)) {
            return;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc,
            "insert",
            [$this, "uploadUrl"],
            [$this, "uploadSetUrls"],
            [$this, "uploadUrl"]
        );
    }

    private function appendAfter(DOMNode $node, DOMNode $add): DOMNode|false
    {
        try {
            return $node->parentNode->insertBefore($add, $node->nextSibling);
        } catch (\Exception $e) {
            return $node->parentNode->appendChild($add);
        }
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function handleImages(DOMDocument $doc, string $callSource, callable $imgCallback, callable $imgSetCallback, callable $videoCallback): void
    {
        Minz_Log::debug("ImageCache[$callSource]: Scanning new document");

        $images = $doc->getElementsByTagName("img");
        foreach ($images as $image) {
            if ($image->hasAttribute("src")) {
                $src = $image->getAttribute("src");
                if ($this->isDisabled($src)) {
                    Minz_Log::debug("ImageCache[$callSource]: Found disabled image $src");
                    continue;
                }

                Minz_Log::debug("ImageCache[$callSource]: Found image $src");
                $result = $imgCallback($src);
                if ($result) {
                    $image->setAttribute("previous-src", $src);
                    $image->setAttribute("src", $result);
                    $this->addClass($image, "cache-image");
                    Minz_Log::debug("ImageCache[$callSource]: Replaced image with $result");
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing image");
                }
            }
            if ($image->hasAttribute("srcset")) {
                $srcSet = $image->getAttribute("srcset");
                Minz_Log::debug("ImageCache[$callSource]: Found image set $srcSet");
                $result = preg_replace_callback("/(?:([^\s,]+)(\s*(?:\s+\d+[wx])(?:,\s*)?))/", $imgSetCallback, $srcSet);
                $result = array_filter($result);
                if ($result) {
                    $image->setAttribute("previous-srcset", $srcSet);
                    $image->setAttribute("srcset", $result);
                    $this->addClass($image, "cache-image");
                    Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing image set");
                }
            }
        }

        $audios = $doc->getElementsByTagName("video");
        foreach ($audios as $video) {
            Minz_Log::debug("ImageCache[$callSource]: Found video");

            if (!$video->hasAttribute("controls")) {
                $video->setAttribute('controls', 'true');
            }

            foreach ($video->childNodes as $source) {
                if ($source->nodeName == 'source') {
                    if (!$source->hasAttribute("src")) {
                        continue;
                    }

                    $src = $source->getAttribute("src");
                    Minz_Log::debug("ImageCache[$callSource]: Found video source $src");
                    $result = $videoCallback($src);
                    if ($result) {
                        $source->setAttribute("previous-src", $src);
                        $source->setAttribute("src", $result);
                        $this->addClass($video, "cache-image");
                        Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                    } else {
                        Minz_Log::debug("ImageCache[$callSource]: Failed replacing video source");
                    }
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
                Minz_Log::debug("ImageCache[$callSource]: Found skipped link $href");
                continue;
            }

            Minz_Log::debug("ImageCache[$callSource]: Found link $href");
            $result = $imgCallback($href);
            if (!$result) {
                Minz_Log::debug("ImageCache[$callSource]: Failed replacing link");
                continue;
            }

            if ($this->isVideoLink($href)) {
                $this->appendVideo($doc, $link, $href, $result);
            } else {
                $this->appendImage($doc, $link, $href, $result);
            }
        }

        Minz_Log::debug("ImageCache[$callSource]: Done scanning document");
    }

    private function addClass(DOMElement $node, string $clazz): void
    {
        if ($node->hasAttribute("controls")) {
            $previous = $node->getAttribute('class');
            $node->setAttribute('class', "$previous $clazz");
        } else {
            $node->setAttribute('class', $clazz);
        }
    }

    private function appendImage(DOMDocument $doc, DOMElement $node, string $originalLink, string $newLink): void
    {
        try {
            $image = $doc->createElement('img');
            $image->setAttribute('src', $newLink);
            $image->setAttribute('previous-src', $originalLink);
            $image->setAttribute('class', 'reddit-image cache-image');

            $this->appendAfter($node, $this->wrapElement($doc, $image));
            Minz_Log::debug("[ImageCache]: Added image link with $originalLink => $newLink");
        } catch (Exception $e) {
            Minz_Log::error("[ImageCache]: Failed to create new DOM element $e");
        }
    }

    private function appendVideo(DOMDocument $doc, DOMElement $node, string $originalLink, string $newLink): void
    {
        try {
            $source = $doc->createElement('source');
            $source->setAttribute('src', $newLink);
            $source->setAttribute('previous-src', $originalLink);

            $video = $doc->createElement('video');
            $video->setAttribute('controls', 'true');
            $video->setAttribute('class', 'reddit-image cache-image');
            $video->appendChild($source);

            $this->appendAfter($node, $this->wrapElement($doc, $video));
            Minz_Log::debug("[ImageCache]: Added video link with $originalLink => $newLink");
        } catch (Exception $e) {
            Minz_Log::error("[ImageCache]: Failed to create new DOM element $e");
        }
    }

    /**
     * @throws DOMException
     */
    private function wrapElement(DOMDocument $doc, DOMElement $node): DOMElement
    {
        $div = $doc->createElement('div');
        $div->appendChild($node);
        return $div;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function getCachedSetUrl(array $matches): string
    {
        return str_replace($matches[1], self::getCachedUrl($matches[1]), $matches[0]);
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function getCachedUrl(string $url): string
    {
        if ($this->isRecache($url) && !$this->isUrlCached($url)) {
            Minz_Log::debug("ImageCache: Re-caching $url");
            $this->uploadUrl($url);
        }

        $encoded_url = rawurlencode($url);
        return FreshRSS_Context::userConf()->param("image_cache_url") . $encoded_url;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function isUrlCached(string $url): bool
    {
        if ($this->isCachedOnRemote($url)) {
            return true;
        }
        $encoded_url = rawurlencode($url);
        $cache_url = FreshRSS_Context::userConf()->param("image_cache_url") . $encoded_url;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $cache_url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 404) {
            return false;
        }
        if ($this->isRecache($url)) {
            $this->setCachedOnRemote($url);
        }
        return true;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function uploadSetUrls(array $matches): void
    {
        self::uploadUrl($matches[1]);
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function uploadUrl(string $to_cache_cache_url): void
    {
        if ($this->isCachedOnRemote($to_cache_cache_url)) {
            return;
        }
        $cached = self::postUrl(FreshRSS_Context::userConf()->param("image_cache_post_url"), [
            "access_token" => FreshRSS_Context::userConf()->param("image_cache_access_token"),
            "url" => $to_cache_cache_url
        ]);
        if ($cached && $this->isRecache($to_cache_cache_url)) {
            $this->setCachedOnRemote($to_cache_cache_url);
        }
    }

    private function postUrl(string $url, array $data): bool
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
        $response = curl_exec($curl);
        curl_close($curl);

        if (!$response) {
            return false;
        }

        $jsonPayload = json_decode($response, associative: true);
        if (!isset($jsonPayload["cached"])) {
            return false;
        }

        return !!$jsonPayload["cached"];
    }

    private function loadContentAsDOM(string $content): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // prevent tag soup errors from showing
        $newContent = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        $doc->loadHTML($newContent);
        return $doc;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function isDisabled(string $src): bool
    {
        $disabledStr = FreshRSS_Context::userConf()->param("image_cache_disabled_url");
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

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     */
    private function isRecache(string $src): bool
    {
        $recacheStr = FreshRSS_Context::userConf()->param("image_recache_url");
        if (!$recacheStr) {
            return false;
        }

        $recacheEntries = explode(',', $recacheStr);
        foreach ($recacheEntries as $recacheEntry) {
            if (str_contains($src, $recacheEntry)) {
                return true;
            }
        }
        return false;
    }

    private function isVideoLink(string $src): bool
    {
        $parsed_url = parse_url($src);
        
        if (isset($parsed_url['host'])) {
            $host = $parsed_url['host'];
            if (str_contains($host, 'redgifs.com')
                || str_contains($host, 'vidble.com')
            ) {
                return true;
            }
        }
        
        if (isset($parsed_url['path'])) {
            $path = $parsed_url['path'];
            if (str_ends_with($path, ".gifv")) {
                return true;
            }
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

    private function isCachedOnRemote(string $url): bool
    {
        return array_key_exists($url, $this->CACHE);
    }

    private function setCachedOnRemote(string $url): void
    {
        $count = count($this->CACHE);
        if ($count >= self::MAX_CACHED) {
            array_shift($this->CACHE);
        }
        $this->CACHE[] = $url;
    }
}
