<?php
/**
 * @file public.php
 * @brief 公共脚本
 *
 * 该脚本包含公共设置、公共函数。
 *
 * 作者: Tak
 * GitHub: https://github.com/taksssss/iptv-tool
 */

require 'assets/opencc/vendor/autoload.php'; // 引入 Composer 自动加载器
use Overtrue\PHPOpenCC\OpenCC; // 使用 OpenCC 库

// 检查并解析配置文件和图标列表文件
@mkdir(__DIR__ . '/data', 0755, true);
$iconDir = __DIR__ . '/data/icon/'; @mkdir($iconDir, 0755, true);
$liveDir = __DIR__ . '/data/live/'; @mkdir($liveDir, 0755, true);
$liveFileDir = __DIR__ . '/data/live/file/'; @mkdir($liveFileDir, 0755, true);
file_exists($configPath = __DIR__ . '/data/config.json') || copy(__DIR__ . '/assets/defaultConfig.json', $configPath);
file_exists($customSourcePath = __DIR__ . '/data/customSource.php') || copy(__DIR__ . '/assets/defaultCustomSource.php', $customSourcePath);
file_exists($iconListPath = __DIR__ . '/data/iconList.json') || file_put_contents($iconListPath, json_encode(new stdClass(), JSON_PRETTY_PRINT));
($iconList = json_decode(file_get_contents($iconListPath), true)) !== null || die("图标列表文件解析失败: " . json_last_error_msg());
$iconListDefault = json_decode(file_get_contents(__DIR__ . '/assets/defaultIconList.json'), true) or die("默认图标列表文件解析失败: " . json_last_error_msg());
$iconListMerged = array_merge($iconListDefault, $iconList); // 同一个键，以 iconList 的为准
$Config = json_decode(file_get_contents($configPath), true) or die("配置文件解析失败: " . json_last_error_msg());

// 获取 serverUrl
$protocol = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http'));
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$serverUrl = $protocol . '://' . $host . $scriptDir;

// 移除 xmltv 软链接
if (file_exists($xmlLinkPath = __DIR__ . '/t.xml')) {
    unlink($xmlLinkPath);
    unlink($xmlLinkPath . ".gz");
}

// 设置时区为亚洲/上海
date_default_timezone_set("Asia/Shanghai");

// 创建或打开数据库
try {
    // 检测数据库类型
    $is_sqlite = $Config['db_type'] === 'sqlite';

    $dsn = $is_sqlite ? 'sqlite:' . __DIR__ . '/data/data.db'
        : "mysql:host={$Config['mysql']['host']};dbname={$Config['mysql']['dbname']};charset=utf8mb4";

    $db = new PDO($dsn, $Config['mysql']['username'] ?? null, $Config['mysql']['password'] ?? null);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo '数据库连接失败: ' . $e->getMessage();
    exit();
}

// 初始化数据库表
function initialDB() {
    global $db;
    global $is_sqlite;

    $typeText = $is_sqlite ? 'TEXT' : 'VARCHAR(255)';
    $typeIntAuto = $is_sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT PRIMARY KEY AUTO_INCREMENT';
    $typeTime = $is_sqlite ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';

    $tables = [
        "CREATE TABLE IF NOT EXISTS epg_data (
            date $typeText NOT NULL,
            channel $typeText NOT NULL,
            epg_diyp $typeText,
            PRIMARY KEY (date, channel)
        )",
        "CREATE TABLE IF NOT EXISTS gen_list (
            id $typeIntAuto,
            channel $typeText NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS update_log (
            id $typeIntAuto,
            timestamp $typeTime,
            log_message $typeText NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS cron_log (
            id $typeIntAuto,
            timestamp $typeTime,
            log_message $typeText NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS channels (
            groupTitle $typeText,
            channelName $typeText,
            chsChannelName $typeText,
            streamUrl $typeText,
            iconUrl $typeText,
            tvgId $typeText,
            tvgName $typeText,
            disable INTEGER DEFAULT 0,
            modified INTEGER DEFAULT 0,
            source $typeText,
            tag $typeText,
            config $typeText
        )",
        "CREATE TABLE IF NOT EXISTS channels_info (
            streamUrl $typeText PRIMARY KEY,
            resolution $typeText,
            speed $typeText
        )",
        "CREATE TABLE IF NOT EXISTS access_log (
            id $typeIntAuto,
            access_time $typeTime NOT NULL,
            client_ip $typeText NOT NULL,
            method $typeText NOT NULL,
            url TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            access_denied INTEGER DEFAULT 0,
            deny_message TEXT
        )"
    ];

    foreach ($tables as $sql) {
        $db->exec($sql);
    }
}

// 获取处理后的频道名：$t2s参数表示是否进行繁转简，默认 false
function cleanChannelName($channel, $t2s = false) {
    global $Config;

    if ($channel === '') {
        return '';
    }

    // 获取忽略字符，默认包含空格和 "-"
    $ignoreChars = str_replace('&nbsp', ' ', array_map('trim', explode(',', $Config['channel_ignore_chars'] ?? '&nbsp, -')));
    $normalizedChannel = str_replace($ignoreChars, '', $channel);

    // 优先使用频道映射（支持正则）
    foreach ($Config['channel_mappings'] as $replace => $search) {
        if (strpos($search, 'regex:') === 0) {
            $pattern = substr($search, 6);
            if (preg_match($pattern, $channel)) {
                return strtoupper(preg_replace($pattern, $replace, $channel));
            }
        } else {
            $channels = array_map('trim', explode(',', $search));
            foreach ($channels as $singleChannel) {
                if (strcasecmp($normalizedChannel, str_replace($ignoreChars, '', $singleChannel)) === 0) {
                    return strtoupper($replace);
                }
            }
        }
    }

    // 繁体转简体（如启用）
    if ($t2s) {
        $normalizedChannel = t2s($normalizedChannel);
    }

    return strtoupper($normalizedChannel);
}

// 繁体转简体
function t2s($channel) {
    return OpenCC::convert($channel, 'TRADITIONAL_TO_SIMPLIFIED');
}

// 台标模糊匹配
function iconUrlMatch($channels, $getDefault = true) {
    global $Config, $iconListDefault, $iconListMerged, $serverUrl;

    // 支持传入字符串或数组
    $channelList = is_array($channels) ? $channels : [$channels];

    foreach ($channelList as $originalChannel) {
        // 精确匹配
        if (isset($iconListMerged[$originalChannel])) {
            return $iconListMerged[$originalChannel];
        }

        $bestMatch = null;
        $iconUrl = null;

        // 正向模糊匹配（原始频道名包含在列表中的频道名中）
        foreach ($iconListMerged as $channelName => $icon) {
            if (stripos($channelName, $originalChannel) !== false) {
                if ($bestMatch === null || mb_strlen($channelName) < mb_strlen($bestMatch)) {
                    $bestMatch = $channelName;
                    $iconUrl = $icon;
                }
            }
        }

        // 反向模糊匹配（列表中的频道名包含在原始频道名中）
        if (!$iconUrl) {
            foreach ($iconListMerged as $channelName => $icon) {
                if (stripos($originalChannel, $channelName) !== false) {
                    if ($bestMatch === null || mb_strlen($channelName) > mb_strlen($bestMatch)) {
                        $bestMatch = $channelName;
                        $iconUrl = $icon;
                    }
                }
            }
        }

        // 成功匹配则立即返回
        if ($iconUrl) {
            return $iconUrl;
        }
    }

    // 所有候选频道都没有匹配，返回默认图标（如果配置中存在）
    return $getDefault ? ($Config['default_icon'] ?? null) : null;
}

// 下载文件
function downloadData($url, $userAgent = '', $timeout = 120, $connectTimeout = 10, $retry = 3) {
    $data = false;
    $error = '';
    $mtime = 0;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . ($userAgent ?: 
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.121 Safari/537.36'),
            'Accept: */*',
            'Connection: keep-alive'
        ]
    ]);

    while ($retry--) {
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            continue;
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $data = substr($response, $headerSize);

        // 获取 Last-Modified
        if (preg_match('/Last-Modified:\s*(.+)\r?\n/i', $headerStr, $matches)) {
            $parsed = strtotime(trim($matches[1]));
            if ($parsed !== false) {
                $mtime = $parsed;
            }
        }

        curl_close($ch);
        return [$data, '', $mtime];
    }

    curl_close($ch);
    return [false, $error, 0];
}

// 日志记录函数
function logMessage(&$log_messages, $message) {
    $log_messages[] = date("[y-m-d H:i:s]") . " " . $message;
    echo date("[y-m-d H:i:s]") . " " . $message . "<br>";
}

// 抓取数据并存入数据库
require_once 'scraper.php';
function scrapeSource($source, $url, $db, &$log_messages) {
    global $sourceHandlers;

    if (empty($sourceHandlers[$source]['handler']) || !is_callable($sourceHandlers[$source]['handler'])) {
        logMessage($log_messages, "【{$source}】处理函数未定义或不可调用");
        return;
    }

    $db->beginTransaction();
    try {
        $allChannelProgrammes = call_user_func($sourceHandlers[$source]['handler'], $url);

        foreach ($allChannelProgrammes as $channelId => $channelProgrammes) {
            $count = $channelProgrammes['process_count'] ?? 0;
            if ($count > 0) {
                insertDataToDatabase([$channelId => $channelProgrammes], $db, $source);
            }
            logMessage($log_messages, "【{$source}】{$channelProgrammes['channel_name']} " .
                ($count > 0 ? "更新成功，共 {$count} 条" : "下载失败！！！"));
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        logMessage($log_messages, "【{$source}】处理出错：" . $e->getMessage());
    }

    echo "<br>";
}

// 插入数据到数据库
function insertDataToDatabase($channelsData, $db, $sourceUrl) {
    global $processedRecords;
    global $Config;
    $skipCount = 0;

    foreach ($channelsData as $channelId => $channelData) {
        $channelName = $channelData['channel_name'];
        foreach ($channelData['diyp_data'] as $date => $diypProgrammes) {
            // 检查是否全天只有一个节目
            if (count($title = array_unique(array_column($diypProgrammes, 'title'))) === 1 
                && preg_match('/节目|節目/u', $title[0])) {
                $skipCount += count($diypProgrammes);
                continue; // 跳过后续处理
            }
            
            // 生成 epg_diyp 数据内容
            $diypContent = json_encode([
                'channel_name' => $channelName,
                'date' => $date,
                'url' => 'https://github.com/taksssss/iptv-tool',
                'source' => $sourceUrl,
                'epg_data' => $diypProgrammes
            ], JSON_UNESCAPED_UNICODE);

            // 当天及未来数据覆盖，其他日期数据忽略
            $action = $date >= date('Y-m-d') ? 'REPLACE' : 'IGNORE';

            // 根据数据库类型选择 SQL 语句
            if ($Config['db_type'] === 'sqlite') {
                $sql = "INSERT OR $action INTO epg_data (date, channel, epg_diyp) VALUES (:date, :channel, :epg_diyp)";
            } else {
                $sql = ($action === 'REPLACE')
                    ? "REPLACE INTO epg_data (date, channel, epg_diyp) VALUES (:date, :channel, :epg_diyp)"
                    : "INSERT IGNORE INTO epg_data (date, channel, epg_diyp) VALUES (:date, :channel, :epg_diyp)";
            }

            // 准备并执行 SQL 语句
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':date', $date, PDO::PARAM_STR);
            $stmt->bindValue(':channel', $channelName, PDO::PARAM_STR);
            $stmt->bindValue(':epg_diyp', $diypContent, PDO::PARAM_STR);
            $stmt->execute();
            
            // 记录被处理过
            $recordKey = $channelName . '-' . $date;
            $processedRecords[$recordKey] = true;

            // 如果是 IGNORE 插入并且未影响任何行，则计入 skipCount
            if ($action === 'IGNORE' && $stmt->rowCount() === 0) {
                $skipCount += count($diypProgrammes);
            }
        }
    }

    return $skipCount;
}

// 获取已存在的数据
function getExistingData() {
    global $db, $Config;
    $existingData = [];

    $liveSourceConfig = $Config['live_source_config'] ?? 'default';
    $stmt = $db->prepare("SELECT * FROM channels WHERE modified = 1 AND config = ?");
    $stmt->execute([$liveSourceConfig]);
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['tag'])) {
                $existingData[$row['tag']] = $row;
            }
        }
    }
    return $existingData;
}

// 频道数据模糊匹配函数
function dbChannelNameMatch($channelName) {
    global $db;
    $concat = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "CONCAT('%', channel, '%')" : "'%' || channel || '%'";
    $stmt = $db->prepare("
        SELECT channel FROM epg_data WHERE (channel = :channel OR channel LIKE :like_channel OR :channel LIKE $concat)
        ORDER BY CASE WHEN channel = :channel THEN 1 WHEN channel LIKE :like_channel THEN 2 ELSE 3 END, LENGTH(channel) DESC
        LIMIT 1
    ");
    $stmt->execute([':channel' => $channelName, ':like_channel' => $channelName . '%']);
    return $stmt->fetchColumn();
}

// 解析 txt、m3u 直播源，并生成直播列表（包含分组、地址等信息）
function doParseSourceInfo($urlLine = null, $parseAll = false) {
    // 获取当前的最大执行时间，临时设置超时时间为 20 分钟
    $original_time_limit = ini_get('max_execution_time');
    set_time_limit(20*60);

    global $liveDir, $liveFileDir, $Config;
    $liveChannelNameProcess = $Config['live_channel_name_process'] ?? false; // 标记是否处理频道名
    $liveSourceConfig = $Config['live_source_config'] ?? 'default';
    
    // 获取已存在的数据
    $existingData = getExistingData();

    // 读取 source.json 内容，处理每行 URL
    $errorLog = '';
    $sourceFilePath = $liveDir . 'source.json';
    $sourceData = json_decode(@file_get_contents($sourceFilePath), true) ?: [];

    // 如果 parseAll 为 true，就遍历所有配置项
    if ($parseAll) {
        $errorLog = '';
        foreach ($sourceData as $configName => $_) {
            $Config['live_source_config'] = $configName; // 临时覆盖当前 config 名
            $partialResult = doParseSourceInfo(null, false); // 逐个调用自己，不传 $urlLine
            if ($partialResult !== true) {
                $errorLog .= $partialResult;
            }
        }
        return $errorLog ?: true;
    }

    $sourceArray = $sourceData[$liveSourceConfig] ?? [];
    $lines = $urlLine ? [$urlLine] : array_filter(array_map('ltrim', $sourceArray));
    $allChannelData = [];
    foreach ($lines as $line) {
        if (empty($line) || $line[0] === '#') continue;
    
        // 解析 URL 和设置项
        $parts = explode('#', $line);
        $url = trim($parts[0]);
        $groupPrefix = $userAgent = $replacePattern = '';
        $white_list = $black_list = [];

        foreach ($parts as $part) {
            $part = ltrim($part);
            if (stripos($part, 'PF=') === 0 || stripos($part, 'prefix=') === 0) {
                $groupPrefix = substr($part, strpos($part, '=') + 1);
            } elseif (stripos($part, 'UA=') === 0 || stripos($part, 'useragent=') === 0) {
                $userAgent = trim(substr($part, strpos($part, '=') + 1));
            } elseif (stripos($part, 'RP=') === 0 || stripos($part, 'replace=') === 0) {
                $replacePattern = trim(substr($part, strpos($part, '=') + 1));
            } elseif (stripos($part, 'FT=') === 0 || stripos($part, 'filter=') === 0) {
                $filter_raw = strtoupper(t2s(trim(substr($part, strpos($part, '=') + 1))));
                $list = array_map('trim', explode(',', ltrim($filter_raw, '!')));
                if (strpos($filter_raw, '!') === 0) {
                    $black_list = $list;
                } else {
                    $white_list = $list;
                }
            }
        }

        // 获取 URL 内容
        $error = '';
        $urlContent = '';
        
        if (stripos($url, '/data/live/file/') === 0) {
            $urlContent = @file_get_contents(__DIR__ . $url);
            if ($urlContent === false) {
                $error = error_get_last()['message'] ?? 'file_get_contents failed with unknown error';
            }
        } else {
            [$urlContent, $error] = downloadData($url, $userAgent, 10, 10, 3);
        }
        
        $fileName = md5(urlencode($url));  // 用 MD5 对 URL 进行命名
        $localFilePath = $liveFileDir . $fileName . '.m3u';
        
        if (!$urlContent) {
            $urlContent = file_exists($localFilePath) ? file_get_contents($localFilePath) : '';
            $errorLog .= $urlContent ? "$url 使用本地缓存<br>" : "解析失败：$url<br>错误信息：$error<br>";
            if (!$urlContent) continue;
        }
        
        // 处理 GBK 编码
        $encoding = mb_detect_encoding($urlContent, ['UTF-8', 'GBK', 'CP936'], true);
        if ($encoding === 'GBK' || $encoding === 'CP936') {
            $urlContent = mb_convert_encoding($urlContent, 'UTF-8', 'GBK');
        }

        // 应用多个字符串替换规则（格式：a1->b1,a2->b2,...）
        if (strpos($replacePattern, '->') !== false) {
            foreach (explode(',', $replacePattern) as $rule) {
                if (strpos($rule, '->') !== false) {
                    [$search, $replace] = array_map('trim', explode('->', $rule, 2));
                    $urlContent = str_replace($search, $replace, $urlContent);
                }
            }
        }

        $urlContentLines = explode("\n", $urlContent);
        $urlChannelData = [];

        // 处理 M3U 格式的直播源
        if (stripos($urlContent, '#EXTM3U') === 0 || stripos($urlContent, '#EXTINF') === 0) {
            foreach ($urlContentLines as $i => $urlContentLine) {
                $urlContentLine = trim($urlContentLine);
    
                // 跳过空行和 M3U 头部
                if (empty($urlContentLine) || stripos($urlContentLine, '#EXTM3U') === 0) continue;
    
                if (stripos($urlContentLine, '#EXTINF') === 0 && isset($urlContentLines[$i + 1]) && 
                    stripos($urlContentLines[$i + 1], '#EXTINF') !== 0) {
                    // 处理 #EXTINF 行，提取频道信息
                    if (preg_match('/#EXTINF:-?\d+(.*),(.+)/', $urlContentLine, $matches)) {
                        $channelInfo = $matches[1];
                        $groupTitle = preg_match('/group-title="([^"]+)"/', $channelInfo, $match) ? trim($match[1]) : '';
                        $originalChannelName = trim($matches[2]);
                        $streamUrl = '';
                        $j = $i + 1;
                        while (!empty($urlContentLines[$j]) && $urlContentLines[$j][0] === '#') {
                            $streamUrl .= trim($urlContentLines[$j++]) . '<br>';
                        }
                        $streamUrl .= strtok(trim($urlContentLines[$j] ?? ''), '\\');
                        $tag = md5($url . $groupTitle . $originalChannelName . $streamUrl);

                        $rowData = [
                            'groupTitle' => ($groupPrefix && strpos($groupTitle, $groupPrefix) !== 0 ? $groupPrefix : '') . $groupTitle,
                            'channelName' => $originalChannelName,
                            'chsChannelName' => '',
                            'streamUrl' => $streamUrl,
                            'iconUrl' => preg_match('/tvg-logo="([^"]+)"/', $channelInfo, $match) ? $match[1] : '',
                            'tvgId' => preg_match('/tvg-id="([^"]+)"/', $channelInfo, $match) ? $match[1] : '',
                            'tvgName' => preg_match('/tvg-name="([^"]+)"/', $channelInfo, $match) ? $match[1] : '',
                            'disable' => 0,
                            'modified' => 0,
                            'source' => $url,
                            'tag' => $tag,
                            'config' => $liveSourceConfig,
                        ];

                        $urlChannelData[] = $rowData;
                    }
                }
            }
        } else {
            // 处理 TXT 格式的直播源
            $groupTitle = '';
            foreach ($urlContentLines as $urlContentLine) {
                $urlContentLine = trim($urlContentLine);
                $parts = explode(',', $urlContentLine);
            
                if (count($parts) >= 2) {
                    if (stripos($parts[1], '#genre#') !== false) {
                        $groupTitle = trim($parts[0]); // 更新 group-title
                        continue;
                    }
            
                    $originalChannelName = trim($parts[0]);
                    $streamUrl = trim($parts[1]) . (isset($parts[2]) && $parts[2] === '' ? ',' : ''); // 最后一个 , 后为空，则视为 URL 一部分
                    $tag = md5($url . $groupTitle . $originalChannelName . $streamUrl);

                    $rowData = [
                        'groupTitle' => ($groupPrefix && strpos($groupTitle, $groupPrefix) !== 0 ? $groupPrefix : '') . $groupTitle,
                        'channelName' => $originalChannelName,
                        'chsChannelName' => '',
                        'streamUrl' => $streamUrl,
                        'iconUrl' => '',
                        'tvgId' => '',
                        'tvgName' => '',
                        'disable' => 0,
                        'modified' => 0,
                        'source' => $url,
                        'tag' => $tag,
                        'config' => $liveSourceConfig,
                    ];
            
                    $urlChannelData[] = $rowData;
                }
            }
        }

        // 将所有 channelName 整合到一起，统一调用 t2s 进行繁简转换
        $channelNames = array_column($urlChannelData, 'channelName'); // 提取所有 channelName
        $chsChannelNames = ($Config['cht_to_chs'] ?? 1) === 0 ? 
            $channelNames : explode("\n", t2s(implode("\n", $channelNames))); // 繁简转换

        // 将转换后的信息写回 urlChannelData
        foreach ($urlChannelData as $index => &$row) {
            // 如果不在白名单或在黑名单中，删除该行
            $chsChannelName = $chsChannelNames[$index];
            $groupTitle = $row['groupTitle'];
            $streamUrl = $row['streamUrl'];
            $in_white = empty($white_list) || array_filter($white_list, function ($w) use ($chsChannelName, $groupTitle, $streamUrl) {
                return stripos($chsChannelName, $w) !== false || stripos($groupTitle, $w) !== false || stripos($streamUrl, $w) !== false;
            });
            $in_black = array_filter($black_list, function ($b) use ($chsChannelName, $groupTitle, $streamUrl) {
                return stripos($chsChannelName, $b) !== false || stripos($groupTitle, $b) !== false || stripos($streamUrl, $b) !== false;
            });
            if (!$in_white || $in_black) {
                unset($urlChannelData[$index]);
                continue;
            }

            // 检查该行是否已经修改
            if (isset($existingData[$row['tag']])) {
                $row = $existingData[$row['tag']];
                continue;
            }

            // 更新部分信息
            $cleanChannelName = cleanChannelName($chsChannelName);
            $dbChannelName = dbChannelNameMatch($cleanChannelName);
            $finalChannelName = $dbChannelName ?: $cleanChannelName;
            $oriChannelName = $row['channelName'];
            $row['channelName'] = $liveChannelNameProcess ? $finalChannelName : $row['channelName'];
            $row['chsChannelName'] = $chsChannelName;
            $row['iconUrl'] = ($row['iconUrl'] ?? false) && ($Config['m3u_icon_first'] ?? false)
                            ? $row['iconUrl']
                            : (iconUrlMatch([$cleanChannelName, $oriChannelName]) ?: $row['iconUrl']);
            $row['tvgName'] = $dbChannelName ?? $row['tvgName'];
        }

        generateLiveFiles($urlChannelData, "file/{$fileName}"); // 单独直播源文件
        $allChannelData = array_merge($allChannelData, $urlChannelData); // 写入 allChannelData
    }
    
    if (!$urlLine) {
        generateLiveFiles($allChannelData, 'tv'); // 总直播源文件
    }

    // 恢复原始超时时间
    set_time_limit($original_time_limit);
    
    return $errorLog ?: true;
}

// 生成 M3U 和 TXT 文件
function generateLiveFiles($channelData, $fileName, $saveOnly = false) {
    global $db, $Config, $liveDir;

    // 获取配置
    $fuzzyMatchingEnable = $Config['live_fuzzy_match'] ?? 1;
    $commentEnabled = $Config['live_url_comment'] ?? 0;
    $txtCommentEnabled = $Config['live_url_comment'] === 1 || $Config['live_url_comment'] === 3 ?? 0;
    $m3uCommentEnabled = $Config['live_url_comment'] === 2 || $Config['live_url_comment'] === 3 ?? 0;

    // 读取 template.json 文件内容
    $templateContent = '';
    $liveSourceConfig = $Config['live_source_config'] ?? 'default';
    if (file_exists($templateFilePath = $liveDir . 'template.json')) {
        $json = json_decode(file_get_contents($templateFilePath), true);
        $templateContent = isset($json[$liveSourceConfig]) ? implode("\n", (array)$json[$liveSourceConfig]) : '';
    }
    $templateExist = $templateContent !== '';
    
    $m3uContent = "#EXTM3U x-tvg-url=\"\"\n";
    $groups = [];

    // 生成更新时间
    if ($Config['gen_live_update_time'] ?? false) {
        $updateTime = date('Y-m-d H:i:s');
        $m3uContent .= "#EXTINF:-1 group-title=\"更新时间\"," . $updateTime . "\nnull\n";
        $groups['更新时间'][] = "$updateTime,null";
    }

    $liveTvgIdEnable = $Config['live_tvg_id_enable'] ?? 1;
    $liveTvgNameEnable = $Config['live_tvg_name_enable'] ?? 1;
    $liveTvgLogoEnable = $Config['live_tvg_logo_enable'] ?? 1;
    if ($fileName === 'tv' && ($Config['live_template_enable'] ?? 1) && $templateExist && !$saveOnly) {
        // 处理有模板且开启的情况
        $templateGroups = [];

        // 解析 template.json 内容
        $currentGroup = '未分组';
        foreach (explode("\n", $templateContent) as $line) {
            $line = trim($line, " ,");
            if (empty($line)) continue;            
            if (strpos($line, '#') === 0) {
                $groupParts = array_map('trim', explode(',', substr($line, 1)));
                $currentGroup = $groupParts[0];  // 提取分组名
                $currentGroupSources = array_slice($groupParts, 1);  // 提取分组源（多个值）
                $templateGroups[$currentGroup]['source'] = $currentGroupSources; // 存储为数组
            } else {
                $channels = array_map('trim', explode(',', $line));
                foreach ($channels as $channel) {
                    $templateGroups[$currentGroup]['channels'][] = $channel;
                }
            }
        }

        // 处理每个分组
        $newChannelData = [];
        foreach ($templateGroups as $templateGroupTitle => $groupInfo) {
            // 如果没有指定频道，直接检查来源、分组标题是否匹配
            if (empty($groupInfo['channels'])) {
                foreach ($channelData as $row) {
                    list($groupTitle, $channelName, , $streamUrl, $iconUrl, $tvgId, $tvgName, $disable, , $source) = array_values($row);

                    if ((!empty($groupInfo['source']) && !in_array($source, $groupInfo['source'])) || ($templateGroupTitle !== 'default' && 
                        (empty($groupTitle) || stripos($groupTitle, $templateGroupTitle) === false && stripos($templateGroupTitle, $groupTitle) === false))) {
                        continue;
                    }

                    // 更新信息
                    $streamParts = explode("<br>", $streamUrl);
                    $streamUrl = array_pop($streamParts);
                    $extraInfo = $streamParts ? implode("\n", $streamParts) . "\n" : '';
                    $m3uStreamUrl = $streamUrl . (($m3uCommentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                    $txtStreamUrl = $streamUrl . (($txtCommentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                    $rowGroupTitle = $templateGroupTitle === 'default' ? $groupTitle : $templateGroupTitle;
                    $row['groupTitle'] = $rowGroupTitle;
                    $row['streamUrl'] = $streamUrl . (($commentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                    $newChannelData[] = $row;

                    if ($disable) continue;

                    // 生成 M3U 内容
                    $extInfLine = "#EXTINF:-1" . 
                        ($tvgId && $liveTvgIdEnable ? " tvg-id=\"$tvgId\"" : "") . 
                        ($tvgName && $liveTvgNameEnable ? " tvg-name=\"$tvgName\"" : "") . 
                        ($iconUrl && $liveTvgLogoEnable ? " tvg-logo=\"$iconUrl\"" : "") . 
                        " group-title=\"$rowGroupTitle\"," . 
                        "$channelName";

                    $m3uContent .= $extInfLine . "\n" . $extraInfo . $m3uStreamUrl . "\n";
                    $groups[$rowGroupTitle][] = "$channelName,$txtStreamUrl";
                }
            } else {
                // 获取繁简转换后的模板频道名称
                $groupChannels = $groupInfo['channels'];
                $cleanChsGroupChannelNames = explode("\n", t2s(implode("\n", array_map('cleanChannelName', $groupChannels))));

                // 如果指定了频道，先遍历 $groupChannels，保证顺序不变
                foreach ($groupChannels as $index => $groupChannelName) {
                    $cleanChsGroupChannelName = $cleanChsGroupChannelNames[$index];
                    foreach ($channelData as $row) {
                        list($groupTitle, $channelName, $chsChannelName, $streamUrl, $iconUrl, $tvgId, $tvgName, $disable, , $source) = array_values($row);

                        // 检查来源匹配
                        if (!empty($groupInfo['source']) && !in_array($source, $groupInfo['source'])) {
                            continue;
                        }

                        // 检查频道名称是否匹配
                        $cleanChsChannelName = cleanChannelName($chsChannelName);

                        // CGTN 和 CCTV 不进行模糊匹配
                        if ($channelName === $groupChannelName || 
                            ($fuzzyMatchingEnable && ($cleanChsChannelName === $cleanChsGroupChannelName || 
                            stripos($cleanChsGroupChannelName, 'CGTN') === false && stripos($cleanChsGroupChannelName, 'CCTV') === false && !empty($cleanChsChannelName) && 
                            (stripos($cleanChsChannelName, $cleanChsGroupChannelName) || stripos($cleanChsGroupChannelName, $cleanChsChannelName)) || 
                            (strpos($groupChannelName, 'regex:') === 0) && @preg_match(substr($groupChannelName, 6), $channelName . $cleanChsChannelName)))) {
                            // 更新信息
                            $streamParts = explode("<br>", $streamUrl);
                            $streamUrl = array_pop($streamParts);
                            $extraInfo = $streamParts ? implode("\n", $streamParts) . "\n" : '';
                            $m3uStreamUrl = $streamUrl . (($m3uCommentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                            $txtStreamUrl = $streamUrl . (($txtCommentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                            $rowGroupTitle = $templateGroupTitle === 'default' ? $groupTitle : $templateGroupTitle;
                            $row['groupTitle'] = $rowGroupTitle;
                            $row['channelName'] = strpos($groupChannelName, 'regex:') === 0 ? $channelName : $groupChannelName; // 正则表达式使用原频道名
                            $row['streamUrl'] = $streamUrl . (($commentEnabled && strpos($streamUrl, '$') === false) ? "\${$groupTitle}" : "");
                            $newChannelData[] = $row;

                            if ($disable) continue;

                            // 生成 M3U 内容
                            $extInfLine = "#EXTINF:-1" . 
                                ($tvgId && $liveTvgIdEnable ? " tvg-id=\"$tvgId\"" : "") . 
                                ($tvgName && $liveTvgNameEnable ? " tvg-name=\"$tvgName\"" : "") . 
                                ($iconUrl && $liveTvgLogoEnable ? " tvg-logo=\"$iconUrl\"" : "") . 
                                " group-title=\"$rowGroupTitle\"," . 
                                $row['channelName']; // 使用 $groupChannels 中的名称

                            $m3uContent .= $extInfLine . "\n" . $extraInfo . $m3uStreamUrl . "\n";
                            $groups[$rowGroupTitle][] = $row['channelName'] . ",$txtStreamUrl";
                        }
                    }
                }
            }
        }
        $channelData = $newChannelData;
    } else {
        // 处理没有模板及仅保存修改信息的情况
        foreach ($channelData as $row) {
            list($groupTitle, $channelName, , $streamUrl, $iconUrl, $tvgId, $tvgName, $disable) = array_values($row);
            if ($disable) continue;
    
            // 生成 M3U 内容
            $extInfLine = "#EXTINF:-1" . 
                ($tvgId && $liveTvgIdEnable ? " tvg-id=\"$tvgId\"" : "") . 
                ($tvgName && $liveTvgNameEnable ? " tvg-name=\"$tvgName\"" : "") . 
                ($iconUrl && $liveTvgLogoEnable ? " tvg-logo=\"$iconUrl\"" : "") . 
                ($groupTitle ? " group-title=\"$groupTitle\"" : "") . 
                ",$channelName";
                
            $streamParts = explode("<br>", $streamUrl);
            $streamUrl = array_pop($streamParts);
            $extraInfo = $streamParts ? implode("\n", $streamParts) . "\n" : '';
            $m3uStreamUrl = $m3uCommentEnabled ? $streamUrl : strtok($streamUrl, '$');
            $txtStreamUrl = $txtCommentEnabled ? $streamUrl : strtok($streamUrl, '$');
            $m3uContent .= $extInfLine . "\n" . $extraInfo . $m3uStreamUrl . "\n";
            $groups[$groupTitle ?: "未分组"][] = "$channelName,$txtStreamUrl";
        }
    }

    // 生成 TXT 内容
    $txtContent = "";
    foreach ($groups as $group => $channels) {
        $txtContent .= "$group,#genre#\n" . implode("\n", $channels) . "\n\n";
    }
    $txtContent = trim($txtContent);

    // 如果 fileName 是 tv，则只保存加密名的文件，并更新数据库
    if ($fileName === 'tv') {
        $fileName = 'file/' . md5(urlencode($liveSourceConfig));

        // 删除当前 liveSourceConfig 对应的旧数据
        $stmt = $db->prepare("DELETE FROM channels WHERE config = ?");
        $stmt->execute([$liveSourceConfig]);
    
        // 批量插入新数据
        $db->beginTransaction();
        $insertStmt = $db->prepare("
            INSERT INTO channels (
                groupTitle, channelName, chsChannelName, streamUrl,
                iconUrl, tvgId, tvgName, disable, modified,
                source, tag, config
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($channelData as $row) {
            $insertStmt->execute([
                $row['groupTitle'] ?? '',
                $row['channelName'] ?? '',
                $row['chsChannelName'] ?? '',
                $row['streamUrl'] ?? '',
                $row['iconUrl'] ?? '',
                $row['tvgId'] ?? '',
                $row['tvgName'] ?? '',
                $row['disable'] ?? 0,
                $row['modified'] ?? 0,
                $row['source'] ?? '',
                $row['tag'] ?? '',
                $liveSourceConfig
            ]);
        }
        $db->commit();
    }

    // 保存 M3U / TXT 文件
    file_put_contents("{$liveDir}{$fileName}.m3u", $m3uContent);
    file_put_contents("{$liveDir}{$fileName}.txt", $txtContent);
}
?>