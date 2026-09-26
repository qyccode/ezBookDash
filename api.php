<?php
/**
 * ezBookDash - 数据代理（ezBookkeeping 记账看板）
 *
 * 职责：登录认证（token 自动缓存续期）→ 调用 ezBookkeeping API → 聚合统计 → 文件缓存
 * 安全：账密与 token 只存在服务端，浏览器仅能拿到聚合后的统计数据
 *
 * 输出接口：
 *   api.php?action=dashboard&range=month|3m|6m|1y|year[&fresh=1]
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* 本文件所有响应都是 JSON，绝不能让 PHP 的警告/提示混进响应体。
   display_errors=On 时 PHP 会以 HTML 形式输出（<br /><b>Warning</b>…），
   哪怕后面紧跟着正确的 {"success":true}，前端 JSON.parse 也会报
   "Unexpected token '<'" —— 于是「编辑成功」被显示成「保存失败」。
   这里统一关闭展示；「成败」只以 JSON 里的 success 字段为准。 */
ini_set('display_errors', '0');

$runtime   = require __DIR__ . '/bootstrap.php';
$config    = $runtime['config'];
$CACHE_DIR = $runtime['cache_dir'];
$DATA_DIR  = $runtime['data_dir'];

/* ================= 基础工具 ================= */

function fail(string $msg): void
{
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_token'];
}

function requireSameOrigin(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($originHost === '' || $requestHost === '' || $originHost !== $requestHost) {
        fail('请求来源校验失败，请刷新页面后重试');
    }
}

function requireCsrf(): void
{
    if (empty($_SESSION['ebk_token'])) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sent = (string)($_SERVER['HTTP_X_EZBOOKDASH_CSRF'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        fail('安全令牌已过期，请刷新页面后重试');
    }
}

function loginRateFile(string $cacheDir): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $cacheDir . '/login_' . substr(hash('sha256', $ip), 0, 20) . '.json';
}

function loginRateCheck(string $cacheDir): void
{
    $file = loginRateFile($cacheDir);
    $raw = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $attempts = array_values(array_filter((array)($raw['attempts'] ?? []), static fn($t): bool => (int)$t > time() - 900));
    if (count($attempts) >= 8) {
        fail('登录尝试过于频繁，请 15 分钟后再试');
    }
}

function loginRateFail(string $cacheDir): void
{
    $file = loginRateFile($cacheDir);
    $raw = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $attempts = array_values(array_filter((array)($raw['attempts'] ?? []), static fn($t): bool => (int)$t > time() - 900));
    $attempts[] = time();
    @file_put_contents($file, json_encode(['attempts' => $attempts]), LOCK_EX);
    @chmod($file, 0600);
}

function loginRateClear(string $cacheDir): void
{
    @unlink(loginRateFile($cacheDir));
}

function cacheGet(string $dir, string $key, int $ttl)
{
    $raw = cacheGetRaw($dir, $key);
    if ($raw === null || (time() - (int)($raw['_t'] ?? 0)) > $ttl) {
        return null;
    }
    return $raw['d'] ?? null;
}

/** 读取缓存原始结构（[' _t'=>写入时间, 'd'=>数据]），不判新鲜度，由调用方按 age 决定用途（SWR 需要） */
function cacheGetRaw(string $dir, string $key): ?array
{
    $file = $dir . '/' . $key . '.json';
    if (!is_file($file)) {
        return null;
    }
    $raw = @json_decode((string)@file_get_contents($file), true);
    return is_array($raw) ? $raw : null;
}

/**
 * 确保目录存在（幂等）。凡是要落盘的地方都先过这里，**不要依赖「目录已经手工建好」**：
 * ① 由 PHP 进程创建的目录属主就是 PHP 自己（www / www-data）；用 FTP 或面板文件管理器
 *    手建的目录常常归 root 或面板账号，PHP 写不进去 —— 而落盘处都带 `@`，失败是**静默的**，
 *    属于最难查的一类故障（目录明明在、权限看着也对，就是不写）。
 * ② 幂等：已存在时 `mkdir` 只返回 false，不报错、不影响已有目录。
 * ③ 全新安装 / 换服务器 / 建测试环境时，不需要任何人记得「先建目录」这个隐藏前提。
 * 实测（php 8.x）：目标目录不存在时 `rename()` 与 `file_put_contents()` 都返回 false。
 */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

function cacheSet(string $dir, string $key, $data): void
{
    ensure_dir($dir);
    $file = $dir . '/' . $key . '.json';
    if (@file_put_contents($file, json_encode(['_t' => time(), 'd' => $data]), LOCK_EX) !== false) {
        @chmod($file, 0600);
    }
}

/**
 * 失效单个缓存（新建标签后要让 meta 重新拉到最新标签列表）。
 * ⚠️ unlink 失败（被 disable_functions 禁用 / 属主不对 / 权限不足）时**不能静默放弃**：
 * 那会让「写交易后失效缓存」整个变成空操作，F5 永远拿旧数据且毫无报错。
 * 兜底 = **软删除**：把文件覆写成 `_t=1`（纪元起点），所有消费方（cacheGet 的 TTL 判断、
 * dashboard/meta 的 SWR 年龄判断）都会把它当彻底过期 → 触发回源重建，语义与删除等价。
 * （目录本身不可写时覆写同样失败 —— 但那种情况下重建也失败，属于更大的环境问题，
 *  api.php?action=ping 的 cacheDirWritable 字段专门用来暴露这种情况。）
 */
/**
 * 删除一个缓存文件（给定完整路径），unlink 失败时软删除兜底。
 * @return bool true = 文件已失效（真删掉或已覆写为过期）；false = 两者都失败（目录不可写等）
 */
function cacheDelPath(string $file): bool
{
    if (@unlink($file)) {
        return true;
    }
    $dir = dirname($file);
    if (is_dir($dir)) {
        return @file_put_contents($file, json_encode(['_t' => 1]), LOCK_EX) !== false;
    }
    return false;
}

function cacheDel(string $dir, string $key): void
{
    cacheDelPath($dir . '/' . $key . '.json');
}

/* ================= ezBookkeeping 客户端 ================= */

class EbkUnauthorizedException extends RuntimeException {}

class EbkClient
{
    private string $baseUrl;
    private string $timezone;
    private bool $sslVerify;

    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim((string)$config['base_url'], '/');
        $this->timezone  = (string)$config['timezone'];
        $this->sslVerify = (bool)($config['ssl_verify'] ?? false);
    }

    /** HTTP JSON 请求，返回 [httpCode, bodyArray] */
    private function httpJson(string $method, string $url, ?array $jsonBody, array $headers, int $timeoutSec = 20): array
    {
        $ch = curl_init($url);
        $hdrs = array_merge([
            'Accept: application/json',
            'X-Timezone-Name: ' . $this->timezone,
        ], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_HTTPHEADER     => $hdrs,
            // 生产默认严格校验证书；可信内网使用自签证书时可在配置中显式关闭。
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody === null ? '' : json_encode($jsonBody));
            $hdrs[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $errno = (int)curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            if ($errno === 28) {   // CURLE_OPERATION_TIMEDOUT
                throw new RuntimeException('AI 识别超时（LLM 60 秒无响应），请重试；持续超时建议在 EZBK 后台更换响应更快的模型');
            }
            throw new RuntimeException('无法连接 ezBookkeeping 服务器：' . $err);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ezBookkeeping 返回了无法解析的内容（HTTP ' . $code . '）');
        }
        return [$code, $decoded];
    }

    /** 账密登录，返回 token（注：ezBookkeeping 登录字段为 loginName，支持用户名或邮箱） */
    public function loginWithPassword(string $loginName, string $password): string
    {
        [$code, $resp] = $this->httpJson('POST', $this->baseUrl . '/api/authorize.json', [
            'loginName' => $loginName,
            'password'  => $password,
        ], []);

        if (($resp['success'] ?? false) !== true) {
            $msg = (string)($resp['errorMessage'] ?? '用户名或密码错误');
            throw new RuntimeException('登录失败：' . $msg);
        }
        if (!empty($resp['result']['need2FA'])) {
            throw new RuntimeException('该账号开启了两步验证（2FA），请改用 API 令牌登录。');
        }
        $token = (string)($resp['result']['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('登录成功但未返回 token');
        }
        return $token;
    }

    /** 校验 API 令牌有效性，返回用户 profile */
    public function verifyToken(string $token): array
    {
        [$code, $resp] = $this->httpJson('GET', $this->baseUrl . '/api/v1/users/profile/get.json', null, ['Authorization: Bearer ' . $token]);
        if ($code === 401 || (($resp['success'] ?? false) !== true)) {
            $msg = (string)($resp['errorMessage'] ?? '令牌无效或已失效');
            throw new RuntimeException('API 令牌无效：' . $msg);
        }
        return $resp['result'] ?? [];   // profile 正常都有 result，兜底避免 "Undefined array key"
    }

    /** 带认证的 GET 请求（单次）；token 失效抛 EbkUnauthorizedException */
    public function request(string $path, array $query, string $token)
    {
        $url = $this->baseUrl . '/api/v1/' . $path . (empty($query) ? '' : '?' . http_build_query($query));
        [$code, $resp] = $this->httpJson('GET', $url, null, ['Authorization: Bearer ' . $token]);

        if ($code === 401 || (($resp['success'] ?? false) === false
            && stripos((string)($resp['errorMessage'] ?? ''), 'authoriz') !== false)) {
            throw new EbkUnauthorizedException($path);
        }
        if (($resp['success'] ?? false) !== true) {
            $msg = (string)($resp['errorMessage'] ?? ('请求失败（HTTP ' . $code . '）'));
            throw new RuntimeException('调用 ' . $path . ' 失败：' . $msg);
        }
        return $resp['result'] ?? [];   // 只读接口，result 缺失按空处理
    }

    /**
     * POST JSON 请求（写操作：transactions/add、llm/recognize_text 等）。
     * 返回 result；401 抛未授权，业务失败抛异常。
     *
     * 注意：不是每个写接口都返回 result。transactions/modify.json、delete.json
     * 成功时只有 {"success":true}，直接用 $resp['result'] 会触发
     * "Undefined array key \"result\"" 警告（display_errors=On 时即以 HTML 污染 JSON）。
     * 所以这里统一用 ?? null 兜底，调用方按需判断。
     */
    public function requestPost(string $path, array $body, string $token, int $timeoutSec = 20)
    {
        $url = $this->baseUrl . '/api/v1/' . $path;
        [$code, $resp] = $this->httpJson('POST', $url, $body, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], $timeoutSec);
        if ($code === 401 || (($resp['success'] ?? false) === false
            && stripos((string)($resp['errorMessage'] ?? ''), 'authoriz') !== false)) {
            throw new EbkUnauthorizedException($path);
        }
        if (($resp['success'] ?? false) !== true) {
            $msg = (string)($resp['errorMessage'] ?? '');
            if ($msg === '') {
                if (in_array($code, [502, 504], true)) {
                    $msg = 'LLM 服务暂时无响应（网关 ' . $code . '），请稍后重试；若持续失败请检查 EZBK 的 LLM 配置';
                } else {
                    $msg = '请求失败（HTTP ' . $code . '）';
                }
            }
            throw new RuntimeException('调用 ' . $path . ' 失败：' . $msg);
        }
        return $resp['result'] ?? null;   // modify/delete 成功时无 result，不能裸读
    }

    /**
     * 并行请求多个端点（curl_multi）。
     * 相比逐个串行调用 request()，总耗时从「各请求之和」降为「最慢的一个」，
     * 9 个端点冷启动可从 2~4s 提速到 0.4~0.8s。
     *
     * @param array<string, array{0:string,1:array}> $defs key => [path, query]
     * @return array<string, mixed> key => result
     * @throws EbkUnauthorizedException 任一请求鉴权失败（整体重试由调用方处理）
     * @throws RuntimeException          任一请求连接失败/返回错误
     */
    /**
     * 并行取多个端点（curl_multi）。
     * $tolerate 里的 key 允许单独失败：不进 $results、也不抛错（用于「上游可能没这个接口」的可选数据，
     * 如标签 —— 否则它会把整批拖垮，只能让它单独占一个串行 RTT）。
     * 401 永远不被容忍：必须抛出去让上层重登后整体重放。
     */
    public function parallelRequests(array $defs, string $token, array $tolerate = []): array
    {
        if (empty($defs)) {
            return [];
        }
        $headers = [
            'Accept: application/json',
            'X-Timezone-Name: ' . $this->timezone,
            'Authorization: Bearer ' . $token,
        ];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($defs as $key => [$path, $query]) {
            $url = $this->baseUrl . '/api/v1/' . $path . (empty($query) ? '' : '?' . http_build_query($query));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
                CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            curl_multi_exec($mh, $active);
            if ($active > 0) {
                if (curl_multi_select($mh, 0.5) === -1) {
                    usleep(50000); // select 失败时短暂休眠，避免忙等
                }
            }
        } while ($active > 0);

        $results = [];
        $unauthorized = false;
        $firstError = null;
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === false || $code === 0) {
                if (!in_array($key, $tolerate, true)) {
                    $firstError = $firstError ?: new RuntimeException('无法连接 ezBookkeeping 服务器：' . $err);
                }
                continue;
            }
            $decoded = json_decode((string)$body, true);
            if (!is_array($decoded)) {
                if (!in_array($key, $tolerate, true)) {
                    $firstError = $firstError ?: new RuntimeException('ezBookkeeping 返回了无法解析的内容（HTTP ' . $code . '，' . $key . '）');
                }
                continue;
            }
            if ($code === 401 || (($decoded['success'] ?? false) === false
                && stripos((string)($decoded['errorMessage'] ?? ''), 'authoriz') !== false)) {
                $unauthorized = true; // 全部处理完再统一抛，让重登后一次性重放
                continue;
            }
            if (($decoded['success'] ?? false) !== true) {
                if (!in_array($key, $tolerate, true)) {
                    $msg = (string)($decoded['errorMessage'] ?? ('HTTP ' . $code));
                    $firstError = $firstError ?: new RuntimeException('调用 ' . $key . ' 失败：' . $msg);
                }
                continue;
            }
            $results[$key] = $decoded['result'] ?? null;
        }
        curl_multi_close($mh);

        if ($unauthorized) {
            throw new EbkUnauthorizedException('parallel');
        }
        if ($firstError !== null) {
            throw $firstError;
        }
        return $results;
    }
}

/* ================= 时间区间计算 ================= */

/**
 * 返回所选区间的 [start, end]（当前区间）与 [prevStart, prevEnd]（上一等长区间），
 * 以及近 12 个月的年月字符串数组（'Y-m'）。
 */
function buildRanges(string $range, DateTimeZone $tz): array
{
    $now = new DateTime('now', $tz);
    $now->setTime(23, 59, 59);

    $monthStart = (new DateTime('now', $tz))->modify('first day of this month')->setTime(0, 0, 0);

    switch ($range) {
        case '3m':
            $start = (clone $monthStart)->modify('-2 months');
            $prevStart = (clone $start)->modify('-3 months');
            break;
        case '6m':
            $start = (clone $monthStart)->modify('-5 months');
            $prevStart = (clone $start)->modify('-6 months');
            break;
        case '1y':
            $start = (clone $monthStart)->modify('-11 months');
            $prevStart = (clone $start)->modify('-12 months');
            break;
        case 'year':
            $start = (new DateTime('Jan 1', $tz))->setTime(0, 0, 0);
            $prevStart = (clone $start)->modify('-1 year');
            break;
        case 'month':
        default:
            $start = clone $monthStart;
            $prevStart = (clone $monthStart)->modify('-1 month');
            $range = 'month';
    }

    $prevEnd = (clone $start)->setTime(0, 0, 0)->modify('-1 second');

    // 近 12 个月的年月列表（含当月）
    $months = [];
    $m = (clone $monthStart)->modify('-11 months');
    for ($i = 0; $i < 12; $i++) {
        $months[] = $m->format('Y-m');
        $m->modify('+1 month');
    }

    $trendStart = (clone $monthStart)->modify('-11 months')->format('Y-m');
    $trendEnd   = $monthStart->format('Y-m');

    return [
        'start'     => $start->getTimestamp(),
        'end'       => $now->getTimestamp(),
        'prevStart' => $prevStart->getTimestamp(),
        'prevEnd'   => $prevEnd->getTimestamp(),
        'months'    => $months,
        'trendFrom' => $trendStart,
        'trendTo'   => $trendEnd,
        // 资产趋势固定看近 12 个月
        'assetFrom' => (clone $monthStart)->modify('-11 months')->setTime(0, 0, 0)->getTimestamp(),
        'assetTo'   => $now->getTimestamp(),
    ];
}

/**
 * 自定义日期区间（YYYY-MM-DD ~ YYYY-MM-DD）：
 * 当前区间 = [from 00:00, to 23:59]；环比上期 = 之前等长区间；
 * 收支趋势/资产趋势跟随自定义区间（资产曲线终点仍为今天，便于看到当前净资产）。
 * 校验失败（格式错 / from>to / 跨度超 2 年）返回 null。
 */
function buildCustomRanges(string $from, string $to, DateTimeZone $tz): ?array
{
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', $tz);
    $endD  = DateTime::createFromFormat('Y-m-d H:i:s', $to . ' 23:59:59', $tz);
    if (!$start || !$endD || $start->format('Y-m-d') !== $from || $endD->format('Y-m-d') !== $to) {
        return null;
    }
    if ($start > $endD) {
        return null;
    }
    // 防滥用：跨度上限 2 年（趋势图与逐日查询数据量可控）
    if ($endD->getTimestamp() - $start->getTimestamp() > 366 * 2 * 86400) {
        return null;
    }

    $dur       = $endD->getTimestamp() - $start->getTimestamp();
    $prevEnd   = $start->getTimestamp() - 1;          // 前一秒起往前推等长区间
    $prevStart = $prevEnd - $dur;

    $fromMonth = (clone $start)->modify('first day of this month');
    $toMonth   = (clone $endD)->modify('first day of this month');
    $months    = [];
    for ($m = clone $fromMonth; $m <= $toMonth; $m->modify('+1 month')) {
        $months[] = $m->format('Y-m');
    }

    return [
        'start'     => $start->getTimestamp(),
        'end'       => $endD->getTimestamp(),
        'prevStart' => $prevStart,
        'prevEnd'   => $prevEnd,
        'months'    => $months,
        'trendFrom' => $fromMonth->format('Y-m'),
        'trendTo'   => $toMonth->format('Y-m'),
        'assetFrom' => $start->getTimestamp(),
        'assetTo'   => (new DateTime('now', $tz))->getTimestamp(),
    ];
}

/* ================= 数据聚合 ================= */

// 官方 API 金额固定为两位小数的整数（见官方文档与前端 AMOUNT_FACTOR=10^2），
// 例：1234 表示 12.34。与用户界面上的"金额显示位数"设置无关。
const AMOUNT_FACTOR = 100.0;

function amountOf($raw, float $div): float
{
    return round(((int)$raw) / $div, 2);
}

/**
 * 将某币种金额（最小单位整数）换算为用户默认币种的最小单位整数。
 * 汇率语义（官方文档）：rate 表示 1 单位基准货币 = rate 单位该货币。
 */
function convertToDefault(int $rawAmount, string $currency, array $rateMap, string $baseCurrency, string $defaultCurrency): int
{
    $valueInBase = (float)$rawAmount;
    if ($currency !== $baseCurrency && isset($rateMap[$currency]) && $rateMap[$currency] != 0.0) {
        $valueInBase = $rawAmount / $rateMap[$currency];
    }
    if ($baseCurrency !== $defaultCurrency && isset($rateMap[$defaultCurrency]) && $rateMap[$defaultCurrency] != 0.0) {
        $valueInBase = $valueInBase * $rateMap[$defaultCurrency];
    }
    return (int)round($valueInBase);
}

/**
 * 将 statistics.json 的 items 按「收入/支出」归类汇总（多币种按账户币种×汇率换算为默认币种）
 * 分类类型：1=收入 2=支出 3=转账（转账不计入收支）
 * $ctx: [catMap, accountMap, rateMap, baseCurrency, defaultCurrency]
 */
function aggregateStatistics(array $items, array $ctx, array &$totals, array &$byCat): void
{
    [$catMap, $accountMap, $rateMap, $baseCurrency, $defaultCurrency] = $ctx;
    $totals = ['income' => 0.0, 'expense' => 0.0];
    $byCat  = ['income' => [], 'expense' => []];
    foreach ($items as $it) {
        $cid = (int)($it['categoryId'] ?? 0);
        if ($cid <= 0 || !isset($catMap[$cid])) {
            continue;
        }
        $type = (int)$catMap[$cid]['type'];
        if ($type === 2) {
            $key = 'expense';
        } elseif ($type === 1) {
            $key = 'income';
        } else {
            continue; // 转账分类
        }
        // 真实 API 统计 items 金额字段名为 "amount"（官方模型 TransactionStatisticResponseItem: json:"amount"）
        $raw = (int)($it['amount'] ?? $it['totalAmount'] ?? 0);
        $accCurrency = (string)(($accountMap[(int)($it['accountId'] ?? 0)])['currency'] ?? $defaultCurrency);
        $raw = convertToDefault($raw, $accCurrency, $rateMap, $baseCurrency, $defaultCurrency);
        $amt = amountOf($raw, AMOUNT_FACTOR);
        $byCat[$key][$cid] = ($byCat[$key][$cid] ?? 0.0) + $amt;
        $totals[$key] += $amt;
    }

    arsort($byCat['income']);
    arsort($byCat['expense']);
}

/** 将 categories/list.json 的按类型分组结构拍平为 [id => 分类]（dashboard 与 cal_month 共用） */
function flattenCategories(array $categories): array
{
    $catMap = [];
    if (!$categories) {
        return $catMap;
    }
    $flatten = function (array $list) use (&$flatten, &$catMap) {
        foreach ($list as $c) {
            if (!is_array($c)) {
                continue;
            }
            $catMap[(int)$c['id']] = $c;
            if (!empty($c['subCategories']) && is_array($c['subCategories'])) {
                $flatten($c['subCategories']);
            }
        }
    };
    // 判断是「按类型分组的对象」（key 为类型，value 是分类列表）还是「扁平分类数组」（元素含 id）
    $isGrouped = false;
    foreach ($categories as $v) {
        if (is_array($v) && !isset($v['id'])) {
            $isGrouped = true;
            break;
        }
    }
    if ($isGrouped) {
        foreach ($categories as $typeList) {
            if (is_array($typeList)) {
                $flatten($typeList);
            }
        }
    } else {
        $flatten($categories);
    }
    return $catMap;
}

/** daily.json 行 → 前端每日收支（多币种换算为默认币种；dashboard 与 cal_month 共用） */
function mapDailyRows(array $rows, array $rateMap, string $baseCurrency, string $defaultCurrency, float $div): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $incRaw = 0;
        $expRaw = 0;
        foreach ((array)($row['amounts'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $currency = (string)($a['currency'] ?? $defaultCurrency);
            $incRaw += convertToDefault((int)($a['incomeAmount'] ?? 0), $currency, $rateMap, $baseCurrency, $defaultCurrency);
            $expRaw += convertToDefault((int)($a['expenseAmount'] ?? 0), $currency, $rateMap, $baseCurrency, $defaultCurrency);
        }
        $out[] = [
            'date'    => (string)($row['date'] ?? ''),
            'income'  => round($incRaw / $div, 2),
            'expense' => round($expRaw / $div, 2),
        ];
    }
    return $out;
}

/** 沿 parentId 向上找顶级大类（名称+颜色），最多 5 层防环；转账/余额调整无分类时返回空 */
function catTopLevel(array $catMap, int $cid): array
{
    $cur = $catMap[$cid] ?? null;
    if (!$cur) {
        return [];
    }
    $top = $cur;
    for ($i = 0; $i < 5; $i++) {
        $pid = (int)($top['parentId'] ?? 0);
        if ($pid <= 0 || !isset($catMap[$pid])) {
            break;
        }
        $top = $catMap[$pid];
    }
    return ['name' => (string)($top['name'] ?? ''), 'color' => '#' . ltrim((string)($top['color'] ?? ''), '#')];
}

/** 交易列表 → 前端行（最近交易与当日明细共用） */
function mapTransactionRows(array $items, array $catMap, float $div, string $defaultCurrency): array
{
    $out = [];
    foreach ($items as $tr) {
        if (!is_array($tr)) {
            continue;
        }
        $type   = (int)($tr['type'] ?? 0);
        $cid    = (int)($tr['categoryId'] ?? 0);
        $srcAcc = $tr['sourceAccount'] ?? null;
        $dstAcc = $tr['destinationAccount'] ?? null;
        $cat    = $tr['category'] ?? ($catMap[$cid] ?? null);
        // 转账 pill 颜色跟随「账户互转」分类自身的颜色（与二级分类色点一致），缺省回退品牌蓝
        $trColor = '#' . ltrim((string)($cat['color'] ?? ''), '#') ?: '#3f66f8';
        $top = match ($type) {
            2, 3, 4 => catTopLevel($catMap, $cid),                               // 收支/转账：沿分类树上溯大类
            default => [],                                                       // 余额调整：无标签
        };
        if ($type === 4 && !$top) {
            // 兼容旧数据或没有分类的转账，才回退到通用「转账」标签。
            $top = ['name' => '转账', 'color' => $trColor];
        }
        $out[] = [
            'id'          => (string)($tr['id'] ?? ''),
            'type'        => $type, // 1余额调整 2收入 3支出 4转账
            'time'        => (int)($tr['time'] ?? 0),
            'amount'      => amountOf($tr['sourceAmount'] ?? 0, $div),
            /* 转账编辑时必须把目标金额原样带回。跨币种转账的目标金额可能与来源金额不同，
               如果只回传 sourceAmount，修改备注也会把汇率关系悄悄改掉。 */
            'destinationAmount' => amountOf($tr['destinationAmount'] ?? 0, $div),
            'currency'    => (string)(($srcAcc ?? [])['currency'] ?? $defaultCurrency),
            'category'    => (string)($cat['name'] ?? '未分类'),
            /* 必须回传真实 categoryId：前端编辑弹层靠它还原分类。
               缺了它前端只能拿「名称」反查分类树，而**大类**（餐饮/购物/交通…）在树里是分组、
               不是子项 → 反查落空 → renderConfirm 兜底成第一个子类，
               用户只改个金额保存就会把大类悄悄改成「买菜做饭」。 */
            'categoryId'  => (string)$cid,
            'categoryColor' => '#' . ltrim((string)($cat['color'] ?? ''), '#'),
            'parent'      => (string)($top['name'] ?? ''),
            'parentColor' => (string)($top['color'] ?? ''),
            'account'     => (string)($srcAcc['name'] ?? ''),
            'destAccount' => (string)($dstAcc['name'] ?? ''),
            'comment'     => (string)($tr['comment'] ?? ''),
            /* 编辑/删除功能所需：真实 ID 与标签（字符串化防大数精度丢失） */
            'sourceAccountId'     => (string)($tr['sourceAccountId'] ?? ($srcAcc['id'] ?? '')),
            'destinationAccountId'=> (string)($tr['destinationAccountId'] ?? ($dstAcc['id'] ?? '0')),
            'tagIds'              => array_values(array_map('strval', (array)($tr['tagIds'] ?? []))),
        ];
    }
    return $out;
}

/** 带自动续期的并行拉取（dashboard 与 cal_month 共用）：任一 401 → 账密重登后整体重放 */
function ebk_fetch_parallel(array $defs, array $tolerate = []): array
{
    global $client, $token;
    try {
        return $client->parallelRequests($defs, $token, $tolerate);
    } catch (EbkUnauthorizedException $e) {
        if (($_SESSION['ebk_mode'] ?? '') === 'password' && !empty($_SESSION['ebk_loginName']) && !empty($_SESSION['ebk_password'])) {
            $token = $client->loginWithPassword((string)$_SESSION['ebk_loginName'], (string)($_SESSION['ebk_password'] ?? ''));
            $_SESSION['ebk_token'] = $token;
            return $client->parallelRequests($defs, $token, $tolerate);
        }
        $_SESSION = [];
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 低频元数据的**统一取数层**（profile / accounts / categories / fx / tags）：
 * 缓存键 `meta_<name>_<tokenHash>`，TTL = cache_meta_ttl（默认 1 小时），未命中的合并成**一次** curl_multi。
 *
 * 为什么要有这层：看板、日历、记账卡片(action=meta)、识别(ebk_ensure_meta) 以前各自持有一份私有缓存，
 * 同一批「分类 + 账户」被反复拉。实测「清缓存 → 打开看板 → 第一次点记一笔」时，看板刚拉过的分类/账户
 * 在 action=meta 里又被重拉一遍（外加上游每枪都在，标签还得再挨一个串行 RTT）→ 记账卡片第一次打开明显卡顿。
 * 全走这层后，同一份数据 1 小时内只打上游一次，谁先打开谁拉、后面的直接命中。
 *
 * $tolerate：`['tags' => []]` 形式，声明「这个 key 允许失败」+ 失败时的兜底值。
 * 失败的 key 会记一条 `meta_<name>_miss_<hash>` 负面缓存（5 分钟），避免每次冷读都白等一个 RTT；
 * 用短 TTL 而不是 1 小时，是怕偶发网络抖动把标签整整一小时藏起来。
 */
function ebk_meta_bundle(array $defs, string $CACHE_DIR, array $tolerate = []): array
{
    global $config, $token;
    $ttl    = (int)($config['cache_meta_ttl'] ?? 3600);
    $tkHash = substr(md5((string)$token), 0, 8);
    $out  = [];
    $todo = [];
    foreach ($defs as $k => $def) {
        $m = cacheGet($CACHE_DIR, "meta_{$k}_{$tkHash}", $ttl);
        if ($m !== null) {
            $out[$k] = $m;
            continue;
        }
        if (array_key_exists($k, $tolerate) && cacheGet($CACHE_DIR, "meta_{$k}_miss_{$tkHash}", 300) !== null) {
            $out[$k] = $tolerate[$k];   // 刚失败过，5 分钟内不再重试
            continue;
        }
        $todo[$k] = $def;
    }
    if ($todo) {
        $got = ebk_fetch_parallel($todo, array_keys($tolerate));
        foreach ($got as $k => $v) {
            cacheSet($CACHE_DIR, "meta_{$k}_{$tkHash}", $v);
            $out[$k] = $v;
        }
        foreach (array_keys($todo) as $k) {          // 批里没回来的 = 被容忍的失败
            if (!array_key_exists($k, $out)) {
                cacheSet($CACHE_DIR, "meta_{$k}_miss_{$tkHash}", []);
                $out[$k] = $tolerate[$k] ?? [];
            }
        }
    }
    return $out;
}

/**
 * 让统一低频层的某些 key 立即失效（含负面缓存标记），下次读会重新打上游。
 * 新增/改动类接口（如 tag_add）必须调它 —— 否则新写的 tag 会被 1 小时的 tags 缓存挡住看不见。
 */
function ebk_meta_forget(string $CACHE_DIR, array $keys): void
{
    global $token;
    $tkHash = substr(md5((string)$token), 0, 8);
    foreach ($keys as $k) {
        cacheDel($CACHE_DIR, "meta_{$k}_{$tkHash}");
        cacheDel($CACHE_DIR, "meta_{$k}_miss_{$tkHash}");
    }
}

/**
 * 记账/改账/删账后，让受影响的月份聚合缓存立即失效。
 *
 * ⚠️ **写交易必须调它**，否则会出现「F5 后这笔账消失/复活、必须点刷新按钮才出来」：
 *   ① 前端写完只做**本地乐观更新**，服务端 cal_<ym>_<hash> 缓存仍是旧的；
 *   ② F5 后 load(fresh=false) → ensureCalMonth(ym,false) 直接命中旧缓存
 *      （当月 TTL = cache_ttl，默认 900s），把内存里正确的数据整块盖掉；
 *   ③ 只有顶栏「刷新同步」走 load(true) → ensureCalMonth(ym,true) 才绕过缓存。
 *   所以写接口自己失效缓存，才能让「普通刷新」看到刚写的账。
 *
 * 失效范围按**涉及的月份**精确删，不学 tx_batch 整目录清：
 *   每笔记账都清掉全部历史月缓存太浪费（历史月缓存 1 年，正是为了秒开）。
 *
 * @param string[] $yms 受影响月份（YYYY-MM；无法确定时传空数组 = 全部月份）
 */
function ebk_cal_forget(string $CACHE_DIR, array $yms = []): void
{
    global $token;
    $tkHash = substr(md5((string)$token), 0, 8);
    if (!$yms) {
        /* 月份未知（如删除接口只给了 id）：退回整目录清 cal_/dash_，
           与 tx_batch 一致。宁可多清也不留旧数据 —— 这是「静默拿错结果」类故障。 */
        foreach ((array)glob($CACHE_DIR . '/*.json') as $f) {
            if (preg_match('/^(cal_|dash_)/', basename($f))) {
                @unlink($f);
            }
        }
        return;
    }
    foreach (array_unique($yms) as $ym) {
        $ym = trim((string)$ym);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            continue;
        }
        cacheDel($CACHE_DIR, "cal_{$ym}_{$tkHash}");
    }
    /* 看板聚合（dash_*）含跨月区间，无法按单月精确删 → 一并失效，
       否则「记完账回首页看板数字没变」会变成下一个 F5 类 bug。 */
    foreach ((array)glob($CACHE_DIR . '/*.json') as $f) {
        if (preg_match('/^dash_/', basename($f))) {
            cacheDelPath($f);   // 走软删除兜底，unlink 被环境挡住时也能失效
        }
    }
}

/**
 * 把上游的英文报错翻成用户看得懂、并且能据此动手的中文。
 * 上游的错误信息是写给开发者的（如 "cannot add transaction before balance modification transaction"），
 * 直接透传到界面等于没提示：用户既不知道哪里出错，也不知道该改什么。
 * 未收录的错误原样返回 —— 不吞信息，方便继续排查。
 */
function ebk_friendly_error(string $msg): string
{
    static $map = [
        /* type=1 的「余额变更」是账户的余额锚点（建账户 / 手动调整余额时产生），
           比它更早的交易会让账户期初余额失去意义，所以上游直接拒绝。 */
        'cannot add transaction before balance modification transaction' =>
            '这个时间早于该账户的「余额变更」记录（一般是建立账户或手动调整余额的时刻）。'
            . '请把时间往后调，或换一个账户再记。',
        'cannot set category id for balance modification transaction' =>
            '「余额变更」类型的交易不能设置分类。',
        'non-balance modification transaction must set category id' =>
            '这笔交易缺少分类，请选择一个分类。',
    ];
    $msg = trim($msg);
    foreach ($map as $en => $zh) {
        if (stripos($msg, $en) !== false) {
            return $zh;
        }
    }
    return $msg;
}

/* ================= 主流程 ================= */

/* 所有响应都是用户私有数据，禁止任何中间层（CDN / 反代 / 浏览器）缓存 GET 结果。
   不加的话，外层一旦按 URL 缓存了 dashboard/cal_month 的 GET 响应，就会出现
   「F5 永远旧数据、?fresh=1 换了个 URL 所以是新的」这种极难排查的现象。 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$action = $_GET['action'] ?? 'dashboard';
$client = new EbkClient($config);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$writeActions = ['login', 'logout', 'clear_cache', 'edit_tx', 'edit_tx_category', 'delete_tx', 'add_tx', 'tx_batch', 'llm_settings_save', 'llm_map_save', 'llm_test', 'ai_recognize', 'tag_add', 'budget_save', 'settings_import'];

csrfToken();
if ($method === 'POST') {
    requireSameOrigin();
    if ($action !== 'login') {
        requireCsrf();
    }
}
if (in_array($action, $writeActions, true) && $method !== 'POST') {
    fail('该操作只接受 POST 请求');
}

/* ---- 版本/环境探针（免登录）：判断「服务器实际执行的是哪一版代码」----
 * 排查「上传了新文件但行为没变」时，最大的盲区是不知道 PHP 跑的到底是不是新代码
 * （传错目录 / 上传失败 / opcache 缓存旧字节码，全都静默发生）。
 * 浏览器直接开 api.php?action=ping：
 *   - 返回本版 JSON → 新代码在跑。行为还不对就看字段：
 *       unlinkWorks=false  → 删除类缓存失效被环境挡住（软删除兜底会接管，但要知道原因）
 *       cacheDirWritable=false → cache/ 目录 PHP 写不进去（重建也会失败，先修权限）
 *       opcacheValidateTimestamps="0" → 上传新文件不会生效，必须重启 PHP
 *   - 返回「未知 action」 → 服务器还在跑旧代码，先解决部署，改代码没有意义。
 * 不泄露任何数据：只有版本号、布尔值与 PHP 版本。 */
if ($action === 'ping') {
    $probe = [
        'success'    => true,
        'v'          => 'api-2026-09-22-security',
        'php'        => PHP_VERSION,
        'serverTime' => date('Y-m-d H:i:s'),
    ];
    $probeFile = $CACHE_DIR . '/.__ping_' . uniqid();
    $probe['cacheDirWritable'] = @file_put_contents($probeFile, 'x') !== false;
    $probe['unlinkWorks'] = $probe['cacheDirWritable'] ? (@unlink($probeFile) && !is_file($probeFile)) : false;
    $probe['globWorks'] = is_array(@glob($CACHE_DIR . '/*.json'));
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        $probe['opcacheEnabled'] = !empty($st['opcache_enabled']);
        $probe['opcacheValidateTimestamps'] = ini_get('opcache.validate_timestamps');
    }
    echo json_encode($probe, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- 退出登录 ---- */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- 清除服务端缓存：清看板/日历/元数据缓存，不动 sessions/（不影响登录态） ---- */
if ($action === 'clear_cache') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $removed = 0;
    foreach ((array)glob($CACHE_DIR . '/*.json') as $f) {
        // 只清单层文件，且限定为 cal_ / dash_ / meta 前缀；sessions 是子目录不受影响
        if (preg_match('/^(cal_|dash_|meta)/', basename($f))) {
            $removed += cacheDelPath($f) ? 1 : 0;   // 走软删除兜底；返回是否真删掉了
        }
    }
    echo json_encode(['success' => true, 'data' => ['removed' => $removed]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- 登录状态查询 ---- */
if ($action === 'status') {
    $loggedIn = !empty($_SESSION['ebk_token']);
    $baseUrlConfigured = trim((string)($config['base_url'] ?? '')) !== '';
    $defaultMode = '';
    if (trim((string)($config['api_token'] ?? '')) !== '') {
        $defaultMode = 'token';
    } elseif (trim((string)($config['username'] ?? '')) !== '' && trim((string)($config['password'] ?? '')) !== '') {
        $defaultMode = 'password';
    }
    echo json_encode([
        'success'     => true,
        'loggedIn'    => $loggedIn,
        'nickname'    => $loggedIn ? (string)($_SESSION['ebk_nickname'] ?? '') : '',
        'defaultMode' => $defaultMode,
        'baseUrlConfigured' => $baseUrlConfigured,
        'csrf'        => csrfToken(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- 登录 ---- */
if ($action === 'login') {
    if (trim((string)($config['base_url'] ?? '')) === '') {
        fail('还没有填写 EZBookKeeping 地址，请先配置 EBK_BASE_URL 或修改 config.php 后再登录');
    }
    loginRateCheck($CACHE_DIR);
    $body = json_decode((string)file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    $mode      = (string)($body['mode'] ?? '');
    $loginName = trim((string)($body['loginName'] ?? ''));
    $password  = (string)($body['password'] ?? '');
    $apiToken  = trim((string)($body['apiToken'] ?? ''));

    // 未指定方式或未填凭据时，回退到服务端默认凭据（config 里填了才生效）
    if ($mode === '') {
        if (trim((string)($config['api_token'] ?? '')) !== '') {
            $mode = 'token';
        } elseif (trim((string)($config['username'] ?? '')) !== '') {
            $mode = 'password';
        }
    }
    if ($mode === 'password') {
        if ($loginName === '') { $loginName = trim((string)($config['username'] ?? '')); }
        if ($password === '')  { $password  = (string)($config['password'] ?? ''); }
        if ($loginName === '' || $password === '') { fail('请输入用户名和密码'); }
    } elseif ($mode === 'token') {
        if ($apiToken === '') { $apiToken = trim((string)($config['api_token'] ?? '')); }
        if ($apiToken === '') { fail('请输入 API 令牌'); }
    } else {
        fail('请选择登录方式');
    }

    try {
        if ($mode === 'password') {
            $token   = $client->loginWithPassword($loginName, $password);
            $profile = $client->verifyToken($token);
            $_SESSION['ebk_mode']      = 'password';
            $_SESSION['ebk_loginName'] = $loginName;
            if (!empty($config['renew_with_password'])) {
                $_SESSION['ebk_password'] = $password;
            } else {
                unset($_SESSION['ebk_password']);
            }
        } else {
            $token   = $apiToken;
            $profile = $client->verifyToken($token);
            $_SESSION['ebk_mode'] = 'token';
        }
        session_regenerate_id(true);
        $_SESSION['csrf_token']   = bin2hex(random_bytes(24));
        $_SESSION['ebk_token']    = $token;
        $_SESSION['ebk_nickname'] = (string)($profile['nickname'] ?? $profile['username'] ?? '');
        $identity = strtolower(trim((string)($profile['username'] ?? $profile['email'] ?? $profile['nickname'] ?? '')));
        $_SESSION['ebk_user_key'] = hash('sha256', strtolower(rtrim((string)$config['base_url'], '/')) . '|' . $identity);
        loginRateClear($CACHE_DIR);
        echo json_encode(['success' => true, 'nickname' => $_SESSION['ebk_nickname'], 'csrf' => csrfToken()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        loginRateFail($CACHE_DIR);
        fail($e->getMessage());
    }
    exit;
}

/* ---- 消费日历：单月每日收支 + 全月交易明细（热力图切月 / 当日明细弹窗共用）----
 * 缓存策略：历史月数据不可变 → 缓存 1 年（等于永久）；当月随记账产生 → 用 cache_ttl。
 * 上游：transactions/amounts/daily.json + transactions/list/all.json（start/end 全量，并行）。 */
if ($action === 'cal_month') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ym = trim((string)($_GET['ym'] ?? ''));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        fail('月份格式应为 YYYY-MM');
    }
    $freshM = isset($_GET['fresh']);
    try {
        $tz    = new DateTimeZone((string)$config['timezone']);
        $start = new DateTime($ym . '-01 00:00:00', $tz);
        $end   = (clone $start)->modify('first day of next month')->modify('-1 second');
        $now   = new DateTime('now', $tz);
        if ($start > $now) {
            fail('不能查看未来月份');
        }
        if ($end > $now) {
            /* 当月只取到「此刻 + 15 分钟容差」。
               ⚠️ 不能按 $now 精确截止：记账时间取自**浏览器时钟**（aiTimeEpoch → Date.now()），
               设备时钟快十几秒时，这笔账会落在服务器时间的「未来」，按 now 截止会被上游查询
               漏掉 → 表现为「记完账流水卡里这笔消失（看板 recent 却有，它的终点是今天 23:59:59）」，
               十几秒后真实时间追上才出现。15 分钟容差吸收时钟差；dashboard 早已用
               「今天 23:59:59」做终点且上游接受（2026-09-21 生产响应亲证），安全。 */
            $end = (clone $now)->modify('+15 minutes');
        }

        $isCurrent = ($ym === $now->format('Y-m'));
        $ttl       = $isCurrent ? (int)($config['cache_ttl'] ?? 900) : 31536000;
        /* v2：交易行新增按转账分类树计算的大类标签，旧缓存中的固定「转账」不能继续复用。 */
        $cacheKey  = 'cal_v2_' . $ym . '_' . substr(md5($token), 0, 8);
        if (!$freshM) {
            $cached = cacheGet($CACHE_DIR, $cacheKey, $ttl);
            if ($cached !== null) {
                echo json_encode(['success' => true, 'data' => $cached], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // 上游并行拉取：每日收支 + 全月交易明细
        $got = ebk_fetch_parallel([
            'daily' => ['transactions/amounts/daily.json', [
                'start_time' => $start->getTimestamp(), 'end_time' => $end->getTimestamp(),
            ]],
            'txns' => ['transactions/list/all.json', [
                'start_time' => $start->getTimestamp(), 'end_time' => $end->getTimestamp(), 'trim_tag' => 1,
            ]],
        ]);

        // 低频元数据（profile/账户/分类/汇率）复用 meta 缓存
        $metaTtl = (int)($config['cache_meta_ttl'] ?? 3600);
        $tkHash  = substr(md5($token), 0, 8);
        $metaDefs = [
            'profile'    => ['users/profile/get.json', []],
            'accounts'   => ['accounts/list.json', []],
            'categories' => ['transaction/categories/list.json', []],
            'fx'         => ['exchange_rates/latest.json', []],
        ];
        $metaData = [];
        $todo = [];
        foreach ($metaDefs as $k => $def) {
            $m = cacheGet($CACHE_DIR, "meta_{$k}_{$tkHash}", $metaTtl);
            if ($m !== null) {
                $metaData[$k] = $m;
            } else {
                $todo[$k] = $def;
            }
        }
        if ($todo) {
            $got2 = ebk_fetch_parallel($todo);
            foreach ($got2 as $k => $v) {
                cacheSet($CACHE_DIR, "meta_{$k}_{$tkHash}", $v);
                $metaData[$k] = $v;
            }
        }
        $profile    = $metaData['profile'] ?? [];
        $fx         = $metaData['fx'] ?? [];
        $defaultCurrency = (string)($profile['defaultCurrency'] ?? 'CNY');
        $baseCurrency    = (string)($fx['baseCurrency'] ?? $defaultCurrency);
        $rateMap = [];
        foreach ((array)($fx['exchangeRates'] ?? []) as $r) {
            if (is_array($r) && isset($r['currency'], $r['rate'])) {
                $rateMap[(string)$r['currency']] = (float)$r['rate'];
            }
        }
        $catMap = flattenCategories(is_array($metaData['categories'] ?? null) ? $metaData['categories'] : []);

        $dailyOut = mapDailyRows(is_array($got['daily'] ?? null) ? $got['daily'] : [], $rateMap, $baseCurrency, $defaultCurrency, AMOUNT_FACTOR);
        $txOut    = mapTransactionRows(is_array($got['txns'] ?? null) ? $got['txns'] : [], $catMap, AMOUNT_FACTOR, $defaultCurrency);

        $payload = ['ym' => $ym, 'daily' => $dailyOut, 'transactions' => $txOut, 'generatedAt' => time()];
        cacheSet($CACHE_DIR, $cacheKey, $payload);
        echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    exit;
}

/* GET icon：代理 EZBK 自定义图标图片。
 * 图标是低频、可重建资源：按账号落盘到 cache/icons 后，后续打开选择器直接读本地，
 * 不再为每一张图重复请求上游。图标目录位于 cache/ 下，普通清缓存只处理根目录 JSON，
 * 因此不会因为刷新看板而清掉图标。 */
if ($action === 'icon') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') { http_response_code(401); exit; }
    $iconId = $_GET['id'] ?? '';
    if (!preg_match('/^\d{1,20}$/', (string)$iconId)) { http_response_code(404); exit; }
    $accountKey = (string)($_SESSION['ebk_user_key'] ?? '');
    if ($accountKey === '') {
        $accountKey = hash('sha256', strtolower(rtrim((string)$config['base_url'], '/')) . '|' . $token);
    }
    $iconDir = $CACHE_DIR . '/icons/' . substr(hash('sha256', $accountKey), 0, 16);
    $iconFile = $iconDir . '/' . $iconId . '.png';
    $missFile = $iconDir . '/' . $iconId . '.miss';

    /* 正常图标缓存：比页面数据缓存更长，图标变化频率很低。 */
    if (@is_file($iconFile) && @filesize($iconFile) > 0) {
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=604800, immutable');
        header('X-EzBookDash-Icon-Cache: hit');
        @readfile($iconFile);
        exit;
    }
    /* 上游明确没有这张图时短暂记忆 404，避免每次打开选择器都重复失败请求。 */
    if (@is_file($missFile) && (time() - (int)@filemtime($missFile)) < 300) {
        http_response_code(404);
        header('Cache-Control: private, max-age=300');
        header('X-EzBookDash-Icon-Cache: miss');
        exit;
    }

    $url = rtrim((string)$config['base_url'], '/') . '/icons/' . $iconId . '.png?token=' . urlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => (bool)($config['ssl_verify'] ?? false),
        CURLOPT_SSL_VERIFYHOST => ($config['ssl_verify'] ?? false) ? 2 : 0,
    ]);
    $img = curl_exec($ch);
    $ct  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code= (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $img !== false && $img !== '') {
        ensure_dir($iconDir);
        $tmp = $iconFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $img, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $iconFile)) {
                @unlink($tmp);
            } else {
                @unlink($missFile);
            }
        }
        header('Content-Type: ' . ($ct ?: 'image/png'));
        header('Cache-Control: private, max-age=604800, immutable');
        header('X-EzBookDash-Icon-Cache: miss');
        echo $img;
    } else {
        ensure_dir($iconDir);
        @touch($missFile);
        @chmod($missFile, 0600);
        http_response_code(404);
        header('Cache-Control: private, max-age=300');
        header('X-EzBookDash-Icon-Cache: miss');
    }
    exit;
}

/* ================= 记一笔（二期·A） ================= */
/** 确保分类/账户/默认账户元数据可用（缓存 1h，无则现拉）。返回 [meta, validAcct, defaultAcct] */
function ebk_ensure_meta(object $client, string $token, string $CACHE_DIR): array
{
    $metaKey = 'meta_' . substr(md5($token), 0, 8);
    $meta = cacheGet($CACHE_DIR, $metaKey, 3600);
    if ($meta === null) {
        /* 与看板/记账卡片共用统一低频层，避免同一批分类/账户被反复拉（识别与映射表统计也调这里） */
        $bundle = ebk_meta_bundle([
            'categories' => ['transaction/categories/list.json', []],
            'accounts'   => ['accounts/list.json', []],
            'profile'    => ['users/profile/get.json', []],
        ], $CACHE_DIR);
        $meta = [
            'categories' => $bundle['categories'] ?? [],
            'accounts'   => $bundle['accounts'] ?? [],
            'defaultAccountId' => (string)($bundle['profile']['defaultAccountId'] ?? ''),
            'generatedAt' => time(),
        ];
        cacheSet($CACHE_DIR, $metaKey, $meta);
    }
    $valid = [];
    $firstAcct = '';
    foreach (($meta['accounts'] ?? []) as $a) {
        if (empty($a['hidden'])) {
            $id = (string)($a['id'] ?? '');
            if ($id !== '') {
                $valid[$id] = true;
                if ($firstAcct === '') {
                    $firstAcct = $id;   // 兜底：EZBK profile 未返回默认账户时，回落第一个有效账户
                }
            }
        }
    }
    $default = (string)($meta['defaultAccountId'] ?? '');
    if ($default === '' || !isset($valid[$default])) {
        $default = $firstAcct;   // 默认账户缺失/无效 → 第一个有效账户
    }
    return [$meta, $valid, $default];
}
/** 账户 ID 防幻觉修正：无效/未填 → 用户默认账户。ID 一律字符串（19 位雪花 ID 超出 JS Number 安全范围，绝不可转数字） */
function ebk_fix_account(string|int $id, array $valid, string $default): string
{
    $id = trim((string)$id);
    return (isset($valid[$id]) && $id !== '' && $id !== '0') ? $id : $default;
}
/** 账户文本匹配：优先从原文找真实账户名称，避免模型返回空 ID 后静默落到默认账户。 */
function ebk_account_normalize(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $clean = preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);
    return is_string($clean) ? $clean : $value;
}
function ebk_account_aliases(array $account): array
{
    $name = trim((string)($account['name'] ?? ''));
    $n = ebk_account_normalize($name);
    $aliases = $name === '' ? [] : [$name];
    /* 常见支付账户口语。账户名称本身始终优先，别名只用于补齐「微信零钱/微信钱包」这类说法。 */
    if ($n !== '' && ($n === '零钱' || mb_strpos($n, '微信零钱') !== false || mb_strpos($n, '微信钱包') !== false)) {
        $aliases = array_merge($aliases, ['微信零钱', '微信钱包', '零钱']);
    }
    if ($n !== '' && mb_strpos($n, '支付宝') !== false) {
        $aliases = array_merge($aliases, ['支付宝', '支付宝余额', '支付宝钱包']);
    }
    return array_values(array_unique(array_filter($aliases, static fn($v) => trim((string)$v) !== '')));
}
/** 返回按原文位置排序的账户候选；每个账户只保留自己的最长匹配。 */
function ebk_account_hints(string $text, array $meta): array
{
    $haystack = ebk_account_normalize($text);
    if ($haystack === '') {
        return [];
    }
    $hits = [];
    foreach ((array)($meta['accounts'] ?? []) as $account) {
        if (!empty($account['hidden']) || (string)($account['id'] ?? '') === '') {
            continue;
        }
        $best = null;
        $nameNorm = ebk_account_normalize((string)($account['name'] ?? ''));
        foreach (ebk_account_aliases($account) as $alias) {
            $needle = ebk_account_normalize((string)$alias);
            if (mb_strlen($needle) < 2) {
                continue;
            }
            $pos = mb_strpos($haystack, $needle);
            if ($pos === false) {
                continue;
            }
            $candidate = ['id' => (string)$account['id'], 'name' => (string)($account['name'] ?? ''), 'matched' => (string)$alias, 'length' => mb_strlen($needle), 'pos' => $pos, 'exact' => $needle === $nameNorm ? 1 : 0];
            if ($best === null || $candidate['length'] > $best['length'] || ($candidate['length'] === $best['length'] && $candidate['exact'] > $best['exact'])) {
                $best = $candidate;
            }
        }
        if ($best !== null) {
            $hits[] = $best;
        }
    }
    usort($hits, static function (array $a, array $b): int {
        return ($a['pos'] <=> $b['pos']) ?: ($b['length'] <=> $a['length']);
    });
    return $hits;
}
function ebk_text_mentions_account(string $text): bool
{
    return preg_match('/微信|支付宝|零钱|钱包|银行卡|借记卡|信用卡|储蓄卡|现金|账户|工资卡|余额宝/u', $text) === 1;
}
function ebk_account_name(array $meta, string $id): string
{
    foreach ((array)($meta['accounts'] ?? []) as $account) {
        if ((string)($account['id'] ?? '') === $id) {
            return (string)($account['name'] ?? '');
        }
    }
    return '';
}
/* GET meta：分类树 + 全量账户（记账表单数据源）。低频数据，缓存 1 小时。 */
if ($action === 'meta') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $metaKey = 'meta2_' . substr(md5($token), 0, 8);   // v2：并入 tags，换 key 让旧缓存立即失效
    $cached = cacheGet($CACHE_DIR, $metaKey, 3600);
    if ($cached !== null) {
        echo json_encode(['success' => true, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        /* 分类/账户/标签走统一低频层：看板或日历刚拉过就直接命中，不再重复上游 RTT；
           标签允许单独失败（老版本 EZBK 可能没这个接口），所以放 tolerate 里与主体同批并取 */
        $bundle = ebk_meta_bundle([
            'categories' => ['transaction/categories/list.json', []],
            'accounts'   => ['accounts/list.json', []],
            'tags'       => ['transaction/tags/list.json', []],
        ], $CACHE_DIR, ['tags' => []]);
        $out = [
            'categories' => $bundle['categories'] ?? [],
            'accounts'   => $bundle['accounts'] ?? [],
            'tags'       => is_array($bundle['tags'] ?? null) ? $bundle['tags'] : [],
            'generatedAt' => time(),
        ];
        cacheSet($CACHE_DIR, $metaKey, $out);
        echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '（HTTP 404）') !== false || strpos($msg, 'HTTP 404') !== false) {
            fail('EZBK 未启用 AI 一句话记账：请在 EZBK 后台配置 LLM（文字识别）并开启「AI 文本识别记账」开关');
        }
        if (strpos($msg, '超时') !== false) {
            fail('AI 识别超时，请重试；持续超时建议更换响应更快的模型');
        }
        fail($msg);
    }
    exit;
}

/* POST edit_tx_category：单笔修改分类。
   ezBookKeeping 的 transactions/modify.json 在只改变分类的请求上可能返回
   “nothing will be updated”，而批量分类接口支持单笔 transactionIds，作为单笔
   编辑的专用路径更可靠。 */
if ($action === 'edit_tx_category') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = trim((string)($in['id'] ?? ''));
    $catId = trim((string)($in['categoryId'] ?? ''));
    if ($id === '') {
        fail('缺少交易 ID');
    }
    if (!preg_match('/^[0-9A-Za-z\-]{1,40}$/', $id)) {
        fail('交易 ID 不合法');
    }
    if ($catId === '' || $catId === '0') {
        fail('请选择分类');
    }
    try {
        $bundle = ebk_meta_bundle(['categories' => ['transaction/categories/list.json', []]], $CACHE_DIR);
        $catMap = flattenCategories(is_array($bundle['categories'] ?? null) ? $bundle['categories'] : []);
        if (!isset($catMap[(int)$catId])) {
            fail('分类不存在或已被删除');
        }
        $client->requestPost('transactions/batch_update/category.json', [
            'transactionIds' => [$id],
            'categoryId' => $catId,
        ], $token);
        $tz = new DateTimeZone((string)$config['timezone']);
        $yms = [];
        foreach ([(int)($in['time'] ?? 0), (int)($in['oldTime'] ?? 0)] as $ts) {
            if ($ts > 0) {
                $yms[] = (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m');
            }
        }
        ebk_cal_forget($CACHE_DIR, array_values(array_unique($yms)));
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail(ebk_friendly_error($e->getMessage()));
    }
    exit;
}

/* POST edit_tx：修改交易（透传上游 transactions/modify.json，金额单位=元） */
if ($action === 'edit_tx') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = trim((string)($in['id'] ?? ''));
    $txTime = (int)($in['time'] ?? 0);
    $type = (int)($in['type'] ?? 0);
    $amount = round((float)($in['amount'] ?? 0), 2);
    if ($id === '') {
        fail('缺少交易 ID');
    }
    if (!in_array($type, [2, 3, 4], true)) {
        fail('交易类型不合法');
    }
    if ($amount <= 0 || $amount > 99999999) {
        fail('金额需大于 0');
    }
    /* ID 一律字符串：19 位雪花 ID 超出 JS Number 安全范围 */
    $srcId = trim((string)($in['sourceAccountId'] ?? ''));
    $dstId = trim((string)($in['destinationAccountId'] ?? ''));
    $catId = trim((string)($in['categoryId'] ?? ''));
    if ($srcId === '' || $srcId === '0') {
        fail('请选择账户');
    }
    if ($type === 4) {
        if ($dstId === '' || $dstId === '0') {
            fail('转账请选择目标账户');
        }
        if ($srcId === $dstId) {
            fail('转出与转入账户不能相同');
        }
    }
    if ($catId === '' || $catId === '0') {
        fail('请选择分类');
    }
    $destinationAmount = round((float)($in['destinationAmount'] ?? $amount), 2);
    if ($type === 4 && ($destinationAmount <= 0 || $destinationAmount > 99999999)) {
        fail('转入金额需大于 0');
    }
    $time = (int)($in['time'] ?? 0);
    if ($time <= 0 || $time > time() + 3600) {
        $time = time();
    }
    /* 最后一道防线：账户/分类无效 → 修正。
       注意必须收下第一个返回值 $meta（分类校验要读 $meta['categories']）；
       之前写成 [, $validAcct, $defAcct] 丢掉了 $meta，而 `$meta[...] ?? []` 的 ?? 是
       静默的（不报错），结果编辑时的分类校验一直拿到空数组、等于没做。
       ⚠️ 校验必须用 $allCat（含大类）：上游允许把账记在「居住/购物」这类**大类**上，
       而大类在 categories 里是分组、子类才是 subCategories。只白名单子类的话，
       编辑一笔记在大类上的流水会把 catId「修正」成该类型第一个子类 —— 用户只改个金额，
       分类就被悄悄换成「买菜做饭」，且前端毫无察觉（接口返回 success）。 */
    [$meta, $validAcct, $defAcct] = ebk_ensure_meta($client, $token, $CACHE_DIR);
    if ($defAcct !== '') {
        $srcId = ebk_fix_account($srcId, $validAcct, $defAcct);
        if ($type === 4) {
            $dstId = ebk_fix_account($dstId, $validAcct, $defAcct);
            if ($srcId === $dstId) {
                fail('转出与转入账户不能相同');
            }
        }
        {
            $typeKey = (string)match ($type) { 2 => '1', 3 => '2', 4 => '3' };
            $validCat = [];   /* 官方写接口要求二级分类；无子类的大类本身视为可选项 */
            foreach (($meta['categories'][$typeKey] ?? []) as $p) {
                $subs = (!empty($p['subCategories'])) ? $p['subCategories'] : [$p];
                foreach ($subs as $c) {
                    $cid = (string)($c['id'] ?? '');
                    if ($cid !== '') { $validCat[$cid] = true; }
                }
            }
            if ($validCat && !isset($validCat[$catId])) {
                $catId = (string)array_key_first($validCat);
            }
        }
    }
    $body = [
        'id'                  => $id,
        'type'                => $type,
        'time'                => $time,
        'utcOffset'           => 480,
        'sourceAccountId'     => $srcId,
        'destinationAccountId'=> ($type === 4 ? $dstId : '0'),
        'categoryId'          => $catId,
        'sourceAmount'        => (int)round($amount * 100),
        'destinationAmount'   => $type === 4 ? (int)round($destinationAmount * 100) : 0,
        'comment'             => mb_substr(trim((string)($in['comment'] ?? '')), 0, 100),
        'tagIds'              => array_values(array_map('strval', (array)($in['tagIds'] ?? []))),
    ];
    try {
        $client->requestPost('transactions/modify.json', $body, $token);
        /* 写后失效聚合缓存。⚠️ 编辑可能**跨月**（改了时间），所以新旧两个月都要失效：
           只失效新月份的话，旧月份会留着一笔已经不存在的账（同样是 F5 现象）。 */
        $tz = new DateTimeZone((string)$config['timezone']);
        $yms = [];
        if ($body['time'] > 0) {
            $yms[] = (new DateTime('@' . $body['time']))->setTimezone($tz)->format('Y-m');
        }
        $oldTime = (int)($in['oldTime'] ?? 0);
        if ($oldTime > 0 && $oldTime !== $body['time']) {
            $yms[] = (new DateTime('@' . $oldTime))->setTimezone($tz)->format('Y-m');
        }
        ebk_cal_forget($CACHE_DIR, $yms);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail(ebk_friendly_error($e->getMessage()));
    }
    exit;
}

/* POST delete_tx：删除交易 */
if ($action === 'delete_tx') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') {
        fail('缺少交易 ID');
    }
    try {
        $client->requestPost('transactions/delete.json', ['id' => $id], $token);
        /* 前端带原交易时间时精确失效该月；旧客户端没带时仍回退为清全部聚合缓存。 */
        $deleteYms = [];
        if ($txTime > 0) {
            $deleteYms[] = (new DateTime('@' . $txTime))->setTimezone(new DateTimeZone((string)$config['timezone']))->format('Y-m');
        }
        ebk_cal_forget($CACHE_DIR, $deleteYms);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    exit;
}

/* POST add_tx：创建交易（透传上游 transactions/add.json），金额单位=元（内部转分） */
if ($action === 'add_tx') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $type = (int)($in['type'] ?? 0);
    $amount = round((float)($in['amount'] ?? 0), 2);
    if (!in_array($type, [2, 3, 4], true)) {
        fail('交易类型不合法');
    }
    if ($amount <= 0 || $amount > 99999999) {
        fail('金额需大于 0');
    }
    /* ID 一律字符串：19 位雪花 ID 超出 JS Number 安全范围，int 转换会精度丢失 */
    $srcId  = trim((string)($in['sourceAccountId'] ?? ''));
    $dstId  = trim((string)($in['destinationAccountId'] ?? ''));
    $catId  = trim((string)($in['categoryId'] ?? ''));
    if ($srcId === '' || $srcId === '0') {
        fail('请选择账户');
    }
    if ($type === 4) {
        if ($dstId === '' || $dstId === '0') {
            fail('转账请选择目标账户');
        }
        if ($srcId === $dstId) {
            fail('转出与转入账户不能相同');
        }
    }
    if ($catId === '' || $catId === '0') {
        fail('请选择分类');
    }
    $destinationAmount = round((float)($in['destinationAmount'] ?? $amount), 2);
    if ($type === 4 && ($destinationAmount <= 0 || $destinationAmount > 99999999)) {
        fail('转入金额需大于 0');
    }
    $time = (int)($in['time'] ?? 0);
    if ($time <= 0 || $time > time() + 3600) {
        $time = time();
    }
    /* 最后一道防线：账户/分类 ID 无效（LLM 幻觉/已删除）→ 修正，确保入账成功 */
    [$meta, $validAcct, $defAcct] = ebk_ensure_meta($client, $token, $CACHE_DIR);
    if ($defAcct !== '') {
        $srcId = ebk_fix_account($srcId, $validAcct, $defAcct);
        if ($type === 4) {
            $dstId = ebk_fix_account($dstId, $validAcct, $defAcct);
            if ($srcId === $dstId) {
                fail('转出与转入账户不能相同');
            }
        }
        {
            $typeKey = (string)match ($type) { 2 => '1', 3 => '2', 4 => '3' };
            $validCat = [];   /* 官方写接口要求二级分类；无子类的大类本身视为可选项 */
            foreach (($meta['categories'][$typeKey] ?? []) as $p) {
                $subs = (!empty($p['subCategories'])) ? $p['subCategories'] : [$p];
                foreach ($subs as $c) {
                    $cid = (string)($c['id'] ?? '');
                    if ($cid !== '') { $validCat[$cid] = true; }
                }
            }
            if ($validCat && !isset($validCat[$catId])) {
                $catId = (string)array_key_first($validCat);   // 无效分类 → 该类型第一项
            }
        }
    }
    $body = [
        'type'                => $type,
        'time'                => $time,
        'utcOffset'           => 480,
        'sourceAccountId'     => (string)$srcId,
        'destinationAccountId'=> (string)($type === 4 ? $dstId : 0),
        'categoryId'          => (string)$catId,
        'sourceAmount'        => (int)round($amount * 100),   // 上游以「分」存储
        'destinationAmount'   => $type === 4 ? (int)round($destinationAmount * 100) : 0,
        'comment'             => mb_substr(trim((string)($in['comment'] ?? '')), 0, 100),
        'tagIds'              => array_values(array_map('strval', (array)($in['tagIds'] ?? []))),
    ];
    try {
        $result = $client->requestPost('transactions/add.json', $body, $token);
        /* 写后失效当月聚合缓存：否则 F5 会命中旧 cal_ 缓存、新账看不见（详见 ebk_cal_forget 注释） */
        ebk_cal_forget($CACHE_DIR, [$body['time'] > 0 ? (new DateTime('@' . $body['time']))->setTimezone(new DateTimeZone((string)$config['timezone']))->format('Y-m') : '']);
        /* 新 id 一律按字符串下发：19 位雪花 ID 超出 JS Number 安全范围，
           若这里发数字，前端拿到的是被截断的精度、再拿它去编辑/删除必然失败（与 edit_tx 的约定一致） */
        $newId = is_array($result) ? (string)($result['id'] ?? '') : '';
        echo json_encode(['success' => true, 'data' => ['id' => $newId]], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail(ebk_friendly_error($e->getMessage()));
    }
    exit;
}

/* ================= 自定义 LLM 识别配置（服务端存储） =================
 * 存放于 data/llm_config.php（与 data/llm_category_map_user.php 同属用户数据，见 data_file()）。
 * 首行是 PHP 守卫，被直接请求时 PHP 执行后立即 exit、输出为空，内容不会外泄（不依赖 nginx 的 deny 规则，
 * php -S 下同样安全）。文件名是 .php 而非 .json，所以设置里的「清除缓存」也删不到它。
 *
 * 为什么不在 cache/ 下：cache 的语义是「可以随手清空」，而这个文件丢了的失败是**静默的** ——
 * ai_recognize 在自定义通道未就绪时会自动回落上游，界面照常出结果，用户不会察觉 API Key 已经没了。
 * 为什么不在 php/ 根目录：根目录是本项目「要部署的文件」集合，把运行时数据混进去，会在整目录上传 /
 * git 检出时被本地空配置覆盖，同样是静默失效。
 */

/* ---------- 受 PHP 守卫的 JSON 数据文件 ----------
 * 首行是一个 PHP 守卫语句（执行到就结束脚本），所以直接 HTTP 请求该文件时输出为空、内容不外泄，
 * 不依赖 nginx 规则，php -S 下同样安全。
 * 内容用 JSON 而不是 PHP 数组：用户编辑的文字不会被拼进代码执行。
 * 又因为后缀是 .php 而不是 .json，设置里的「清除缓存」只删 *.json，不会误删这些数据。 */

function guarded_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = (string)@file_get_contents($file);
    $pos = strpos($raw, "\n");
    if ($pos === false) {
        return null;
    }
    $data = json_decode(substr($raw, $pos + 1), true);
    return is_array($data) ? $data : null;
}

function guarded_write(string $file, array $data): bool
{
    ensure_dir(dirname($file));   // 目录不存在时 file_put_contents 会失败（实测返回 false）
    $body = "<?php exit; ?>\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $ok = @file_put_contents($file, $body, LOCK_EX) !== false;
    if ($ok) {
        @chmod($file, 0600);
    }
    return $ok;
}

/**
 * 用户数据文件的统一入口（不是缓存、也不是要部署的代码）。
 * 规则：私有 data 目录保存用户数据，私有 cache 目录只放可重建缓存。
 * 自带旧位置自愈：2026-09-19 之前这两个文件放在 cache/ 下，部署新版后首次访问会自动搬到位，不会丢 Key。
 */
function data_file(string $name): string
{
    global $DATA_DIR;
    $new = $DATA_DIR . '/' . $name;
    $old = __DIR__ . '/data/' . $name;
    if (!is_file($new) && is_file($old)) {
        ensure_dir(dirname($new));   // rename 到不存在的目录会失败 —— 那样 Key 就真丢了（实测）
        @copy($old, $new);           // 保留旧文件，便于升级失败时回滚
        @chmod($new, 0600);
    }
    return $new;
}

/** Per-account private settings. Legacy global data is copied only for the first account that uses it. */
function user_data_file(string $name): string
{
    global $DATA_DIR;
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        return data_file($name);
    }
    $accountKey = (string)($_SESSION['ebk_user_key'] ?? '');
    if ($accountKey === '') {
        $accountKey = hash('sha256', $token);
    }
    $dot = strrpos($name, '.');
    $suffix = '_' . substr($accountKey, 0, 16);
    $scoped = $dot === false ? $name . $suffix : substr($name, 0, $dot) . $suffix . substr($name, $dot);
    $target = $DATA_DIR . '/' . $scoped;
    $legacy = data_file($name);
    $ownerFile = $DATA_DIR . '/.legacy_owner';
    $ownerHash = substr($accountKey, 0, 16);
    $legacyOwner = is_file($ownerFile) ? trim((string)@file_get_contents($ownerFile)) : '';
    if ($legacyOwner === '') {
        @file_put_contents($ownerFile, $ownerHash, LOCK_EX);
        @chmod($ownerFile, 0600);
        $legacyOwner = $ownerHash;
    }
    if (!is_file($target) && $legacyOwner === $ownerHash && is_file($legacy)) {
        @copy($legacy, $target);
        @chmod($target, 0600);
    }
    return $target;
}

function llm_config_file(): string
{
    return user_data_file('llm_config.php');
}

function llm_config_defaults(): array
{
    return [
        'mode'     => 'upstream',                      // upstream=跟随上游 / custom=自定义 OpenAI 兼容
        'base_url' => 'https://api.ainn.cc/v1',
        'api_key'  => '',
        'model'    => '',
    ];
}

function llm_config_get(): array
{
    $cfg = guarded_read(llm_config_file());
    return is_array($cfg) ? array_merge(llm_config_defaults(), $cfg) : llm_config_defaults();
}

function llm_config_save(array $cfg): bool
{
    return guarded_write(llm_config_file(), $cfg);
}

/** Key 掩码：只露头 3 位与末 4 位（完整 Key 永不下发浏览器） */
function llm_mask_key(string $key): string
{
    $key = trim($key);
    $len = strlen($key);
    if ($len === 0) {
        return '';
    }
    if ($len <= 10) {
        return str_repeat('•', $len);
    }
    return substr($key, 0, 3) . str_repeat('•', 10) . substr($key, -4);
}

function llm_cfg_ready(array $cfg): bool
{
    return trim((string)$cfg['base_url']) !== '' && trim((string)$cfg['api_key']) !== '' && trim((string)$cfg['model']) !== '';
}

/** OpenAI 兼容 Chat Completions 调用（自定义识别与「测试连接」共用） */
function llm_chat(array $cfg, array $messages, int $timeout = 60, int $maxTokens = 0): array
{
    global $config;
    $url = rtrim(trim((string)$cfg['base_url']), '/') . '/chat/completions';
    $payload = [
        'model'       => trim((string)$cfg['model']),
        'messages'    => $messages,
        'temperature' => 0,
    ];
    if ($maxTokens > 0) {
        $payload['max_tokens'] = $maxTokens;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim((string)$cfg['api_key']),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => (bool)($config['ssl_verify'] ?? false),
        CURLOPT_SSL_VERIFYHOST => ($config['ssl_verify'] ?? false) ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') {
        throw new RuntimeException('无法连接接口：' . ($err !== '' ? $err : '网络错误'));
    }
    $json = json_decode((string)$body, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string)($json['error']['message'] ?? $json['message'] ?? '') : '';
        if ($msg === '') {
            $msg = mb_substr(trim((string)$body), 0, 160);
        }
        throw new RuntimeException('接口返回 HTTP ' . $code . ($msg !== '' ? '：' . $msg : ''));
    }
    if (!is_array($json)) {
        throw new RuntimeException('接口返回内容不是合法 JSON');
    }
    return $json;
}

/**
 * 归一化分类结构。上游（以及 meta 缓存）里的 categories 是**按类型分组的字典**
 * （形如 {"1":[收入大类…],"2":[支出大类…]}），历史上也曾出现过扁平数组，故两种都兼容。
 */
function ebk_category_groups(array $meta): array
{
    $cats = $meta['categories'] ?? [];
    if (!is_array($cats) || !$cats) {
        return [];
    }
    $isList = array_keys($cats) === range(0, count($cats) - 1);
    if ($isList) {
        return array_values(array_filter($cats, 'is_array'));
    }
    $groups = [];
    foreach ($cats as $bucket) {
        if (!is_array($bucket)) {
            continue;
        }
        foreach ($bucket as $g) {
            if (is_array($g)) {
                $groups[] = $g;
            }
        }
    }
    return $groups;
}

/** 把「大类 id」扩成「大类 + 全部子类 id」的逗号串（官方文档格式：category_ids 用 , 分隔）。
 *  为什么要在代理层展开：统计页分类排行/环形图给的是大类 id，其金额 = 大类 + 全部子类聚合；
 *  但 list 过滤的匹配粒度是「精确 id」，上游版本对「传大类是否连带子类」行为不一致 ——
 *  只传大类 id 时，记在子类上的账会查不到（症状：分类排行下钻流水页，有的分类空空如也）。
 *  显式展开成 大类,子类1,子类2… 后，任何上游版本行为都一致。
 *  传多个 id（本身已是逗号串）时逐个展开；查不到的 id 原样保留，不吞不报错。 */
function ebk_expand_category_ids(string $ids, array $categories): string
{
    $out = [];
    $seen = [];
    /* ⚠️ $categories 是 categories 数据本身（分组字典 {"1":[…],"2":[…]} 或扁平数组），
       而 ebk_category_groups() 期望的是**带 'categories' 键的整个 meta 数组**。
       2026-09-21 生产事故：这里曾直接把 $categories 传进去，$meta['categories']
       永远取不到 → groups 恒空 → expand 上线以来从未真正展开过一次 id，
       「大类下钻有数据」全靠「大类自己也记账」侥幸（mock 的 EXP_CATS 与用户真实数据皆是）。
       雪花医疗这种「大类无账、账全在子类」的分类一上线即 0 笔，才把它炸出来。 */
    $groups = ebk_category_groups(['categories' => $categories]);
    foreach (explode(',', $ids) as $id) {
        $id = trim($id);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $out[] = $id;
        $seen[$id] = true;
        foreach ($groups as $g) {
            if ((string)($g['id'] ?? '') === $id && !empty($g['subCategories']) && is_array($g['subCategories'])) {
                foreach ($g['subCategories'] as $sub) {
                    $sid = (string)($sub['id'] ?? '');
                    if ($sid !== '' && !isset($seen[$sid])) {
                        $out[] = $sid;
                        $seen[$sid] = true;
                    }
                }
                break;
            }
        }
    }
    return implode(',', $out);
}

/** 上游分类 type：1=收入 2=支出 3=转账（llm_category_map.php 里的类型标记与之一致） */
const EBK_CAT_TYPE_INCOME = 1;
const EBK_CAT_TYPE_EXPENSE = 2;
const EBK_CAT_TYPE_TRANSFER = 3;

/** 旧版本用户覆盖文件路径。新版本直接更新 llm_category_map.php；保留读取能力用于一次性迁移旧设置。 */
function llm_map_override_file(): string
{
    return user_data_file('llm_category_map_user.php');
}

function llm_map_override(): array
{
    $data = guarded_read(llm_map_override_file());
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && is_string($v) && $k !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * 加载分类语义映射表（子类名 => [大类名, 触发词, 类型]）。
 * 主文件是唯一写入来源；旧版本的 user 覆盖文件只在升级兼容时临时合并。
 * 文件缺失或格式异常 → 返回空数组，识别自动降级为「只有分类名」，不报错。
 */
function llm_category_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = llm_category_map_base();
    foreach (llm_map_override() as $key => $trig) {
        $sub = $key;
        $type = 0;
        if (preg_match('/^(\d+)\|(.*)$/u', $key, $m)) {
            $type = (int)$m[1];
            $sub = (string)$m[2];
        }
        if (isset($map[$sub]) && ($type === 0 || (int)($map[$sub][2] ?? 0) === $type) && trim($trig) !== '') {
            $map[$sub][1] = $trig;
        }
    }
    return $map;
}

/** 读基线映射表（不含用户覆盖）。注意：不要对它做 static 缓存，保存后要能立刻读到新值 */
function llm_category_map_base(): array
{
    /* 基线映射表属用户定制数据（由 category_final.html 生成），2026-09-21 起归位 data/，
       与 llm_category_map_user.php 同居（见 data_file()）。⚠️ 该文件**不能加** `<?php exit; ?>`
       首行 —— 它是 @include 加载的，include 到 exit 会直接终止整个 API 请求；
       HTTP 防护由 data/ 的 nginx deny + 顶层 return 天然无输出兜底（内容亦无敏感信息）。
       不设出厂回落垫片（2026-09-21 拍板：两份相同文件徒增维护成本）：data/ 缺失时返回空数组，
       表现为「映射窗口 0 条 / 一句话记账只按分类名识别 / llm_map_save 明确报错」——
       可感知、不静默、恢复=本地重跑 gen_category_map.py 再传一份。 */
    $file = data_file('llm_category_map.php');
    if (!is_file($file)) {
        return [];
    }
    $data = @include $file;
    return is_array($data) ? $data : [];
}

/** 触发词长度上限：够写很长的同义词清单，又不至于被塞进一整篇文章 */
const LLM_TRIGGER_MAX = 400;

/**
 * 拿上游的分类名去映射表里找触发词。
 * 命中顺序：① 子类名精确（类型须一致，「人情」「其他」在收支下同名，靠类型区分）
 *           ② 同类型内包含匹配（两侧名字都 ≥2 字，且必须唯一命中，避免「其他」误配「其他转账」）
 * 返回 [触发词, 命中方式, 命中的映射条目名]；未命中时触发词与条目名为空串、方式为 none。
 * 第 3 个值给编辑弹窗用：反查哪些映射条目真的被用户的分类用上了。
 */
function llm_match_triggers(array $map, string $subName, int $catType): array
{
    $n = preg_replace('/\s+/u', '', $subName);
    if ($n === '' || !$map) {
        return ['', 'none', ''];
    }
    if (isset($map[$n])) {
        return (int)($map[$n][2] ?? 0) === $catType ? [(string)$map[$n][1], 'exact', $n] : ['', 'none', ''];
    }
    if (mb_strlen($n) < 2) {
        return ['', 'none', ''];
    }
    $hits = [];
    foreach ($map as $k => $v) {
        if ((int)($v[2] ?? 0) !== $catType || mb_strlen($k) < 2) {
            continue;
        }
        if (mb_strpos($k, $n) !== false || mb_strpos($n, $k) !== false) {
            $hits[] = [(string)$v[1], $k];
        }
    }
    return count($hits) === 1 ? [$hits[0][0], 'fuzzy', $hits[0][1]] : ['', 'none', ''];
}

/** 将完整映射直接保存到主文件，主文件不能使用 guarded_write 的 exit 头。 */
function llm_category_map_save_base(array $map): bool
{
    $file = data_file('llm_category_map.php');
    ensure_dir(dirname($file));
    $body = "<?php\n/** ezBookDash 分类语义映射表（可由设置页更新） */\nreturn "
        . var_export($map, true) . ";\n";
    $tmp = @tempnam(dirname($file), 'llm-map-');
    if ($tmp === false) {
        return false;
    }
    $ok = @file_put_contents($tmp, $body, LOCK_EX) !== false;
    if ($ok) {
        @chmod($tmp, 0600);
        $ok = @rename($tmp, $file);
    }
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}

/** 当前账本分类的精确用户触发词优先于基线映射；复合键避免收支/转账下同名分类互相覆盖。 */
function llm_triggers_for_category(array $map, array $override, string $name, int $catType): array
{
    $n = preg_replace('/\s+/u', '', $name);
    $key = $catType . '|' . $n;
    if ($n !== '' && isset($override[$key]) && trim((string)$override[$key]) !== '') {
        return [(string)$override[$key], 'custom', $key];
    }
    return llm_match_triggers($map, $n, $catType);
}

/** 抽取「常见转账说法」，用于让模型判 type=4 并选择对应的转账分类。 */
function llm_transfer_hints(array $map, int $pick = 4): string
{
    $words = [];
    foreach ($map as $v) {
        if ((int)($v[2] ?? 0) !== EBK_CAT_TYPE_TRANSFER) {
            continue;
        }
        foreach (array_slice(explode('、', (string)$v[1]), 0, $pick) as $w) {
            $w = trim((string)preg_replace('/（.*$/u', '', $w));
            if ($w !== '') {
                $words[] = $w;
            }
        }
    }
    return implode('、', array_values(array_unique($words)));
}

/**
 * 从 meta 抽出「支出分类 / 收入分类 / 转账分类 / 账户 / 标签」清单，用于拼 prompt。
 * 分类行格式：`id 大类/子类 = 触发词`（映射表命中才有 `= 触发词` 部分）。
 * 第 6 个返回值是映射命中统计，供设置窗口显示。
 */
function llm_build_catalog(array $meta, array $tags = []): array
{
    $map     = llm_category_map();
    $override = llm_map_override();
    $expense = [];
    $income  = [];
    $transfer = [];
    $hit     = 0;
    $total   = 0;
    $miss    = [];
    $configured = 0;
    $unconfigured = [];
    foreach (ebk_category_groups($meta) as $group) {
        if (!empty($group['hidden']) || (string)($group['id'] ?? '') === '') {
            continue;
        }
        $gType = (int)($group['type'] ?? 0);
        $gName = (string)($group['name'] ?? '');
        $subs  = (array)($group['subCategories'] ?? []);
        $rows  = [];
        foreach ($subs as $s) {
            if (!empty($s['hidden']) || (string)($s['id'] ?? '') === '') {
                continue;
            }
            $sName = (string)($s['name'] ?? '');
            [$trig, ] = llm_triggers_for_category($map, $override, $sName, $gType);
            if ($trig !== '') {
                $configured++;
            } else {
                $unconfigured[] = $sName;
            }
            /* 上游分类自带的备注也一并带上：用户可在 ezbook 界面里给个别分类补词 */
            $remark = trim((string)($s['comment'] ?? ''));
            if ($remark !== '' && mb_strpos($trig, $remark) === false) {
                $trig = $trig === '' ? $remark : $trig . '；' . $remark;
            }
            $total++;
            if ($trig !== '') {
                $hit++;
            } else {
                $miss[] = $sName;
            }
            $rows[] = (string)$s['id'] . ' ' . $gName . '/' . $sName . ($trig !== '' ? ' = ' . $trig : '');
        }
        if (!$rows) {
            /* 无子类的顶层分类：它本身就是可选分类，同样试着配触发词 */
            [$trig, ] = llm_triggers_for_category($map, $override, $gName, $gType);
            if ($trig !== '') {
                $configured++;
            } else {
                $unconfigured[] = $gName;
            }
            $remark = trim((string)($group['comment'] ?? ''));
            if ($remark !== '' && mb_strpos($trig, $remark) === false) {
                $trig = $trig === '' ? $remark : $trig . '；' . $remark;
            }
            $total++;
            if ($trig !== '') {
                $hit++;
            } else {
                $miss[] = $gName;
            }
            $rows[] = (string)$group['id'] . ' ' . $gName . ($trig !== '' ? ' = ' . $trig : '');
        }
        if ($gType === EBK_CAT_TYPE_INCOME) {
            $income = array_merge($income, $rows);
        } elseif ($gType === EBK_CAT_TYPE_TRANSFER) {
            $transfer = array_merge($transfer, $rows);
        } else {
            $expense = array_merge($expense, $rows);
        }
    }
    $accts = [];
    foreach ((array)($meta['accounts'] ?? []) as $a) {
        if (!empty($a['hidden'])) {
            continue;
        }
        $kind = [1 => '现金', 2 => '借记卡', 3 => '信用卡', 4 => '虚拟账户', 5 => '负债', 6 => '应收款项', 7 => '投资账户', 8 => '储蓄账户', 9 => '其他'][(int)($a['category'] ?? 0)] ?? '';
        $suffix = $kind !== '' ? '（' . $kind . '）' : '';
        $accts[] = (string)($a['id'] ?? '') . ' ' . (string)($a['name'] ?? '') . $suffix;
    }
    $tagRows = [];
    foreach ($tags as $t) {
        if (!empty($t['hidden'])) {
            continue;
        }
        $tagRows[] = (string)($t['id'] ?? '') . ' ' . (string)($t['name'] ?? '');
    }
    $stats = [
        'total'   => $total,                 // 当前可用子类数
        'hit'     => $hit,                   // 其中拿到触发词的
        'miss'    => $miss,                  // 没配上的子类名（供排查）
        'configured' => $configured,         // 仅分类映射中的触发词，不含上游分类备注
        'unconfigured' => $unconfigured,
        'mapSize' => count($map),            // 映射表条目数
    ];
    return [$expense, $income, $transfer, $accts, $tagRows, $stats];
}

/**
 * 取用户的 meta（分类树+账户）。
 * 两个缓存键都要看：meta2_ 由看板的 meta 接口写（打开过记账页就有）、meta_ 由识别流程写 ——
 * 只看其中一个会出现「明明有分类却统计不到」。都冷时补拉一次 ebk_ensure_meta（结果缓存 1 小时）。
 * 拉不到返回 null，调用方按「拿不到账本分类」降级。
 */
function llm_meta_for_client(object $client, string $CACHE_DIR, string $token): ?array
{
    $h = substr(md5($token), 0, 8);
    foreach (['meta2_' . $h, 'meta_' . $h] as $key) {
        $raw = cacheGetRaw($CACHE_DIR, $key);
        if ($raw !== null && is_array($raw['d'] ?? null)) {
            return $raw['d'];
        }
    }
    try {
        [$meta, ] = ebk_ensure_meta($client, $token, $CACHE_DIR);
        return $meta;
    } catch (Throwable $e) {
        return null;
    }
}

/** 用户账本里实际可选的分类名集合，键为「类型|分类名」（隐藏的不算） */
function llm_user_cat_names(array $meta): array
{
    $out = [];
    foreach (ebk_category_groups($meta) as $g) {
        if (!empty($g['hidden'])) {
            continue;
        }
        $t    = (int)($g['type'] ?? 0);
        $subs = (array)($g['subCategories'] ?? []);
        if (!$subs) {
            $out[$t . '|' . (string)($g['name'] ?? '')] = true;
            continue;
        }
        foreach ($subs as $s) {
            if (empty($s['hidden'])) {
                $out[$t . '|' . (string)($s['name'] ?? '')] = true;
            }
        }
    }
    return $out;
}

/**
 * 编辑弹窗的数据源：映射表全量条目（分类固定，不可增删）+ 当前触发词 + 基线值 + 是否改过 + 是否被用上。
 * matched 用与识别同一套 llm_match_triggers 反查，避免弹窗说「用上了」而实际没命中。
 * $meta 为 null 时 matched 给 null，前端显示成「未知」。
 */
function llm_map_items(?array $meta): array
{
    $map      = llm_category_map();
    /* 保存后主文件就是当前值；旧 user 覆盖已在 llm_category_map() 中兼容合并。 */
    $base     = $map;
    $actual   = [];
    $used     = [];
    if ($meta !== null) {
        foreach (ebk_category_groups($meta) as $group) {
            if (!empty($group['hidden'])) {
                continue;
            }
            $type = (int)($group['type'] ?? 0);
            $groupName = (string)($group['name'] ?? '');
            $subs = array_values(array_filter((array)($group['subCategories'] ?? []), static fn($s): bool => is_array($s) && empty($s['hidden'])));
            $rows = $subs ?: [$group];
            foreach ($rows as $row) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '' || (string)($row['id'] ?? '') === '') {
                    continue;
                }
                $norm = preg_replace('/\s+/u', '', $name);
                $key = $type . '|' . $norm;
                $actual[$key] = ['key' => $key, 'sub' => $name, 'group' => $groupName, 'type' => $type];
                [, , $matched] = llm_match_triggers($map, $name, $type);
                if ($matched !== '') {
                    $used[$type . '|' . $matched] = true;
                }
            }
        }
    }
    $items = [];
    foreach ($map as $sub => $v) {
        $type = (int)($v[2] ?? 0);
        $key = $type . '|' . preg_replace('/\s+/u', '', (string)$sub);
        $trig = (string)($v[1] ?? '');
        $items[] = [
            'key'     => $key,
            'sub'     => (string)$sub,
            'group'   => (string)($v[0] ?? ''),
            'type'    => $type,
            'trig'    => $trig,
            'base'    => (string)($base[$sub][1] ?? ''),
            'custom'  => false,
            'matched' => $meta === null ? null : (isset($actual[$key]) || isset($used[$key])),
        ];
    }
    $known = [];
    foreach ($items as $item) {
        $known[(string)$item['key']] = true;
    }
    /* 没有基线文件，或用户在 EZBookKeeping 新建了自定义分类时，也要给每个真实分类生成输入框。 */
    foreach ($actual as $key => $row) {
        if (isset($known[$key])) {
            continue;
        }
        [$trig, ] = llm_triggers_for_category($map, [], (string)$row['sub'], (int)$row['type']);
        $items[] = $row + [
            'trig'    => $trig,
            'base'    => '',
            'custom'  => false,
            'matched' => true,
        ];
    }
    return $items;
}

/** 映射命中统计（供设置窗口显示，不暴露触发词正文） */
function llm_map_stats(array $meta, array $tags = []): array
{
    $r = llm_build_catalog($meta, $tags);
    $s = $r[5] ?? ['total' => 0, 'hit' => 0, 'miss' => [], 'mapSize' => 0];
    return [
        'total'   => (int)$s['total'],
        'hit'     => (int)$s['hit'],
        'miss'    => array_slice(array_values((array)$s['miss']), 0, 4),   // 只列前几个，其余用 missN 带过，免得把设置窗口撑长
        'missN'   => count((array)$s['miss']),
        'configured' => (int)($s['configured'] ?? 0),
        'unconfigured' => array_slice(array_values((array)($s['unconfigured'] ?? [])), 0, 4),
        'unconfiguredN' => count((array)($s['unconfigured'] ?? [])),
        'mapSize' => (int)$s['mapSize'],
        'custom'  => 0,                                                     // 保留字段以兼容旧前端
    ];
}

/**
 * 带 meta 取数的映射命中统计：缓存命中就直接算，缓存冷则补拉一次 meta（内部已 catch）。
 * 拿不到 meta 返回 null —— 统计只是锦上添花，不能让设置窗口打不开。
 */
function llm_map_stats_for_client(object $client, string $CACHE_DIR, string $token): ?array
{
    $meta = llm_meta_for_client($client, $CACHE_DIR, $token);
    return $meta === null ? null : llm_map_stats($meta);
}

/** 分类防幻觉：类型与分类必须匹配（type 2=收入 3=支出 4=转账），否则回落到该类型第一个分类 */
function ebk_fix_category(string|int $id, int $type, array $meta): string
{
    $want = match ($type) { 2 => 1, 3 => 2, 4 => 3, default => 2 };
    $id   = trim((string)$id);
    $groups = ebk_category_groups($meta);
    if ($id !== '' && $id !== '0') {
        foreach ($groups as $group) {
            if ((int)($group['type'] ?? 0) !== $want || !empty($group['hidden'])) {
                continue;
            }
            $subs = (array)($group['subCategories'] ?? []);
            foreach ($subs as $s) {
                if (empty($s['hidden']) && (string)($s['id'] ?? '') === $id) {
                    return $id;
                }
            }
            if (!$subs && (string)($group['id'] ?? '') === $id) {
                return $id;
            }
        }
    }
    /* 无效/缺失 → 该类型第一个可用分类 */
    foreach ($groups as $group) {
        if ((int)($group['type'] ?? 0) !== $want || !empty($group['hidden'])) {
            continue;
        }
        foreach ((array)($group['subCategories'] ?? []) as $s) {
            if (empty($s['hidden']) && (string)($s['id'] ?? '') !== '') {
                return (string)$s['id'];
            }
        }
        if ((string)($group['id'] ?? '') !== '') {
            return (string)$group['id'];
        }
    }
    return '0';
}

/**
 * 自定义 LLM 一句话识别：把用户真实的分类/账户清单交给模型，让其直接从清单里挑 id。
 * 返回结构与上游 recognize_text 对齐（sourceAmount 单位=分，time=Unix 秒）。
 */
function llm_recognize_custom(array $cfg, string $text, array $meta, string $tz, array $tags = []): array
{
    [$expense, $income, $transfer, $accts, $tagRows] = llm_build_catalog($meta, $tags);
    if (!$expense && !$income && !$transfer) {
        throw new RuntimeException('拿不到分类清单，无法识别（请先在记账里创建分类）');
    }
    $transferHints = llm_transfer_hints(llm_category_map());
    $block = static function (string $title, array $rows): string {
        return $rows ? ($title . "：\n" . implode("\n", $rows) . "\n") : '';
    };
    $now = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');
    $sys = "你是记账助手。把用户的一句话解析成一条记账记录，只输出一个 JSON 对象，不要解释、不要 Markdown 围栏。\n"
         . $block('可用支出分类（格式：id 大类/子类 = 触发词）', $expense)
         . $block('可用收入分类（格式：id 大类/子类 = 触发词）', $income)
         . $block('可用转账分类（格式：id 大类/子类 = 触发词）', $transfer)
         . $block('可用账户（格式：id 名称（账户类型））', $accts)
         . $block('可用标签（格式：id 名称，可多选）', $tagRows)
         . "输出字段：type（3=支出，2=收入，4=转账）、amount（数字，单位元）、"
         . "categoryId（必须是对应交易类型分类清单里的 id，转账也必须选择转账分类）、"
         . "sourceAccountId（付款账户 id）、destinationAccountId（转入账户 id，非转账填 \"0\"）、"
         . "comment（简短备注，20 字内）、"
         . "time（本地时间字符串，格式 \"YYYY-MM-DD HH:MM:SS\"；用户没提到时间就**原样抄写**下面「当前时间」的值，禁止输出数字时间戳）、"
         . "tagIds（标签 id 字符串数组，没有就给 []）。\n"
         . "等号后面是该子类的常见说法，用户口语命中哪个就选哪个子类；几句话都能对上时选更具体的那个。\n"
         . "只能使用上面清单中出现过的 id，不要编造 id；分类必须与交易类型一致。\n"
         . ($transferHints !== '' ? "常见转账说法（type=4，并从转账分类中选择最匹配的一项）：" . $transferHints . "。\n" : '')
         . "当前时间：" . $now . "（时区 " . $tz . "）。";
    $res = llm_chat($cfg, [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $text],
    ], 60);

    $content = (string)($res['choices'][0]['message']['content'] ?? '');
    if ($content === '' && !empty($res['choices'][0]['message']['reasoning_content'])) {
        $content = (string)$res['choices'][0]['message']['reasoning_content'];
    }
    if (trim($content) === '') {
        throw new RuntimeException('模型没有返回内容');
    }
    $json = trim($content);
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $json, $m)) {   // 去掉代码围栏
        $json = $m[1];
    }
    $s = strpos($json, '{');
    $e = strrpos($json, '}');
    if ($s !== false && $e !== false && $e > $s) {
        $json = substr($json, $s, $e - $s + 1);
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('模型返回的不是合法 JSON：' . mb_substr(trim($content), 0, 80));
    }

    $type = (int)($data['type'] ?? 3);
    if (!in_array($type, [2, 3, 4], true)) {
        $type = 3;
    }
    $amountYuan = (float)preg_replace('/[^0-9.\-]/', '', (string)($data['amount'] ?? '0'));
    /* 时间解析：优先「YYYY-MM-DD HH:MM:SS」本地时间字符串（模型**抄写**「当前时间」，几乎不出错），
       兼容数字（部分模型仍会输出 Unix 秒）。
       ⚠️ 旧版让模型自己算 Unix 秒 —— LLM 做「当前时间 → epoch」的心算经常差一两个小时且时对时错
       （2026-09-21 生产实锤：10:46 被识别成 11:46），字符串抄写没有这个问题。 */
    $timeRaw = $data['time'] ?? '';
    $time    = 0;
    if (is_numeric($timeRaw)) {
        $time = (int)$timeRaw;
        if ($time > 4102444800) {        // > 2100 年（常见于误给毫秒）→ 当作没给
            $time = 0;
        }
    } elseif (is_string($timeRaw) && trim($timeRaw) !== '') {
        try {
            /* 无时区后缀的字符串按配置时区解释；带时区后缀（+08:00 等）DateTime 会自动优先采用 */
            $time = (new DateTime(trim($timeRaw), new DateTimeZone($tz)))->getTimestamp();
        } catch (Throwable $te) {
            $time = 0;                   // 解析不了 → 当作没给，用当前时间
        }
    }
    if ($time < 946684800) {             // 早于 2000 年 → 当作没给，用当前时间
        $time = time();
    }
    $tagIds = [];
    foreach ((array)($data['tagIds'] ?? []) as $tid) {
        $tid = trim((string)$tid);
        if ($tid !== '' && $tid !== '0') {
            $tagIds[] = $tid;
        }
    }
    return [
        'type'                 => $type,
        'time'                 => $time,
        'categoryId'           => ebk_fix_category((string)($data['categoryId'] ?? ''), $type, $meta),
        'sourceAccountId'      => trim((string)($data['sourceAccountId'] ?? '')),
        'destinationAccountId' => $type === 4 ? trim((string)($data['destinationAccountId'] ?? '')) : '0',
        'sourceAmount'         => (int)round($amountYuan * 100),
        'comment'              => mb_substr(trim((string)($data['comment'] ?? '')), 0, 60),
        'tagIds'               => array_values(array_unique($tagIds)),
    ];
}

/* GET tx_search：全量流水搜索（透传 list/all.json 过滤参数）。
   交易随时在变，不做服务端缓存；过滤参数里 min_time/max_time 是「时间序列 Id」不是 Unix 时间，
   所以日期区间必须走 list/all.json 的 start_time/end_time（Unix 秒）。 */
if ($action === 'tx_search') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* 大结果护栏：上游 list/all 一次返回全量，2 万笔级别（~20MB JSON）在默认 128M 下
       json_decode 就会耗尽内存 → 500 空响应。仅本 action 提限，请求结束自动还原。
       ini_get 返回 "128M"/"2G"/"-1"（无限）这类简写，要按单位解析，别直接 (int)。 */
    $ml = trim((string)ini_get('memory_limit'));
    if ($ml !== '-1' && $ml !== '') {
        $unit = strtolower(substr($ml, -1));
        $num = (int)$ml;
        $bytes = $unit === 'g' ? $num * 1073741824 : ($unit === 'm' ? $num * 1048576 : ($unit === 'k' ? $num * 1024 : (int)$ml));
        if ($bytes > 0 && $bytes < 512 * 1048576) {
            ini_set('memory_limit', '512M');
        }
    }
    /* ---- 收集并清洗参数 ---- */
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) > 50) {
        fail('关键词最长 50 字');
    }
    $type = (int)($_GET['type'] ?? 0);
    if (!in_array($type, [0, 1, 2, 3, 4], true)) {
        fail('交易类型不合法');
    }
    /** 单个 19 位内雪花 ID 参数（空=不过滤）；19 位 ID 必须全程字符串 */
    $idParam = static function (string $key): string {
        $v = trim((string)($_GET[$key] ?? ''));
        return preg_match('/^\d{1,20}$/', $v) ? $v : '';
    };
    $catId  = $idParam('cat');
    $acctId = $idParam('acct');
    $tagIds = [];
    foreach (explode(',', (string)($_GET['tags'] ?? '')) as $tid) {
        $tid = trim((string)$tid);
        if ($tid !== '' && preg_match('/^\d{1,20}$/', $tid)) {
            $tagIds[] = $tid;
        }
    }
    $tagMode = (string)($_GET['tagMode'] ?? 'any');
    if (!in_array($tagMode, ['any', 'all', 'none'], true)) {
        $tagMode = 'any';
    }
    $amtMin = ($_GET['amtMin'] ?? '') !== '' ? round((float)$_GET['amtMin'], 2) : 0.0;
    $amtMax = ($_GET['amtMax'] ?? '') !== '' ? round((float)$_GET['amtMax'], 2) : 0.0;
    if ($amtMin < 0 || $amtMax < 0 || $amtMin > 99999999 || $amtMax > 99999999) {
        fail('金额超出范围');
    }
    if ($amtMin > 0 && $amtMax > 0 && $amtMin > $amtMax) {
        fail('金额区间下限不能大于上限');
    }
    /* 日期区间反了要显式报错：上游对 start_time > end_time 只会静默返回空集，
       用户会误以为「这段时间没有流水」，而不是发现条件填反了 */
    $fromRaw = (string)($_GET['from'] ?? '');
    $toRaw   = (string)($_GET['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) && $fromRaw > $toRaw) {
        fail('开始日期不能晚于结束日期');
    }
    /* ---- 组上游参数（金额元→分；日期按站点时区换算 Unix 秒） ---- */
    /* 元数据提前取一次（命中缓存 0 RTT）：既给下面的大类展开用，也给结果归一化用。
       失败不拦查询 —— 退化为「不展开、按单 id 查」的旧行为，只在登录失效时提前退出。 */
    $bundle = [];
    try {
        $bundle = ebk_meta_bundle([
            'categories' => ['transaction/categories/list.json', []],
        ], $CACHE_DIR);
        if (!is_array($bundle)) {
            $bundle = [];
        }
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $bundle = [];
    }
    $params = ['trim_tag' => 1];
    if ($type > 0) {
        $params['type'] = $type;
    }
    if ($catId !== '') {
        /* 大类 → 大类+全部子类：统计页排行给的是大类 id，但记在子类上的账只按精确 id 匹配
           是查不到的（上游版本对该行为不一致）。展开成官方逗号多 id 格式后行为稳定。 */
        $params['category_ids'] = ebk_expand_category_ids($catId, is_array($bundle['categories'] ?? null) ? $bundle['categories'] : []);
    }
    if ($acctId !== '') {
        $params['account_ids'] = $acctId;
    }
    if ($tagMode === 'none') {
        $params['tag_filter'] = 'none';   // 上游特殊值：无标签（model_transaction.go TransactionNoTagFilterValue）
    } elseif ($tagIds) {
        /* 注意模式值以 Go 源码为准：0=HAS_ANY 任一命中、1=HAS_ALL 全部命中（官方文档 txt 里写反了） */
        $params['tag_filter'] = ($tagMode === 'all' ? '1:' : '0:') . implode(',', $tagIds);
    }
    if ($q !== '') {
        $params['keyword'] = $q;
        $params['match_mode'] = 1;   // 忽略大小写
    }
    if ($amtMin > 0 && $amtMax > 0) {
        $params['amount_filter'] = 'bt:' . (int)round($amtMin * AMOUNT_FACTOR) . ':' . (int)round($amtMax * AMOUNT_FACTOR);
    } elseif ($amtMin > 0) {
        $params['amount_filter'] = 'gt:' . (int)round($amtMin * AMOUNT_FACTOR);
    } elseif ($amtMax > 0) {
        $params['amount_filter'] = 'lt:' . (int)round($amtMax * AMOUNT_FACTOR);
    }
    try {
        $tz = new DateTimeZone((string)$config['timezone']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? ''))) {
            $params['start_time'] = (new DateTime($_GET['from'] . ' 00:00:00', $tz))->getTimestamp();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))) {
            $end = new DateTime($_GET['to'] . ' 23:59:59', $tz);   // 含 end 当日全天
            $now = new DateTime('now', $tz);
            if ($end > $now) {
                /* 同 cal_month：终点留 15 分钟容差，吸收设备时钟差，
                   否则刚记的账（浏览器时间略快于服务器）在搜索页「查今天」里短暂消失。 */
                $end = (clone $now)->modify('+15 minutes');
            }
            $params['end_time'] = $end->getTimestamp();
        }
        /* 上游并行拉取（401 自动重放）；归一化与 cal_month 共用同一套 mapTransactionRows，
           前端因此能直接复用 txParts 渲染。分类/默认币种走统一元数据入口。 */
        $got = ebk_fetch_parallel(['txns' => ['transactions/list/all.json', $params]]);
        $bundle = ebk_meta_bundle([
            'categories' => ['transaction/categories/list.json', []],
            'profile'    => ['users/profile/get.json', []],
        ], $CACHE_DIR);
        $catMap  = flattenCategories(is_array($bundle['categories'] ?? null) ? $bundle['categories'] : []);
        $items   = mapTransactionRows(is_array($got['txns'] ?? null) ? $got['txns'] : [], $catMap, AMOUNT_FACTOR, (string)($bundle['profile']['defaultCurrency'] ?? 'CNY'));
        usort($items, static fn(array $a, array $b): int => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));   // 最新在前
        echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => count($items)]], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail(ebk_friendly_error($e->getMessage()));
    }
    exit;
}

/* POST tx_batch：批量改分类 / 加标签 / 清标签 / 删除（透传上游批量端点）。
   上游批量删除强制校验登录密码（IsPasswordEqualsUserPassword，空密码直接拒）。 */
if ($action === 'tx_batch') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in  = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $op  = (string)($in['op'] ?? '');
    $ops = [
        'category' => ['transactions/batch_update/category.json', 'transactionIds'],
        'tagAdd'   => ['transactions/batch_update/tag/add.json', 'transactionIds'],
        'tagClear' => ['transactions/batch_update/tag/clear.json', 'transactionIds'],
        'delete'   => ['transactions/batch_delete.json', 'ids'],   // 批量删除的键名不同：ids
    ];
    if (!isset($ops[$op])) {
        fail('未知批量操作');
    }
    $ids = [];
    foreach ((array)($in['ids'] ?? []) as $tid) {
        $tid = trim((string)$tid);
        /* 真实上游是 19 位纯数字雪花 id；本地 mock 的测试 id 是 "YYYY-MM-DD-N" 格式。
           两者都放行（字母/数字/连字符），空格、引号、Unicode 等一律拒绝。 */
        if (preg_match('/^[0-9A-Za-z\-]{1,40}$/', $tid)) {
            $ids[] = $tid;
        }
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
        fail('请先勾选要操作的流水');
    }
    if (count($ids) > 500) {
        fail('一次最多操作 500 笔');
    }
    [$ep, $idKey] = $ops[$op];
    $body = [$idKey => $ids];
    if ($op === 'category') {
        $catId = trim((string)($in['categoryId'] ?? ''));
        if ($catId === '' || $catId === '0') {
            fail('请选择分类');
        }
        /* 有效性校验（防手滑/防幻觉）：分类必须存在于分类树；类型是否与流水一致由前端提示，
           服务端不拦混合类型——与上游 App 的批量改分类行为一致。 */
        $bundle = ebk_meta_bundle(['categories' => ['transaction/categories/list.json', []]], $CACHE_DIR);
        $catMap = flattenCategories(is_array($bundle['categories'] ?? null) ? $bundle['categories'] : []);
        if (!isset($catMap[(int)$catId])) {
            fail('分类不存在或已被删除');
        }
        $body['categoryId'] = $catId;
    } elseif ($op === 'tagAdd') {
        $tagIds = [];
        foreach ((array)($in['tagIds'] ?? []) as $tid) {
            $tid = trim((string)$tid);
            if ($tid !== '' && $tid !== '0') {
                $tagIds[] = $tid;
            }
        }
        if (!$tagIds) {
            fail('请选择要添加的标签');
        }
        /* tags 是可失败接口：元数据拿不到时跳过本地校验，交由上游把关。
           $tolerate 必须是「key => 兜底值」映射（array_key_exists 判定），不能写成列表 */
        $bundle = ebk_meta_bundle(['tags' => ['transaction/tags/list.json', []]], $CACHE_DIR, ['tags' => []]);
        $validTags = [];
        foreach ((array)($bundle['tags'] ?? []) as $t) {
            $validTags[(string)($t['id'] ?? '')] = true;
        }
        if ($validTags) {
            foreach ($tagIds as $tid) {
                if (!isset($validTags[$tid])) {
                    fail('标签不存在或已被删除');
                }
            }
        }
        $body['tagIds'] = array_values(array_unique($tagIds));
    } elseif ($op === 'delete') {
        $password = (string)($in['password'] ?? '');
        if ($password === '') {
            fail('批量删除需要输入登录密码确认');
        }
        $body['password'] = $password;
    }
    try {
        $client->requestPost($ep, $body, $token);
        /* 批量改动影响看板与日历：失效 cal_/dash_ 缓存（不动 meta_/data/，next load 自动重建） */
        $removed = 0;
        foreach ((array)glob($CACHE_DIR . '/*.json') as $f) {
            if (preg_match('/^(cal_|dash_)/', basename($f)) && cacheDelPath($f)) {
                $removed++;
            }
        }
        echo json_encode(['success' => true, 'data' => ['affected' => count($ids), 'cacheCleared' => $removed]], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        if (stripos($msg, 'password') !== false) {
            fail('登录密码不正确，请重新输入');
        }
        fail(ebk_friendly_error($msg));
    }
    exit;
}

/* GET llm_settings_get：回显 AI 识别配置（Key 只回掩码） */
if ($action === 'llm_settings_get') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = llm_config_get();
    /* 映射命中统计：优先读 meta 缓存；缓存冷（刚清过缓存 / 还没开过记账页）时补拉一次，
       免得用户为了看命中情况还得先去翻一遍记账页。拉回来的 meta 会缓存 1 小时，不会反复打上游。 */
    $mapStats = llm_map_stats_for_client($client, $CACHE_DIR, $token);
    echo json_encode(['success' => true, 'data' => [
        'mode'      => (string)$cfg['mode'],
        'base_url'  => (string)$cfg['base_url'],
        'model'     => (string)$cfg['model'],
        'hasKey'    => trim((string)$cfg['api_key']) !== '',
        'keyMasked' => llm_mask_key((string)$cfg['api_key']),
        'ready'     => llm_cfg_ready($cfg),
        'mapStats'  => $mapStats,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* POST llm_settings_save：保存 AI 识别配置（api_key 留空=保持不变；clearKey=true=清空） */
if ($action === 'llm_settings_save') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in  = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cur = llm_config_get();
    $mode = (string)($in['mode'] ?? $cur['mode']);
    if (!in_array($mode, ['upstream', 'custom'], true)) {
        $mode = 'upstream';
    }
    $base  = trim((string)($in['base_url'] ?? $cur['base_url']));
    $model = trim((string)($in['model'] ?? $cur['model']));
    if ($base !== '' && !preg_match('#^https?://#i', $base)) {
        fail('接口地址需以 http:// 或 https:// 开头');
    }
    if (mb_strlen($model) > 100) {
        fail('模型名过长');
    }
    if (!empty($in['clearKey'])) {
        $key = '';
    } else {
        $keyIn = trim((string)($in['api_key'] ?? ''));
        $key = $keyIn === '' ? (string)$cur['api_key'] : $keyIn;   // 留空 = 不改动
    }
    $cfg = ['mode' => $mode, 'base_url' => $base, 'api_key' => $key, 'model' => $model];
    if (!llm_config_save($cfg)) {
        fail('写入配置失败：data 目录不可写');
    }
    echo json_encode(['success' => true, 'data' => [
        'mode'      => $mode,
        'base_url'  => $base,
        'model'     => $model,
        'hasKey'    => $key !== '',
        'keyMasked' => llm_mask_key($key),
        'ready'     => llm_cfg_ready($cfg),
        'mapStats'  => llm_map_stats_for_client($client, $CACHE_DIR, $token),
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* GET llm_map_get：分类语义映射表全量条目。分类本身固定（不可增删改），只有触发词可编辑 */
if ($action === 'llm_map_get') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $meta  = llm_meta_for_client($client, $CACHE_DIR, $token);
    $items = llm_map_items($meta);
    $customN  = 0;
    $matchedN = 0;
    foreach ($items as $it) {
        if (!empty($it['custom'])) {
            $customN++;
        }
        if ($it['matched'] === true) {
            $matchedN++;
        }
    }
    echo json_encode(['success' => true, 'data' => [
        'items'    => $items,
        'max'      => LLM_TRIGGER_MAX,
        'metaOk'   => $meta !== null,
        'customN'  => $customN,
        'matchedN' => $meta === null ? null : $matchedN,   // null = 拿不到用户分类，前端显示「未知」
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* POST llm_map_save：把完整分类映射直接保存到 data/llm_category_map.php */
if ($action === 'llm_map_save') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in   = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $rows = $in['items'] ?? null;
    if (!is_array($rows)) {
        fail('提交内容格式不对');
    }
    $meta = llm_meta_for_client($client, $CACHE_DIR, $token);
    $available = llm_map_items($meta);
    $allowed = [];
    foreach ($available as $item) {
        $allowed[(string)($item['key'] ?? '')] = $item;
    }
    if (!$allowed) {
        fail('没有可编辑的分类，请先在 EZBookKeeping 中创建分类');
    }
    $beforeMap = llm_category_map();
    $beforeOverride = llm_map_override();
    $nextMap  = $beforeMap;
    $changed  = 0;
    $reverted = 0;
    $ignored  = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $ignored++;
            continue;
        }
        $key = trim((string)($row['key'] ?? ''));
        if ($key === '' && trim((string)($row['sub'] ?? '')) !== '') {
            $legacySub = trim((string)$row['sub']);
            foreach ($available as $item) {
                if ((string)($item['sub'] ?? '') === $legacySub) {
                    $key = (string)($item['key'] ?? '');
                    break;
                }
            }
        }
        if ($key === '' || !isset($allowed[$key])) {
            $ignored++;
            continue;
        }
        $item = $allowed[$key];
        $sub = trim((string)($item['sub'] ?? $key));
        /* 触发词会原样拼进 prompt 的 `id 大类/子类 = 触发词` 行，所以把换行/连续空白压成单个空格，防止把行拆散 */
        $trig = (string)($row['trig'] ?? '');
        $trig = trim((string)preg_replace('/\s+/u', ' ', $trig));
        if (mb_strlen($trig) > LLM_TRIGGER_MAX) {
            fail('「' . $sub . '」的触发词过长（' . LLM_TRIGGER_MAX . ' 字以内）');
        }
        $type = (int)($item['type'] ?? 0);
        $mapKey = '';
        foreach ($nextMap as $candidate => $value) {
            if (preg_replace('/\s+/u', '', (string)$candidate) === preg_replace('/\s+/u', '', $sub)
                && (int)($value[2] ?? 0) === $type) {
                $mapKey = (string)$candidate;
                break;
            }
        }
        if ($mapKey === '') {
            $mapKey = $sub;
            $nextMap[$mapKey] = [(string)($item['group'] ?? ''), '', $type];
        }
        $oldTrig = (string)($nextMap[$mapKey][1] ?? '');
        if ($oldTrig !== $trig) {
            $nextMap[$mapKey][1] = $trig;
            $changed++;
        }
    }
    if ($nextMap !== $beforeMap || $beforeOverride !== []) {
        if (!llm_category_map_save_base($nextMap)) {
            fail('写入失败：data 目录不可写');
        }
        /* 清空旧版本覆盖文件，避免它在下一次请求中覆盖主映射。 */
        if ($beforeOverride !== [] && !guarded_write(llm_map_override_file(), [])) {
            fail('旧版分类映射清理失败，请检查私有数据目录权限');
        }
    }
    echo json_encode(['success' => true, 'data' => [
        'customN'  => 0,
        'changed'  => $changed,
        'reverted' => $reverted,
        'ignored'  => $ignored,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 导出完整的分类映射，键为「类型|分类名」，值为触发词。 */
function llm_map_export(): array
{
    $out = [];
    foreach (llm_category_map() as $sub => $value) {
        $type = (int)($value[2] ?? 0);
        $out[$type . '|' . (string)$sub] = (string)($value[1] ?? '');
    }
    return $out;
}

/* POST llm_test：用提交上来的（或已保存的）配置做一次极小请求，验证地址/Key/模型 */
if ($action === 'llm_test') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in  = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cur = llm_config_get();
    $probe = [
        'base_url' => trim((string)($in['base_url'] ?? $cur['base_url'])),
        'api_key'  => trim((string)($in['api_key'] ?? '')) !== '' ? trim((string)$in['api_key']) : (string)$cur['api_key'],
        'model'    => trim((string)($in['model'] ?? $cur['model'])),
    ];
    if ($probe['base_url'] === '' || $probe['model'] === '') {
        fail('请先填写接口地址与模型');
    }
    if ($probe['api_key'] === '') {
        fail('请先填写 API Key');
    }
    if (!preg_match('#^https?://#i', $probe['base_url'])) {
        fail('接口地址需以 http:// 或 https:// 开头');
    }
    $t0 = microtime(true);
    try {
        $res = llm_chat($probe, [
            ['role' => 'user', 'content' => '请只回复两个字：可用'],
        ], 30, 32);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $reply = trim((string)($res['choices'][0]['message']['content'] ?? ''));
        echo json_encode(['success' => true, 'data' => [
            'ms'        => $ms,
            'model'     => (string)($res['model'] ?? $probe['model']),
            'reply'     => mb_substr($reply, 0, 60),
            'promptTokens' => (int)($res['usage']['prompt_tokens'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    exit;
}

/* POST ai_recognize：一句话记账——自定义 LLM 优先，未配置或失败则回落上游文本识别 */
if ($action === 'ai_recognize') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') {
        fail('请输入记账内容');
    }
    if (mb_strlen($text) > 200) {
        fail('内容过长（200 字以内）');
    }
    try {
        [$metaRaw, $validAcct, $defAcct] = ebk_ensure_meta($client, $token, $CACHE_DIR);

        $cfg       = llm_config_get();
        $useCustom = ($cfg['mode'] === 'custom' && llm_cfg_ready($cfg));
        $result    = null;
        $via       = 'upstream';
        $customErr = '';

        if ($useCustom) {
            /* 标签清单从 meta2 缓存取（前端已拉过，零成本）；没有就不带标签 */
            $tags = [];
            $m2 = cacheGet($CACHE_DIR, 'meta2_' . substr(md5($token), 0, 8), 3600);
            if (is_array($m2) && !empty($m2['tags'])) {
                $tags = $m2['tags'];
            }
            try {
                $result = llm_recognize_custom($cfg, $text, $metaRaw, (string)($config['timezone'] ?? 'Asia/Shanghai'), $tags);
                $via    = 'custom';
            } catch (Throwable $ce) {
                $customErr = $ce->getMessage();     // 自定义失败 → 静默回落上游
            }
        }

        if ($result === null) {
            try {
                $result = $client->requestPost('llm/transactions/recognize_text.json', ['text' => $text], $token, 60);
            } catch (Throwable $ue) {
                $umsg = $ue->getMessage();
                if ($customErr !== '') {
                    fail('自定义识别失败：' . $customErr . '；回落上游也失败：' . $umsg);
                }
                if (strpos($umsg, '404') !== false) {
                    fail('上游未启用 AI 一句话记账。可到「设置 → AI 识别」改用自定义 LLM：填入接口地址、API Key 与模型。');
                }
                fail($umsg);
            }
        }

        /* 防幻觉：分类必须属于该类型（上游与自定义两条路径统一收口） */
        $rType = (int)($result['type'] ?? 3);
        if (!in_array($rType, [2, 3, 4], true)) {
            $rType = 3;
        }
        /* 账户先按用户原文做确定匹配，再接受模型结果。最长账户名优先，
           例如同时存在「微信」和「微信零钱」时，后者会优先命中。转账按原文出现顺序取前两个。 */
        $accountHints = ebk_account_hints($text, $metaRaw);
        $accountSource = 'ai';
        $accountMatched = '';
        if ($accountHints) {
            $result['sourceAccountId'] = $accountHints[0]['id'];
            $accountMatched = $accountHints[0]['matched'];
            $accountSource = 'text';
            if ($rType === 4 && isset($accountHints[1])) {
                $result['destinationAccountId'] = $accountHints[1]['id'];
            }
        }
        /* 防幻觉：账户 ID 必须有效（否则上游 add 会报 source account not found）。 */
        if ($defAcct !== '') {
            $rawSource = (string)($result['sourceAccountId'] ?? '');
            $rawDest   = (string)($result['destinationAccountId'] ?? '');
            $result['sourceAccountId'] = ebk_fix_account($rawSource, $validAcct, $defAcct);
            $result['destinationAccountId'] = ebk_fix_account($rawDest, $validAcct, $defAcct);
            if (!$accountHints && ($rawSource === '' || !isset($validAcct[$rawSource]) || $rawSource === '0')) {
                $accountSource = 'default';
            }
        }
        $accountWarning = '';
        $accountNeedsConfirm = false;
        if ($accountSource === 'default') {
            $defaultName = ebk_account_name($metaRaw, $defAcct);
            if (ebk_text_mentions_account($text)) {
                $accountWarning = '文字中提到的账户未匹配到现有账户，请手动选择账户';
                $accountNeedsConfirm = true;
            } else {
                $accountWarning = '未指定账户，当前使用默认账户' . ($defaultName !== '' ? '：' . $defaultName : '');
            }
        }
        $result['type']       = $rType;
        $result['categoryId'] = ebk_fix_category((string)($result['categoryId'] ?? ''), $rType, $metaRaw);
        if ($rType !== 4) {
            $result['destinationAccountId'] = '0';
        }
        $result['sourceAmount'] = (int)($result['sourceAmount'] ?? 0);
        $result['accountSource'] = $accountSource;
        $result['accountMatched'] = $accountMatched;
        $result['accountNeedsConfirm'] = $accountNeedsConfirm;
        $result['accountWarning'] = $accountWarning;
        echo json_encode(['success' => true, 'data' => $result, 'via' => $via], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    exit;
}

/* POST tag_add：新建交易标签（透传上游 transaction/tags/add.json，成功后失效 meta 缓存） */
if ($action === 'tag_add') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        fail('请输入标签名');
    }
    if (mb_strlen($name) > 20) {
        fail('标签名过长（20 字以内）');
    }
    try {
        $tag = $client->requestPost('transaction/tags/add.json', ['groupId' => '0', 'name' => $name], $token, 20);
        cacheDel($CACHE_DIR, 'meta2_' . substr(md5($token), 0, 8));   // 让下次 meta 带上新标签
        ebk_meta_forget($CACHE_DIR, ['tags']);                        // 统一低频层的 tags 也要失效，否则新标签被 1h 缓存挡住
        echo json_encode(['success' => true, 'data' => $tag], JSON_UNESCAPED_UNICODE);
    } catch (EbkUnauthorizedException $e) {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '登录已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    exit;
}

/* ==================== A2 预算管理 ====================
 * 数据存私有 data 目录，并按登录账号隔离；旧版 budget.json 会在首次使用时迁移。
 * 键用**分类名**而不是分类 id —— 上游 id 是 19 位雪花、每个实例都不同，
 * 用名字才跨实例可移植、用户也能手编。大类/小类都支持：大类键就是「餐饮」，
 * 小类键写成「餐饮/外卖点餐」（与上游的层级展示一致，便于阅读）。
 *
 * ⚠️ 父子独立计算（2026-09-20 已拍板）：大类预算的 spent = 该大类金额 **减去**
 * 所有「已设预算的子类」金额，小类预算的 spent 只算自己。理由见需求稿 2.5：
 * 若包含式，用户设「餐饮 2000」+「餐饮/外卖 500」而外卖花了 600 时，餐饮会显示
 * 已用 1100（含外卖），用户永远算不清"还能花多少在非外卖上"。独立计算让每个数字
 * 含义唯一。代价是用户第一次会疑惑"外卖怎么不算进餐饮"→ 弹窗内常驻一行说明。
 */

function budget_file(): string
{
    global $DATA_DIR;
    $target = user_data_file('budget.php');
    $legacy = $DATA_DIR . '/budget.json';
    $accountKey = (string)($_SESSION['ebk_user_key'] ?? '');
    $owner = is_file($DATA_DIR . '/.legacy_owner') ? trim((string)@file_get_contents($DATA_DIR . '/.legacy_owner')) : '';
    if (!is_file($target) && $accountKey !== '' && $owner === substr($accountKey, 0, 16) && is_file($legacy)) {
        @copy($legacy, $target);
        @chmod($target, 0600);
    }
    return $target;
}

function budget_defaults(): array
{
    return ['version' => 1, 'updatedAt' => 0, 'monthly' => [], 'alerts' => ['warn' => 80, 'danger' => 100]];
}

/** 读取预算配置。文件不存在 / 损坏都返回默认值 + $broken 标记，绝不抛异常（前端要么引导态要么静默降级，不能白屏） */
function budget_read(?bool &$broken = null): array
{
    $broken = false;
    $file = budget_file();
    if (!is_file($file)) {
        return budget_defaults();                 // hasBudget=false 由 monthly 是否为空决定
    }
    $data = guarded_read($file);
    if (!is_array($data)) {
        $broken = true;                           // 文件在但内容坏了：降级 + 告知前端，不覆盖用户文件
        return budget_defaults();
    }
    $out = array_merge(budget_defaults(), $data);
    $out['monthly'] = is_array($out['monthly'] ?? null) ? $out['monthly'] : [];
    $out['alerts']  = is_array($out['alerts'] ?? null) ? array_merge(['warn' => 80, 'danger' => 100], $out['alerts']) : ['warn' => 80, 'danger' => 100];
    return $out;
}

/** level 判定在后端算，前端只负责染色。none/warn/danger/over 四档 */
function budget_level(float $pct, array $alerts): string
{
    $warn   = (float)($alerts['warn'] ?? 80);
    $danger = (float)($alerts['danger'] ?? 100);
    if ($pct > 100) {          // 超支：真超 100% 才算，danger 阈值被调高也不影响这里
        return 'over';
    }
    if ($pct >= $danger) {
        return 'danger';
    }
    if ($pct >= $warn) {
        return 'warn';
    }
    return 'none';
}

/* GET budget_get：读配置 + 算当期执行进度。
   ⚠️ spent 的数据源取**已缓存的 dashboard payload**，绝不在这里重新打上游 ——
   这是需求稿里「不新增上游请求」的硬约束。dashboard 缓存不在时直接返回
   progressOk=false，前端显示"正在等待看板数据"，而不是自己去拉。 */
if ($action === 'budget_get') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tkHash = substr(md5($token), 0, 8);
    $broken = false;
    $cfg = budget_read($broken);

    /* 月度预算天然是"按自然月"的，与 dashboard 的 range 参数无关。
       直接找当月那份 dashboard 缓存（range=month 的 key），不指定就读不到进度。 */
    $now  = new DateTime('now', new DateTimeZone((string)($config['timezone'] ?? 'Asia/Shanghai')));
    $ym   = trim((string)($_GET['ym'] ?? $now->format('Y-m')));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || $ym > $now->format('Y-m')) {
        fail('预算月份不合法');
    }
    /* 与 dashboard/cal_month 当前 v2 缓存键保持一致；旧键会导致 progressOk 永远为 false，
       首页预算卡长期停在“正在等待本月账单数据”。 */
    $dash = cacheGetRaw($CACHE_DIR, 'dash_v2_month_' . $tkHash);
    $cal  = cacheGetRaw($CACHE_DIR, 'cal_v2_' . $ym . '_' . $tkHash);
    $catRank = [];
    $dataYM  = '';
    if ($ym === $now->format('Y-m') && is_array($dash) && isset($dash['d']['data']['categoryRank']['expense'])) {
        $catRank = $dash['d']['data']['categoryRank']['expense'];
        $dataYM  = (string)substr((string)(($dash['d']['data']['daily'] ?? [])[count($dash['d']['data']['daily'] ?? []) - 1]['date'] ?? ''), 0, 7);
    } elseif (is_array($cal) && isset($cal['d']['transactions'])) {
        $byTop = [];
        foreach ((array)$cal['d']['transactions'] as $tx) {
            if ((int)($tx['type'] ?? 0) !== 3) {
                continue;
            }
            $top = (string)($tx['parent'] ?? $tx['category'] ?? '未分类');
            $sub = (string)($tx['category'] ?? $top);
            if (!isset($byTop[$top])) {
                $byTop[$top] = ['name' => $top, 'amount' => 0.0, 'subs' => []];
            }
            $byTop[$top]['amount'] += (float)($tx['amount'] ?? 0);
            if (!isset($byTop[$top]['subs'][$sub])) {
                $byTop[$top]['subs'][$sub] = ['id' => (string)($tx['categoryId'] ?? ''), 'name' => $sub, 'amount' => 0.0];
            }
            $byTop[$top]['subs'][$sub]['amount'] += (float)($tx['amount'] ?? 0);
        }
        foreach ($byTop as $row) {
            $row['subs'] = array_values($row['subs']);
            $catRank[] = $row;
        }
        $dataYM = $ym;
    }
    $progressOk = ($dataYM === $ym);   // 缓存里的月份必须是当月，否则进度就是错的

    /* 把 categoryRank 摊平成「大类名 => 金额」和「大类/子类 名 => 金额」两张表 */
    $catAmt  = [];     // 大类名 => amount
    $subAmt  = [];     // "大类名/子类名" => amount
    foreach ($catRank as $top) {
        $tname = (string)($top['name'] ?? '');
        if ($tname === '') {
            continue;
        }
        $catAmt[$tname] = (float)($top['amount'] ?? 0);
        foreach ((array)($top['subs'] ?? []) as $s) {
            /* ⚠️ subs 里含大类自身（大类可自己记账），自身那条不能进子类表，
               否则 "餐饮/餐饮" 会被算成子类、从大类里被扣两次。 */
            if ((int)($s['id'] ?? 0) === (int)($top['id'] ?? -1)) {
                continue;
            }
            $subAmt[$tname . '/' . (string)($s['name'] ?? '')] = (float)($s['amount'] ?? 0);
        }
    }

    $monthly = $cfg['monthly'];
    $alerts  = $cfg['alerts'];
    /* 已被设了预算的子类名集合，按大类归类 —— 大类 spent 要减去它们（父子独立） */
    $budgetedSubsByTop = [];
    foreach ($monthly as $key => $_v) {
        if ($key === '总体' || $key === '') {
            continue;
        }
        if (strpos($key, '/') !== false) {
            [$tn, ] = explode('/', $key, 2);
            $budgetedSubsByTop[$tn][] = $key;
        }
    }

    $rows = [];
    /* 「总体」行永远在最前（若设了） */
    if (isset($monthly['总体'])) {
        $budget = (float)$monthly['总体'];
        $spent  = 0.0;
        foreach ($catAmt as $a) {
            $spent += $a;
        }
        $pct = $budget > 0 ? round($spent / $budget * 100, 1) : 0;
        $rows[] = [
            'name' => '总体', 'budget' => round($budget, 2), 'spent' => round($spent, 2),
            'pct' => $pct, 'level' => budget_level($pct, $alerts), 'isTotal' => true,
            'color' => '#3f66f8', 'over' => round(max(0, $spent - $budget), 2), 'exists' => true,
        ];
    }
    foreach ($monthly as $key => $budgetRaw) {
        if ($key === '总体' || $key === '') {
            continue;
        }
        $budget = (float)$budgetRaw;
        $isSub  = strpos($key, '/') !== false;
        if ($isSub) {
            $spent = (float)($subAmt[$key] ?? 0);
        } else {
            /* 大类：总额减去所有已设预算的子类（父子互不污染） */
            $spent = (float)($catAmt[$key] ?? 0);
            foreach ((array)($budgetedSubsByTop[$key] ?? []) as $subKey) {
                $spent -= (float)($subAmt[$subKey] ?? 0);
            }
            $spent = max(0, $spent);
        }
        $pct = $budget > 0 ? round($spent / $budget * 100, 1) : 0;
        /* exists：该分类在当期账本里有没有出现（没有的话前端给灰色提示，但不拦保存） */
        $exists = $isSub ? isset($subAmt[$key]) : isset($catAmt[$key]);
        $rows[] = [
            'name' => $key, 'budget' => round($budget, 2), 'spent' => round($spent, 2),
            'pct' => $pct, 'level' => budget_level($pct, $alerts), 'isTotal' => false,
            'color' => '', 'over' => round(max(0, $spent - $budget), 2), 'exists' => $exists,
        ];
    }
    /* 排序：总体恒在最前，其余超支在前、再按 pct 降序 */
    usort($rows, function ($a, $b) {
        if ($a['isTotal'] !== $b['isTotal']) {
            return $a['isTotal'] ? -1 : 1;
        }
        return $b['pct'] <=> $a['pct'];
    });

    /* 给每行补分类色（按名字从 meta 里取）。
       meta_categories_<hash> 是 ebk_meta_bundle() 写的单项缓存，结构是 cacheSet 包过的
       ['_t'=>写入时间, 'd'=>上游 categories/list.json 的原始体]；而那个原始体**是按类型
       分组的对象** {"1":[…收入],"2":[…支出],"3":[…转账]}（实测确认，不是数组也不是
       {categories:…}），正好是 flattenCategories() 支持的两种形状之一。 */
    if ($rows) {
        $raw = cacheGetRaw($CACHE_DIR, 'meta_categories_' . $tkHash);
        $catNameColor = [];
        foreach (flattenCategories((array)($raw['d'] ?? [])) as $c) {
            if (!empty($c['name'])) {
                $catNameColor[(string)$c['name']] = '#' . ltrim((string)($c['color'] ?? ''), '#');
            }
        }
        foreach ($rows as &$r) {
            if ($r['color'] !== '') {
                continue;
            }
            $nm = strpos($r['name'], '/') !== false ? substr((string)$r['name'], (int)strpos($r['name'], '/') + 1) : $r['name'];
            $r['color'] = $catNameColor[$nm] ?? '';
        }
        unset($r);
    }

    echo json_encode(['success' => true, 'data' => [
        'config'     => ['monthly' => $monthly, 'alerts' => $alerts],
        'progress'   => $rows,
        'month'      => $ym,
        'hasBudget'  => count($monthly) > 0,
        'progressOk' => $progressOk,           // false = 当月看板缓存还没生成，进度暂不可信
        'broken'     => $broken,               // true = 文件损坏已降级，前端提示但不清用户文件
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* POST budget_save：全量保存预算表 */
if ($action === 'budget_save') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $monthly = $in['monthly'] ?? null;
    if (!is_array($monthly)) {
        fail('提交内容格式不对');
    }
    if (count($monthly) > 60) {
        fail('预算条目最多 60 条');
    }
    $clean = [];
    foreach ($monthly as $name => $val) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;                              // 空名静默跳过（前端删除行会留空键，不该报错）
        }
        if (mb_strlen($name) > 30) {
            fail('预算名称过长（30 字以内）：' . $name);
        }
        if (!is_numeric($val)) {
            fail('预算金额必须是数字：' . $name);
        }
        $amt = (float)$val;
        if ($amt <= 0 || $amt > 99999999) {
            fail('预算金额需大于 0 且不超过 99999999：' . $name);
        }
        /* 金额统一保留两位小数，避免 100.005 这类脏值写进文件 */
        $clean[$name] = round($amt, 2);
    }
    $cfg = budget_read();
    $cfg['monthly']   = $clean;
    $cfg['updatedAt'] = time();
    if (!guarded_write(budget_file(), $cfg)) {
        fail('保存失败：私有数据目录不可写，请检查 storage_dir 权限');
    }
    /* 预算改动不影响 dashboard 的 cal_/dash_ 聚合（那些是账单数据），
       但 dashboard 缓存里带了我们算好的 progress 吗？——没有，progress 是 budget_get 现算的，
       所以这里不需要清任何缓存。仅为「保存后刷新页面立刻看到」而保持接口幂等。 */
    echo json_encode(['success' => true, 'data' => [
        'config' => ['monthly' => $clean, 'alerts' => $cfg['alerts']],
        'saved'  => count($clean),
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* GET settings_export：导出可迁移的非敏感设置。
   登录凭据、Session、API Token、AI API Key、缓存和交易数据永不进入导出包。 */
if ($action === 'settings_export') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ai = llm_config_get();
    $budget = budget_read();
    echo json_encode(['success' => true, 'data' => [
        'format' => 'ezbookdash-settings',
        'version' => 1,
        'appVersion' => '1.0.0',
        'exportedAt' => date(DATE_ATOM),
        'ai' => [
            'mode' => (string)($ai['mode'] ?? 'upstream'),
            'base_url' => (string)($ai['base_url'] ?? ''),
            'model' => (string)($ai['model'] ?? ''),
        ],
        'categoryMapping' => llm_map_export(),
        'budget' => [
            'monthly' => is_array($budget['monthly'] ?? null) ? $budget['monthly'] : [],
            'alerts' => is_array($budget['alerts'] ?? null) ? $budget['alerts'] : ['warn' => 80, 'danger' => 100],
        ],
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* POST settings_import：导入非敏感设置包。AI API Key 保留目标设备已有值。 */
if ($action === 'settings_import') {
    $token = (string)($_SESSION['ebk_token'] ?? '');
    if ($token === '') {
        echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in) || ($in['format'] ?? '') !== 'ezbookdash-settings' || (int)($in['version'] ?? 0) !== 1) {
        fail('不是有效的 ezBookDash 设置备份文件');
    }
    $hasAi = array_key_exists('ai', $in);
    $hasMap = array_key_exists('categoryMapping', $in);
    $hasBudget = array_key_exists('budget', $in);
    if (!$hasAi && !$hasMap && !$hasBudget) {
        fail('设置备份中没有可导入的内容');
    }

    $aiIncoming = null;
    if ($hasAi) {
        if (!is_array($in['ai'])) {
            fail('AI 配置格式不正确');
        }
        $mode = (string)($in['ai']['mode'] ?? 'upstream');
        $base = trim((string)($in['ai']['base_url'] ?? ''));
        $model = trim((string)($in['ai']['model'] ?? ''));
        if (!in_array($mode, ['upstream', 'custom'], true)) {
            fail('AI 识别方式不合法');
        }
        if (mb_strlen($base) > 500 || mb_strlen($model) > 120) {
            fail('AI 配置内容过长');
        }
        $aiIncoming = ['mode' => $mode, 'base_url' => $base, 'model' => $model];
    }

    $mapIncoming = null;
    if ($hasMap) {
        if (!is_array($in['categoryMapping']) || count($in['categoryMapping']) > 500) {
            fail('分类映射格式不正确或条目过多');
        }
        $mapIncoming = [];
        foreach ($in['categoryMapping'] as $key => $value) {
            $key = trim((string)$key);
            $value = trim((string)$value);
            if ($key === '') {
                continue;
            }
            if (!preg_match('/^[123]\|.{1,160}$/u', $key)) {
                continue;
            }
            if (mb_strlen($value) > LLM_TRIGGER_MAX) {
                fail('分类映射触发词过长');
            }
            $mapIncoming[$key] = (string)preg_replace('/\s+/u', ' ', $value);
        }
    }

    $budgetIncoming = null;
    if ($hasBudget) {
        if (!is_array($in['budget'])) {
            fail('预算配置格式不正确');
        }
        $monthlyIn = $in['budget']['monthly'] ?? [];
        if (!is_array($monthlyIn) || count($monthlyIn) > 60) {
            fail('预算条目格式不正确或过多');
        }
        $monthly = [];
        foreach ($monthlyIn as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 30 || !is_numeric($value)) {
                fail('预算条目格式不正确：' . $name);
            }
            $amount = round((float)$value, 2);
            if ($amount <= 0 || $amount > 99999999) {
                fail('预算金额不合法：' . $name);
            }
            $monthly[$name] = $amount;
        }
        $alertsIn = $in['budget']['alerts'] ?? ['warn' => 80, 'danger' => 100];
        if (!is_array($alertsIn)) {
            fail('预算预警配置格式不正确');
        }
        $alerts = ['warn' => 80, 'danger' => 100];
        foreach (['warn', 'danger'] as $level) {
            if (array_key_exists($level, $alertsIn)) {
                if (!is_numeric($alertsIn[$level]) || (float)$alertsIn[$level] < 0 || (float)$alertsIn[$level] > 100) {
                    fail('预算预警阈值不合法');
                }
                $alerts[$level] = round((float)$alertsIn[$level], 1);
            }
        }
        $budgetIncoming = ['version' => 1, 'updatedAt' => time(), 'monthly' => $monthly, 'alerts' => $alerts];
    }

    /* 所有内容先校验，再写入；失败时恢复三份原始配置，避免半导入状态。 */
    $beforeAi = llm_config_get();
    $beforeMap = llm_category_map();
    $beforeBase = llm_category_map_base();
    $beforeOverride = llm_map_override();
    $beforeBudget = budget_read();
    $written = [];
    try {
        if ($aiIncoming !== null) {
            $nextAi = array_merge(llm_config_defaults(), $beforeAi, $aiIncoming);
            if (!llm_config_save($nextAi)) {
                throw new RuntimeException('AI 配置写入失败，请检查私有数据目录权限');
            }
            $written[] = 'ai';
        }
        if ($mapIncoming !== null) {
            $nextMap = $beforeMap;
            $applied = 0;
            foreach ($mapIncoming as $mapKey => $trigger) {
                if (!preg_match('/^([123])\\|(.*)$/u', (string)$mapKey, $mm)) {
                    continue;
                }
                $type = (int)$mm[1];
                $sub = (string)$mm[2];
                $found = '';
                foreach ($nextMap as $candidate => $entry) {
                    if (preg_replace('/\\s+/u', '', (string)$candidate) === preg_replace('/\\s+/u', '', $sub)
                        && (int)($entry[2] ?? 0) === $type) {
                        $found = (string)$candidate;
                        break;
                    }
                }
                if ($found === '') {
                    continue;
                }
                if ((string)($nextMap[$found][1] ?? '') !== (string)$trigger) {
                    $nextMap[$found][1] = (string)$trigger;
                }
                $applied++;
            }
            if (!llm_category_map_save_base($nextMap)) {
                throw new RuntimeException('分类映射写入失败，请检查私有数据目录权限');
            }
            if ($beforeOverride !== [] && !guarded_write(llm_map_override_file(), [])) {
                throw new RuntimeException('旧版分类映射清理失败，请检查私有数据目录权限');
            }
            $mapApplied = $applied;
            $written[] = 'mapping';
        }
        if ($budgetIncoming !== null) {
            if (!guarded_write(budget_file(), $budgetIncoming)) {
                throw new RuntimeException('预算写入失败，请检查私有数据目录权限');
            }
            $written[] = 'budget';
        }
    } catch (Throwable $e) {
        if (in_array('ai', $written, true)) { @llm_config_save($beforeAi); }
        if (in_array('mapping', $written, true)) {
            @llm_category_map_save_base($beforeBase);
            @guarded_write(llm_map_override_file(), $beforeOverride);
        }
        if (in_array('budget', $written, true)) { @guarded_write(budget_file(), $beforeBudget); }
        fail($e->getMessage());
    }
    echo json_encode(['success' => true, 'data' => [
        'ai' => $aiIncoming !== null,
        'mapping' => $mapIncoming === null ? null : (int)($mapApplied ?? 0),
        'budget' => $budgetIncoming === null ? null : count($budgetIncoming['monthly']),
        'keyPreserved' => true,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'dashboard') {
    fail('未知 action');
}

$range = (string)($_GET['range'] ?? 'month');
if (!in_array($range, ['month', '3m', '6m', '1y', 'year'], true)) {
    $range = 'month';
}

// 自定义日期区间：from/to 同时给出时优先生效，range 视为 custom
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));
$isCustom = false;
if ($from !== '' || $to !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fail('自定义日期格式应为 YYYY-MM-DD');
    }
    $isCustom = true;
    $range    = 'custom';
}
$fresh = isset($_GET['fresh']);

// 登录态校验：未登录则要求前端跳转登录页
$token = (string)($_SESSION['ebk_token'] ?? '');
if ($token === '') {
    echo json_encode(['success' => false, 'requireLogin' => true, 'error' => '未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 缓存按登录 token 隔离，避免不同账号命中同一份缓存；自定义区间把 from/to 编入 key
/* v2：交易行新增按转账分类树计算的大类标签，旧 dashboard 快照需要自然失效。 */
$cacheKey = 'dash_v2_' . ($isCustom ? "custom_{$from}_{$to}" : $range) . '_' . substr(md5($token), 0, 8);

// ---- 缓存策略（stale-while-revalidate：秒开 + 后台静默刷新）----
//   新鲜期 cache_ttl（默认 15 分钟）→ 直接返回缓存；
//   陈旧期 cache_stale_ttl（默认 24 小时）且环境支持 fastcgi_finish_request（nginx+fpm）
//     → 先返回缓存快照（带 stale 标记）秒开，请求结束后台静默重拉；
//     不支持 fcf 的环境（php -S 本地演示）退化为同步重新生成；
//   超过陈旧期 / fresh=1 / 无缓存 → 同步重新生成。
$cacheTtl = (int)($config['cache_ttl'] ?? 900);
$staleTtl = (int)($config['cache_stale_ttl'] ?? 86400);
$rawCache = !$fresh ? cacheGetRaw($CACHE_DIR, $cacheKey) : null;
$cacheAge = $rawCache !== null ? time() - (int)($rawCache['_t'] ?? 0) : PHP_INT_MAX;

if ($rawCache !== null && $cacheAge <= $cacheTtl) {
    echo json_encode($rawCache['d'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($rawCache !== null && $cacheAge <= $staleTtl && function_exists('fastcgi_finish_request')) {
    $stalePayload = $rawCache['d'];
    $stalePayload['stale'] = true; // 前端据此显示「缓存快照」提示
    echo json_encode($stalePayload, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();      // 立即把响应交付浏览器，之后进入后台刷新
    ebk_build_dashboard(true);
    exit;
}

// 同步生成（缓存缺失/过期，或 fresh=1 强制刷新）
$payload = ebk_build_dashboard(false);
if ($payload !== null) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * 生成 dashboard 数据（完整拉取 + 聚合）。
 * 同步模式与 SWR 后台刷新共用：后台模式失败时静默返回 null（旧缓存保留），
 * 同步模式失败 fail() 输出错误并退出。
 * 函数体内聚合代码缩进保持与原先一致（历史原因，不影响执行）。
 * @return array|null payload（['success'=>true,'data'=>...]），失败已输出/静默时返回 null
 */
function ebk_build_dashboard(bool $background): ?array
{
    global $client, $token, $config, $CACHE_DIR, $cacheKey, $isCustom, $from, $to, $range;
    try {
    $tz = new DateTimeZone((string)$config['timezone']);
    $R  = $isCustom
        ? (buildCustomRanges($from, $to, $tz) ?? fail('无效的自定义日期范围（请检查起止顺序，跨度不超过 2 年）'))
        : buildRanges($range, $tz);

    // 带自动续期的并行拉取封装（共用 ebk_fetch_parallel）：任一请求 401 → 账密重登后整体重放
    $fetchParallel = 'ebk_fetch_parallel';

    // ---- 数据拉取（A/B 混合优化）----
    // 低频数据组：profile/账户/分类/汇率 变化极少 → 独立缓存 cache_meta_ttl（默认 1 小时），
    //   dashboard 缓存过期重拉时这四项直接命中本地缓存，不再打上游；
    // 实时数据组：跟随时段参数变化 → 每次重拉；
    // 两组的 miss 项合并为【一次 curl_multi 并行请求】，总耗时 = 最慢的一个请求（原为 9 个串行之和）。
    $metaTtl = (int)($config['cache_meta_ttl'] ?? 3600);
    $tkHash  = substr(md5($token), 0, 8);
    $metaDefs = [
        'profile'    => ['users/profile/get.json', []],
        'accounts'   => ['accounts/list.json', []],
        'categories' => ['transaction/categories/list.json', []],
        'fx'         => ['exchange_rates/latest.json', []],
    ];
    $metaData = [];
    $todo = [];
    foreach ($metaDefs as $k => $def) {
        $m = cacheGet($CACHE_DIR, "meta_{$k}_{$tkHash}", $metaTtl);
        if ($m !== null) {
            $metaData[$k] = $m;
        } else {
            $todo[$k] = $def;
        }
    }

    $liveDefs = [
        'trends'    => ['transactions/statistics/trends.json', [
            'start_year_month' => $R['trendFrom'],
            'end_year_month'   => $R['trendTo'],
        ]],
        'statsCur'  => ['transactions/statistics.json', [
            'start_time' => $R['start'], 'end_time' => $R['end'],
        ]],
        'statsPrev' => ['transactions/statistics.json', [
            'start_time' => $R['prevStart'], 'end_time' => $R['prevEnd'],
        ]],
        'assets'    => ['transactions/statistics/asset_trends.json', [
            'start_time' => $R['assetFrom'], 'end_time' => $R['assetTo'],
        ]],
        'daily'     => ['transactions/amounts/daily.json', [
            'start_time' => $R['start'], 'end_time' => $R['end'],
        ]],
        'recent'    => ['transactions/list.json', [
            'type' => 0, 'count' => max(1, min(50, (int)$config['recent_count'])), 'page' => 1,
            'trim_tag' => 1,
        ]],
    ];
    $got = $fetchParallel($todo + $liveDefs);
    foreach ($got as $k => $v) {
        if (isset($todo[$k])) {
            cacheSet($CACHE_DIR, "meta_{$k}_{$tkHash}", $v);
            $metaData[$k] = $v;
        }
    }
    $profile    = $metaData['profile'] ?? null;
    $accounts   = $metaData['accounts'] ?? null;
    $categories = $metaData['categories'] ?? null;
    $fx         = $metaData['fx'] ?? null;
    $trends     = $got['trends'] ?? null;
    $statsCur   = $got['statsCur'] ?? null;
    $statsPrev  = $got['statsPrev'] ?? null;
    $assets     = $got['assets'] ?? null;
    $daily      = $got['daily'] ?? null;
    $recent     = $got['recent'] ?? null;

    // ---- 金额换算：API 金额固定两位小数；多币种按最新汇率换算为默认币种 ----
    $div = AMOUNT_FACTOR;
    $defaultCurrency = (string)($profile['defaultCurrency'] ?? 'CNY');
    $rateMap = [];
    $baseCurrency = (string)($fx['baseCurrency'] ?? $defaultCurrency);
    foreach ((array)($fx['exchangeRates'] ?? []) as $r) {
        if (is_array($r) && isset($r['currency'], $r['rate'])) {
            $rateMap[(string)$r['currency']] = (float)$r['rate'];
        }
    }

    // ---- 分类映射（含子分类）----
    // 兼容「按类型分组的对象」与「扁平数组」两种返回格式（共用 flattenCategories）
    $catMap = flattenCategories(is_array($categories) ? $categories : []);

    // ---- 账户映射与净资产（余额按各账户币种换算）----
    $accountsOut  = [];
    $accountMap   = [];
    $netWorth     = 0.0;
    foreach ((array)$accounts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $aid  = (int)($a['id'] ?? 0);
        $currency = (string)($a['currency'] ?? $defaultCurrency);
        $bal = amountOf($a['balance'] ?? 0, $div);
        $value = amountOf(convertToDefault((int)($a['balance'] ?? 0), $currency, $rateMap, $baseCurrency, $defaultCurrency), AMOUNT_FACTOR);
        $accountMap[$aid] = $a;

        if (!empty($a['hidden'])) {
            continue; // 隐藏账户不进排行/快照（accountMap 已保留，走势兜底等逻辑仍可用）
        }
        $netWorth += $value;
        $accountsOut[] = [
            'id'       => $aid,
            'name'     => (string)($a['name'] ?? ''),
            'color'    => (string)($a['color'] ?? ''),
            'currency' => $currency,
            'balance'  => $bal,          // 原币种金额
            'value'    => $value,        // 换算为默认币种后的金额
            'isAsset'  => !empty($a['isAsset']),
            'isLiability' => !empty($a['isLiability']),
            'hidden'   => false,
            'parentId' => (int)($a['parentId'] ?? 0),
            'type'     => (int)($a['type'] ?? 0),
            'category' => (int)($a['category'] ?? 0),   // 账户类别：1现金 2借记 3信用卡 4虚拟 5负债 6应收 7投资 8储蓄
        ];
    }
    $accountsAll = $accountsOut;   // 全量账户快照（资产组成桑基图需要完整结构，不受 TOP10 截断）
    // TOP10 排序：区分正负——资产在前（余额降序），负债在后（欠得多的在前），
    // 避免 abs 混排时「-1500 排在 1000 前面」的反直觉顺序
    usort($accountsOut, function ($x, $y) {
        $xl = !empty($x['isLiability']) || $x['value'] < 0;
        $yl = !empty($y['isLiability']) || $y['value'] < 0;
        if ($xl !== $yl) {
            return $xl ? 1 : -1;
        }
        return abs($y['value']) <=> abs($x['value']);
    });
    $accountsOut = array_slice($accountsOut, 0, 10);

    // ---- 当前/上期收支汇总 ----
    $ctx = [$catMap, $accountMap, $rateMap, $baseCurrency, $defaultCurrency];
    $curTotals = $curByCat = [];
    aggregateStatistics((array)($statsCur['items'] ?? []), $ctx, $curTotals, $curByCat);
    $prevTotals = $prevByCat = [];
    aggregateStatistics((array)($statsPrev['items'] ?? []), $ctx, $prevTotals, $prevByCat);

    // 分类ID → 顶级大类ID（沿 parentId 向上走；防御性最多 8 层防环）
    $topCat = function (int $cid) use ($catMap): int {
        $cur = $cid;
        for ($i = 0; $i < 8; $i++) {
            $pid = (int)($catMap[$cur]['parentId'] ?? 0);
            if ($pid <= 0 || !isset($catMap[$pid]) || $pid === $cur) {
                return $cur;
            }
            $cur = $pid;
        }
        return $cur;
    };

    // 分类占比输出：按顶级大类聚合（默认视图），每个大类带 subs 小类明细（点击下钻用）
    $buildCatList = function (array $map, float $total) use ($catMap, $topCat): array {
        $tops = [];
        $subs = [];
        foreach ($map as $cid => $amt) {
            $tid = $topCat((int)$cid);
            $tops[$tid] = ($tops[$tid] ?? 0) + $amt;
            $subs[$tid][] = [
                /* ⚠️ id 必须是字符串！上游雪花 id 是 19 位 int64（json:"id,string"），
                   若这里输出 JSON 数字，JS JSON.parse 会按 float64 取整（安全整数上限 2^53≈9e15），
                   尾部 2~3 位直接被改写 → 前端拿腐蚀后的 id 去 tx_search 查询 → 永远空结果。
                   单独创建的分类（雪花低 22 位为 0）恰是 float64 精确可表示值、侥幸存活，
                   批量导入的小类（sequence 位非 0）必死 —— 这就是「大类能查、小类必空」的根因。 */
                'id'     => (string)$cid,
                'name'   => (string)($catMap[$cid]['name'] ?? '未知分类'),
                'color'  => '#' . ltrim((string)($catMap[$cid]['color'] ?? ''), '#'),
                'amount' => round($amt, 2),
            ];
        }
        $out = [];
        foreach ($tops as $tid => $amt) {
            usort($subs[$tid], fn($x, $y) => $y['amount'] <=> $x['amount']);
            /* ⚠️ 大类是一等分类：账可以记在大类自身，此时 $subs[$tid] 里会有一条「自己」。
               若该大类没有任何真正的子类，subs 就只剩这条自身记录 —— 前端若据此判定
               「可下钻小类」，点了只会看到一个等于自己的环，陷入死循环、永远到不了流水。
               这里显式给出 hasChildren（排除自身后还有没有别的分类），前端用它决定
               是「下钻小类」还是「直接看流水」。 */
            /* 同上：subs 的 id 已改为字符串，这里必须同型比较，否则 int!==string 恒真 → hasChildren 恒 true */
            $realSubs = array_filter($subs[$tid], fn($s) => (string)$s['id'] !== (string)$tid);
            $out[] = [
                'id'     => (string)$tid,
                'name'   => (string)($catMap[$tid]['name'] ?? '未知分类'),
                'color'  => '#' . ltrim((string)($catMap[$tid]['color'] ?? ''), '#'),
                'amount' => round($amt, 2),
                'pct'    => $total > 0 ? round($amt / $total * 100, 1) : 0,
                'hasChildren' => count($realSubs) > 0,
                'subs'   => array_map(function ($s) use ($total) {
                    $s['pct'] = $total > 0 ? round($s['amount'] / $total * 100, 1) : 0;
                    return $s;
                }, $subs[$tid]),
            ];
        }
        usort($out, fn($x, $y) => $y['amount'] <=> $x['amount']);
        return $out;
    };

    // ---- 月度收支趋势（近 12 个月）----
    $trendMap = [];
    foreach ((array)$trends as $t) {
        if (!is_array($t)) {
            continue;
        }
        $ym = $t['year'] . '-' . str_pad((string)$t['month'], 2, '0', STR_PAD_LEFT);
        $inc = 0.0;
        $exp = 0.0;
        foreach ((array)($t['items'] ?? []) as $it) {
            $cid = (int)($it['categoryId'] ?? 0);
            if ($cid <= 0 || !isset($catMap[$cid])) {
                continue;
            }
            $type = (int)$catMap[$cid]['type'];
            // 真实 API 趋势 items 金额字段名为 "amount"（官方模型 json:"amount"）
            $raw = (int)($it['amount'] ?? $it['totalAmount'] ?? 0);
            $accCurrency = (string)(($accountMap[(int)($it['accountId'] ?? 0)])['currency'] ?? $defaultCurrency);
            $raw = convertToDefault($raw, $accCurrency, $rateMap, $baseCurrency, $defaultCurrency);
            $amt = amountOf($raw, $div);
            if ($type === 1) {
                $inc += $amt;
            } elseif ($type === 2) {
                $exp += $amt;
            }
        }
        $trendMap[$ym] = ['income' => round($inc, 2), 'expense' => round($exp, 2)];
    }
    $trendSeries = [];
    foreach ($R['months'] as $ym) {
        $trendSeries[] = [
            'month'   => $ym,
            'income'  => $trendMap[$ym]['income'] ?? 0.0,
            'expense' => $trendMap[$ym]['expense'] ?? 0.0,
        ];
    }

    // ---- 净资产走势（日粒度）----
    // 真实 API 的 asset_trends 是「交易驱动的余额重构」：
    //   ① 只在「有交易/余额变动的日子」返回数据行，无交易的天整体缺失；
    //   ② 每天的 items 只包含【当天有变动】的账户——未变动账户当天不在 items 里！
    // 因此不能直接对当天 items 求和（会把没变动的账户余额丢掉）。
    // 正确算法：每账户单独跟踪最近一次已知余额（lastKnown），每天对所有
    // 非隐藏账户的 lastKnown 求和；从未在走势中出现过的账户视为「统计窗口内
    // 无交易」，用其当前余额（accountMap 里的实时 balance）全程兜底——
    // 这样曲线最后一天的合计必然与 KPI 净资产口径一致。
    $lastKnown = [];  // accountId => 原币种整数余额（×AMOUNT_FACTOR，最近一次已知 closing）
    $neverSeen = [];  // 从未在走势里出现的非隐藏账户 => 当前原币种余额（窗口内无变动）
    foreach ($accountMap as $aid => $a) {
        if (!is_array($a) || !empty($a['hidden'])) {
            continue;
        }
        $neverSeen[$aid] = (int)($a['balance'] ?? 0);
    }

    // 按日期分组（同一天可能有多行，防御性合并处理）
    $rowsByDate = [];
    foreach ((array)$assets as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = sprintf('%04d-%02d-%02d', (int)($row['year'] ?? 0), (int)($row['month'] ?? 0), (int)($row['day'] ?? 0));
        if ($date === '0000-00-00') {
            continue;
        }
        foreach ((array)($row['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rowsByDate[$date][] = $item;
        }
    }
    ksort($rowsByDate);

    $sumKnown = function () use (&$lastKnown, &$neverSeen, $accountMap, $rateMap, $baseCurrency, $defaultCurrency, $div): float {
        // 注意 &$lastKnown/&$neverSeen 必须按引用捕获：PHP 闭包 use 默认按值，
        // 按值捕获会在定义时复制空数组，导致每天求和恒等于当前余额（曲线全平）。
        // 汇总所有非隐藏账户的最近已知余额（各账户按币种换算为默认币种）
        $net = 0.0;
        foreach ($lastKnown + $neverSeen as $aid => $raw) {
            $accItem  = $accountMap[$aid] ?? null;
            $currency = (string)($accItem['currency'] ?? $defaultCurrency);
            $net += amountOf(convertToDefault($raw, $currency, $rateMap, $baseCurrency, $defaultCurrency), $div);
        }
        return round($net, 2);
    };

    $netByDate = [];
    foreach ($rowsByDate as $date => $items) {
        // 先更新当天出现账户的 lastKnown，再对全部账户求和
        foreach ($items as $item) {
            $aid = (int)($item['accountId'] ?? 0);
            $accItem = $accountMap[$aid] ?? null;
            if ($accItem === null || !empty($accItem['hidden'])) {
                continue; // 隐藏账户/未知账户不计入净资产走势（与净资产口径一致）
            }
            $lastKnown[$aid] = (int)($item['accountClosingBalance'] ?? 0);
            unset($neverSeen[$aid]);
        }
        $netByDate[$date] = $sumKnown();
    }

    $assetSeries = [];
    $assetDates  = array_keys($netByDate);
    sort($assetDates);
    if (count($assetDates) > 0) {
        $cursor = new DateTime($assetDates[0], $tz); // 从最早已知日开始，不伪造账户存在前的 0
        $today  = (new DateTime('now', $tz))->setTime(0, 0, 0);
        $di     = 0;
        $n      = count($assetDates);
        $carry  = $netByDate[$assetDates[0]];
        while ($di < $n && $cursor->format('Y-m-d') === $assetDates[$di]) {
            $carry = $netByDate[$assetDates[$di]];
            $assetSeries[] = ['date' => $assetDates[$di], 'net' => $carry];
            $cursor->modify('+1 day');
            $di++;
        }
        while ($cursor <= $today) { // 缺失日沿用最近余额，补到「今天」
            $key = $cursor->format('Y-m-d');
            if ($di < $n && $key === $assetDates[$di]) {
                $carry = $netByDate[$assetDates[$di]];
                $di++;
            }
            $assetSeries[] = ['date' => $key, 'net' => $carry];
            $cursor->modify('+1 day');
        }
    } elseif ($netWorth != 0.0) {
        $assetSeries[] = [
            'date' => (new DateTime('now', $tz))->format('Y-m-d'),
            'net'  => round($netWorth, 2),
        ];
    }

    // ---- 每日收支（按币种×汇率换算为默认币种后求和，共用 mapDailyRows）----
    $dailySeries = mapDailyRows(is_array($daily) ? $daily : [], $rateMap, $baseCurrency, $defaultCurrency, $div);

    // ---- 最近交易（共用 mapTransactionRows）----
    $recentOut = mapTransactionRows(is_array($recent['items'] ?? null) ? $recent['items'] : [], $catMap, $div, $defaultCurrency);

    // ---- 环比 ----
    $growth = fn(float $cur, float $prev): ?float =>
        $prev != 0.0 ? round(($cur - $prev) / abs($prev) * 100, 1) : null;

    // ---- 储蓄率阈值兜底 ----
    // 储蓄率 = (收入-支出)/收入，收入基数过小时指标失真（如月收入仅 ¥1.58 会得出 -18120%）。
    // 收入 < ¥100 或不足支出 5% 视为「无有效收入基数」，返回 null（前端显示 —，悬停有说明）。
    $income  = $curTotals['income'];
    $expense = $curTotals['expense'];
    $savingRate = ($income >= 100 && $income >= $expense * 0.05)
        ? round(($income - $expense) / $income * 100, 1)
        : null;

    // ---- 财务健康度（近 12 个月滚动口径）----
    // 全部由现有数据推导，无新增上游请求。口径注意：
    //   · 账本未录入房产等固定资产市值 → 资产负债率偏保守（高估），悬停说明写明；
    //   · 净资产年涨幅含工资结余等新增投入，非纯投资回报。
    $gross = 0.0; $liab = 0.0; $liquid = 0.0;
    foreach ((array)$accountsAll as $acc) {
        if (!empty($acc['hidden']) || !isset($acc['value'])) {
            continue; // 隐藏账户不计入健康度（与净资产口径一致）
        }
        if (!empty($acc['isLiability'])) {
            $liab += abs((float)$acc['value']);
        } elseif (!empty($acc['isAsset'])) {
            $gross += max(0.0, (float)$acc['value']);
            // 流动资产：现金(1)/借记(2)/虚拟(4)/储蓄(8) 三类账户，不含应收(6)/投资(7)
            if (in_array((int)$acc['category'], [1, 2, 4, 8], true)) {
                $liquid += max(0.0, (float)$acc['value']);
            }
        }
    }
    $monthsCnt  = max(1, count($trendSeries));
    $avgExpense = array_sum(array_column($trendSeries, 'expense')) / $monthsCnt;

    $health = [];
    // ① 资产负债率（<50% 稳健 / 50~60% 观察 / >60% 偏高）
    if ($liab > 0.01) {
        $dr = round($liab / ($gross + $liab) * 100, 1);
        $health[] = ['key' => 'debtRatio', 'label' => '资产负债率', 'value' => $dr, 'unit' => '%',
            'level' => $dr < 50 ? 'good' : ($dr <= 60 ? 'watch' : 'warn'),
            'tag'   => $dr < 50 ? '稳健' : ($dr <= 60 ? '观察' : '偏高'),
            'hint'  => '总负债 ÷ 总资产。账本未录入房产等固定资产市值，本比率偏保守（高估），<50% 为稳健线。'];
    } else {
        $health[] = ['key' => 'debtRatio', 'label' => '资产负债率', 'value' => null, 'unit' => '%',
            'level' => 'good', 'tag' => '无负债', 'hint' => '当前账本无负债账户，处于无杠杆状态。'];
    }
    // ② 应急金覆盖月数（流动资产 ÷ 月均支出，≥3 达标 / 1~3 不足 / <1 预警）
    if ($avgExpense > 0.01) {
        $em = round($liquid / $avgExpense, 1);
        $health[] = ['key' => 'emergency', 'label' => '应急金覆盖', 'value' => $em, 'unit' => ' 个月',
            'level' => $em >= 3 ? 'good' : ($em >= 1 ? 'watch' : 'warn'),
            'tag'   => $em >= 3 ? '达标' : ($em >= 1 ? '不足' : '预警'),
            'hint'  => '流动资产（现金+借记+储蓄+支付钱包）÷ 月均支出。理财规划建议覆盖 3~6 个月生活费。'];
    } else {
        $health[] = ['key' => 'emergency', 'label' => '应急金覆盖', 'value' => null, 'unit' => ' 个月',
            'level' => 'none', 'tag' => '暂无支出', 'hint' => '近 12 个月无支出记录，无法估算应急金覆盖。'];
    }
    // ③ 净资产覆盖支出（FIRE 月数，≥300 达成 4% 法则 / 36~300 积累中 / <36 起步）
    if ($avgExpense > 0.01) {
        $fm = round($netWorth / $avgExpense, 0);
        $health[] = ['key' => 'fire', 'label' => '净资产覆盖支出', 'value' => $fm, 'unit' => ' 个月',
            'level' => $fm >= 300 ? 'good' : ($fm >= 36 ? 'watch' : 'warn'),
            'tag'   => $fm >= 300 ? '接近自由' : ($fm >= 36 ? '积累中' : '起步期'),
            'hint'  => '当前净资产 ÷ 月均支出。≥300 个月（25 年）约等于 4% 法则下的财务自由线。'];
    } else {
        $health[] = ['key' => 'fire', 'label' => '净资产覆盖支出', 'value' => null, 'unit' => ' 个月',
            'level' => 'none', 'tag' => '暂无支出', 'hint' => '近 12 个月无支出记录，无法估算覆盖月数。'];
    }
    // ④ 净资产年涨幅（≥8% 强劲 / 0~8% 正增长 / <0 缩水）
    $nwFirst = $assetSeries[0]['net'] ?? null;
    $nwLast  = $assetSeries !== [] ? end($assetSeries)['net'] : null;
    if ($nwFirst !== null && $nwLast !== null && abs($nwFirst) > 0.01) {
        $yoy = round(($nwLast - $nwFirst) / abs($nwFirst) * 100, 1);
        $health[] = ['key' => 'nwYoy', 'label' => '净资产年涨幅', 'value' => $yoy, 'unit' => '%',
            'level' => $yoy >= 8 ? 'good' : ($yoy >= 0 ? 'watch' : 'warn'),
            'tag'   => $yoy >= 8 ? '强劲' : ($yoy >= 0 ? '正增长' : '缩水'),
            'hint'  => '近 12 个月净资产首尾对比。含工资结余等新增储蓄投入，非纯投资回报率。'];
    } else {
        $health[] = ['key' => 'nwYoy', 'label' => '净资产年涨幅', 'value' => null, 'unit' => '%',
            'level' => 'none', 'tag' => '数据不足', 'hint' => '资产走势数据不足，无法计算年涨幅。'];
    }

    $payload = [
        'success' => true,
        'data'    => [
            'generatedAt' => time(),
            'range'       => $range,
            // 自定义区间时携带具体日期，前端提示文案直接使用
            'dateFrom'    => $isCustom ? $from : null,
            'dateTo'      => $isCustom ? $to : null,
            'profile'     => [
                'nickname'        => (string)($profile['nickname'] ?? $profile['username'] ?? ''),
                'defaultCurrency' => $defaultCurrency,
            ],
            'fx' => [
                'baseCurrency' => $baseCurrency,
                'rates'        => $rateMap,
                'updateTime'   => (int)($fx['updateTime'] ?? 0),
            ],
            'kpi' => [
                'expense'      => round($curTotals['expense'], 2),
                'income'       => round($curTotals['income'], 2),
                'balance'      => round($curTotals['income'] - $curTotals['expense'], 2),
                'savingRate'   => $savingRate,
                'netWorth'     => round($netWorth, 2),
                'expenseGrowth' => $growth($curTotals['expense'], $prevTotals['expense']),
                'incomeGrowth'  => $growth($curTotals['income'], $prevTotals['income']),
            ],
            'health' => $health,
            'trends' => $trendSeries,
            'assets' => $assetSeries,
            'daily'  => $dailySeries,
            'categoryRank' => [
                'expense' => $buildCatList($curByCat['expense'], $curTotals['expense']),
                'income'  => $buildCatList($curByCat['income'], $curTotals['income']),
            ],
            /* 上期分类汇总：统计页「分类环比对比」用（按金额降序 TOP12，含名称与颜色） */
            'catPrev' => (function () use ($prevByCat, $catMap): array {
                $build = function (array $byCat) use ($catMap): array {
                    $rows = [];
                    foreach ($byCat as $cid => $amt) {
                        $c = $catMap[(int)$cid] ?? null;
                        $rows[] = ['id' => (string)$cid, 'name' => (string)($c['name'] ?? '未分类'),
                            'color' => '#' . ltrim((string)($c['color'] ?? ''), '#'), 'amount' => round((float)$amt, 2)];
                    }
                    usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
                    return array_slice($rows, 0, 12);
                };
                return ['expense' => $build($prevByCat['expense']), 'income' => $build($prevByCat['income'])];
            })(),
            'accounts' => $accountsOut,
            'accounts_all' => $accountsAll,   // 全量账户（桑基图用）
            'recent'   => $recentOut,
        ],
    ];

    cacheSet($CACHE_DIR, $cacheKey, $payload);
    return $payload;
    } catch (Throwable $e) {
        // 后台刷新失败：静默放弃本次更新，旧缓存继续服务（下次请求再尝试）
        if ($background) {
            error_log('[ebk-dashboard] 后台刷新失败: ' . $e->getMessage());
            return null;
        }
        fail($e->getMessage());
    }
}


