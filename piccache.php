<?php

const CACHE_PLACE_PATH = "/cache";

# define("CACHE_PLACE_PATH", sys_get_temp_dir());
# Also possible:
# define("CACHE_PLACE_PATH", "C:\\your\\Directory");
# define("CACHE_PLACE_PATH", "/var/www/html/directory");
# Remember to set correct privileges allowing PHP access.

class CacheHit
{
    public $fetched;
    public $length;
    public $filename;
    public $content_type;

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
    public $cached;
    public $fetched;
    public $filename;

    public function __construct(bool $cached = false, bool $fetched = false, ?string $filename = null)
    {
        $this->filename = $filename;
        $this->fetched = $fetched;
        $this->cached = $cached;
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
        $parent_folder = $this->join_paths(CACHE_PLACE_PATH, 'piccache');
        if (!file_exists($parent_folder)) {
            mkdir($this->join_paths($parent_folder));
        }

        $url_hash = hash('sha256', $url);
        $store_filename = explode('?', pathinfo($url, PATHINFO_BASENAME))[0];
        $store_extension = pathinfo($store_filename, PATHINFO_EXTENSION);

        if (array_key_exists($store_extension, $this->extensions)) {
            $store_extension = $this->map_extension($store_extension);
        } else {
            $content_type = $this->extract_content_type($url);
            $new_extension = $this->get_extension_from_content_type($content_type);
            if ($new_extension) {
                $store_extension = $new_extension;
            }
        }

        if (!str_ends_with(".${store_filename}", $store_extension)) {
            $store_filename = "${store_filename}.${store_extension}";
        }

        return $this->join_paths($parent_folder, "$url_hash-$store_filename");
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
            $extension = $parts[1];
            return $this->map_extension($extension);
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

    public function store_in_cache(string $url): FetchHit
    {
        $file_name = $this->get_filename($url);
        if (file_exists($file_name)) {
            return new FetchHit(true, false, $file_name);
        }

        $content = file_get_contents($url);
        if (!$content) {
            return new FetchHit(false, false, null);
        }

        file_put_contents($file_name, $content);
        chmod($file_name, 0777);
        return new FetchHit(true, true, $file_name);
    }

    public function get_cached_data(string $url): CacheHit
    {
        $this->store_in_cache($url);
        $file_name = $this->get_filename($url);
        if (!file_exists($file_name)) {
            return new CacheHit();
        }

        $file_size = filesize($file_name);

        $finfo = finfo_open(FILEINFO_MIME);
        $content_type = finfo_file($finfo, $file_name);
        finfo_close($finfo);

        return new CacheHit(true, $file_name, $file_size, $content_type);
    }
}

function end_wrong_query()
{
    http_response_code(400);
    exit();
}

$cache = new Cache();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_data = json_decode(file_get_contents('php://input'), true);
    if (!$post_data || !array_key_exists("url", $post_data)) {
        end_wrong_query();
    }

    $fetchHit = $cache->store_in_cache($post_data['url']);
    header('Content-Type: application/json; charset=utf-8');
    $filename = $fetchHit->filename;
    $cached = $fetchHit->cached;
    $fetched = $fetchHit->fetched;
    echo "{\"status\": \"OK\", \"filename\": \"${filename}\", \"cached\": \"${$cached}\", \"fetched\": \"${fetched}\"}" . PHP_EOL;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $url = $_GET['url'];
    if (!$url) {
        end_wrong_query();
    }

    $cache_hit = $cache->get_cached_data($url);
    if (!$cache_hit->fetched) {
        header('X-Piccache-Status: MISS');
        header("Location: $url", true, 302);
        exit();
    } else {
        header('X-Piccache-Status: HIT');
        header("X-Piccache-File: $cache_hit->filename");
        header("Content-Type: $cache_hit->content_type");
        header("Content-Length: $cache_hit->length");
        fpassthru(fopen($cache_hit->filename, 'rb'));
    }

} else {
    http_response_code(405);
}
exit();
?>
