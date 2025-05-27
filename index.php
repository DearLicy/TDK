<?php
require __DIR__.'/config.php';
require __DIR__.'/api.php';

// 设置 Content-Type 为 application/json
header('Access-Control-Allow-Origin: *');

try {
    // 验证输入
    if (!isset($_GET['url']) || empty($_GET['url'])) {
        throw new Exception('URL parameter is required');
    }

    // 确保URL有协议头
    $url = $_GET['url'];
    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }

    // 初始化
    $favicon = new \Jerrybendy\Favicon\Favicon([
        'debug' => isset($_GET['debug']),
        'fetch_meta' => !isset($_GET['favicon_only'])
    ]);

    // 处理缓存（使用新的缓存系统）
    $cache = new Cache(HASH_KEY, CACHE_DIR);
    
    if (!isset($_GET['refresh']) && $data = $cache->get($url, '', EXPIRE)) {
        echo $data;
        exit;
    }

    // 获取数据
    $result = $favicon->getSiteInfo($url, false);
    
    // 缓存结果
    $cache->set($url, json_encode($result));
    
    // 输出结果
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/error.html';
    exit;
}

/**
 * 缓存类
 */
class Cache
{
    public $dir = 'cache'; //图标缓存目录
    public $hash_key = 'DearLicy'; // 哈希密钥

    public function __construct($hash_key, $dir = 'cache')
    {
        $this->hash_key = $hash_key;
        $this->dir = $dir;
    }

    /**
     * 获取缓存的值, 不存在时返回 null
     *
     * @param string $key      缓存键(URL)
     * @param string $default  默认图片
     * @param int    $expire   过期时间
     * @return mixed
     */
    public function get($key, $default, $expire)
    {
        $host = $this->extractHost($key);
        if (!$host) return null;

        $hash = substr(hash_hmac('sha256', $host, $this->hash_key), 8, 16);
        $f = $host . '_' . $hash . '.txt';
        $path = $this->dir . '/' . $f;

        if (is_file($path)) {
            $data = file_get_contents($path);
            if (md5($data) == $default) {
                $expire = 43200; //如果返回默认图标，过期时间为12小时。
            }
            if ((time() - filemtime($path)) > $expire) {
                return null;
            } else {
                return $data;
            }
        }
        return null;
    }

    /**
     * 设置缓存
     * 保存图标到缓存目录
     *
     * @param string $key      缓存键(URL)
     * @param string $value    缓存值(图标)
     */
    public function set($key, $value)
    {
        //如果缓存目录不存在则创建
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true) or die('创建缓存目录失败！');
        }

        $host = $this->extractHost($key);
        if (!$host) return;

        $hash = substr(hash_hmac('sha256', $host, $this->hash_key), 8, 16);
        $f = $host . '_' . $hash . '.txt';
        $path = $this->dir . '/' . $f;

        $imgdata = fopen($path, "w") or die("Unable to open file!");
        if (flock($imgdata, LOCK_EX)) {
            fwrite($imgdata, $value);
            flock($imgdata, LOCK_UN);
        }
        fclose($imgdata);
    }

    /**
     * 从URL中提取host
     *
     * @param string $url
     * @return string|null
     */
    private function extractHost($url)
    {
        // 确保URL有协议头
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'http://' . ltrim($url, '/');
        }

        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return null;
        }

        return strtolower($parsed['host']);
    }
}