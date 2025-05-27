<?php
// 缓存配置
define('CACHE_DIR', 'cache');
define('HASH_KEY', 'secure_key_' . bin2hex(random_bytes(8)));
define('EXPIRE', 2592000); // 30天缓存

// 默认配置
define('DEFAULT_FAVICON', '../default.png');
define('DEFAULT_TITLE', 'No Title');
define('DEFAULT_DESC', 'No description available');
define('DEFAULT_KEYWORDS', '');

// 安全限制
define('MAX_URL_LENGTH', 512);
define('ALLOWED_DOMAINS', []); // 空数组表示允许所有域名