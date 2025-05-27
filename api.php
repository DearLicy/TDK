<?php
/**
 * Favicon + TDK 获取类
 * @author    Jerry Bendy
 * @link      https://www.iowen.cn
 * @version   3.0.0
 */

namespace Jerrybendy\Favicon;

class Favicon
{
    // Debug 模式
    public $debug_mode = false;

    // 请求配置
    private $options = [
        'timeout'      => 5,      // 请求超时（秒）
        'user_agent'   => 'Mozilla/5.0 (compatible; FaviconFetcher/3.0)', // 默认 User-Agent
        'fetch_meta'   => true,   // 是否获取 TDK
    ];

    // 缓存数据
    private $params = [];
    private $full_host = '';
    private $data = null;

    // TDK 数据
    private $meta = [
        'title'       => '',
        'description' => '',
        'keywords'    => '',
        'canonical'   => '',
        'favicon_url' => '',
    ];

    // 性能统计
    private $_last_time_spend = 0;
    private $_last_memory_usage = 0;

    // 文件映射（用于静态规则）
    private $_file_map = [];
    private $_default_icon = '';

    /**
     * 构造函数
     * @param array $options 配置选项
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 获取网站信息（Favicon + TDK）
     * @param string $url       目标网址
     * @param bool   $returnJson 是否返回 JSON 格式
     * @return string|array
     */
    public function getSiteInfo($url, $returnJson = false)
    {
        $time_start = microtime(true);

        // 1. 初始化并解析 URL
        $this->params['origin_url'] = $url;
        $this->full_host = $this->formatUrl($url);

        if (!$this->full_host) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }

        // 2. 获取 HTML 内容
        $html = $this->getFile($url);
        if ($html['status'] !== 'OK') {
            $html = $this->getFile($this->full_host);
        }

        // 3. 解析 TDK（如果启用）
        if ($this->options['fetch_meta'] && !empty($html['data'])) {
            $this->parseMeta($html['data'], $url);
        }

        // 4. 获取 Favicon
        $this->getFaviconData($html['data'] ?? '', $url);

        // 5. 构建返回结果
        $result = [
            'success' => true,
            'data' => [
                'title'       => $this->meta['title'],
                'description' => $this->meta['description'],
                'keywords'    => $this->meta['keywords'],
                'canonical'   => $this->meta['canonical'],
                'favicon_url' => str_replace('\/', '/', $this->meta['favicon_url']),
                'host'        => str_replace('\/', '/', $this->full_host),
                'performance' => [
                    'time_spent'   => round(microtime(true) - $time_start, 3) . 's',
                    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                ],
            ],
        ];

        return $returnJson ? json_encode($result) : $result;
    }

    /**
     * 解析 TDK（Title, Description, Keywords）
     * @param string $html     HTML 内容
     * @param string $baseUrl  基准 URL（用于相对路径转换）
     */
    private function parseMeta($html, $baseUrl)
    {
        // 清理 HTML（去除换行符）
        $html = str_replace(["\n", "\r"], '', $html);

        // 提取 <title>
        preg_match('/<title>(.*?)<\/title>/i', $html, $matches);
        $this->meta['title'] = $matches[1] ?? '';

        // 提取 <meta name="description">
        preg_match('/<meta\s+name="description"\s+content="(.*?)"/i', $html, $matches);
        $this->meta['description'] = $matches[1] ?? '';

        // 提取 <meta name="keywords">
        preg_match('/<meta\s+name="keywords"\s+content="(.*?)"/i', $html, $matches);
        $this->meta['keywords'] = $matches[1] ?? '';

        // 提取 <link rel="canonical">
        preg_match('/<link\s+rel="canonical"\s+href="(.*?)"/i', $html, $matches);
        $this->meta['canonical'] = $matches[1] ?? $baseUrl;
    }

    /**
     * 获取 Favicon 数据
     * @param string $html     HTML 内容
     * @param string $baseUrl  基准 URL
     */
    private function getFaviconData($html, $baseUrl)
    {
        // 1. 尝试从 HTML 中提取 Favicon URL
        $faviconUrl = $this->extractFaviconUrl($html, $baseUrl);

        // 2. 如果找到 Favicon URL，尝试下载
        if ($faviconUrl) {
            $this->meta['favicon_url'] = $faviconUrl;
            $this->data = $this->getFile($faviconUrl, true);
        } else {
            // 3. 尝试默认 /favicon.ico
            $faviconUrl = $this->full_host . '/favicon.ico';
            $data = $this->getFile($faviconUrl, true);

            if ($data['status'] === 'OK') {
                $this->meta['favicon_url'] = $faviconUrl;
                $this->data = $data;
            } else {
                // 4. 使用 Google 服务作为后备方案
                $googleUrl = 'https://t3.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&url=' . $this->full_host;
                $this->meta['favicon_url'] = $googleUrl;
            }
        }
    }

    /**
     * 从 HTML 中提取 Favicon URL
     * @param string $html     HTML 内容
     * @param string $baseUrl  基准 URL
     * @return string|null
     */
    private function extractFaviconUrl($html, $baseUrl)
    {
        if (empty($html)) return null;

        // 匹配 <link rel="icon"> 或 <link rel="shortcut icon">
        if (preg_match('/<link[^>]+rel=.(icon|shortcut icon|alternate icon|apple-touch-icon)[^>]+>/i', $html, $match_tag)) {
            if (preg_match('/href=([\'"])(.*?)\1/i', $match_tag[0], $match_url)) {
                return $this->filterRelativeUrl(trim($match_url[2]), $baseUrl);
            }
        }

        return null;
    }

    /**
     * 格式化 URL（补全协议）
     * @param string $url 原始 URL
     * @return string|false
     */
    public function formatUrl($url)
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'http://' . $url;
            }
            $parsed = parse_url($url);
        }

        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }

        return ($parsed['scheme'] ?? 'http') . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    }

    /**
     * 转换相对路径为绝对路径
     * @param string $url  相对 URL
     * @param string $base 基准 URL
     * @return string
     */
    public function filterRelativeUrl($url, $base)
    {
        if (strpos($url, '://') !== false) {
            return $url; // 已经是绝对路径
        }

        $baseParts = parse_url($base);
        $baseRoot = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        // 处理 // 开头的 URL（协议相对）
        if (substr($url, 0, 2) === '//') {
            return $baseParts['scheme'] . ':' . $url;
        }

        // 处理 / 开头的 URL（根目录相对）
        if ($url[0] === '/') {
            return $baseRoot . $url;
        }

        // 处理 ./ 或 ../ 的相对路径
        $basePath = isset($baseParts['path']) ? dirname($baseParts['path']) : '';
        $fullPath = $baseRoot . '/' . ltrim($basePath . '/' . $url, '/');

        // 简化路径（处理 ./ 和 ../）
        $parts = explode('/', $fullPath);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.' && $part !== '') {
                $result[] = $part;
            }
        }

        return implode('/', $result);
    }

    /**
     * 获取远程文件（支持重定向）
     * @param string $url     目标 URL
     * @param bool   $isImage 是否为图片
     * @return array
     */
    private function getFile($url, $isImage = false)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->options['timeout'],
            CURLOPT_USERAGENT      => $this->options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // 验证图片（如果是图片请求）
        if ($isImage && $data) {
            $imageInfo = @getimagesizefromstring($data);
            if (!$imageInfo) {
                $this->_log("Invalid image data from: {$url}");
                $data = false;
            }
        }

        return [
            'status'   => ($status >= 200 && $status < 400) ? 'OK' : 'FAIL',
            'data'     => $data,
            'real_url' => $effectiveUrl,
        ];
    }

    /**
     * 设置默认图标
     * @param string $filePath 默认图标路径
     */
    public function setDefaultIcon($filePath)
    {
        $this->_default_icon = $filePath;
    }

    /**
     * 设置文件映射规则
     * @param array $map 映射规则（正则 => 文件路径）
     */
    public function setFileMap(array $map)
    {
        $this->_file_map = $map;
    }

    /**
     * 调试日志
     * @param string $message 日志内容
     */
    private function _log($message)
    {
        if ($this->debug_mode) {
            error_log('[Favicon] ' . $message);
        }
    }
}