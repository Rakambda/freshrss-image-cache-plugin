<?php

use JetBrains\PhpStorm\NoReturn;

const CACHE_PLACE_PATH = "/cache";
const CONFIG_PATH = "/cache/config.json";
const CACHE_FOLDER_NAME = 'piccache';
const HASH_SUBFOLDER_COUNT = 3;
const ENABLE_DEBUGGING = false;

if (ENABLE_DEBUGGING) {
    $logFile = "/app/www/data/users/_/piccache_error.log";
    if (!file_exists($logFile)) {
        touch($logFile);
    }

    if (filesize($logFile) >= 1048576) { // 10Mb
        $fp = fopen($logFile, "w");
        fclose($fp);
    }

    ini_set("log_errors", 1);
    ini_set("log_errors_max_len", 2048);
    ini_set("error_log", $logFile);
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class Config
{
    private static ?array $config = null;

    public static function get_config(): array
    {
        if (!Config::$config) {
            $json_content = file_get_contents(CONFIG_PATH);
            Config::$config = json_decode($json_content, associative: true);
        }
        return Config::$config;
    }

    public static function get_redgifs_bearer(): ?string
    {
        $config = self::get_config();
        if (isset($config['redgifs_bearer'])) {
            return $config['redgifs_bearer'];
        }
        return null;
    }

    public static function get_user_agent(): ?string
    {
        $config = self::get_config();
        if (isset($config['user_agent'])) {
            return $config['user_agent'];
        }
        return null;
    }

    public static function get_imgur_client_id(): ?string
    {
        $config = self::get_config();
        if (isset($config['imgur_client_id'])) {
            return $config['imgur_client_id'];
        }
        return null;
    }
}

class CacheHit
{
    public bool $fetched;
    public ?int $length;
    public ?string $filename;
    public ?string $content_type;

    public function __construct(bool $fetched = false, ?string $filename = null, ?int $length = 0, ?string $content_type = null)
    {
        $this->filename = $filename;
        $this->length = $length;
        $this->fetched = $fetched;
        $this->content_type = $content_type;
    }
}

class FetchHit
{
    public bool $cached;
    public bool $fetched;
    public ?string $filename;
    public ?array $headers;
    public ?string $comment;

    public function __construct(bool $cached = false, bool $fetched = false, ?string $filename = null, ?array $headers = [], ?string $comment = null)
    {
        $this->filename = $filename;
        $this->fetched = $fetched;
        $this->cached = $cached;
        $this->headers = $headers;
        $this->comment = $comment;
    }
}

class Cache
{
    private array $extensions;

    public function __construct()
    {
        $this->extensions = [
            'jpg' => 'jpg',
            'jpeg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'svg' => 'svg',
            'svg+xml' => 'svg',
            'webp' => 'webp',
            'avif' => 'avif',
            'tiff' => 'tiff',

            'mp4' => 'mp4',
            'webm' => 'webm',

            'aac' => 'aac',
            'mp3' => 'mp3',
            'mpeg' => 'mp3',
        ];
    }

    private function join_paths(...$paths): string
    {
        return preg_replace('~[/\\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, $paths));
    }

    private function get_filename($url): string
    {
        $hash_folder = $this->get_folder($url);
        if (!file_exists($hash_folder)) {
            umask(0);
            mkdir($hash_folder, recursive: true);
            chmod($hash_folder, 0775);
        }

        $store_filename = explode('?', pathinfo($url, PATHINFO_BASENAME))[0];
        $store_extension = $this->get_filename_extension($url, $store_filename);

        if (!str_ends_with(".$store_filename", $store_extension)) {
            $store_filename = "$store_filename.$store_extension";
        }

        $url_hash = $this->get_url_hash($url);
        return $this->join_paths($hash_folder, "$url_hash-$store_filename");
    }

    private function get_folder(string $url): string
    {
        $url_hash = $this->get_url_hash($url);
        $parsed_url = parse_url($url);
        $host_parts = explode('.', $parsed_url['host']);
        $domain = implode('.', array_slice($host_parts, count($host_parts) - 2));
        $sub_hashes = [];
        for ($i = 0; $i < HASH_SUBFOLDER_COUNT; $i++) {
            if ($i >= strlen($url_hash)) {
                $sub_hashes[] = "_";
            } else {
                $sub_hashes[] = $url_hash[$i];
            }
        }

        return $this->join_paths(CACHE_PLACE_PATH, CACHE_FOLDER_NAME, $domain, ...$sub_hashes);
    }

    private function get_filename_extension(string $url, string $store_filename): string
    {
        $path_extension = pathinfo($store_filename, PATHINFO_EXTENSION);
        if (!$this->isRedgifs($url) && !$this->isVidble($url) && array_key_exists($path_extension, $this->extensions)) {
            return $this->map_extension($path_extension);
        }

        $content_type = $this->extract_content_type($url);
        $content_type_extension = $this->get_extension_from_content_type($content_type);
        if ($content_type_extension && array_key_exists($content_type_extension, $this->extensions)) {
            return $this->map_extension($content_type_extension);
        }

        if ($this->isRedgifs($url)) {
            return $this->map_extension('mp4');
        }
        if ($this->isVidble($url)) {
            return $this->map_extension('mp4');
        }

        return $path_extension;
    }

    private function get_extension_from_content_type(?string $content_type): ?string
    {
        if (!$content_type) {
            return null;
        }

        $parsed_content_type = $this->parse_content_header_value($content_type);
        if (!$parsed_content_type || !isset($parsed_content_type["value"])) {
            return null;
        }

        $content_type_value = $parsed_content_type["value"];
        if (str_starts_with($content_type_value, "image/")
            || str_starts_with($content_type_value, "video/")
            || str_starts_with($content_type_value, "audio/")
        ) {

            $parts = explode('/', $content_type_value, 2);
            return $parts[1];
        }

        return null;
    }

    private function map_extension(?string $extension): ?string
    {
        if (!$extension || !array_key_exists($extension, $this->extensions)) {
            return $extension;
        }
        return $this->extensions[$extension];
    }

    private function extract_content_type(string $url): ?string
    {
        $raw_content_type = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function ($curl, $header) use (&$raw_content_type) {
                $len = strlen($header);

                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                if (strtolower(trim($header[0])) == "content-type") {
                    $raw_content_type = trim($header[1]);
                }
                return $len;
            }
        );
        curl_exec($ch);
        curl_close($ch);
        return $raw_content_type;
    }

    private function parse_content_header_value(string $value): array
    {
        $retVal = array();
        $value_pattern = '/^([^;]+)\s*(.*)\s*?$/';
        $param_pattern = '/([a-z]+)=(([^\"][^;]+)|(\"(\\\"|[^"])+\"))/';
        $vm = array();

        if (preg_match($value_pattern, $value, $vm)) {
            $retVal['value'] = $vm[1];
            if (count($vm) > 1) {
                $pm = array();
                if (preg_match_all($param_pattern, $vm[2], $pm)) {
                    $pcount = count($pm[0]);
                    for ($i = 0; $i < $pcount; $i++) {
                        $value = $pm[2][$i];
                        if (str_starts_with($value, '"')) {
                            $value = stripcslashes(substr($value, 1, mb_strlen($value) - 2));
                        }
                        $retVal['params'][$pm[1][$i]] = $value;
                    }
                }
            }
        }

        return $retVal;
    }

    private function get_link_content(string $url): array
    {
        $user_agent = Config::get_user_agent();

        if ($this->isRedgifs($url)) {
            $url = $this->get_redgifs_url_from_m3u8($url);
            if (!$url) {
                return [false, []];
            }
        }
        if ($this->isVidble($url)) {
            $url = $this->get_vidble_url($url);
            if (!$url) {
                return [false, []];
            }
        }
        if ($this->isImgur($url)) {
            $url = $this->get_imgur_url($url);
            if (!$url) {
                return [false, []];
            }
        }

        $context = stream_context_create(["http" => [
            'header' => "User-Agent: $user_agent\r\n",
        ]]);
        $content = file_get_contents($url, false, $context);
        return [$content, $http_response_header];
    }

    private function get_redgifs_url_from_api(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $gif_id = basename($path);

        $user_agent = Config::get_user_agent();
        $bearer = Config::get_redgifs_bearer();

        $context = stream_context_create(["http" => [
            'header' => "Authorization: Bearer $bearer\r\nUser-Agent: $user_agent\r\n"
        ]]);
        $api_response = file_get_contents("https://api.redgifs.com/v2/gifs/$gif_id?views=yes&users=yes&niches=yes", false, $context);
        if (!$api_response) {
            return null;
        }

        $json_response = json_decode($api_response, associative: true);
        if (!$json_response) {
            return null;
        }
        return $json_response['gif']['urls']['hd'];
    }

    private function get_redgifs_url_from_m3u8(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $gif_id = basename($path);

        $api_response = file_get_contents("https://api.redgifs.com/v2/gifs/$gif_id/hd.m3u8");
        if (!$api_response) {
            return null;
        }

        $pm = array();
        if (preg_match_all('/^(?!#).*/mi', $api_response, $pm)) {
            foreach ($pm as $v) {
                return $v[0];
            }
        }

        return null;
    }

    private function get_vidble_url(string $url): ?string
    {
        preg_match('/watch\\?v=([a-zA-Z0-9]+)/', $url, $matches);
        if ($matches && isset($matches[1])) {
            $video_id = $matches[1];
            return "https://www.vidble.com/$video_id.mp4";
        }
        return null;
    }

    private function get_imgur_url(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $post_id = implode(explode('.', basename($path), -1));

        if (str_ends_with($path, '.gifv')) {
            $direct_url = "https://i.imgur.com/$post_id.mp4";
            $content_type = $this->extract_content_type($direct_url);
            if ($content_type) {
                if (str_starts_with($content_type, 'image/') || str_starts_with($content_type, 'video/')) {
                    return $direct_url;
                }
            }
        }

        $user_agent = Config::get_user_agent();
        $bearer = Config::get_imgur_client_id();

        $context = stream_context_create(["http" => [
            'header' => "Authorization: Client-ID $bearer\r\nUser-Agent: $user_agent\r\n"
        ]]);

        $api_response = file_get_contents("https://api.imgur.com/3/image/$post_id", false, $context);
        if (!$api_response || $this->content_type_contains($http_response_header, 'text/html')) {
            return null;
        }

        $json_response = json_decode($api_response, associative: true);
        if (!$json_response || !$json_response["success"]) {
            return null;
        }
        return $json_response['data']['link'];
    }

    private function isRedgifs(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'redgifs.com');
    }

    private function isVidble(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'vidble.com');
    }

    private function isImgur(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'imgur.com');
    }

    public function store_in_cache(string $url): FetchHit
    {
        if ($this->is_cached($url)) {
            return new FetchHit(true, false, comment: 'File already exists in cache');
        }

        [$content, $headers] = $this->get_link_content($url);
        if (!$content) {
            $response_code = -1;
            if ($headers) {
                preg_match('/\d{3}/', $headers[0], $matches);
                if ($matches) {
                    $response_code = intval($matches[0]);
                }
            }

            if ($response_code == -1 || $response_code == 404) {
                return new FetchHit(true, false, headers: $headers, comment: 'Got 404');
            }

            return new FetchHit(false, false, headers: $headers, comment: 'Could not get media content');
        }
        if ($this->content_type_contains($headers, 'text/html')) {
            return new FetchHit(false, true, headers: $headers, comment: 'Response has HTML content type');
        }
        if (preg_match("#^\s*<!doctype html>.*#i", $content)) {
            return new FetchHit(false, true, headers: $headers, comment: 'Response was HTML');
        }

        $file_name = $this->get_filename($url);

        umask(0);
        file_put_contents($file_name, $content);
        chmod($file_name, 0775);
        return new FetchHit(true, true, $file_name, $headers, 'Added to cache');
    }

    public function get_cached_data(string $url): CacheHit
    {
        $this->store_in_cache($url);
        if (!$this->is_cached($url)) {
            return new CacheHit();
        }

        $file_name = $this->get_filename($url);
        $file_size = filesize($file_name);

        $content_type = mime_content_type($file_name);
        return new CacheHit(true, $file_name, $file_size, $content_type);
    }

    private function is_cached(string $url): bool
    {
        $folder = $this->get_folder($url);
        $hash = $this->get_url_hash($url);
        if (glob("$folder/$hash*", GLOB_NOSORT)) {
            return true;
        }
        return false;
    }

    private function get_url_hash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function content_type_contains(array $headers, string $type): bool
    {
        if (!$headers or !isset($headers['Content-Type'])) {
            return false;
        }
        if (str_contains($headers['Content-Type'], $type)) {
            return true;
        }
        return false;
    }
}

#[NoReturn]
function end_wrong_query(): void
{
    http_response_code(400);
    exit();
}

function reply_video(CacheHit $cache_hit): void
{
    $filesize = $cache_hit->length;
    $length = $filesize;
    $offset = 0;

    if (isset($_SERVER['HTTP_RANGE'])) {
        $partialContent = true;
        preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);

        $offset = intval($matches[1]);
        $end = (isset($matches[2]) && $matches[2]) ? intval($matches[2]) : $filesize;
        $length = $end - $offset;
    } else {
        $partialContent = false;
    }

    $file = fopen($cache_hit->filename, 'r');
    fseek($file, $offset);
    $data = fread($file, $length);
    fclose($file);

    if ($partialContent) {
        $bytes_length = $offset + $length - 1;
        header("HTTP/1.1 206 Partial Content");
        header("Content-Range: bytes $offset-$bytes_length/$filesize");
    }

    $filename = pathinfo($cache_hit->filename, PATHINFO_BASENAME);

    header("X-Piccache-Status: HIT");
    header("X-Piccache-File: $cache_hit->filename");
    header("Content-Type: $cache_hit->content_type");
    header("Content-Length: $filesize");
    header("Content-Disposition: inline; filename=\"$filename\"");
    header("Accept-Ranges: bytes");

    print($data);
}

try {
    $cache = new Cache();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_data = json_decode(file_get_contents('php://input'), true);
        if (!$post_data || !array_key_exists("url", $post_data)) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $fetchHit = $cache->store_in_cache($post_data['url']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($fetchHit) . PHP_EOL;

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $url = $_GET['url'];
        if (!$url) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $cache_hit = $cache->get_cached_data($url);
        if (!$cache_hit->fetched) {
            header('X-Piccache-Status: MISS');
            header("Location: $url", response_code: 302);
            exit();
        } else {
            if (str_starts_with($cache_hit->content_type, 'video/')) {
                reply_video($cache_hit);
            } else {
                header("X-Piccache-Status: HIT");
                header("X-Piccache-File: $cache_hit->filename");
                header("Content-Type: $cache_hit->content_type");
                header("Content-Length: $cache_hit->length");
                fpassthru(fopen($cache_hit->filename, 'rb'));
            }
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        $url = $_GET['url'];
        if (!$url) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $cache_hit = $cache->get_cached_data($url);
        if (!$cache_hit->fetched) {
            header('X-Piccache-Status: MISS', response_code: 404);
            exit();
        } else {
            header('X-Piccache-Status: HIT');
            header("X-Piccache-File: $cache_hit->filename");
            header("Content-Type: $cache_hit->content_type");
            header("Content-Length: $cache_hit->length", response_code: 204);
        }

    } else {
        http_response_code(405);
    }
} catch (Exception $e) {
    header("X-Piccache-Error: Uncaught exception");
    header("Content-Type: text/plain", response_code: 500);
    error_log($e);
}
exit();
