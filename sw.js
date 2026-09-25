/* EZBookKeeping 记账统计看板 · Service Worker
 * 缓存策略：
 *   - 安装时预缓存核心静态资源（图表库/字体/图标）
 *   - api.php：始终走网络，不在浏览器持久化任何私有账务数据
 *   - 页面导航：network-first，避免升级后长期运行旧 HTML/内联脚本
 *   - 其余同源静态资源：stale-while-revalidate
 *   - 非 GET / 跨域请求：直接放行不拦截
 * 版本升级：改代码后把 CACHE 版本号 +1 即可全量刷新客户端缓存 */
const CACHE = 'ebk-pwa-v3';
const PRECACHE = [
  'echarts.min.js', 'fonts/inter-latin.woff2',
  'favicon.svg', 'manifest.json',
  'icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png', 'icons/apple-touch-icon-180.png'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(ks => Promise.all(ks.filter(k => k.startsWith('ebk-pwa-') && k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;                    // 登录等 POST 不拦截
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;          // 跨域不拦截

  /* 私有 API 不进入 Cache Storage。离线时明确失败，避免登出/换账号后残留账务数据。 */
  if (url.pathname.endsWith('api.php')) {
    e.respondWith(fetch(req));
    return;
  }

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => new Response(
      '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>ezBookDash</title><p style="font:16px system-ui;padding:32px">当前处于离线状态，连接网络后再试。</p>',
      {status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}}
    )));
    return;
  }

  /* 静态资源与页面壳：stale-while-revalidate——缓存优先秒开，后台静默更新 */
  e.respondWith(
    caches.match(req).then(hit => {
      const net = fetch(req).then(res => {
        if (res && res.status === 200) {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(req, copy));
        }
        return res;
      }).catch(() => hit);
      return hit || net;
    })
  );
});
