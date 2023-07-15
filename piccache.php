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

class Cache
{
    function join_paths(...$paths): string
    {
        return preg_replace('~[/\\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, $paths));
    }

    function get_filename($url): string
    {
        $parent_folder = $this->join_paths(CACHE_PLACE_PATH, 'piccache');
        if (!file_exists($parent_folder)) {
            mkdir($this->join_paths($parent_folder));
        }

        $store_filename = explode('?', pathinfo($url, PATHINFO_BASENAME))[0];
        $url_hash = hash('sha256', $url);
        return $this->join_paths($parent_folder, "$url_hash-$store_filename");
    }

    function store_in_cache(string $url): bool
    {
        $file_name = $this->get_filename($url);
        if (file_exists($file_name)) {
            return true;
        }

        $content = file_get_contents($url);
        if (!$content) {
            return false;
        }

        file_put_contents($file_name, $content);
        chmod($file_name, 0775);
        return true;
    }

    function get_cached_data(string $url): CacheHit
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

    $cache->store_in_cache($post_data['url']);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"status": "OK"}' . PHP_EOL;

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
        header("Content-Type: $cache_hit->content_type");
        header("Content-Length: $cache_hit->length");
        fpassthru(fopen($cache_hit->filename, 'rb'));
    }

} else {
    http_response_code(405);
}
exit();
?>
