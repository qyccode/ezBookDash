<?php
/**
 * EZBookKeeping 记账统计看板 - 配置文件
 *
 * 部署后只需修改：base_url（服务器地址）。
 * 认证改为「登录页」方式：访问看板需先登录（账密 / API 令牌二选一），
 * 登录态保存在服务端会话（Session），凭据与 token 均不暴露给浏览器。
 */

return [
    // EZBookKeeping 服务器地址（NAS 内网地址或域名），末尾不要带 /
    // 示例：'http://192.168.1.100:8080' 或 'https://ezbk.example.com'
    'base_url'     => '',

    // ============ 可选：服务端默认凭据（建议留空）============
    // 看板自带登录页，访问需先登录。以下三项为「可选默认凭据」：
    //   - 填写后，登录页会出现「使用默认凭据登录」按钮，可一键登录（适合可信内网，免手动输入）
    //   - 留空时，必须在登录页手动输入账密或 API 令牌（公网环境强烈建议留空，任何人无法免登录查看）
    'api_token'    => '',
    'username'     => '',
    'password'     => '',
    // ========================================================

    // 时区（IANA 名称），需与 EZBookKeeping 中记录时间一致，一般用上海时区
    'timezone'     => 'Asia/Shanghai',

    // HTTPS 证书校验。生产环境必须保持 true；只有明确使用自签证书的可信内网才关闭。
    'ssl_verify'   => true,

    // 私有运行数据目录（缓存、Session、预算、AI Key）。留空时使用项目目录下的 runtime/，
    // 该目录由 .htaccess 禁止 Web 访问。Docker / NAS 推荐用 EBK_STORAGE_DIR 指向持久化目录。
    'storage_dir'  => '',

    // 仅当应用位于可信反向代理后、且代理会覆盖 X-Forwarded-Proto 时开启。
    'trust_proxy'  => false,

    // 登录会话有效期（秒），默认 7 天，到期后需重新登录
    'session_lifetime' => 604800,

    // 是否在加密磁盘上的服务端 Session 中暂存登录密码，以便 token 失效时自动续期。
    // 默认关闭：更安全；关闭后 token 失效会要求重新登录。长期使用建议直接用 API 令牌登录。
    'renew_with_password' => false,

    // ---- 缓存策略（stale-while-revalidate 三级）----
    // cache_ttl：新鲜期（秒）。期内直接返回缓存，默认 15 分钟。
    'cache_ttl'        => 900,
    // cache_stale_ttl：陈旧期（秒）。过期但未超过此值时，先返回缓存快照秒开，
    //   服务器（nginx+fpm）后台静默重拉，下次刷新即是新数据。默认 24 小时。
    //   超过陈旧期才同步等待重新拉取。
    'cache_stale_ttl'  => 86400,
    // cache_meta_ttl：低频数据（账户列表/分类/汇率/昵称）独立缓存时长（秒），默认 1 小时。
    //   这些数据变化极少，dashboard 缓存过期重拉时无需再打上游。
    'cache_meta_ttl'   => 3600,

    // 最近交易列表条数（1-50）
    'recent_count' => 15,
];
