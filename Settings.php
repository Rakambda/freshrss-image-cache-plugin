<?php

namespace ImageCache;

class Settings
{
    const DEFAULT_CACHE_URL = "https://example.com/pic?url=";
    const DEFAULT_CACHE_POST_URL = "https://example.com/prepare";
    const DEFAULT_CACHE_DISABLED_URL = "";
    const DEFAULT_RECACHE_URL = "";
    const DEFAULT_CACHE_ACCESS_TOKEN = "";
    const DEFAULT_VIDEO_VOLUME = 1;

    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function getImageCacheUrl(): string
    {
        if (array_key_exists('image_cache_url', $this->settings)) {
            return (string)$this->settings['image_cache_url'];
        }

        return self::DEFAULT_CACHE_URL;
    }

    public function getImageCachePostUrl(): string
    {
        if (array_key_exists('image_cache_post_url', $this->settings)) {
            return (string)$this->settings['image_cache_post_url'];
        }

        return self::DEFAULT_CACHE_POST_URL;
    }

    public function getImageCacheAccessToken(): string
    {
        if (array_key_exists('image_cache_access_token', $this->settings)) {
            return (string)$this->settings['image_cache_access_token'];
        }

        return self::DEFAULT_CACHE_ACCESS_TOKEN;
    }

    public function getImageDisabledUrl(): string
    {
        if (array_key_exists('image_cache_disabled_url', $this->settings)) {
            return (string)$this->settings['image_cache_disabled_url'];
        }

        return self::DEFAULT_CACHE_DISABLED_URL;
    }

    public function getImageRecacheUrl(): string
    {
        if (array_key_exists('image_recache_url', $this->settings)) {
            return (string)$this->settings['image_recache_url'];
        }

        return self::DEFAULT_RECACHE_URL;
    }

    public function getVideoDefaultVolume(): float
    {
        if (array_key_exists('video_default_volume', $this->settings)) {
            return (float)$this->settings['video_default_volume'];
        }

        return self::DEFAULT_VIDEO_VOLUME;
    }
}