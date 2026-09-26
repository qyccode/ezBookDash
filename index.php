<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* 服务端判断登录态：已登录则首屏直接渲染看板（登录框隐藏），避免刷新时登录框闪烁 */
$__runtime   = require __DIR__ . '/bootstrap.php';
$__needLogin = empty($_SESSION['ebk_token']);
$__csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($__csrf === '') {
    $__csrf = bin2hex(random_bytes(24));
    $_SESSION['csrf_token'] = $__csrf;
}
session_write_close();
$__nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$__nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
$__loginAttr = $__needLogin ? '' : ' hidden';   // 登录框：未登录显示
$__dashAttr  = $__needLogin ? ' hidden' : '';   // 看板/顶栏：已登录显示
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>ezBookDash · ezBookkeeping 记账看板</title>
<!-- PWA：图标 / 清单 / 主题色（favicon.svg 为矢量主图标，PNG 全套供旧浏览器与安装使用） -->
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="icon" href="icons/icon-192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="icons/apple-touch-icon-180.png">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#3f66f8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ezBookDash">
<!-- ECharts：本地同域加载（与看板同目录 echarts.min.js，5.6.0）。不再依赖外网 CDN：
     内网/NAS 环境加载提速 0.5~2s，且不受 CDN 可用性/广告拦截规则影响。同步加载保证后续内联脚本执行时 echarts 已就绪 -->
<script src="echarts.min.js"></script>
<style>
/* 兜底：确保 hidden 属性始终生效（不被 .login-view/.seg/.iconbtn 等 display 声明覆盖） */
[hidden]{display:none !important}
/* 资产组成 · 汇总条（桑基图卡片顶部） */
.skpi{display:flex;gap:10px;flex-wrap:wrap;margin:2px 0 10px}
.skpi .it{flex:1 1 150px;background:var(--bg-soft);border-radius:12px;padding:10px 14px;min-width:0}
.skpi .it b{display:block;font-size:12px;font-weight:500;color:var(--text-sub);margin-bottom:2px}
.skpi .it .v{font-size:19px;font-weight:700;letter-spacing:.3px;font-variant-numeric:tabular-nums}
/* 净资产隐藏联动：汇总条金额模糊遮罩（与 KPI 马赛克同一状态源 state.netHidden） */
.skpi .it .v{transition:filter .45s ease,opacity .45s ease}
.skpi.net-masked .it .v{filter:blur(9px);opacity:.6;user-select:none}
:root{
  /* 设计令牌 · 品牌色（操作/选中/结余） */
  --accent:#3f66f8; --accent-soft:rgba(63,102,248,.1);
  /* 设计令牌 · 语义色（支出红涨 / 收入绿 / 净资产紫 / 警示橙） */
  --rose:#ef4d6e; --green:#18a768; --purple:#7a5af5; --orange:#f2803a;
  /* 设计令牌 · 中性阶（页面底 / 卡片 / 描边） */
  --bg:#f5f7fc; --bg-soft:#eaeffa; --card:#ffffff; --card-border:rgba(15,35,90,.08);
  /* 设计令牌 · 文字三阶（主 / 次 / 弱，对比度 13.8:1 / 5.1:1 / 3.2:1） */
  --text:#18213d; --text-sub:#5d6a8e; --text-dim:#9aa5c4;
  /* 设计令牌 · 图表专用 */
  --grid:rgba(24,33,61,.07); --tip-bg:#ffffff;
  --shadow:0 1px 2px rgba(20,35,80,.04),0 8px 24px rgba(20,35,80,.06);
  --hover-lift:0 2px 4px rgba(20,35,80,.06),0 14px 32px rgba(20,35,80,.1);
}
html[data-theme="dark"]{
  /* 暗色镜像：同结构反转，语义色提亮一档 */
  --accent:#5d82ff; --accent-soft:rgba(93,130,255,.14);
  --rose:#ff6b8a; --green:#2fca85; --purple:#9d7bff; --orange:#ff9a5c;
  --bg:#0a0f1f; --bg-soft:#0d1428; --card:#111a33; --card-border:rgba(125,150,220,.12);
  --text:#e9edfb; --text-sub:#93a0c5; --text-dim:#5a6788;
  --grid:rgba(150,175,240,.1); --tip-bg:#182242;
  --shadow:0 1px 2px rgba(0,0,0,.25),0 10px 28px rgba(0,0,0,.35);
  --hover-lift:0 2px 6px rgba(0,0,0,.3),0 16px 36px rgba(0,0,0,.42);
}
*{margin:0;padding:0;box-sizing:border-box}
/* 移动端防横向滚动：hidden 之上加 clip（部分移动浏览器平移手势可绕过 hidden），
   任何子元素超宽都不会撑破页面、也无法左右拖动 */
html,body{max-width:100%;overflow-x:hidden;overflow-x:clip}
img,svg,video{max-width:100%}
table{max-width:100%}
/* ---------- 字体：Inter（西文/数字）+ 系统中文栈 ----------
   Inter latin 可变字重子集（wght 100-900）自托管，仅 48KB，含数字与 ¥(U+00A5)；
   中文字符 Inter 无字形自动回退 PingFang/HarmonyOS/雅黑，零额外下载 */
@font-face{
  font-family:"Inter";
  font-style:normal;
  font-weight:100 900;
  font-display:swap;
  src:url("fonts/inter-latin.woff2") format("woff2");
}
body{
  font-family:"Inter",-apple-system,"PingFang SC","HarmonyOS Sans SC","Microsoft YaHei",system-ui,sans-serif;
  background:var(--bg);color:var(--text);min-height:100vh;
  background-image:radial-gradient(1200px 500px at 80% -10%,var(--accent-soft),transparent 60%);
  transition:background .3s,color .3s;
}
.wrap{max-width:1280px;margin:0 auto;padding:28px 24px 48px}
/* ---------- 顶栏 ---------- */
.topbar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.brand .logo{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;flex:none}
.brand .logo svg{width:20px;height:20px;stroke:#fff}
.brand h1{font-size:19px;font-weight:600;letter-spacing:.5px}
.brand .sub{font-size:12px;color:var(--text-sub);margin-top:2px}
.seg{display:flex;background:var(--bg-soft);border:1px solid var(--card-border);border-radius:11px;padding:3px;gap:2px}
.seg button{border:0;background:transparent;color:var(--text-sub);font-size:13px;padding:7px 14px;border-radius:8px;cursor:pointer;transition:.18s;font-family:inherit}
.seg button:hover{color:var(--text)}
.seg button.on{background:var(--card);color:var(--accent);box-shadow:var(--shadow);font-weight:600}
.iconbtn{width:36px;height:36px;border-radius:10px;border:1px solid var(--card-border);background:var(--card);color:var(--text-sub);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.18s}
.iconbtn:hover{color:var(--accent);border-color:var(--accent)}
.iconbtn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.iconbtn.spin svg{animation:spin .8s linear infinite}
/* 顶栏「搜索流水」入口：位于流水页时点亮（流水不属于顶部页签，用它指示当前所在页） */
#txsEntryBtn.on{color:var(--accent);border-color:var(--accent);background:var(--accent-soft)}
/* ---------- 页面路由 ---------- */
.pagetabs{display:flex;gap:2px;background:var(--bg-soft);border:1px solid var(--card-border);border-radius:11px;padding:3px}
.pagetabs button{border:0;background:transparent;color:var(--text-sub);font-size:13px;padding:7px 16px;border-radius:8px;cursor:pointer;font-family:inherit;font-weight:500}
.pagetabs button.on{background:var(--card);color:var(--accent);font-weight:600;box-shadow:var(--shadow)}
.page{display:none}
.page.on{display:block;animation:fadein .25s ease}
@keyframes fadein{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
/* 底部 TabBar（移动端）· 柔光玻璃：低不透明度 + 强模糊 + 内发光，玻璃上叠玻璃滑块 */
.tabbar{position:fixed;left:14px;right:14px;bottom:calc(10px + env(safe-area-inset-bottom));z-index:70;display:flex;height:62px;padding:6px;border:1px solid rgba(255,255,255,.32);border-radius:999px;background:rgba(255,255,255,.22);backdrop-filter:blur(20px) saturate(160%);-webkit-backdrop-filter:blur(20px) saturate(160%);box-shadow:0 18px 44px rgba(20,35,80,.14), inset 0 1px 0 rgba(255,255,255,.45), inset 0 0 18px rgba(255,255,255,.16)}
.tab-slider{position:absolute;top:6px;bottom:6px;left:6px;width:calc((100% - 12px)/4);border-radius:999px;background:rgba(255,255,255,.36);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px) saturate(160%);box-shadow:0 4px 16px rgba(63,102,248,.16), inset 0 1px 0 rgba(255,255,255,.6);transition:transform .45s cubic-bezier(.34,1.56,.64,1),opacity .25s ease;transform:translateX(0);opacity:1;pointer-events:none}
.tab-slider[data-i="1"]{transform:translateX(100%)}
.tab-slider[data-i="2"]{transform:translateX(200%)}
.tab-slider[data-i="3"]{transform:translateX(300%)}
.tabbar button{position:relative;z-index:1;flex:1;border:0;background:transparent;color:var(--text-dim);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:0;border-radius:999px;cursor:pointer;font-family:inherit;transition:color .3s}
.tabbar button svg{width:21px;height:21px}
.tabbar button span{font-size:10.5px;font-weight:600;letter-spacing:.5px}
.tabbar button.on{color:var(--accent)}
@media (min-width:721px){.tabbar{display:none}}   /* 桌面用顶部页签，底部 TabBar 仅移动端 */
html[data-theme="dark"] .tabbar{background:rgba(17,26,51,.24);border-color:rgba(125,150,220,.12);box-shadow:0 18px 44px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.05), inset 0 0 18px rgba(120,150,255,.05)}
html[data-theme="dark"] .tab-slider{background:rgba(120,150,255,.12);box-shadow:0 4px 16px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.08)}
html[data-theme="dark"] .tabbar button.on{color:#9db8ff}
/* ---------- 流水页（搜索 + 批量整理） ---------- */
/* 筛选卡与结果卡是 #pageTxs 下的兄弟节点，而 .card 自身没有外边距
   （其它页面的卡片间隔靠 .grid/.home-grid 的 gap 提供），这里必须显式给间隔，
   否则两张卡片上下贴死、看起来像连成一块 */
.txs-filter{margin-bottom:14px}
.txs-qrow{display:flex;gap:8px}
.txs-qrow input{flex:1;min-width:0;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:14px;padding:9px 11px;border-radius:9px;outline:none;transition:.18s;font-family:inherit}
.txs-qrow input:focus{border-color:var(--accent);background:var(--card)}
.txs-qrow .minibtn{flex:none;height:auto;padding:0 14px;border-radius:9px}
.txs-quick{display:flex;align-items:center;gap:8px;margin-top:10px}
.txs-quick .miniseg{margin-right:auto}
/* 导出入口：未查询时置灰（disabled 由 JS 控制），有结果显示笔数 */
.txs-expbtn{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.txs-expbtn:disabled{opacity:.4;cursor:not-allowed}
/* ============ 方向箭头图标（全站统一）============
   见 JS 里的 arrowSVG()：所有方向指示都用 SVG，不再用 ‹ › ▸ ▾ 字符。
   基础类只定对齐与继承色，尺寸由各场景的修饰类给（如 .arw-14）。
   svg 是替换元素，vertical-align 默认 baseline 对齐 —— 配合 inline-flex 容器的
   align-items:center 就能坐正，不会像字符那样带上字体的行高偏移。 */
.arw{display:inline-block;flex:none;vertical-align:middle}
.arw-10{width:10px;height:10px}
.arw-12{width:12px;height:12px}
.arw-14{width:14px;height:14px}
.arw-15{width:15px;height:15px}
.txs-expcaret{width:11px;height:11px}.txs-expmenu{margin-top:10px;border:1px solid var(--card-border);border-radius:10px;overflow:hidden;background:var(--bg-soft);animation:expmenu .16s ease}
@keyframes expmenu{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.txs-expitem{display:flex;flex-direction:column;gap:2px;width:100%;text-align:left;padding:10px 12px;border:0;background:transparent;cursor:pointer;font-family:inherit;color:var(--text);transition:.15s}
.txs-expitem+.txs-expitem{border-top:1px solid var(--card-border)}
.txs-expitem:hover{background:var(--card)}
.txs-expitem:disabled{opacity:.45;cursor:not-allowed}
.ei-name{font-size:13px;font-weight:600}
.ei-desc{font-size:11.5px;color:var(--text-sub);font-weight:400}
.txs-more{margin-top:12px;padding-top:12px;border-top:1px dashed var(--card-border);display:grid;grid-template-columns:1fr 1fr;gap:9px 10px}
.txs-f{display:flex;flex-direction:column;gap:4px;font-size:11.5px;color:var(--text-sub);font-weight:600}
.txs-f input,.txs-f select{width:100%;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:13.5px;padding:8px 10px;border-radius:9px;outline:none;transition:.18s;font-family:inherit;font-weight:400;color-scheme:light dark;min-width:0}
.txs-f input:focus,.txs-f select:focus{border-color:var(--accent);background:var(--card)}
.txs-f select:disabled{opacity:.45;cursor:not-allowed}
.txs-picker{width:100%;min-width:0;display:flex;align-items:center;gap:8px;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:13.5px;padding:8px 10px;border-radius:9px;outline:none;transition:border-color .18s,background .18s;font-family:inherit;font-weight:400;text-align:left;cursor:pointer}
.txs-picker:hover,.txs-picker:focus-visible{border-color:var(--accent);background:var(--card)}
.txs-picker:disabled{opacity:.45;cursor:not-allowed}
.txs-picker span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.txs-picker svg{width:14px;height:14px;flex:none;color:var(--text-dim);stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.txs-tip{font-size:11.5px;color:var(--text-sub);margin-top:10px}
#txsList{margin-top:2px}
#txsList .cd-row{cursor:pointer;padding:9px 2px}
.txs-ck{flex:none;width:20px;height:20px;border-radius:6px;border:1.5px solid var(--card-border);display:none;align-items:center;justify-content:center;font-size:12px;color:transparent;background:var(--card);transition:.15s;margin-right:2px}
.txs-list.sel-mode .cd-row .tx-crow{align-items:center}
.txs-list.sel-mode .txs-ck{display:inline-flex}
.txs-list.sel-mode .cd-row.on .txs-ck{background:var(--accent);border-color:var(--accent);color:#fff}
.txs-load{display:block;width:100%;margin-top:10px;padding:9px 0;border:1px dashed var(--card-border);border-radius:9px;background:transparent;color:var(--text-sub);font-size:12.5px;font-family:inherit;cursor:pointer}
.txs-load:hover{color:var(--accent);border-color:var(--accent)}
.txs-load[hidden]{display:none}
/* 批量操作条：多选模式下替代底部 TabBar（z 75 > tabbar 70，低于一切弹层） */
.txs-bar{position:fixed;left:14px;right:14px;bottom:calc(10px + env(safe-area-inset-bottom));z-index:75;display:flex;align-items:center;gap:7px;padding:9px 12px;border:1px solid var(--card-border);border-radius:999px;background:var(--card);box-shadow:0 14px 38px rgba(20,35,80,.18)}
.txs-bar .tb-n{font-size:12.5px;font-weight:600;color:var(--text-sub);white-space:nowrap;margin-right:auto}
.txs-bar .tb-n b{color:var(--accent)}
.tb-btn{flex:none;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:12px;font-weight:600;padding:7px 11px;border-radius:999px;cursor:pointer;font-family:inherit;transition:.15s}
.tb-btn:hover{border-color:var(--accent);color:var(--accent)}
.tb-btn.danger{color:#e5484d;border-color:rgba(229,72,77,.35);background:rgba(229,72,77,.08)}
.tb-btn.danger:hover{background:rgba(229,72,77,.14)}
.tb-btn.ghost{border-style:dashed;color:var(--text-sub)}
/* ⚠️ 窄屏必须允许换行：6 个元素在 390px 下需要 376px 内容宽，而条只有 360px，
   不换行会直接把「删除」挤出圆角外面（320px 时连「清标签」也一起挤出去）。
   方案：≤560px 折成两行 —— 第一行「退出 + 已选 N 笔」，第二行四个操作等宽平分。
   不用横向滚动：批量操作是「选完就要点」的场景，按钮藏在滚动区外等于没有。 */
@media (max-width:560px){
  .txs-bar{flex-wrap:wrap;row-gap:8px;border-radius:18px;padding:10px 12px}
  .txs-bar .tb-n{margin-right:0}                 /* 换行后不需要再用 auto 顶开 */
  .txs-bar .tb-btn{flex:1 1 0;min-width:0;padding:8px 6px}   /* 四等分，文字长的自动缩字距 */
  #txsBarCat,#txsBarTag,#txsBarClr,#txsBarDel{flex-basis:calc(25% - 6px)}
}
@media (min-width:721px){.txs-bar{left:50%;right:auto;transform:translateX(-50%);width:min(620px,calc(100% - 28px));bottom:16px}}
/* 批量操作弹窗：夹在设置窗(120)与轻确认(130)之间 */
.txs-op-mask{z-index:122}
.txs-op-input{width:100%;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:14px;padding:9px 11px;border-radius:9px;outline:none;transition:.18s;font-family:inherit;margin-top:10px;color-scheme:light dark}
.txs-op-input:focus{border-color:var(--accent);background:var(--card)}
.txs-op-body .miniseg{margin-bottom:2px}
.txs-tags{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:10px;max-height:190px;overflow-y:auto}
.txs-tagit{display:flex;align-items:center;gap:7px;padding:8px 10px;border:1px solid var(--card-border);border-radius:9px;background:var(--bg-soft);font-size:13px;cursor:pointer;user-select:none}
.txs-tagit .ck{flex:none;width:17px;height:17px;border-radius:5px;border:1.5px solid var(--card-border);display:inline-flex;align-items:center;justify-content:center;font-size:11px;color:transparent;transition:.15s;background:var(--card)}
.txs-tagit.on{border-color:var(--accent)}
.txs-tagit.on .ck{background:var(--accent);border-color:var(--accent);color:#fff}
.txs-warn{font-size:13px;color:var(--text);line-height:1.55;margin-top:2px}
.txs-warn b{color:#e5484d}
.txs-empty{padding:34px 0;text-align:center;color:var(--text-sub);font-size:12.5px;line-height:1.8}
.txs-empty b{color:var(--text)}
/* 首页月份条 */
.home-month{display:flex;align-items:center;gap:10px;padding:13px 18px;margin-bottom:14px}
.hm-left{min-width:0}
.hm-label{font-size:14.5px;font-weight:600}
.hm-sub{font-size:11px;color:var(--text-dim);margin-top:2px}
.hm-add{flex:none}
.hm-add[hidden]{display:none}
.home-month .cal-nav{margin-left:auto;background:var(--bg-soft);border:1px solid var(--card-border);border-radius:11px;padding:3px}
.home-month .cal-navbtn{width:30px;height:30px;font-size:16px}
.home-month .cal-ymbtn{font-size:13.5px;padding:5px 10px}
/* 首页布局：桌面「流水(宽) + 日历/预算(窄)」，移动端纵向（日历、预算在流水前）。
   右侧栏单独纵向排列，避免左侧流水展开时影响预算的位置。 */
.home-grid{display:grid;gap:14px;grid-template-areas:"side" "tx";margin-bottom:16px}
.home-side{grid-area:side;display:flex;flex-direction:column;gap:14px;min-width:0}
.home-tx{grid-area:tx}
@media (min-width:721px){
  .home-grid{grid-template-columns:minmax(0,1fr) 400px;grid-template-areas:"tx side";align-items:start}
}
/* ---------- 按日分组流水：日期条 + 可折叠明细 ----------
   设计：日期条=导航层（永远可见，一眼看全月节奏）；明细=按需层（点开才画）。
   54 笔 × 56px = 3024px 是这张卡的真正元凶，不是日期条。默认收起后整卡高度压在 ~750px。 */
/* ⚠️ 水平 padding 走变量（--dg-pad）：日期条的负 margin 必须与它严格相等，
   否则底色块会短一截（差 2px 都能看出来「没贴边」）。两处写死数字迟早会对不上，
   所以只留一处真值。 */
.daygroup{--dg-pad:12px;background:var(--bg-soft);border-radius:12px;
  padding:2px var(--dg-pad) 4px;margin-bottom:10px}
.daygroup:last-child{margin-bottom:0}
.dayhead{display:flex;align-items:center;gap:8px;padding:9px 10px 7px;border-bottom:1px dashed var(--card-border);
  cursor:pointer;border-radius:8px;transition:background .15s}
/* ⚠️ 没有 --hover 这个变量：.daygroup 自身就是 --bg-soft，hover 要「抬起」到 --card。
   ⚠️ 底块必须自己铺满整行：.daygroup 有 12px 水平 padding，若 .dayhead 只靠自身
      padding 撑宽度，底色块会比日期条窄一圈，看着「没居中、不协调」（老大反馈）。
      所以给 .dayhead 加回左右 padding（10px）并让它 width:100%（flex 子项默认拉伸），
      再用负 margin 抵消 .daygroup 的水平 padding，底色就能真正顶到块边缘。
   ⚠️ 负 margin 用 calc(-1 * var(--dg-pad))，不要写死 -10px ——
      写死时与 12px 的 padding 差 2px，实测就是「看着还是没贴边」。
   ⚠️ 负 margin 只作用在**左右**，上下不能动：.dayhead 自身的上下 padding（9/7px）
      要保留，否则文字会顶着底色块上下边缘。
   ⚠️ 收起态的底部虚线是「这点还有明细」的唯一暗示，必须常驻显性
      （见 §dayhead::before 那条高光线）。 */
.dayhead{margin-left:calc(-1 * var(--dg-pad));margin-right:calc(-1 * var(--dg-pad))}
/* hover 两版都试过，最终选「不换底 + 顶部高光线」：
   ① 「整行底色换 --card(#fff)」的问题 —— 底部虚线在纯白上对比度只有 1.09:1，
      肉眼几乎看不见，整行看着像一块没内容的浅色板，「不好看」（老大反馈）。
   ② 「补充色块(v1, rgba(15,35,90,.05))」对比度够，但外轮廓比日期条窄一圈、
      又不含虚线，看着像一块贴歪的补丁。
   最终：底色不动（保持 --bg-soft 让虚线可见），只用一条 2px accent 高光线
   从左端划过整行做「悬停反馈」——方向感明确（从左亮起）、不抢内容、不产生补丁感。 */
.dayhead{background-repeat:no-repeat;background-size:100% 2px;background-position:top left}
.dayhead:hover{background-image:linear-gradient(var(--accent),var(--accent))}
/* 展开态：不能整条铺 accent-soft —— 那会在下方明细（透明底）处戛然而止，
   看着是一块半截色块，很突兀（老大反馈）。
   改法：**底色铺在整个 .daygroup 容器上**，颜色从日期条连续延伸到明细区底部，
   是一块完整的「展开容器」而不是半截色带；日期条自身再叠一层 card 抬色形成层次，
   左侧留一条 accent 竖条做锚点。这样深浅主题下都不会有刺眼的撞色。 */
.daygroup.open{background:var(--card);box-shadow:var(--shadow)}
.daygroup.open .dayhead{background-color:var(--bg-soft);border-bottom-color:transparent;position:relative}
/* 展开态 hover：容器已是 --card，日期条再抬一层会长得像「白上白」，
   所以展开态不换底，只保留上面那条 accent 高光线（.dayhead:hover 已给）。 */
.daygroup.open .dayhead:hover{background-color:var(--bg-soft)}
.daygroup.open .dayhead::before{content:"";position:absolute;left:0;top:7px;bottom:5px;width:3px;
  border-radius:2px;background:var(--accent)}
/* ============================================================
   日期条垂直居中：**不要再按字号逐个补 `top` 了**。
   ------------------------------------------------------------
   复盘（老大反馈了三轮「N 笔偏上」，前两轮我都修错了方向）：

   事实（`_probe_ink.js` 像素实测，devicePixelRatio=4，日期条 h=32 / padTop=9 / padBot=7）：
     元素     墨迹中心  自身盒子中心  差      字号    墨迹高
     date        17.25      17.0     +0.25   13px    12
     wd          16.88      17.27    -0.39   11px    10.25
     cnt         15.75      17.05    -1.30   10.5px  10      ← 「N 笔」，就是它偏上
     amt         17.38      17.0     +0.38   12px    11.75
     today       17.00      17.0      0.00   10px    14（胶囊）

   结论：**盒子的中心是齐的**（全在 17 附近），但**字形墨迹的重心不齐** ——
   「笔数」的墨迹高 10px 而它盒子 11px，墨迹天然坐在盒子偏上处。
   前两轮加 `top` +0.5~0.77px 不仅没治本，反而把 date/wd/amt 又往下推了，
   制造出「其它都还行、就 small 字号最偏」的错觉。

   正解：**不再依赖「盒子对齐」，改成让「墨迹」对齐。**
   给这几个元素统一的、与字号无关的补偿 —— 用 `transform: translateY()`
   一次性把所有文字下移到墨迹居中位置，而不是逐个按字号猜数值。
   1) 先把所有文字的 line-height 压到 1（去掉半行距干扰，行盒 = em 盒）；
   2) 再用 flex 的 align-items:center 对齐「行盒」（此时行盒≈em 盒）；
   3) 墨迹在 em 盒里天生偏上（Ascent>Descent），用一个**统一的小量**把
      整组文字下推，使**墨迹**落在内容区中心。
   统一量优于逐字号量：字号改了不用重新量、不会出现「这个对齐那个不对齐」。

   实测（本文件下面的 --dh-ink-fix 就是最终值）：让 cnt 的墨迹中心从 15.75
   移到 17 附近，需要下移 ≈1.25px；同时 date/wd/amt 只需 ≤0.4px。
   → 取折中 0.9px，极差压到 ~0.9px（肉眼不可辨），且**所有元素同向**，
     不会再出现「一个偏上一个偏下」的撕裂感。
   ⚠️ 别再用「按字号逐个补 top」那一套（旧文字保留在 §旧方案 注释里作警示）。
   ============================================================ */
.dayhead b,.dayhead .dh-wd,.dayhead .dh-cnt,.dayhead .dh-today,.dayhead .dh-amt{line-height:1}
/* ↑ 关键前提：line-height:1 让「行盒 = em 盒」。
   实测反查确认它已生效（getComputedStyle 给 `13px` / `10.5px` / `12px`，
   即 line-height 数值 = font-size）。若哪天这条失效，本节的补偿会全部错位。 */
.dayhead b,.dayhead .dh-wd,.dayhead .dh-cnt,.dayhead .dh-amt{
  position:relative;top:var(--dh-ink-fix,1.4px);   /* 基础量：整组文字下移到「墨迹居中」 */
}
/* 「笔数」再补一点点。
   为什么只有它需要额外补偿：它的**墨迹高(10px) < 盒子高(11px)**，而其它元素
   两者接近（date 12/13、amt 11.75/12）。墨迹在盒子里天然坐得偏上，
   字号越小这个「墨迹盒差」占比越大 —— 所以小字号元素会系统性地更偏上。
   这是**字号相关的固有量**，不是可以靠调一个统一值解决的问题。
   经验值：10.5px 字号需再 +0.55px。
   ⚠️ 若改了 .dh-cnt 的 font-size，这个数要重测（`_probe_ink.js` 会直接给出）。 */
.dayhead .dh-cnt{top:calc(var(--dh-ink-fix,1.4px) + .55px)}
.dayhead b,.dayhead .dh-wd,.dayhead .dh-cnt,.dayhead .dh-amt{
  position:relative;   /* 保证上面 top 生效 */
}
/* 「今天」胶囊：不参与上面那组补偿（胶囊墨迹在自身盒里已居中）。
   但它必须与邻居**同向**落位 —— 邻居整体下移了多少，它就补多少，
   否则会出现「胶囊比邻居高」或「比邻居低」的撕裂（老大两轮反馈都是这个）。
   用同一个变量，保证永远同步。 */
.dayhead .dh-today{position:relative;top:var(--dh-ink-fix,1.4px)}
.dayhead b{font-size:13px;color:var(--text);font-weight:600}
.dayhead .dh-wd{font-size:11px;color:var(--text-dim)}
.dayhead .dh-cnt{font-size:10.5px;color:var(--text-sub);font-variant-numeric:tabular-nums}
/* 「今天」徽标：inline-flex + align-items:center 让文字在胶囊内居中。
   胶囊不参与上面那组补偿 —— 它的墨迹中心实测就是 17.00（已经正中），
   给它加任何额外偏移都会立刻显示为「比邻居低」。 */
.dayhead .dh-today{font-size:10px;font-weight:600;color:var(--accent);background:var(--accent-soft);
  border-radius:20px;padding:2px 7px;display:inline-flex;align-items:center}
.dayhead .dh-amt{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:12px;font-variant-numeric:tabular-nums}
.dayhead .dh-in{color:var(--green)}
.dayhead .dh-exp{color:var(--rose)}
/* 折叠箭头用 SVG 图标，不用 ▸/▾ 之类字符 ——
   字符在 9~10px 下笔画只有 1px 上下，跟小数点一样看不出朝向（老大反馈）。
   图标画在 24 格 viewBox 里、stroke-width 2.4，缩到 14px 仍是清晰的折线。
   基准朝向是「向下」（展开态），收起态转 -90° 变成「向右」。
   基准必须是 rotate(0) 那条，否则 SVG 会带着默认旋转进页面、第一帧就跳一下。 */
.dayhead .dh-arrow{flex:none;width:15px;height:15px;color:var(--text-sub);
  display:block;transform:rotate(-90deg);transition:transform .2s ease,color .18s}
.dayhead:hover .dh-arrow{color:var(--text)}
.daygroup.open .dayhead .dh-arrow{transform:rotate(0)}
/* 兜底：收起态即使 .dayrows 里还残留内容也不占位。
   实际实现是「收起时清空 innerHTML」（见 toggleTxDay），这行只是双保险，
   防的是有人后续改回「渲染全部 · 用 display 控制显隐」而忘了这层。 */
.daygroup:not(.open) .dayrows{display:none}
.dayrows .cd-row{padding:8px 2px}
.dayrows .tx-crow .cd{display:none}
/* 窄屏：日期条要挤下「日期+星期+今天+笔数+双合计+箭头」，收两档 ——
   ①隐藏「今天」徽标（日期本身就能看出是今天，笔数信息更值钱）
   ②支出合计省略「支出」二字（颜色已表意：收入绿、支出红），否则 390px 下必挤爆 */
@media (max-width:430px){
  .dayhead{gap:6px}
  .dayhead .dh-today{display:none}
  .dayhead .dh-cnt{font-size:10px}
  .dayhead .dh-amt{gap:8px;font-size:11.5px}
  .dayhead .dh-exp .dh-lbl{display:none}
}
.dayrows .tx-crow .pill{order:-1;margin-right:2px}
@keyframes spin{to{transform:rotate(360deg)}}
/* ---------- 卡片 ---------- */
.card{background:var(--card);border:1px solid var(--card-border);border-radius:16px;box-shadow:var(--shadow);padding:20px 22px;transition:transform .2s,box-shadow .2s,background .3s;animation:rise .5s ease both}
.card:hover{transform:translateY(-2px);box-shadow:var(--hover-lift)}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.card{animation:none}}
.card .head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.card .head h3{font-size:14.5px;font-weight:600}
.card .head .hint{font-size:11.5px;color:var(--text-dim);margin-left:auto}
/* ⚠️ 「搜索结果」标题行在窄屏会被挤断行：h3 + 长摘要 + 两个按钮在 390px 下正好顶满
   （实测 336px 可用宽 = h3 43 + hint 173 + tools 100 + gap 20），h3 被压到 43px 就折成两行，
   且 .headtools 的 flex-wrap 会让两按钮上下叠成 54px 高。
   方案：≤560px 允许整行换行，h3 独占第一行（flex 不收缩），摘要与按钮排到第二行。 */
@media (max-width:560px){
  .txs-head{flex-wrap:wrap;row-gap:8px}
  .txs-head h3{flex:1 1 100%}                   /* 标题独占一行，不再被压 */
  .txs-head .hint{margin-left:0;flex:1 1 auto;min-width:0}
  .txs-head .headtools{flex:none;margin-left:auto}
}
/* ---------- KPI ---------- */
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.kpi .label{font-size:12.5px;color:var(--text-sub);display:flex;align-items:center;gap:7px;height:23px}   /* 固定行高：净资产卡带眼睛按钮（23px）时文字基线与前三卡一致 */
.kpi .dot{width:8px;height:8px;border-radius:3px;flex:none}
.kpi .num{font-size:27px;font-weight:650;letter-spacing:-.01em;margin-top:9px;font-variant-numeric:tabular-nums}
.kpi .foot{margin-top:9px;font-size:12px;color:var(--text-sub);display:flex;align-items:center;gap:6px;min-height:18px}   /* foot 提到 --text-sub：浅底 #eaeffa 上 --text-dim 对比仅约 2.1:1 偏弱，--text-sub ≈ 4.7:1（2026-09-21 打磨） */
/* KPI 卡层级：浅底内嵌感 + 1px 同色系描边（.card 基类边框本就占 1px，只把透明换回
   --card-border，零布局位移；低亮屏边界不再发虚，仍不做悬停上浮、保持「弱化容器突出数字」） */
.kpi{background:var(--bg-soft);border-color:var(--card-border);box-shadow:none}
.kpi:hover{transform:none;box-shadow:none}
/* 键盘可达性：统一可见焦点环（WCAG 2.4.7） */
button:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
/* 净资产 · 显示/隐藏（马赛克） */
.kpi .num.rel{position:relative}
.kpi .num .t{white-space:nowrap}
.kpi .net-hidden .t{visibility:hidden}
.netmask{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none}
.eye{flex:none;margin-left:auto;width:23px;height:23px;padding:0;border:0;border-radius:8px;background:transparent;color:var(--text-dim);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.18s}
.eye:hover{color:var(--accent);background:var(--bg-soft)}
.eye svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.badge{display:inline-flex;align-items:center;gap:2px;font-size:11.5px;font-weight:600;padding:1.5px 7px;border-radius:20px}
.badge.up{color:var(--rose);background:rgba(239,77,110,.12)}
.badge.down{color:var(--green);background:rgba(24,167,104,.12)}
.badge.good{color:var(--green);background:rgba(24,167,104,.12)}
.badge.bad{color:var(--rose);background:rgba(239,77,110,.12)}
/* ---------- A2 本月预算卡 ----------
   与 KPI/健康度同一「内嵌浅底」语言。进度条颜色走 level 四档
   （none 中性蓝 / warn 橙 / danger 红 / over 深红）—— 这是**警示语义**，
   与股票涨跌色无关，不受「涨红跌绿」约定约束。 */
.bud-card{margin-bottom:0}
.bud-list{display:flex;flex-direction:column;gap:11px}
.bud-it{background:var(--bg-soft);border-radius:12px;padding:11px 14px 12px;min-width:0}
.bud-top{display:flex;align-items:baseline;gap:8px;margin-bottom:8px}
.bud-nm{font-size:13.5px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px;min-width:0}
.bud-nm .dot{width:8px;height:8px;border-radius:3px;flex:none}
.bud-nm .sub{font-size:11.5px;font-weight:500;color:var(--text-dim)}   /* 「/xx」小类后缀弱化 */
.bud-pct{font-size:13px;font-weight:650;margin-left:auto;font-variant-numeric:tabular-nums;white-space:nowrap}
.bud-it[data-lv="none"] .bud-pct{color:var(--accent)}
.bud-it[data-lv="warn"] .bud-pct{color:var(--orange)}
.bud-it[data-lv="danger"] .bud-pct{color:var(--rose)}
.bud-it[data-lv="over"] .bud-pct{color:var(--rose)}
.bud-over{font-size:11.5px;font-weight:600;color:var(--rose);background:rgba(239,77,110,.12);padding:1.5px 7px;border-radius:20px;white-space:nowrap}
.bud-bar{height:7px;border-radius:6px;background:var(--card-border);overflow:hidden;position:relative}
.bud-bar i{display:block;height:100%;border-radius:6px;transition:width .5s cubic-bezier(.4,0,.2,1);min-width:0}
.bud-it[data-lv="none"] .bud-bar i{background:var(--accent)}
.bud-it[data-lv="warn"] .bud-bar i{background:var(--orange)}
.bud-it[data-lv="danger"] .bud-bar i{background:var(--rose)}
.bud-it[data-lv="over"] .bud-bar i{background:var(--rose)}
.bud-num{margin-top:7px;font-size:11.5px;color:var(--text-sub);display:flex;gap:6px;font-variant-numeric:tabular-nums}
.bud-num .sep{color:var(--text-dim)}
.bud-num .left{margin-left:auto}
.bud-more{margin-top:10px;font-size:12px;color:var(--accent);background:none;border:0;padding:2px 0;cursor:pointer;font-family:inherit;font-weight:600}
.bud-empty{background:var(--bg-soft);border-radius:12px;padding:18px 16px;text-align:center}
.bud-empty p{margin:0 0 11px;font-size:12.5px;color:var(--text-sub)}
/* 总体行比分类行更醒目（它是这张卡的主角）：底色淡、字重高、进度条高一点 */
.bud-it.total{background:var(--card)}
.bud-it.total .bud-nm{font-size:14.5px}
.bud-it.total .bud-bar{height:8px}
.bud-it.total .bud-num{font-size:12px}
.bud-loading{font-size:12.5px;color:var(--text-dim);padding:6px 0}
@media (max-width:560px){
  .bud-nm{font-size:13px}
  .bud-it{padding:10px 12px 11px}
  .bud-num{flex-wrap:wrap;row-gap:3px}
  .bud-num .left{margin-left:0;width:100%}
}
/* ---------- A2 预算编辑弹窗 ----------
⚠️ z-index **必须写在 .dp-mask 之后**的「设置窗口」块里（见下方 .bud-mask{104}）。
原因：.dp-mask{z-index:60} 在第 739 行，若在这里（更早）写 .bud-mask{z-index:104}，
会因同优先级「后写的胜出」而失效。
⚠️ 取值 104（不是 124）：预算弹窗里要再弹**分类选择器**（.pick-mask 110 / .pick-sheet 111），
预算弹窗必须**低于**选择器，否则它的 mask 会拦截 pointer events，选择器点不动。
   两者特异性相同（单类选择器）→ **源码顺序决定胜负 → 后写的 60 生效**，
   预算弹窗会排在设置窗之下。这是本项目已经踩过的坑（见技能 §16b-1）。 */
.bud-total{background:var(--bg-soft);border-radius:12px;padding:13px 14px;margin-bottom:14px}
.bud-total .fl{display:block;font-size:12.5px;font-weight:600;color:var(--text-sub);margin-bottom:7px}
.bud-total .in{display:flex;align-items:center;gap:8px}
.bud-total .cur{font-size:15px;font-weight:600;color:var(--text-sub)}
.bud-total input{flex:1;min-width:0;border:1px solid var(--card-border);background:var(--card);color:var(--text);font-size:16px;font-weight:600;padding:10px 12px;border-radius:9px;outline:none;transition:.18s;font-family:inherit;font-variant-numeric:tabular-nums;color-scheme:light dark}
.bud-total input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(63,102,248,.12)}
.bud-rows{display:flex;flex-direction:column;gap:9px}
.bud-row{display:flex;align-items:center;gap:9px;background:var(--bg-soft);border-radius:11px;padding:10px 12px;min-width:0}
.bud-row .bi{flex:1;min-width:0}
.bud-row .bn{font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px}
.bud-row .bn .dot{width:8px;height:8px;border-radius:3px;flex:none}
.bud-row .bn .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bud-row .bn .gone{font-size:11px;font-weight:500;color:var(--text-dim);background:var(--card);padding:1px 6px;border-radius:20px;flex:none}
.bud-row .bs{font-size:11.5px;color:var(--text-sub);margin-top:3px;font-variant-numeric:tabular-nums}
.bud-row input.money{flex:none;width:96px;border:1px solid var(--card-border);background:var(--card);color:var(--text);font-size:13.5px;padding:7px 9px;border-radius:8px;outline:none;text-align:right;transition:.18s;font-family:inherit;font-variant-numeric:tabular-nums;color-scheme:light dark}
.bud-row input.money:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(63,102,248,.12)}
.bud-row .del{flex:none;width:29px;height:29px;padding:0;border:0;border-radius:8px;background:transparent;color:var(--text-dim);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.18s}
.bud-row .del:hover{color:var(--rose);background:rgba(239,77,110,.1)}
.bud-row .del svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round}
.bud-add{width:100%;margin-top:10px;border:1px dashed var(--card-border);background:transparent;color:var(--text-sub);font-size:13px;font-weight:600;padding:10px;border-radius:10px;cursor:pointer;font-family:inherit;transition:.18s}
.bud-add:hover{border-color:var(--accent);color:var(--accent)}
/* 父子独立计算是反直觉的，必须常驻说明 —— 否则用户会以为外卖没被算进餐饮是 bug */
/* ⚠️ 必须给足下边距：.dp-btns 是各弹窗共用的裸 flex 行（没有自己的 margin），
   所以紧邻它上方的元素要自己把间距补出来，否则说明框和按钮会贴死。 */
.bud-note{margin:12px 0 16px;font-size:11.5px;line-height:1.65;color:var(--text-sub);background:var(--bg-soft);border-radius:10px;padding:10px 12px}
.bud-note b{color:var(--text)}
.bud-body{max-height:min(58vh,460px);overflow-y:auto;margin:0 -4px;padding:0 4px}
.bud-err{margin-top:11px;font-size:12.5px;font-weight:600;color:var(--rose);background:rgba(239,77,110,.1);border-radius:9px;padding:9px 11px}
@media (max-width:560px){
  .bud-row{flex-wrap:wrap}
  .bud-row .bi{flex:1 1 100%}
  .bud-row input.money{flex:1 1 auto;width:auto}
}
/* ---------- 财务健康度 ----------
   与 KPI 卡同一「内嵌浅底」语言（bg-soft 圆角块），状态三色走语义变量；
   状态点带同色光环（st-bg），数字与 KPI 同字重同级感 */
.health-card{margin-bottom:16px}
.health-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.health-it{--st:var(--text-dim);--st-bg:transparent;background:var(--bg-soft);border-radius:12px;padding:13px 15px 12px;transition:transform .18s,box-shadow .18s;cursor:default;min-width:0}
.health-it:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.health-it[data-lv="good"]{--st:var(--green);--st-bg:rgba(24,167,104,.14)}
.health-it[data-lv="watch"]{--st:var(--orange);--st-bg:rgba(242,128,58,.16)}
.health-it[data-lv="warn"]{--st:var(--rose);--st-bg:rgba(239,77,110,.13)}
.health-it .hl{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text-sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.health-it .st{width:8px;height:8px;border-radius:50%;background:var(--st);box-shadow:0 0 0 3px var(--st-bg);flex:none;transition:background .3s}
.health-it .hv{font-size:21px;font-weight:650;letter-spacing:-.01em;margin-top:8px;font-variant-numeric:tabular-nums;color:var(--text);display:flex;align-items:baseline;gap:3px;white-space:nowrap}
.health-it .hv .hu{font-size:12px;font-weight:500;color:var(--text-sub);letter-spacing:0}
.health-it .ht{margin-top:5px;font-size:11px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.health-it .ht b{color:var(--st);font-weight:600}
/* ---------- 消费日历（热力图）----------
   纯 CSS grid 手绘（不用 ECharts calendar）：7 列周一开头、格子 aspect-ratio 1 自适应。
   色阶与看板语义色同族：支出=橙红阶（--rose 族）、收入=绿阶（--green 族）；
   暗色主题用基色透明度阶梯降饱和，保证深底和谐 */
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-top:2px}
.cal-grid .wd{font-size:10.5px;color:var(--text-dim);text-align:center;padding:2px 0 4px}
.cal-cell{aspect-ratio:1;border-radius:22%;background:var(--bg-soft);transition:transform .12s;min-width:0;display:flex;align-items:center;justify-content:center;font-size:10.5px;color:var(--text-sub);font-variant-numeric:tabular-nums}
.cal-pad{aspect-ratio:1}   /* 1 号前空位：透明占位，保持列对齐但不渲染成格子 */
.cal-cell.l3,.cal-cell.l4{color:#fff}
.cal-cell.l3{font-weight:500}.cal-cell.l4{font-weight:600}
.cal-cell.has{cursor:pointer}
.cal-cell.has:hover{transform:scale(1.14)}
.cal-cell.today{outline:2px solid var(--accent);outline-offset:1px}
/* 点击选中：内描边（不与今天的外 outline 冲突），再点取消；选中今天时外框让位避免双框 */
.cal-cell.sel{box-shadow:inset 0 0 0 2px var(--accent)}
.cal-cell.today.sel{outline:none}
.cal-cell.l1{background:#f9e2d2}.cal-cell.l2{background:#f4b48c}.cal-cell.l3{background:#ee8552}.cal-cell.l4{background:#e0512e}
#calGrid[data-t="income"] .cal-cell.l1{background:#d8f0e4}#calGrid[data-t="income"] .cal-cell.l2{background:#a8dfc3}#calGrid[data-t="income"] .cal-cell.l3{background:#62c798}#calGrid[data-t="income"] .cal-cell.l4{background:#18a768}
html[data-theme="dark"] .cal-cell.l1{background:rgba(226,84,46,.2)}html[data-theme="dark"] .cal-cell.l2{background:rgba(226,84,46,.45)}
html[data-theme="dark"] .cal-cell.l3{background:rgba(238,133,82,.72)}html[data-theme="dark"] .cal-cell.l4{background:#ee8552}
html[data-theme="dark"] #calGrid[data-t="income"] .cal-cell.l1{background:rgba(24,167,104,.22)}
html[data-theme="dark"] #calGrid[data-t="income"] .cal-cell.l2{background:rgba(24,167,104,.48)}
html[data-theme="dark"] #calGrid[data-t="income"] .cal-cell.l3{background:rgba(98,199,152,.72)}html[data-theme="dark"] #calGrid[data-t="income"] .cal-cell.l4{background:#2fca85}
.cal-foot{display:flex;align-items:center;gap:8px;margin-top:12px;padding-top:10px;border-top:1px dashed var(--card-border);font-size:11px;color:var(--text-dim)}
.cal-lg{display:flex;align-items:center;gap:3px}
.cal-lg i{width:12px;height:12px;border-radius:4px;display:inline-block;background:var(--bg-soft)}
.cal-lg i[data-l="1"]{background:#f9e2d2}.cal-lg i[data-l="2"]{background:#f4b48c}.cal-lg i[data-l="3"]{background:#ee8552}.cal-lg i[data-l="4"]{background:#e0512e}
#calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="1"]{background:#d8f0e4}#calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="2"]{background:#a8dfc3}#calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="3"]{background:#62c798}#calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="4"]{background:#18a768}
html[data-theme="dark"] .cal-lg i[data-l="1"]{background:rgba(226,84,46,.2)}html[data-theme="dark"] .cal-lg i[data-l="2"]{background:rgba(226,84,46,.45)}
html[data-theme="dark"] .cal-lg i[data-l="3"]{background:rgba(238,133,82,.72)}html[data-theme="dark"] .cal-lg i[data-l="4"]{background:#ee8552}
html[data-theme="dark"] #calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="1"]{background:rgba(24,167,104,.22)}
html[data-theme="dark"] #calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="2"]{background:rgba(24,167,104,.48)}
html[data-theme="dark"] #calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="3"]{background:rgba(98,199,152,.72)}html[data-theme="dark"] #calGrid[data-t="income"]~.cal-foot .cal-lg i[data-l="4"]{background:#2fca85}
.cal-peak{margin-left:auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cal-peak b{color:var(--text);font-weight:600}
/* 日历月份导航：‹ 年月 ›（年月文字可点弹月份选择器） */
.cal-nav{display:flex;align-items:center;gap:2px;margin-left:auto}
.cal-navbtn{width:24px;height:24px;padding:0;border:0;border-radius:8px;background:transparent;color:var(--text-sub);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:15px;line-height:1;transition:.18s;font-family:inherit}
.cal-navbtn:hover:not(:disabled){color:var(--accent);background:var(--accent-soft)}
.cal-navbtn:disabled{color:var(--text-dim);opacity:.45;cursor:not-allowed}
.cal-ymbtn{border:0;background:transparent;color:var(--text);font-size:12.5px;font-weight:600;cursor:pointer;padding:4px 8px;border-radius:8px;transition:.18s;font-family:inherit;white-space:nowrap}
.cal-ymbtn:hover{background:var(--accent-soft);color:var(--accent)}
/* 月份选择器面板 */
.calym-mask{position:fixed;inset:0;z-index:96;background:rgba(8,14,30,.45);display:flex;align-items:center;justify-content:center;padding:20px;animation:fadein .2s ease}
@keyframes fadein{from{opacity:0}to{opacity:1}}
.calym-panel{background:var(--card);border:1px solid var(--card-border);border-radius:16px;box-shadow:var(--hover-lift);padding:14px;width:min(320px,calc(100vw - 32px));animation:rise .3s ease both}
.calym-ynav{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.calym-ynav .cal-navbtn{width:30px;height:30px;background:var(--bg-soft);font-size:14px}
.calym-year{font-size:14.5px;font-weight:600}
.calym-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.calym-grid button{border:0;border-radius:10px;background:var(--bg-soft);color:var(--text-sub);font-size:13px;padding:10px 0;cursor:pointer;transition:.15s;font-family:inherit}
.calym-grid button:hover:not(:disabled){background:var(--accent-soft);color:var(--accent)}
.calym-grid button.on{background:var(--accent);color:#fff;font-weight:600}
.calym-grid button:disabled{color:var(--text-dim);opacity:.5;cursor:not-allowed}
.calym-tip{margin:10px 2px 0;font-size:11px;color:var(--text-dim)}
/* 当日明细弹窗：桌面居中小卡，移动端底部弹出 */
.cd-modal-mask{position:fixed;inset:0;z-index:97;background:rgba(8,14,30,.45);display:flex;align-items:center;justify-content:center;padding:20px;animation:fadein .2s ease}
.cd-modal{background:var(--card);border:1px solid var(--card-border);border-radius:16px;box-shadow:var(--hover-lift);width:min(380px,100%);max-height:min(560px,80vh);display:flex;flex-direction:column;animation:rise .3s ease both;overflow:hidden}
.cd-head{display:flex;align-items:center;padding:14px 16px 10px}
.cd-title{font-size:15px;font-weight:600}
.cd-sub{font-size:11.5px;color:var(--text-dim);margin-top:2px}
.cd-close{margin-left:auto;width:28px;height:28px;border:0;border-radius:9px;background:var(--bg-soft);color:var(--text-sub);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.18s}
.cd-close:hover{color:var(--accent);background:var(--accent-soft)}
.cd-list{overflow-y:auto;padding:2px 16px;flex:1}
/* 明细行 · 两行式账单排版：主行「分类 + 金额」，次行「大类 · 备注/流向 + 时间」 */
/* 明细行：与最近交易同款两行式（tx-crow 主行 + tx-mrow 次行），同一天仅显示时刻 */
.cd-row{display:flex;flex-direction:column;gap:4px;padding:10px 0;border-bottom:1px dashed var(--card-border);font-size:13px;min-width:0;max-width:100%;overflow:hidden}
.cd-row:last-child{border-bottom:0}
.cd-row:last-child{border-bottom:0}
.cd-row .cd{width:10px;height:10px;border-radius:4px;flex:none;align-self:flex-start;margin-top:6px}
.cd-row .cdmain{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
.cd-row .l1{display:flex;align-items:baseline;gap:8px;min-width:0}
.cd-row .l1 .nm{font-size:13.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cd-row .l1 .amt{margin-left:auto;font-size:14px;font-weight:650;font-variant-numeric:tabular-nums;flex:none}
.cd-row .l2{display:flex;align-items:center;gap:6px;min-width:0;font-size:11px;color:var(--text-dim)}
.cd-row .l2 .pill{flex:none}
.cd-row .l2 .meta{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cd-row .l2 .tm{margin-left:auto;flex:none}
.cd-row .amt.neg{color:var(--text)}
.cd-row .amt.pos{color:var(--green)}
.cd-row .amt.tr{color:var(--accent)}
/* 大类小标签：大类色 12% 底 + 大类色文字；圆角 5px 与分类色点同观感，兼作移动端色块锚点 */
.pill{display:inline-flex;align-items:center;font-size:10.5px;font-weight:600;padding:2px 6px;border-radius:5px;line-height:1.5;white-space:nowrap}
/* 资产页 · 净资产走势卡当前值 */
.asset-nowline{display:flex;align-items:baseline;gap:8px;margin:-6px 0 6px}
.an-label{font-size:11.5px;color:var(--text-dim)}
.an-val{font-size:21px;font-weight:650;color:var(--purple);font-variant-numeric:tabular-nums;letter-spacing:-.01em}
.an-sub{font-size:11px;color:var(--text-dim)}
/* 资产总览卡：净资产大字 + 眼睛 + 总资产/总负债（参考专业记账 App） */
.asset-overview{padding:18px 20px 16px}
.ao-net{display:flex;align-items:center;gap:8px}
.ao-net .lb{font-size:12px;color:var(--text-sub);font-weight:500}
.ao-net .eye{width:26px;height:26px;border:0;border-radius:50%;background:var(--bg-soft);color:var(--text-dim);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:color .18s,background .18s}
.ao-net .eye:hover{color:var(--accent);background:var(--accent-soft)}
.ao-val{font-size:30px;font-weight:700;letter-spacing:-.01em;margin:6px 0 10px;font-variant-numeric:tabular-nums;color:var(--text)}
.ao-rows{display:flex;gap:20px;font-size:12.5px;color:var(--text-sub)}
.ao-rows b{color:var(--text);font-weight:600;font-variant-numeric:tabular-nums;margin-left:4px}
/* 隐藏态：银行 App 同款圆点遮罩（净资产/总资产/总负债互相可推导，全部遮罩） */
.mask-dots{color:var(--text-dim);letter-spacing:3px}
.cd-loading{padding:28px 0;text-align:center;color:var(--text-dim);font-size:12.5px}
.cd-sum{display:flex;gap:14px;padding:11px 16px;background:var(--bg-soft);border-top:1px solid var(--card-border);font-size:12px;color:var(--text-sub)}
.cd-sum b{color:var(--text);font-weight:600;font-variant-numeric:tabular-nums}
@media (max-width:720px){
  .cd-modal-mask{align-items:flex-end;padding:0}
  .cd-modal{width:100%;max-height:72vh;border-radius:18px 18px 0 0;border-bottom:0}
}
/* 日历悬停浮层（深色，与 ECharts tooltip 同观感） */
#calTip{position:fixed;z-index:90;background:var(--tip-bg);color:var(--text);border:1px solid var(--grid);border-radius:10px;padding:7px 11px;font-size:12px;box-shadow:0 6px 20px rgba(0,0,0,.15);pointer-events:none;white-space:nowrap}
/* 健康度口径浮层：同观感但支持长文本换行；桌面悬停 / 手机点按图标均可唤出 */
#healthTip{position:fixed;z-index:90;background:var(--tip-bg);color:var(--text);border:1px solid var(--grid);border-radius:10px;padding:9px 12px;font-size:12px;line-height:1.55;box-shadow:0 6px 20px rgba(0,0,0,.15);pointer-events:none;max-width:250px;white-space:pre-line}
.hinfo{margin-left:auto;flex:none;width:18px;height:18px;padding:0;border:0;border-radius:50%;background:transparent;color:var(--text-dim);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:color .18s,background .18s;touch-action:manipulation}
.hinfo:hover{color:var(--accent);background:var(--accent-soft)}
.hinfo svg{width:12px;height:12px}
#calTip .d{color:var(--text-sub);margin-right:8px}
#calTip b{font-weight:600;font-variant-numeric:tabular-nums}
/* ---------- 网格 ---------- */
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:16px}
.grid>*{min-width:0}          /* 网格子项允许收缩，内容超宽时内部滚动而非撑破布局 */
.grid .span2{grid-column:span 2}
.chart{width:100%;height:300px}
.chart.tall{height:320px}
/* ---------- 排行 / 账户 / 流水 ---------- */
.rankrow{display:flex;align-items:center;gap:10px;padding:7.5px 0;cursor:pointer;border-radius:6px}
.rankrow .no{width:18px;font-size:12px;color:var(--text-dim);font-weight:600;flex:none}
.rankrow .cd{width:9px;height:9px;border-radius:3px;flex:none}
.rankrow .nm{font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px}
.rankrow .bar{flex:1;height:6px;border-radius:4px;background:var(--bg-soft);overflow:hidden}
.rankrow .bar i{display:block;height:100%;border-radius:4px;opacity:.85}
.rankrow .amt{font-size:12.5px;font-weight:600;font-variant-numeric:tabular-nums;flex:none}
.rankrow .pct{font-size:11px;color:var(--text-dim);width:44px;text-align:right;flex:none}
/* A3 下钻按钮：常驻低存在感，hover 才升到主色；自身也可以被点（事件委托里用 .rk-go 分流）。
   用 inline-flex 而非默认 inline-block：箭头 SVG 与「流水」二字靠 gap 对中，
   比依赖文字空格稳定（空格宽度随字体变，gap 不会）。 */
.rk-go{flex:none;display:inline-flex;align-items:center;gap:3px;font-size:11px;font-family:inherit;color:var(--text-sub);background:transparent;
  border:1px solid var(--card-border);border-radius:6px;padding:2px 7px;cursor:pointer;
  opacity:.62;transition:.15s;white-space:nowrap;margin-left:2px}
.rankrow:hover .rk-go{opacity:1;color:var(--accent);border-color:var(--accent)}
.rk-go:hover{background:var(--bg-soft)}
.rk-go .arw{width:9px;height:9px}
@media (max-width:560px){ .rk-go{display:none} }
/* 排行分类名后的「可下钻」小箭头：跟名字同基线，别用字符 ›（10px 下几乎看不见） */
.rk-sub-ico{display:inline-flex;align-items:center;vertical-align:middle}
.rk-sub-ico .arw{width:9px;height:9px;color:var(--text-dim)}
/* A2 联动：统计页排行行内的预算标记（警示语义色，与涨跌色无关）。
   只给「已设预算」的分类挂；none 档用中性色，避免满屏彩色噪音。 */
.rk-bud{flex:none;font-size:10.5px;line-height:1;border-radius:5px;padding:3px 5px;
  white-space:nowrap;margin-left:2px;border:1px solid transparent;
  background:var(--bg-soft);color:var(--text-sub);border-color:var(--card-border)}
.rk-bud[data-lv="warn"]{background:rgba(242,128,58,.12);color:#f2803a;border-color:rgba(242,128,58,.35)}
.rk-bud[data-lv="danger"]{background:rgba(239,77,110,.12);color:#ef4d6e;border-color:rgba(239,77,110,.35)}
.rk-bud[data-lv="over"]{background:rgba(239,77,110,.2);color:#ef4d6e;border-color:#ef4d6e;font-weight:700}
@media (max-width:560px){ .rk-bud{display:none} }   /* 窄屏连 .rk-go 都藏了，标记同理 */
.acctrow{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed var(--card-border)}
.acctrow:last-child{border-bottom:0}
.acctrow .cd{width:10px;height:10px;border-radius:4px;flex:none}
.acctrow .nm{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-right:auto}
.acctrow .amt{font-size:13.5px;font-weight:600;font-variant-numeric:tabular-nums}
.acctrow .amt.neg{color:var(--rose)}
.acctrow .amt.pos{color:var(--green)}
/* A3 下钻：账户行可点 → 该账户流水。只给「可点态」加手型与 hover 底，避免误以为所有行都能点 */
.acctrow.clickable{cursor:pointer;border-radius:6px;padding-left:6px;padding-right:6px;margin:0 -6px;transition:background .15s}
.acctrow.clickable:hover{background:var(--bg-soft)}
.acctrow.clickable:hover .nm{color:var(--accent)}
/* ---------- 最近交易 ----------
   桌面：单行表格「分类+大类pill | 备注 | 账户·时间 | 金额」；
   移动端：两行制——行1「大类pill+分类+金额」、行2「账户 · 备注 · 时间」（备注中段省略）。
   td 桌面保持 table-cell（td 上直接 flex 会破坏表格布局），内部包 flex 容器；
   行内片段与明细弹窗共用 .tx-* 通用样式（见下方）。 */
/* ---------- 交易行通用片段：最近交易与明细弹窗共用（改交易行观感只需动这里）---------- */
.tx-crow{display:flex;align-items:center;gap:8px;min-width:0;white-space:nowrap}
.tx-crow .cd{width:9px;height:9px;border-radius:3px;flex:none}
.tx-crow .cnm{overflow:hidden;text-overflow:ellipsis;flex:0 1 auto;min-width:0;font-weight:500}
.tx-crow .pill{flex:none}
.tx-crow .pill:not(:first-child){margin-left:2px}
.tx-crow .amt{margin-left:auto;font-weight:600;font-size:13.5px;font-variant-numeric:tabular-nums;flex:none}
.tx-crow .amt.in{color:var(--green)}
.tx-crow .amt.out{color:var(--rose)}
.tx-crow .amt.tr{color:var(--accent)}
.tx-mrow{display:flex;align-items:center;gap:6px;width:100%;max-width:100%;min-width:0;overflow:hidden;color:var(--text-sub);font-size:12px}
.tx-mrow .meta1{display:block;flex:1 1 0;width:0;min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tx-mrow .tm{margin-left:auto;flex:none;color:var(--text-dim);white-space:nowrap}
/* ---------- 状态 ---------- */
.skel{position:relative;overflow:hidden;background:var(--bg-soft);border-radius:12px}
.skel::after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.25),transparent);animation:sh 1.3s infinite}
@keyframes sh{to{transform:translateX(100%)}}
.errorbox{grid-column:1/-1;text-align:center;padding:70px 20px;color:var(--text-sub)}
.errorbox .big{font-size:44px;margin-bottom:14px}
.errorbox p{margin-bottom:18px;font-size:14px}
.errorbox button{border:0;background:var(--accent);color:#fff;font-size:14px;padding:10px 26px;border-radius:10px;cursor:pointer;font-family:inherit}
.empty{display:flex;align-items:center;justify-content:center;height:100%;min-height:120px;color:var(--text-dim);font-size:13px}
footer{text-align:center;font-size:12px;color:var(--text-dim);margin-top:26px;line-height:1.9}
/* ---------- 一句话记账：FAB + 弹层 + 确认卡 ---------- */
.toast{position:fixed;left:50%;top:calc(18px + env(safe-area-inset-top));transform:translate(-50%,0);z-index:200;background:var(--card);border:1px solid var(--card-border);color:var(--text);font-size:13px;font-weight:600;padding:10px 18px;border-radius:999px;box-shadow:0 8px 24px rgba(20,35,80,.18);animation:toastIn .3s ease both}
@keyframes toastIn{from{opacity:0;transform:translate(-50%,8px)}to{opacity:1;transform:translate(-50%,0)}}
.fab{position:fixed;right:16px;bottom:calc(96px + env(safe-area-inset-bottom));z-index:75;min-width:54px;height:54px;padding:0 18px;border:0;border-radius:27px;background:var(--accent);display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;box-shadow:0 10px 26px rgba(63,102,248,.32);transition:transform .2s;font-family:inherit;color:#fff;font-size:14px;font-weight:700}
.fab:active{transform:scale(.92)}
.fab svg{width:24px;height:24px}
.fab span{white-space:nowrap}
/* FAB 全端显示：桌面右下角同样悬浮（编辑交易入口在流水的每一行） */
@media (min-width:721px){.fab{right:28px;bottom:28px}}
.add-mask{position:fixed;inset:0;background:rgba(10,16,40,.5);backdrop-filter:blur(3px);z-index:90;display:flex;align-items:center;justify-content:center;padding:16px}
.add-mask[hidden]{display:none}
.add-sheet{width:100%;max-width:420px;max-height:86vh;overflow-y:auto;background:var(--card);border:1px solid var(--card-border);border-radius:18px;padding:16px 16px 14px;box-shadow:var(--hover-lift);animation:rise .3s ease both}
.add-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.add-head h3{font-size:15.5px;font-weight:700}
.ai-row{display:flex;gap:8px;margin-bottom:8px}
.ai-row input{flex:1;min-width:0;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:14px;padding:11px 12px;border-radius:12px;outline:none;font-family:inherit;transition:border-color .18s}
.ai-row input:focus{border-color:var(--accent);background:var(--card)}
.ai-btn{flex:none;border:0;border-radius:12px;padding:0 16px;font-size:13px;font-weight:600;color:#fff;background:linear-gradient(135deg,var(--accent),var(--purple));cursor:pointer;font-family:inherit;transition:opacity .18s}
.ai-btn:hover{opacity:.92}
.ai-btn:disabled{opacity:.55;cursor:not-allowed}
.ai-hint{font-size:11px;color:var(--text-dim);margin-bottom:10px;line-height:1.5}
.ai-result[hidden]{display:none}
.ai-card{background:var(--bg-soft);border:1px solid var(--card-border);border-radius:14px;padding:12px 14px;margin-bottom:10px}
.aic-head{display:block;margin-bottom:8px}
/* 交易类型切换（支出/收入/转账）：整行三等分，选中态＝各类型语义色淡底＋同色细描边 */
.aic-seg{display:flex;gap:3px;background:var(--card);border:1px solid var(--card-border);border-radius:11px;padding:3px}
.aic-seg button{flex:1;min-width:0;border:0;background:transparent;color:var(--text-sub);font-size:13px;font-weight:500;padding:7px 0;border-radius:8px;cursor:pointer;font-family:inherit;line-height:1.35;transition:background-color .18s,color .18s;-webkit-tap-highlight-color:transparent;user-select:none}
.aic-seg button:hover{color:var(--text)}
.aic-seg button.on{font-weight:600}
.aic-seg button.on[data-t="3"]{background:rgba(239,77,110,.14);color:var(--rose);box-shadow:inset 0 0 0 1px rgba(239,77,110,.26)}
.aic-seg button.on[data-t="2"]{background:rgba(24,167,104,.14);color:var(--green);box-shadow:inset 0 0 0 1px rgba(24,167,104,.26)}
.aic-seg button.on[data-t="4"]{background:rgba(122,90,245,.14);color:var(--purple);box-shadow:inset 0 0 0 1px rgba(122,90,245,.26)}
/* ¥ 随所选类型着色：让「记什么」与「记多少」产生视觉关联 */
.ai-result[data-t="3"] .aic-amt .cur{color:var(--rose)}
.ai-result[data-t="2"] .aic-amt .cur{color:var(--green)}
.ai-result[data-t="4"] .aic-amt .cur{color:var(--purple)}
.aic-cat{font-size:12.5px;color:var(--text-sub)}
/* 金额＝卡片里唯一的「主输入」。给它和其它字段同一套白底描边（形状一致才一眼看出可输入），
   但用更大字号＋松内距保住主字段地位；聚焦 accent 描边＋柔光环强化「这里能打字」 */
.aic-amt{display:flex;align-items:baseline;gap:8px;margin-bottom:10px;background:var(--card);border:1px solid var(--card-border);border-radius:9px;padding:8px 11px;transition:border-color .18s,box-shadow .18s}
.aic-amt:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.aic-amt .cur{font-size:19px;font-weight:600;color:var(--text-sub);flex:none;line-height:1}
.aic-amt input{flex:1;border:0;outline:0;background:transparent;color:var(--text);font-size:24px;font-weight:700;font-family:inherit;font-variant-numeric:tabular-nums;min-width:0;line-height:1.2}
.aic-amt input::placeholder{color:var(--text-dim);font-weight:500}   /* 比真值更轻，避免把占位当金额 */
/* 选择行：图标徽章 + 选择框 */
.pick-row, .aic-fields label{display:flex;align-items:center;gap:8px}
/* 分区标题（分类/账户） */
.asec{margin-bottom:10px}
.asec-t{font-size:11.5px;font-weight:600;color:var(--text-dim);margin-bottom:6px;letter-spacing:1px}
/* 账户分组选择器：⚡常用 + 按账户大类分组的胶囊流 */
.acct-groups{max-height:170px;overflow-y:auto;border:1px solid var(--card-border);border-radius:12px;background:var(--bg-soft);padding:8px 10px}
.acct-groups::-webkit-scrollbar{width:4px}
.acct-groups::-webkit-scrollbar-thumb{background:var(--card-border);border-radius:2px}
.ag-head{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:var(--text-dim);padding:6px 2px 4px}
.ag-head .agi{font-size:13px}
.ag-flow{display:flex;flex-wrap:wrap;gap:5px;padding-bottom:4px}
.ag-acct{border:1px solid var(--card-border);background:var(--card);color:var(--text-sub);font-size:12px;padding:5px 11px;border-radius:999px;cursor:pointer;font-family:inherit;transition:.15s;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ag-acct.on{border-color:var(--accent);background:var(--accent-soft);color:var(--accent);font-weight:600}
.ag-acct .an-l{font-size:10px;opacity:.7;margin-right:3px}
/* 时间+备注行 */
/* 分类/账户 · 只读输入框 + 底部双栏选择器 */
.pk-input{flex:1;min-width:0;text-align:left;cursor:pointer;font-weight:600;color:var(--text)}
.pk-input::placeholder{font-weight:400;color:var(--text-dim)}
.pick-mask{position:fixed;inset:0;background:rgba(10,16,40,.5);backdrop-filter:blur(2px);z-index:110}
.pick-mask[hidden]{display:none}
.pick-sheet{position:fixed;left:0;right:0;bottom:0;z-index:111;margin:0 auto;max-width:480px;height:min(62vh,460px);display:flex;flex-direction:column;background:var(--card);border:1px solid var(--card-border);border-bottom:0;border-radius:18px 18px 0 0;overflow:hidden;box-shadow:var(--hover-lift);animation:rise .3s ease both;padding:14px 14px 8px}
.pick-sheet[hidden]{display:none}
.pick-sheet.tag-mode{height:auto;max-height:min(62vh,460px)}   /* 标签是平铺列表，内容少时窗口自适应不撑满 */
@media (min-width:721px){
  /* 桌面端使用居中弹窗；手机端保留底部抽屉，避免宽屏出现不自然的贴底面板。 */
  .pick-sheet{left:50%;top:50%;right:auto;bottom:auto;width:min(560px,calc(100vw - 40px));max-width:none;height:min(68vh,560px);border-bottom:1px solid var(--card-border);border-radius:18px;padding:18px 18px 14px;transform:translate(-50%,-50%);animation:pickCenterIn .24s ease both}
  .pick-sheet.tag-mode{height:auto;max-height:min(68vh,560px)}
  @keyframes pickCenterIn{from{opacity:0;transform:translate(-50%,calc(-50% + 10px))}to{opacity:1;transform:translate(-50%,-50%)}}
}
.pick-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-left:2px;font-size:15.5px;font-weight:700;color:var(--text);flex:none}
.pick-close{border:0;background:var(--bg-soft);width:30px;height:30px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-sub);font-size:13px}
.pick-cols{display:flex;flex:1;min-height:0;border:1px solid var(--card-border);border-radius:12px;overflow:hidden}
.pick-left{flex:0 0 44%;overflow-y:auto;overscroll-behavior:contain;touch-action:pan-y;-webkit-overflow-scrolling:touch;border-right:1px solid var(--card-border);background:var(--bg);padding:6px 0}
.pick-right{flex:1;overflow-y:auto;overscroll-behavior:contain;touch-action:pan-y;-webkit-overflow-scrolling:touch;background:var(--card);padding:6px 0}
.pk-g{display:flex;align-items:center;gap:8px;padding:12px 12px;font-size:13px;color:var(--text-sub);cursor:pointer}
/* 自定义图标：圆角方块 + object-fit:contain。圆形会切掉方图四角、cover 会裁掉非 1:1 的图，都会被看成「显示不完整」 */
.pk-g img,.pk-i img{width:22px;height:22px;border-radius:6px;object-fit:contain;flex:none;background:var(--bg-soft)}
.pk-g .ico-fb,.pk-i .ico-fb,.pk-g .ico-dot{width:22px;height:22px;border-radius:6px;flex:none;font-size:11px;display:inline-flex;align-items:center;justify-content:center;background:var(--bg-soft)}
.pk-g .ico-cat{width:22px;height:22px;border-radius:6px;flex:none;display:inline-flex;align-items:center;justify-content:center;background:var(--bg-soft);color:var(--text-sub)}
.pk-g .ico-cat svg{width:15px;height:15px;display:block;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.pk-g.on .ico-cat{color:var(--accent);background:var(--accent-soft)}
.pk-g .pk-gl{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pk-g .arr{opacity:.45;font-size:12px}
.pk-g.on{background:var(--card);color:var(--accent);font-weight:600;box-shadow:inset 3px 0 0 var(--accent)}
.pk-i{display:flex;align-items:center;gap:8px;padding:12px 14px;font-size:13px;color:var(--text);cursor:pointer}
.pk-i.on{color:var(--accent);font-weight:600;background:var(--accent-soft)}
.pk-i.pk-all{color:var(--accent);font-weight:600;border-bottom:1px solid var(--card-border);background:var(--bg-soft)}
.pk-i.pk-all.on{background:var(--accent-soft)}
/* 标签选择（多选）：胶囊网格 + 内联新建；复用 pick-sheet，隐藏左栏让右栏占满 */
.pk-hr{display:flex;align-items:center;gap:10px}
.pk-sub{font-size:11.5px;color:var(--text-dim);font-weight:500}
.pick-close.pk-done{width:auto;height:30px;padding:0 14px;background:var(--accent);color:#fff;font-size:12.5px;font-weight:600}
.pk-tags{display:flex;flex-wrap:wrap;gap:8px;padding:14px}
.pk-tag{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--card-border);background:var(--card);border-radius:999px;padding:6px 13px;font-size:12.5px;color:var(--text-sub);cursor:pointer;transition:background-color .18s,color .18s,border-color .18s;-webkit-tap-highlight-color:transparent;user-select:none}
.pk-tag:hover{color:var(--text)}
.pk-tag.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent);font-weight:600}
.pk-tag .ck{font-size:10.5px;line-height:1}
.pk-tag.new{border-style:dashed;color:var(--text-dim)}
.pk-tag.new:hover{color:var(--accent);border-color:var(--accent)}
.pk-tagin{flex:1;min-width:150px;border:1px solid var(--accent);background:var(--card);border-radius:12px;padding:6px 13px;font-size:12.5px;color:var(--text);outline:none;font-family:inherit}   /* 输入框用圆角矩形而非全圆：与胶囊标签区分，且整行宽时不成胶囊状 */
.pk-tagin::placeholder{color:var(--text-dim)}
.pk-empty{width:100%;font-size:12px;color:var(--text-dim);padding:0 2px 6px}
.aic-fields{display:flex;flex-direction:column;gap:8px}
.aic-fields label{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-dim)}
.aic-fields label>span,.aic-fields label>select,.aic-fields label>input{flex:1;min-width:0}
.aic-fields select,.aic-fields input{border:1px solid var(--card-border);background:var(--card);color:var(--text);font-size:13px;padding:8px 10px;border-radius:9px;outline:none;font-family:inherit}
.aic-fields input[type="datetime-local"]{color-scheme:light dark}
.aic-fields input:focus,.aic-fields select:focus{border-color:var(--accent)}
/* 弹层内加载遮罩（保存/删除中的视觉反馈） */
.add-sheet{position:relative}
.sheet-loading{position:absolute;inset:0;background:rgba(255,255,255,.82);backdrop-filter:blur(2px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;z-index:5;border-radius:18px}
.sheet-loading[hidden]{display:none}
html[data-theme="dark"] .sheet-loading{background:rgba(15,22,45,.82)}
.spin2{width:28px;height:28px;border:3px solid var(--accent-soft);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
.sheet-loading .t{font-size:12.5px;color:var(--text-sub);font-weight:500}
.add-err{color:var(--rose);font-size:12.5px;margin:0 0 8px}
.add-err[hidden]{display:none}
.ai-account-hint{font-size:12px;line-height:1.5;margin:0 0 8px;padding:7px 9px;border-radius:8px;color:var(--orange);background:rgba(242,128,58,.1);border:1px solid rgba(242,128,58,.25)}
.ai-account-hint[data-tone="ok"]{color:var(--green);background:rgba(24,167,104,.1);border-color:rgba(24,167,104,.25)}
.ai-account-hint[hidden]{display:none}
/* 编辑模式：删除+保存并排 */
.add-btns{display:flex;gap:10px}
.add-del{flex:none;width:64px;border:1px solid rgba(239,77,110,.4);background:rgba(239,77,110,.08);color:var(--rose);font-size:13.5px;font-weight:600;border-radius:12px;cursor:pointer;font-family:inherit;transition:.18s}
.add-del:hover{background:rgba(239,77,110,.16)}
.add-del[hidden]{display:none}
.add-submit{flex:1;border:0;font-size:14.5px;font-weight:600;padding:12px;border-radius:12px;cursor:pointer;font-family:inherit;background:linear-gradient(135deg,var(--accent),var(--purple));color:#fff;letter-spacing:4px;transition:opacity .18s}
.add-submit:hover{opacity:.92}
.add-submit:disabled{opacity:.55;cursor:not-allowed}
/* 流水行可点击（编辑入口） */
#dayTxWrap .cd-row{cursor:pointer}
/* 表头提示：收起态说明可点开 */
#dayTxHint{font-size:11px}

/* ---------- 登录页 ---------- */
.login-view{display:flex;align-items:center;justify-content:center;min-height:70vh;padding:20px}
.login-card{width:100%;max-width:400px;background:var(--card);border:1px solid var(--card-border);border-radius:18px;box-shadow:var(--hover-lift);padding:34px 32px;animation:rise .5s ease both}
.login-head{text-align:center;margin-bottom:24px}
.login-head .logo{width:52px;height:52px;border-radius:15px;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.login-head .logo svg{width:26px;height:26px;stroke:#fff}
.login-head h2{font-size:20px;font-weight:700;letter-spacing:.5px}
.login-head p{font-size:12.5px;color:var(--text-sub);margin-top:5px}
.login-demo-notice{font-size:12px;line-height:1.65;color:var(--text-sub);background:var(--accent-soft);border:1px solid var(--accent-soft);border-radius:10px;padding:10px 12px;margin:-4px 0 14px}
.login-demo-notice code{font-family:inherit;color:var(--accent);font-weight:650;white-space:nowrap}
.login-tabs{display:flex;background:var(--bg-soft);border:1px solid var(--card-border);border-radius:11px;padding:3px;margin-bottom:18px}
.login-tabs button{flex:1;border:0;background:transparent;color:var(--text-sub);font-size:13.5px;padding:9px 0;border-radius:8px;cursor:pointer;transition:.18s;font-family:inherit}
.login-tabs button.on{background:var(--card);color:var(--accent);font-weight:600;box-shadow:var(--shadow)}
.login-fields input,.login-fields textarea{width:100%;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:14px;padding:12px 14px;border-radius:10px;margin-bottom:12px;outline:none;transition:.18s;font-family:inherit;resize:none}
.login-fields input:focus,.login-fields textarea:focus{border-color:var(--accent);background:var(--card)}
.login-error{color:var(--rose);font-size:12.5px;margin:-2px 0 12px;text-align:center;line-height:1.5}
.login-btn{width:100%;border:0;background:linear-gradient(135deg,var(--accent),var(--purple));color:#fff;font-size:15px;font-weight:600;padding:12px;border-radius:11px;cursor:pointer;transition:.18s;font-family:inherit;letter-spacing:2px;margin-bottom:10px}
.login-btn:hover{opacity:.92;transform:translateY(-1px)}
.login-btn:disabled{opacity:.6;cursor:not-allowed}
.login-btn.default{background:var(--bg-soft);color:var(--text-sub);border:1px solid var(--card-border);font-size:13px;letter-spacing:1px}
.login-btn.default:hover{color:var(--text);border-color:var(--accent);opacity:1}
.login-hint{font-size:11.5px;color:var(--text-dim);text-align:center;margin-top:14px;line-height:1.6}
/* donut 切换 */
.miniseg{display:flex;gap:2px;background:var(--bg-soft);border-radius:8px;padding:2px}
.miniseg button{border:0;background:transparent;color:var(--text-sub);font-size:11.5px;padding:3.5px 10px;border-radius:6px;cursor:pointer;font-family:inherit}
.miniseg button.on{background:var(--card);color:var(--accent);font-weight:600;box-shadow:var(--shadow)}
.headtools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.minibtn{border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text-sub);font-size:11px;padding:3px 9px;border-radius:7px;cursor:pointer;font-family:inherit;transition:color .15s,border-color .15s}
.minibtn:hover{color:var(--accent);border-color:var(--accent)}
/* 统计页区间选择条 */
.stats-range{display:flex;justify-content:flex-start;margin-bottom:14px}
.stats-range .seg button{font-size:12.5px;padding:6px 13px}
@media (max-width:720px){
  .stats-range{margin-bottom:10px}
}
.sk-zoom-btn{display:none;flex:none;width:26px;height:26px;padding:0;border-radius:8px;margin-left:auto;align-items:center;justify-content:center}
/* ---------- 桑基图全屏弹窗 ---------- */
.sk-modal-holder{position:fixed;inset:0;z-index:80;display:flex}
.sk-modal-holder[hidden]{display:none}   /* 覆盖 .sk-modal-holder 的 display:flex（grid 内 hidden 属性会被布局覆盖） */
.sk-modal{flex:1;display:none;flex-direction:column;background:var(--bg);padding:14px 12px calc(14px + env(safe-area-inset-bottom))}
/* 关闭按钮独立于旋转内容：挂在 holder 上（holder 无 transform），任何旋转状态下位置都固定可控。
   默认（横屏/桌面）右上角；force-ls 旋转态放竖屏左上角——手机逆时针横握（顶部朝左）后正好是用户视角的右上角 */
.sk-modal-holder .m-close{position:absolute;z-index:95;top:calc(10px + env(safe-area-inset-top));right:10px;width:34px;height:34px;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text-sub);border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.12)}
.sk-modal-holder .m-close:hover{color:var(--accent);border-color:var(--accent)}
.sk-modal-holder.force-ls .m-close{top:10px;right:auto;left:10px}
/* 强制横屏：竖屏持握时内容顺时针旋转 90°（宽度=视口高），手机逆时针横过来（顶部朝左）看即桌面版效果，
   不依赖系统自动旋转。几何推导：盒子置于 top:0;left:100%，绕左上角 rotate(90deg) 后恰好铺满视口；
   动画必须关闭：rise 的 fill-mode:both 终帧 transform:none 会覆盖 rotate(90deg)。
   padding-left 加大给不旋转的悬浮关闭按钮留位 */
.sk-modal-holder.force-ls{overflow:hidden}
.sk-modal-holder.force-ls .sk-modal{display:flex;position:absolute;top:0;left:100%;width:100vh;height:100vw;transform:rotate(90deg);transform-origin:0 0;padding:10px 10px 10px 44px;animation:none}
.sk-modal.on{display:flex;animation:rise .28s ease both}
.sk-modal .m-head{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex:none}
.sk-modal .m-head h3{font-size:15px;font-weight:600;margin:0}
.sk-modal .m-head .hint{font-size:11.5px;color:var(--text-dim);margin-left:auto}
.sk-modal .m-chart{flex:1;min-height:0;position:relative}
.sk-modal .m-chart>div{position:absolute;inset:0}
/* 强制横屏提示条：悬浮在图表上层，2.6s 后自动淡出，不占布局空间 */
.m-lstip{position:absolute;z-index:90;left:50%;top:calc(16px + env(safe-area-inset-top));transform:translateX(-50%);display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:9px;background:var(--bg-soft);border:1px dashed var(--card-border);color:var(--text-sub);font-size:11.5px;box-shadow:0 2px 10px rgba(0,0,0,.12);pointer-events:none;opacity:1;transition:opacity .5s}
.m-lstip.fade{opacity:0}
@media (max-width:720px){
  .sk-zoom-btn{display:flex}
  .sk-modal-holder.force-ls .sk-modal{padding:8px 10px}
}
@media (max-width:720px) and (orientation:landscape){
  .sk-modal-holder.force-ls .sk-modal{position:static;width:auto;height:auto;transform:none}
  .sk-modal-holder.force-ls .m-close{right:10px;left:auto}
  .sk-modal-holder.force-ls .m-lstip{display:none}
}
/* ---------- 自定义日期面板 ---------- */
.dp-mask{position:fixed;inset:0;background:rgba(10,16,40,.45);backdrop-filter:blur(2px);z-index:60;display:flex;align-items:center;justify-content:center;padding:16px}
.dp-card{width:100%;max-width:380px;background:var(--card);border:1px solid var(--card-border);border-radius:16px;box-shadow:var(--hover-lift);padding:22px 20px 18px;animation:rise .3s ease both}
.dp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.dp-head h3{font-size:15.5px;font-weight:700}
.dp-close{border:0;background:var(--bg-soft);width:30px;height:30px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-sub)}
.dp-close:hover{color:var(--text)}
.dp-close svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round}
.dp-fields{display:flex;align-items:flex-end;gap:8px;margin-bottom:6px}
.dp-fields label{flex:1;display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--text-sub)}
.dp-fields input[type=date]{width:100%;border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text);font-size:13.5px;padding:9px 10px;border-radius:9px;outline:none;transition:.18s;font-family:inherit;color-scheme:light dark}
.dp-fields input[type=date]:focus{border-color:var(--accent);background:var(--card)}
.dp-sep{font-size:12.5px;color:var(--text-dim);padding-bottom:10px;flex:none}
.dp-err{color:var(--rose);font-size:12.5px;margin:2px 0 4px}
.dp-quick{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 16px}
.dp-quick button{border:1px solid var(--card-border);background:var(--bg-soft);color:var(--text-sub);font-size:12px;padding:5.5px 11px;border-radius:8px;cursor:pointer;font-family:inherit;transition:.18s}
.dp-quick button:hover{color:var(--accent);border-color:var(--accent)}
.dp-btns{display:flex;gap:10px}
.dp-btns button{flex:1;border:0;font-size:13.5px;font-weight:600;padding:10px;border-radius:10px;cursor:pointer;font-family:inherit;transition:.18s}
.dp-cancel{background:var(--bg-soft);color:var(--text-sub)}
.dp-cancel:hover{color:var(--text)}
.dp-apply{background:linear-gradient(135deg,var(--accent),var(--purple));color:#fff;letter-spacing:2px}
.dp-apply:hover{opacity:.92}
/* ===== 设置窗口 ===== */
.sm-mask{z-index:120}
.sm-card{max-width:400px;padding:20px 18px 16px;max-height:min(88vh,820px);overflow-y:auto;overscroll-behavior:contain}
/* 头部吸附：内容变长后滚动时「设置 / ✕」始终可见 */
.sm-card .dp-head{position:sticky;top:-20px;margin:-20px -18px 12px;padding:20px 18px 10px;background:var(--card);z-index:3}
#setAiCustom{border-top:1px solid var(--card-border)}
.mc-mask{z-index:130}
/* A2 预算弹窗：夹在设置窗 120 与映射编辑 125 之间（层级约定见技能 §16b-1 / §UI 约定） */
.bud-mask{z-index:104}
.mc-card{max-width:340px;padding:20px 18px 16px}
.mc-msg{font-size:13px;color:var(--text-sub);line-height:1.65;margin-bottom:16px}
#miniOk.danger{background:linear-gradient(135deg,#f2647f,var(--rose))}
.set-sec{margin-top:14px}
.set-sec-t{font-size:11.5px;font-weight:700;letter-spacing:.6px;color:var(--text-dim);margin:0 2px 7px}
.set-group{background:var(--bg-soft);border:1px solid var(--card-border);border-radius:12px;overflow:hidden}
.set-row{display:flex;align-items:center;gap:12px;padding:11px 13px;border-top:1px solid var(--card-border)}
.set-row:first-child{border-top:0}
.set-row .sr-txt{flex:1;min-width:0}
.set-row .sr-name{font-size:13.5px;font-weight:600;color:var(--text)}
.set-row .sr-desc{font-size:11.5px;color:var(--text-sub);margin-top:2px;line-height:1.45}
.set-row .sr-desc[data-tone="ok"]{color:var(--green)}
.set-row .sr-desc[data-tone="warn"]{color:var(--orange)}
.set-btn{border:1px solid var(--card-border);background:var(--card);color:var(--text);font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:9px;cursor:pointer;font-family:inherit;flex:none;transition:.18s;white-space:nowrap}
.set-btn:hover{border-color:var(--accent);color:var(--accent)}
.set-btn:disabled{opacity:.55;cursor:default}
.set-btn.danger{color:var(--rose);border-color:rgba(239,77,110,.34)}
.set-btn.danger:hover{background:rgba(239,77,110,.1);border-color:var(--rose);color:var(--rose)}
.set-seg{display:flex;gap:3px;background:var(--card);border:1px solid var(--card-border);border-radius:10px;padding:3px;flex:none}
.set-seg button{border:0;background:transparent;color:var(--text-sub);font-size:12.5px;font-family:inherit;font-weight:500;padding:6px 11px;border-radius:7px;cursor:pointer;transition:.16s;white-space:nowrap}
.set-seg button.on{background:var(--accent-soft);color:var(--accent);font-weight:600;box-shadow:inset 0 0 0 1px rgba(63,102,248,.34)}
/* 识别方式两项文案长短不一（跟随上游/自定义）→ 定宽保证等宽 */
#setAiMode button{min-width:74px;text-align:center}
.set-foot{margin-top:15px;text-align:center;font-size:11px;color:var(--text-dim);letter-spacing:.3px}
/* AI 识别分组 */
.set-field{display:block;padding:11px 13px;border-top:1px solid var(--card-border)}
.set-field:first-child{border-top:0}
.set-field .fl{display:block;font-size:12.5px;font-weight:600;color:var(--text);margin-bottom:6px}
.set-input{width:100%;box-sizing:border-box;background:var(--card);border:1px solid var(--card-border);border-radius:9px;padding:8px 11px;font-size:13px;color:var(--text);font-family:inherit;outline:none;transition:border-color .18s,box-shadow .18s}
.set-input::placeholder{color:var(--text-dim)}
.set-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.set-keywrap{display:flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--card-border);border-radius:9px;padding-right:5px;transition:border-color .18s,box-shadow .18s}
.set-keywrap:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.set-keywrap .set-input{border:0;border-radius:9px 0 0 9px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.4px}
.set-keywrap .set-input:focus{box-shadow:none}
.set-mini{border:0;background:transparent;color:var(--accent);font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;padding:6px;flex:none;border-radius:7px;white-space:nowrap}
.set-mini:hover{background:var(--accent-soft)}
.set-hint{display:block;font-size:11.5px;color:var(--text-dim);line-height:1.5;margin-top:5px}
.set-hint[data-tone="ok"]{color:var(--green)}
.set-hint[data-tone="err"]{color:var(--rose)}
/* 标题行右侧挂外部引导链接（如「去获取」） */
.set-field .fl.fl-act{display:flex;align-items:center;justify-content:space-between;gap:8px}
.set-link{color:var(--accent);font-weight:600;text-decoration:none;white-space:nowrap;transition:opacity .18s}
.set-link:hover{text-decoration:underline;opacity:.85}
.set-btn.primary{background:linear-gradient(135deg,var(--accent),var(--purple));border-color:transparent;color:#fff}
.set-btn.primary:hover{opacity:.92;color:#fff;border-color:transparent}
.set-btn.primary:disabled{opacity:.55}
@media (max-width:430px){
  /* 窄屏：带三档选择器的行改为上下布局，避免文字被挤成细条 */
  .set-row:has(.set-seg){flex-wrap:wrap;gap:9px}
  .set-row:has(.set-seg) .sr-txt{flex:1 1 100%}
  .set-row:has(.set-seg) .set-seg{width:100%}
  .set-row:has(.set-seg) .set-seg button{flex:1}
}
/* 设置里的「分类映射」行：整行可点，右侧一个箭头做可点暗示 */
.set-row-btn{width:100%;box-sizing:border-box;border:0;background:transparent;font-family:inherit;text-align:left;cursor:pointer;transition:background .16s}
.set-row-btn:hover{background:var(--card)}
.set-row-btn:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.set-chev{width:16px;height:16px;flex:none;stroke:var(--text-dim);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;transition:transform .16s,stroke .16s}
.set-row-btn:hover .set-chev{stroke:var(--accent);transform:translateX(2px)}
/* ===== 分类映射编辑窗口 ===== */
/* z-index 夹在设置窗口(120)与轻确认(130)之间：能盖住设置，又让轻确认能盖住自己 */
.mp-mask{z-index:125}
.mp-card{max-width:560px;max-height:min(90vh,780px);display:flex;flex-direction:column;padding:20px 18px 14px;overflow:hidden}
.mp-card .dp-head{margin-bottom:10px}
.mp-bar{display:flex;align-items:center;gap:8px;margin-bottom:9px;flex:none}
.mp-bar .set-seg{flex:none}
.mp-search{flex:1;min-width:0}
/* 说明与空态是「要读的内容」，用 text-sub(5.1:1)，不是 text-dim 那档装饰用弱色(3.2:1) */
.mp-tip{font-size:11.5px;color:var(--text-sub);line-height:1.5;margin-bottom:10px;flex:none}
.mp-tip[data-tone="warn"]{color:var(--orange)}
.mp-list{flex:1;min-height:110px;overflow-y:auto;overscroll-behavior:contain;border:1px solid var(--card-border);border-radius:11px;background:var(--bg-soft)}
/* 分组标题是「读到哪一步」的结构信息，用 text-sub 而非更淡的 text-dim（暗色下 text-dim 只有 3.3:1，读不动） */
.mp-gh{position:sticky;top:0;z-index:2;background:var(--bg-soft);font-size:11.5px;font-weight:700;letter-spacing:.4px;color:var(--text-sub);padding:8px 12px 5px;border-bottom:1px solid var(--card-border)}
.mp-row{padding:9px 12px;border-top:1px solid var(--card-border)}
.mp-gh+.mp-row{border-top:0}
.mp-row.dirty{background:var(--card)}
.mp-rh{display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap}
.mp-sub{font-size:13px;font-weight:600;color:var(--text)}
.mp-tag{font-size:10.5px;font-weight:600;padding:2px 6px;border-radius:5px;white-space:nowrap;border:1px solid transparent}
.mp-tag.pend{background:rgba(242,128,58,.13);color:var(--orange);border-color:rgba(242,128,58,.3)}
.mp-tag.mod{background:var(--accent-soft);color:var(--accent);border-color:rgba(63,102,248,.3)}
.mp-tag.idle{background:var(--card);color:var(--text-sub);border-color:var(--card-border)}
.mp-rev{margin-left:auto;border:0;background:transparent;color:var(--accent);font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;padding:3px 7px;border-radius:6px;white-space:nowrap}
.mp-rev:hover{background:var(--accent-soft)}
/* 触发词列表动辄一两行放不下，固定 rows=2 会把内容裁掉（还要手动拖右下角才能看全）；
   改为按内容自动长高（超过 220px 才内部滚动），resize 就没必要了 */
.mp-ta{display:block;width:100%;box-sizing:border-box;background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:7px 9px;font-size:12.5px;line-height:1.55;color:var(--text);font-family:inherit;outline:none;resize:none;overflow:hidden;transition:border-color .16s,box-shadow .16s}
.mp-ta::placeholder{color:var(--text-dim)}
.mp-ta:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.mp-empty{font-size:12.5px;color:var(--text-sub);text-align:center;padding:28px 12px}
.mp-foot{display:flex;align-items:center;gap:10px;margin-top:11px;flex:none}
.mp-count{flex:1;min-width:0;font-size:11.5px;color:var(--text-sub);line-height:1.45}
.mp-count[data-tone="warn"]{color:var(--orange)}
.mp-acts{display:flex;gap:8px;flex:none}
@media (max-width:520px){
  .mp-bar{flex-wrap:wrap}
  .mp-bar .set-seg{width:100%}
  .mp-bar .set-seg button{flex:1}
  .mp-search{width:100%}
  .mp-card{padding:18px 14px 12px}
}
@media (max-width:1000px){
  .grid{grid-template-columns:1fr}
  .grid .span2{grid-column:span 1}
  /* minmax(0,1fr)：1fr 默认最小尺寸 auto，KPI 金额 nowrap 一长就把轨道撑破产生横向滚动 */
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .kpis>*{min-width:0}
}
/* ---------- 平板 / 手机（≤720px）---------- */
@media (max-width:720px){
  .wrap{padding:16px 12px 36px}
  .card{padding:16px 14px;border-radius:13px}
  /* 顶栏：logo 行单独一行，控件换行排列 */
  .topbar{gap:10px;margin-bottom:16px}
  .brand h1{font-size:17px}
  .brand .sub{display:none}                 /* 挤，隐藏副标题 */
  .iconbtn{width:40px;height:40px}          /* 触摸目标 40px+ */
  /* 手机已有底部「流水」入口，顶部搜索会重复占位；进入流水页后使用页内搜索框。 */
  #txsEntryBtn{display:none}
  /* 记一笔保留熟悉的加号，但收紧为不遮挡内容的 48px 触控圆钮。 */
  .fab{width:48px;height:48px;min-width:48px;padding:0;border-radius:50%;right:14px;bottom:calc(84px + env(safe-area-inset-bottom))}
  .fab span{display:none}
  .fab svg{width:22px;height:22px}
  /* 流水快捷筛选：类型占剩余空间，操作按钮保持内容宽度，避免中文逐字折行。 */
  .txs-quick{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:stretch;gap:6px}
  .txs-quick .miniseg{min-width:0;margin-right:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2px}
  .txs-quick .miniseg button{min-width:0;padding:5px 3px;font-size:12px;white-space:nowrap}
  .txs-quick>.minibtn{padding-left:8px;padding-right:8px;white-space:nowrap}
  /* 统计页区间选择：通栏平铺（已挪入统计页顶部） */
  .stats-range .seg{width:100%;overflow:visible}
  .stats-range .seg button{flex:1;padding:7px 4px;text-align:center;white-space:nowrap}
  /* KPI：两列保持，数字缩小防溢出；minmax(0,1fr) 防长金额撑破轨道 */
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .kpis>*{min-width:0}
  .kpi .num{font-size:20px;letter-spacing:0;overflow:hidden;text-overflow:ellipsis}
  .kpi .num .t{display:block;overflow:hidden;text-overflow:ellipsis}
  .kpi .label{font-size:11.5px}
  .kpi .foot{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  /* 日均卡：移动端只显示「全月约 ¥x」，桌面显示完整「按 N 天平均 · 全月约」 */
  .dayavg-base{display:none}
  /* 移动端 TabBar：桌面页签隐藏、页脚与滚动留出悬浮玻璃条高度 */
  .pagetabs{display:none}
  .wrap{padding-bottom:calc(104px + env(safe-area-inset-bottom))}
  /* 财务健康度：2×2 平铺，数字缩小防溢出 */
  .health-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .health-it{padding:12px 13px 11px}
  .health-it .hv{font-size:18px}
  /* 图表高度收紧 */
  .chart{height:250px}
  .chart.tall{height:260px}
  /* 分类/账户行内长名省略 */
  .rankrow .nm{max-width:30vw}
  .acctrow .nm{max-width:44vw}
  /* 触控目标：眼睛按钮放大到 30px（桌面 23px 太小） */
  .eye{width:30px;height:30px}
  /* 桑基图汇总条：单行三列收紧（默认 flex-basis 150px 会在手机上折成 2+1 行） */
  .skpi{gap:8px;margin-bottom:8px}
  .skpi .it{flex:1 1 0;padding:9px 11px;border-radius:10px}
  .skpi .it b{font-size:11px;margin-bottom:1px}
  .skpi .it .v{font-size:15.5px}
  footer{padding:0 6px;word-break:break-all}
}
@media (max-width:360px){
  /* 320px 左右的窄屏：筛选类型独占一行，右侧操作按钮放第二行。 */
  .txs-quick{grid-template-columns:minmax(0,1fr) auto}
  .txs-quick .miniseg{grid-column:1/-1}
}
/* ---------- 小手机（≤420px）：KPI 保持双列（与视觉稿一致），字号收紧防溢出 ---------- */
@media (max-width:420px){
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
  .kpi{padding:13px 14px}
  .kpi .num{font-size:19px}
  .login-card{padding:26px 20px}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand">
      <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l5-6 4 3 6-8" stroke="#fff"/><circle cx="18" cy="6" r="1.6" fill="#fff" stroke="none"/></svg></div>
      <div>
        <h1>ezBookDash</h1>
        <div class="sub" id="brandSub">ezBookkeeping</div>
      </div>
    </div>
    <div class="pagetabs" id="pageTabs" role="navigation" aria-label="主要页面"<?= $__dashAttr ?>>
      <button data-p="home" class="on">首页</button>
      <button data-p="txs">流水</button>
      <button data-p="stats">统计</button>
      <button data-p="assets">资产</button>
    </div>
    <button class="iconbtn" id="txsEntryBtn" title="搜索流水" aria-label="搜索流水"<?= $__dashAttr ?>><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20.4 20.4 16 16"/></svg></button>
    <button class="iconbtn" id="refreshBtn" title="刷新数据" aria-label="刷新数据"<?= $__dashAttr ?>><svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.3M21 3v6h-6"/></svg></button>
    <button class="iconbtn" id="settingsBtn" title="设置"<?= $__dashAttr ?>><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.1"/><path d="M19.14 12.94a7.1 7.1 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.84a.5.5 0 0 0 .12.64l2.03 1.58a7.1 7.1 0 0 0 0 1.88L2.83 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.3.6.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.08.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58z"/></svg></button>
  </div>

  <div id="loginView" class="login-view"<?= $__loginAttr ?>>
    <div class="login-card">
      <div class="login-head">
        <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l5-6 4 3 6-8" stroke="#fff"/><circle cx="18" cy="6" r="1.6" fill="#fff" stroke="none"/></svg></div>
        <h2>ezBookDash</h2>
       <p>请登录后查看 · 数据来自 ezBookkeeping</p>
      </div>
      <div class="login-demo-notice" id="baseUrlNotice" hidden>还没有填写 ezBookKeeping 地址，请先配置 <code>EBK_BASE_URL</code> 或修改 <code>config.php</code> 后再登录。</div>
      <div class="login-tabs" id="loginTabs">
        <button data-m="password" class="on">账密登录</button>
        <button data-m="token">API 令牌登录</button>
      </div>
      <div class="login-fields">
        <div id="fPassword">
          <input id="inLoginName" autocomplete="username" placeholder="用户名或邮箱">
          <input id="inLoginPassword" type="password" autocomplete="current-password" placeholder="密码">
        </div>
        <div id="fToken" hidden>
          <textarea id="inLoginToken" placeholder="粘贴 ezBookkeeping 的 API 令牌" rows="3"></textarea>
        </div>
      </div>
      <div class="login-error" id="inLoginError" hidden></div>
      <button class="login-btn" id="inLoginBtn">登 录</button>
      <button class="login-btn default" id="inDefaultBtn" hidden>使用默认凭据登录</button>
      <p class="login-hint">凭据仅用于服务端验证，不保存在浏览器本地</p>
    </div>
  </div>

  <div id="content"<?= $__dashAttr ?>>
    <!-- ===== 首页 · 记账台 ===== -->
    <div class="page on" id="pageHome">
      <div class="home-month card">
        <div class="hm-left"><p class="hm-label">当月概览</p><p class="hm-sub">切月联动下方全部数据</p></div>
        <div class="cal-nav"><button class="cal-navbtn" id="calPrevBtn" aria-label="上个月"><svg class="arw arw-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button><button class="cal-ymbtn" id="calYMBtn" aria-label="选择月份">2026年9月</button><button class="cal-navbtn" id="calNextBtn" aria-label="下个月"><svg class="arw arw-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button></div>
      </div>
      <div class="kpis" id="kpis"></div>
      <div class="home-grid">
        <div class="card home-tx"><div class="head"><h3>当月流水</h3><span class="hint" id="dayTxHint"></span></div><div id="dayTxWrap"></div></div>
        <div class="home-side">
          <div class="card home-cal"><div class="head"><h3>消费日历</h3><div class="headtools"><div class="miniseg" id="calSeg"><button data-t="expense" class="on">支出</button><button data-t="income">收入</button></div></div></div><div class="cal-grid" id="calGrid"></div><div class="cal-foot"><span class="cal-lg">少<i data-l="1"></i><i data-l="2"></i><i data-l="3"></i><i data-l="4"></i>多</span><span class="cal-peak" id="calPeak"></span></div></div>
          <div class="card bud-card" id="budCard">
          <div class="head"><h3>月度预算</h3><span class="hint" id="budHint"></span><div class="headtools"><button class="minibtn" id="budEditBtn">编辑</button></div></div>
          <div id="budBody"><div class="bud-loading">正在读取预算…</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== 统计 ===== -->
    <div class="page" id="pageStats">
      <div class="stats-range"><div class="seg" id="rangeSeg"<?= $__dashAttr ?>>
        <button data-r="month" class="on">本月</button>
        <button data-r="3m">近3月</button>
        <button data-r="6m">近6月</button>
        <button data-r="1y">近1年</button>
        <button data-r="year">今年</button>
        <button data-r="custom" id="customRangeBtn" title="自定义日期范围">自定义</button>
      </div></div>
      <div class="grid">
        <div class="card span2"><div class="head"><h3>收支趋势</h3><span class="hint" id="trendHint">近 12 个月</span></div><div class="chart tall" id="trendChart"></div></div>
        <div class="card"><div class="head"><h3>分类分析</h3><div class="headtools"><button class="minibtn" id="donutBack" hidden><svg class="arw arw-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg> 大类</button><button class="minibtn" id="rankBack" hidden><svg class="arw arw-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg> 大类</button><div class="miniseg" id="anaSeg"><button data-v="rank" class="on">排行</button><button data-v="donut">环形</button></div><div class="miniseg" id="anaTypeSeg"><button data-t="expense" class="on">支出</button><button data-t="income">收入</button></div></div></div><div class="chart tall" id="donutChart" hidden></div><div id="rankList"></div></div>
        <div class="card"><div class="head"><h3>储蓄率趋势</h3><span class="hint" id="saveHint">月度 · 含均值线</span></div><div class="chart sq" id="saveChart"></div></div>
        <div class="card"><div class="head"><h3>星期消费分布</h3><span class="hint" id="dowHint">日均支出</span></div><div class="chart sq" id="dowChart"></div></div>
        <div class="card"><div class="head"><h3>分类环比</h3><span class="hint" id="cmpHint">本期 vs 上期</span></div><div class="chart sq" id="cmpChart"></div></div>
        <div class="card span2"><div class="head"><h3>每日消费</h3><span class="hint" id="dailyHint"></span></div><div class="chart" id="dailyChart"></div></div>
        <div class="card"><div class="head"><h3>累计结余</h3><span class="hint">区间内逐日累计</span></div><div class="chart" id="cumChart"></div></div>
      </div>
    </div>

    <!-- ===== 资产 ===== -->
    <div class="page" id="pageAssets">
      <div class="grid">
        <div class="card asset-overview" style="grid-column:1/-1">
          <div class="ao-net"><span class="lb">净资产</span><button class="eye" id="netEyeBtn" title="隐藏净资产" aria-pressed="false"></button></div>
          <div class="ao-val" id="aoVal">¥0.00</div>
          <div class="ao-rows"><span>总资产 <b id="aoGross">¥0.00</b></span><span>总负债 <b id="aoLiab">¥0.00</b></span></div>
        </div>
        <div class="card" style="grid-column:1/-1"><div class="head"><h3>资产组成</h3><button class="minibtn sk-zoom-btn" id="skZoomBtn" title="放大查看" aria-label="放大查看桑基图"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3M8 11h6M11 8v6"/></svg></button></div><div class="chart" id="sankeyChart" style="height:480px"></div></div>
        <div class="card span2"><div class="head"><h3>净资产走势</h3><span class="hint" id="assetHint">日粒度 · 近 12 个月</span></div><div class="chart tall" id="assetChart"></div></div>
        <div class="card"><div class="head"><h3>账户余额</h3><span class="hint">TOP 10</span></div><div id="acctList"></div></div>
        <div class="card health-card" id="healthCard" hidden style="grid-column:1/-1"><div class="head"><h3>财务健康度</h3><span class="hint">近 12 个月滚动</span></div><div class="health-grid" id="healthGrid"></div></div>
      </div>
    </div>

    <!-- ===== 流水（搜索 + 批量整理） ===== -->
    <div class="page" id="pageTxs">
      <div class="card txs-filter">
        <div class="txs-qrow">
          <input id="txsQ" placeholder="关键词：分类 / 备注 / 账户" maxlength="50" autocomplete="off">
          <button class="minibtn" id="txsGoBtn">查询</button>
        </div>
        <div class="txs-quick">
          <div class="miniseg" id="txsTypeSeg"><button data-t="0" class="on">全部</button><button data-t="3">支出</button><button data-t="2">收入</button><button data-t="4">转账</button></div>
          <button class="minibtn" id="txsMoreBtn">更多筛选</button>
          <button class="minibtn txs-expbtn" id="txsExportBtn" disabled title="请先查询">导出 <svg class="arw txs-expcaret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
        </div>
        <div class="txs-expmenu" id="txsExportMenu" hidden>
          <button type="button" class="txs-expitem" data-act="csv">
            <span class="ei-name">导出 CSV</span>
            <span class="ei-desc" id="txsExpCount">—</span>
          </button>
          <button type="button" class="txs-expitem" data-act="clip">
            <span class="ei-name">复制到剪贴板</span>
            <span class="ei-desc">制表符分隔，可直接粘进 Excel</span>
          </button>
        </div>
        <div class="txs-more" id="txsMore" hidden>
          <label class="txs-f"><span>开始日期</span><input type="date" id="txsFrom"></label>
          <label class="txs-f"><span>结束日期</span><input type="date" id="txsTo"></label>
          <label class="txs-f"><span>分类</span><button type="button" class="txs-picker" id="txsCatPicker" aria-haspopup="dialog"><span id="txsCatPickerText">全部分类</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><select id="txsCat" hidden><option value="">全部分类</option></select></label>
          <label class="txs-f"><span>账户</span><button type="button" class="txs-picker" id="txsAcctPicker" aria-haspopup="dialog"><span id="txsAcctPickerText">全部账户</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><select id="txsAcct" hidden><option value="">全部账户</option></select></label>
          <label class="txs-f"><span>标签</span><select id="txsTag"><option value="">不限标签</option></select></label>
          <label class="txs-f"><span>标签模式</span><select id="txsTagMode"><option value="any">包含任一</option><option value="all">包含全部</option><option value="none">无标签</option></select></label>
          <label class="txs-f"><span>金额 ≥（元）</span><input type="number" id="txsAmtMin" min="0" step="0.01" inputmode="decimal" placeholder="不限"></label>
          <label class="txs-f"><span>金额 ≤（元）</span><input type="number" id="txsAmtMax" min="0" step="0.01" inputmode="decimal" placeholder="不限"></label>
        </div>
        <p class="txs-tip">至少填一个条件后查询 · 日期含当日 · 金额按元填写</p>
      </div>
      <div class="card">
        <div class="head txs-head"><h3>搜索结果</h3><span class="hint" id="txsHint"></span><div class="headtools"><button class="minibtn" id="txsSelAll" hidden>全选本页</button><button class="minibtn" id="txsSelBtn">多选</button></div></div>
        <div class="txs-list" id="txsList"></div>
        <button class="txs-load" id="txsLoadBtn" hidden>加载更多</button>
      </div>
    </div>

    <!-- 桑基图全屏弹窗：fixed 全屏，脱离 grid 布局流。关闭按钮挂在 holder（无 transform）上，
         强制横屏旋转时不跟随内容旋转，位置始终可控 -->
    <div class="sk-modal-holder" id="skModalHolder" hidden><div class="sk-modal" id="skModal" role="dialog" aria-label="资产组成全屏视图"><div class="m-head"><h3>资产组成</h3></div><div class="m-lstip" id="skModalLsTip" hidden><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="10" rx="2"/><path d="M7 21h10"/></svg>请将手机横过来查看</div><div class="m-chart"><div id="skModalChart"></div></div></div><button class="m-close" id="skModalClose" aria-label="关闭"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>

    <!-- 当日消费明细弹窗：桌面居中小卡 / 移动端底部弹出 -->
    <div class="cd-modal-mask" id="cdModalMask" hidden><div class="cd-modal" role="dialog" aria-label="当日消费明细">
      <div class="cd-head"><div><p class="cd-title" id="cdTitle">—</p><p class="cd-sub" id="cdSub"></p></div><button class="cd-close" id="cdClose" aria-label="关闭"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>
      <div class="cd-list" id="cdList"></div>
      <div class="cd-sum" id="cdSum"></div>
    </div></div>

    <!-- 批量操作条：多选模式下替代底部 TabBar（桌面浮于底部居中） -->
    <div class="txs-bar" id="txsBar" hidden>
      <button class="tb-btn ghost" id="txsCancelBtn">退出</button>
      <span class="tb-n">已选 <b id="txsBarN">0</b> 笔</span>
      <button class="tb-btn" id="txsBarCat">改分类</button>
      <button class="tb-btn" id="txsBarTag">加标签</button>
      <button class="tb-btn" id="txsBarClr">清标签</button>
      <button class="tb-btn danger" id="txsBarDel">删除</button>
    </div>

    <!-- 批量操作弹窗：一壳三态（改分类 / 加标签 / 删除）。z-index 122 夹在设置窗 120 与轻确认 130 之间 -->
    <div class="dp-mask txs-op-mask" id="txsOpMask" hidden>
      <div class="dp-card" role="dialog" aria-modal="true" aria-labelledby="txsOpTitle">
        <div class="dp-head">
          <h3 id="txsOpTitle">批量操作</h3>
          <button class="dp-close" id="txsOpClose" title="关闭" aria-label="关闭"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
        </div>
        <div class="txs-op-body">
          <div id="txOpCat" hidden>
            <div class="miniseg" id="txOpCatSeg"><button data-t="2">收入</button><button data-t="3" class="on">支出</button></div>
            <select id="txOpCatSel" class="txs-op-input"></select>
            <p class="txs-tip" id="txOpCatTip">所选流水将全部改为该分类 · 请确认分类类型与流水一致</p>
          </div>
          <div id="txOpTag" hidden>
            <div class="txs-tags" id="txOpTagList"></div>
            <p class="txs-tip">勾选要添加的标签（可多选，追加到流水现有标签之后）</p>
          </div>
          <div id="txOpDel" hidden>
            <p class="txs-warn">将删除所选 <b id="txOpDelN">0</b> 笔流水，删除后不可恢复。</p>
            <input type="password" id="txOpPwd" class="txs-op-input" placeholder="输入登录密码确认" autocomplete="current-password">
            <p class="txs-tip">上游要求批量删除必须密码校验 · 密码仅本次提交使用，不会被保存</p>
          </div>
        </div>
        <div class="dp-err" id="txsOpErr" hidden></div>
        <div class="dp-btns">
          <button class="dp-cancel" id="txsOpCancel">取消</button>
          <button class="dp-apply" id="txsOpOk">确定</button>
        </div>
      </div>
    </div>

    <!-- 月份选择器面板：年切换 + 12 宫格 -->
    <div class="calym-mask" id="calYMPanel" hidden><div class="calym-panel" role="dialog" aria-label="选择月份">
      <div class="calym-ynav"><button class="cal-navbtn" id="calYearPrev" aria-label="上一年"><svg class="arw arw-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button><p class="calym-year" id="calYMYear">2026 年</p><button class="cal-navbtn" id="calYearNext" aria-label="下一年"><svg class="arw arw-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button></div>
      <div class="calym-grid" id="calYMGrid"></div>
      <p class="calym-tip">当前选中月高亮 · 未来月份不可选</p>
    </div></div>

    <footer id="foot"></footer>
  </div>

  <!-- 移动端底部 TabBar · 玻璃液态 -->
  <nav class="tabbar" id="tabbar" aria-label="主要页面" hidden>
    <span class="tab-slider" data-i="0"></span>
    <button data-p="home" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg><span>首页</span></button>
    <button data-p="txs"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 5h14M7 12h14M7 19h14"/><circle cx="3" cy="5" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="19" r="1" fill="currentColor" stroke="none"/></svg><span>流水</span></button>
    <button data-p="stats"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg><span>统计</span></button>
    <button data-p="assets"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l5-6 4 3 6-8"/><circle cx="18" cy="6" r="1.6" fill="currentColor" stroke="none"/><path d="M3 21h18"/></svg><span>资产</span></button>
  </nav>

  <!-- 一句话记账 · 悬浮 FAB（移动端；桌面用月份条按钮） -->
  <button class="fab" id="fabAdd" hidden aria-label="记一笔"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg><span>记一笔</span></button>

  <!-- 一句话记账 · 弹层 -->
  <div class="add-mask" id="addMask" hidden>
    <div class="add-sheet" role="dialog" aria-modal="true" aria-labelledby="addTitle">
      <div class="add-head">
        <h3 id="addTitle">记一笔</h3>
        <button class="cd-close" id="addClose" aria-label="关闭"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      </div>
      <div class="ai-row" id="aiRow">
        <input id="aiText" placeholder="可选：昨天午饭花了35元" maxlength="200" autocomplete="off">
        <button id="aiBtn" class="ai-btn">识别</button>
      </div>
      <div class="ai-hint" id="aiHint">可先说一句话自动填入，也可直接填写下方表单</div>
      <div class="ai-result" id="aiResult" hidden>
        <div class="ai-card">
          <div class="aic-head">
            <div class="aic-seg" id="aiTypeSeg">
              <button type="button" data-t="3">支出</button>
              <button type="button" data-t="2">收入</button>
              <button type="button" data-t="4">转账</button>
            </div>
          </div>
          <div class="aic-amt"><span class="cur">¥</span><input id="aiAmt" inputmode="decimal" autocomplete="off" placeholder="0.00" aria-label="金额"></div>
          <div class="aic-fields">
            <label id="catWrap">分类<input id="aiCatTxt" class="pk-input" readonly placeholder="点击选择分类"></label>
            <label id="acctWrap">账户<input id="aiAcctTxt" class="pk-input" readonly placeholder="点击选择账户"></label>
            <label id="aiDstField" hidden>转入<input id="aiDstTxt" class="pk-input" readonly placeholder="点击选择转入账户"></label>
            <label>时间<input id="aiTime" type="datetime-local" autocomplete="off"></label>
            <label>备注<input id="aiMemo" maxlength="100" autocomplete="off"></label>
            <label id="tagWrap">标签<input id="aiTagTxt" class="pk-input" readonly placeholder="点击选择标签"></label>
          </div>
        </div>
        <div class="ai-account-hint" id="aiAccountHint" hidden></div>
        <div class="add-err" id="addErr" hidden></div>
        <div class="add-btns">
          <button class="add-del" id="aiDel" hidden>删除</button>
          <button class="add-submit" id="aiSave">✓ 确认保存</button>
        </div>
      </div>
      <div class="sheet-loading" id="sheetLoading" hidden><div class="spin2"></div><div class="t" id="sheetLoadingT">保存中…</div></div>
    </div>
  </div>

  <!-- 分类/账户 · 响应式双栏选择器（手机底部抽屉 / 桌面居中弹窗） -->
  <div class="pick-mask" id="pickMask" hidden></div>
  <div class="pick-sheet" id="pickSheet" hidden>
    <div class="pick-head"><span id="pkTitle">选择分类</span><span class="pk-hr"><span id="pkSub" class="pk-sub" hidden></span><button class="pick-close" id="pkClose" aria-label="关闭">✕</button></span></div>
    <div class="pick-cols">
      <div class="pick-left" id="pkLeft"></div>
      <div class="pick-right" id="pkRight"></div>
    </div>
  </div>
</div>

<!-- 自定义日期范围面板 -->
<div class="dp-mask" id="datePanel" hidden>
  <div class="dp-card" role="dialog" aria-modal="true" aria-label="自定义日期范围">
    <div class="dp-head">
      <h3>自定义日期范围</h3>
      <button class="dp-close" id="dpClose" title="关闭" aria-label="关闭"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="dp-fields">
      <label>开始日期 <input type="date" id="dpFrom"></label>
      <span class="dp-sep">至</span>
      <label>结束日期 <input type="date" id="dpTo"></label>
    </div>
    <div class="dp-err" id="dpErr" hidden></div>
    <div class="dp-quick">
      <button data-q="thisMonth">本月</button>
      <button data-q="lastMonth">上个月</button>
      <button data-q="30d">近30天</button>
      <button data-q="90d">近90天</button>
      <button data-q="thisYear">今年</button>
    </div>
    <div class="dp-btns">
      <button class="dp-cancel" id="dpCancel">取消</button>
      <button class="dp-apply" id="dpApply">查询</button>
    </div>
  </div>
</div>

<!-- 设置窗口 -->
<div class="dp-mask sm-mask" id="setMask" hidden>
  <div class="dp-card sm-card" role="dialog" aria-modal="true" aria-label="设置">
    <div class="dp-head">
      <h3>设置</h3>
      <button class="dp-close" id="setClose" title="关闭" aria-label="关闭"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>

    <div class="set-sec">
      <div class="set-sec-t">外观</div>
      <div class="set-group">
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">深色模式</div>
            <div class="sr-desc" id="setThemeDesc">跟随系统设置自动切换</div>
          </div>
          <div class="set-seg" id="setThemeSeg" role="radiogroup" aria-label="深色模式">
            <button type="button" role="radio" data-m="auto">系统</button>
            <button type="button" role="radio" data-m="light">浅色</button>
            <button type="button" role="radio" data-m="dark">深色</button>
          </div>
        </div>
      </div>
    </div>

    <div class="set-sec">
      <div class="set-sec-t">AI 识别 · 一句话记账</div>
      <div class="set-group">
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">识别方式</div>
            <div class="sr-desc" id="setAiModeDesc">上游服务器上的配置</div>
          </div>
          <div class="set-seg" id="setAiMode" role="radiogroup" aria-label="识别方式">
            <button type="button" role="radio" data-m="upstream">跟随上游</button>
            <button type="button" role="radio" data-m="custom">自定义</button>
          </div>
        </div>

        <div id="setAiCustom">
        <label class="set-field">
          <span class="fl">接口地址</span>
          <input id="setAiBase" class="set-input" placeholder="https://api.ainn.cc/v1" autocomplete="off" spellcheck="false">
          <span class="set-hint">OpenAI 兼容地址，末尾带 /v1</span>
          <span class="set-hint">还没有艾能 API 账号？<a class="set-link" href="https://api.ainn.cc/register?channel=c_zund9i5n" target="_blank" rel="noopener noreferrer">去注册</a></span>
        </label>

        <label class="set-field">
          <span class="fl fl-act">API Key<a class="set-link" href="https://api.ainn.cc/console/token" target="_blank" rel="noopener noreferrer">去获取</a></span>
          <span class="set-keywrap">
            <input id="setAiKey" class="set-input" type="password" placeholder="粘贴你的 API Key" autocomplete="new-password" spellcheck="false">
            <button type="button" class="set-mini" id="setAiKeyEye">显示</button>
          </span>
          <span class="set-hint" style="display:flex;align-items:center;gap:8px;justify-content:space-between">
            <span id="setAiKeyHint">只存在服务器，不会下发到浏览器</span>
            <button type="button" class="set-mini" id="setAiKeyClear" hidden>清除 Key</button>
          </span>
        </label>

        <label class="set-field">
          <span class="fl">模型</span>
          <input id="setAiModel" class="set-input" placeholder="如 gpt-4o-mini" autocomplete="off" spellcheck="false">
        </label>

        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">连接检查</div>
            <div class="sr-desc" id="setAiTestDesc">未测试</div>
          </div>
          <button class="set-btn" id="setAiTest">测试连接</button>
        </div>

        <button type="button" class="set-row set-row-btn" id="setAiMapRow" aria-haspopup="dialog" aria-controls="mapMask">
          <div class="sr-txt">
            <div class="sr-name">分类映射</div>
            <div class="sr-desc" id="setAiMapDesc">正在读取…</div>
          </div>
          <svg class="set-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </button>
        </div>

        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">保存配置</div>
            <div class="sr-desc" id="setAiStatus">正在读取…</div>
          </div>
          <button class="set-btn primary" id="setAiSave">保存</button>
        </div>
      </div>
      <div class="set-hint" style="margin:7px 2px 0">分类与账户清单会随请求一起给模型，识别结果直接是真实 ID</div>
    </div>

    <div class="set-sec">
      <div class="set-sec-t">预算</div>
      <div class="set-group">
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">预算管理</div>
            <div class="sr-desc" id="setBudDesc">设置每月总体与分类预算</div>
          </div>
          <button class="set-btn" id="setBudBtn">编辑</button>
        </div>
      </div>
    </div>

    <div class="set-sec">
      <div class="set-sec-t">数据</div>
      <div class="set-group">
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">导出设置</div>
            <div class="sr-desc">导出主题、AI 配置、分类映射和预算，不含密钥</div>
          </div>
          <button class="set-btn" id="setExport">导出</button>
        </div>
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">导入设置</div>
            <div class="sr-desc">从设置备份恢复，可迁移到其他设备</div>
          </div>
          <button class="set-btn" id="setImport">导入</button>
          <input type="file" id="setImportFile" accept=".json,application/json" hidden>
        </div>
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">清除缓存</div>
            <div class="sr-desc">清理本地离线缓存与服务端数据缓存，不会退出登录</div>
          </div>
          <button class="set-btn" id="setClear">清除</button>
        </div>
      </div>
    </div>

    <div class="set-sec">
      <div class="set-sec-t">账户</div>
      <div class="set-group">
        <div class="set-row">
          <div class="sr-txt">
            <div class="sr-name">退出登录</div>
            <div class="sr-desc" id="setAcctDesc">已登录</div>
          </div>
          <button class="set-btn danger" id="setLogout">退出</button>
        </div>
      </div>
    </div>

    <div class="set-foot">ezBookDash · v1</div>
  </div>
</div>

<!-- A2 预算编辑弹窗（z-index 124：设置窗 120 < 本窗 < 映射编辑 125 < 轻确认 130） -->
<div class="dp-mask bud-mask" id="budMask" hidden>
  <div class="dp-card" role="dialog" aria-modal="true" aria-labelledby="budTitle">
    <div class="dp-head">
      <h3 id="budTitle">预算管理</h3>
      <button class="dp-close" id="budClose" title="关闭" aria-label="关闭"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="bud-total">
      <span class="fl">总体预算 · 每月</span>
      <div class="in"><span class="cur" id="budCur">¥</span><input id="budTotalIn" inputmode="decimal" placeholder="不设总体预算" autocomplete="off"></div>
    </div>
    <div class="bud-body">
      <div class="bud-rows" id="budRows"></div>
      <button class="bud-add" id="budAddBtn">＋ 添加分类预算</button>
    </div>
    <p class="bud-note" id="budNote"><b>父子独立计算：</b>给某个小类单独设了预算后，它就从大类预算里扣出去，两边互不重复计。例如「餐饮 2000」+「餐饮/外出就餐 500」，则餐饮这 2000 管的是「除外出就餐之外的餐饮」。</p>
    <div class="dp-btns">
      <button class="dp-cancel" id="budCancel">取消</button>
      <button class="dp-apply" id="budSave">保存</button>
    </div>
    <div class="bud-err" id="budErr" hidden></div>
  </div>
</div>

<!-- 通用轻确认（退出登录 / 清除缓存） -->
<div class="dp-mask mc-mask" id="miniMask" hidden>
  <div class="dp-card mc-card" role="dialog" aria-modal="true" aria-labelledby="miniTitle">
    <div class="dp-head"><h3 id="miniTitle">确认</h3></div>
    <div class="mc-msg" id="miniMsg"></div>
    <div class="dp-btns">
      <button class="dp-cancel" id="miniCancel">取消</button>
      <button class="dp-apply" id="miniOk">确定</button>
    </div>
  </div>
</div>

<!-- 分类映射编辑：分类固定（不可增删改），只改触发词 -->
<div class="dp-mask mp-mask" id="mapMask" hidden>
  <div class="dp-card mp-card" role="dialog" aria-modal="true" aria-labelledby="mapTitle">
    <div class="dp-head">
      <h3 id="mapTitle">分类映射</h3>
      <button class="dp-close" id="mapClose" title="关闭" aria-label="关闭"><svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="mp-bar">
      <div class="set-seg" id="mapSeg" role="radiogroup" aria-label="类型">
        <button type="button" role="radio" data-t="2">支出</button>
        <button type="button" role="radio" data-t="1">收入</button>
        <button type="button" role="radio" data-t="3">转账</button>
      </div>
      <input id="mapSearch" class="set-input mp-search" placeholder="搜子类 / 大类 / 触发词" autocomplete="off" spellcheck="false">
    </div>
    <div class="mp-tip" id="mapTip">分类由账本固定，只能改触发词。触发词会随请求一起给模型，帮它判断「这句话属于哪个分类」。</div>
    <div class="mp-list" id="mapList"><div class="mp-empty">正在读取…</div></div>
    <div class="mp-foot">
      <div class="mp-count" id="mapCount">—</div>
      <div class="mp-acts">
        <button type="button" class="set-btn" id="mapReset" disabled>全部还原</button>
        <button type="button" class="set-btn primary" id="mapSave" disabled>保存</button>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= htmlspecialchars($__nonce, ENT_QUOTES, 'UTF-8') ?>">
"use strict";
/* ================= 全局状态 ================= */
const state = { range:"month", data:null, donutType:"expense", donutDrill:null, rankType:"expense", rankDrill:null, calType:"expense", anaView:"rank", calYM:null, calCache:{}, txOpenDays:{}, charts:{}, themeMode: localStorage.getItem("ebk_theme")||"auto", netHidden: localStorage.getItem("ebk_net_hidden")==="1", loading:false, loggedIn:false, loginMode:"password", loginBusy:false, authEpoch:0, csrf:<?= json_encode($__csrf, JSON_UNESCAPED_SLASHES) ?>,
  customFrom: localStorage.getItem("ebk_custom_from")||"", customTo: localStorage.getItem("ebk_custom_to")||"" };

const nativeFetch=window.fetch.bind(window);
function apiFetch(input,init={}){
  const epoch=state.authEpoch;
  const opts={...init};
  const headers=new Headers(opts.headers||{});
  if(String(opts.method||"GET").toUpperCase()==="POST"&&state.csrf) headers.set("X-EzBookDash-CSRF",state.csrf);
  opts.headers=headers;
  return nativeFetch(input,opts).then(response=>{
    if(epoch!==state.authEpoch) throw new DOMException("账号已切换，请重试","AbortError");
    return response;
  });
}

const PALETTE = ["#3f66f8","#18a768","#f2803a","#7a5af5","#12b5cb","#ef4d6e","#e6a700","#5d6a8e","#2f9e44","#c2255c"];
/* 图表统一字体：与页面同栈（Inter 数字 + 系统中文回退），注册一次主题全量生效（轴/图例/提示框） */
const FONT = '"Inter",-apple-system,"PingFang SC","Microsoft YaHei",system-ui,sans-serif';
echarts.registerTheme("wb", { textStyle: { fontFamily: FONT } });
const CURRENCY_SYMBOLS = { CNY:"¥", USD:"$", EUR:"€", GBP:"£", JPY:"JP¥", KRW:"₩", HKD:"HK$", TWD:"NT$", SGD:"S$", AUD:"A$", CAD:"C$" };
const nf = new Intl.NumberFormat("zh-CN",{minimumFractionDigits:2,maximumFractionDigits:2});

/* ================= 主题 ================= */
function resolveTheme(){ return state.themeMode==="auto" ? (matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light") : state.themeMode; }
function applyTheme(){
  document.documentElement.dataset.theme = resolveTheme();
  drawThemeIcon();
  if(state.data) renderCharts();
  netApply();        /* 隐藏态下按新主题色重绘马赛克 */
}
function drawThemeIcon(){
  // 主题状态同步到设置窗口里的三档选择器（深色模式：系统 / 浅色 / 深色）
  syncThemeSeg();
}
matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => { if(state.themeMode==="auto") applyTheme(); });

/* ================= 设置窗口 ================= */
const THEME_DESC = { auto:"跟随系统设置自动切换", light:"始终使用浅色外观", dark:"始终使用深色外观" };

function syncThemeSeg(){
  const seg = document.getElementById("setThemeSeg");
  if(!seg) return;
  [...seg.children].forEach(b=>{
    const on = b.dataset.m === state.themeMode;
    b.classList.toggle("on", on);
    b.setAttribute("aria-checked", on ? "true" : "false");
  });
  const d = document.getElementById("setThemeDesc");
  if(d) d.textContent = THEME_DESC[state.themeMode] || "";
}

function openSettings(){
  syncThemeSeg();
  const ad = document.getElementById("setAcctDesc");
  if(ad) ad.textContent = (state.data && state.data.profile && state.data.profile.nickname) || "已登录";
  document.getElementById("setMask").hidden = false;
  loadAiSettings();
}
function closeSettings(){ document.getElementById("setMask").hidden = true; }

/* 设置备份：只导出非敏感配置，登录 Token、密码和 AI API Key 永不进入文件。 */
function settingsDownload(data){
  const pkg={...data,theme:{mode:state.themeMode,netHidden:state.netHidden,customFrom:state.customFrom||"",customTo:state.customTo||""}};
  const stamp=new Date().toISOString().slice(0,19).replace(/[T:]/g,"-");
  const blob=new Blob([JSON.stringify(pkg,null,2)],{type:"application/json;charset=utf-8"});
  const url=URL.createObjectURL(blob), a=document.createElement("a");
  a.href=url; a.download=`ezbookdash-settings-${stamp}.json`; a.click();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
}
document.getElementById("setExport").onclick=async()=>{
  const btn=document.getElementById("setExport"); btn.disabled=true; btn.textContent="导出中…";
  try{
    const r=await apiFetch("api.php?action=settings_export",{cache:"no-store"}), j=await r.json();
    if(j.requireLogin){setView(false);throw new Error("登录已失效，请重新登录");}
    if(!j.success) throw new Error(j.error||"导出失败");
    settingsDownload(j.data); toast("设置已导出（不含密钥）");
  }catch(e){toast(e.message||"导出失败");}
  finally{btn.disabled=false;btn.textContent="导出";}
};
function settingsImportSummary(pkg){
  const ai=pkg.ai?1:0, map=pkg.categoryMapping&&typeof pkg.categoryMapping==="object"?Object.keys(pkg.categoryMapping).length:0;
  const budget=pkg.budget?.monthly&&typeof pkg.budget.monthly==="object"?Object.keys(pkg.budget.monthly).length:0;
  return `将导入：AI 配置 ${ai?"1 项":"未包含"}、分类映射 ${map} 条、预算 ${budget} 条。\n\n现有分类映射和预算将按备份内容覆盖；目标设备已有的 AI API Key 会保留，备份文件不包含密钥。`;
}
document.getElementById("setImport").onclick=()=>document.getElementById("setImportFile").click();
document.getElementById("setImportFile").addEventListener("change",async e=>{
  const file=e.target.files?.[0]; e.target.value=""; if(!file) return;
  try{
    const pkg=JSON.parse(await file.text());
    if(!pkg||pkg.format!=="ezbookdash-settings"||Number(pkg.version)!==1) throw new Error("不是有效的 ezBookDash 设置备份文件");
    const ok=await miniConfirm("导入设置",settingsImportSummary(pkg),"导入",true); if(!ok) return;
    const r=await apiFetch("api.php?action=settings_import",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(pkg)}), j=await r.json();
    if(j.requireLogin){setView(false);throw new Error("登录已失效，请重新登录");}
    if(!j.success) throw new Error(j.error||"导入失败");
    const t=pkg.theme||{};
    if(["auto","light","dark"].includes(t.mode)){state.themeMode=t.mode;localStorage.setItem("ebk_theme",t.mode);}
    if(typeof t.netHidden==="boolean"){state.netHidden=t.netHidden;localStorage.setItem("ebk_net_hidden",t.netHidden?"1":"0");}
    if(typeof t.customFrom==="string"){state.customFrom=t.customFrom;localStorage.setItem("ebk_custom_from",t.customFrom);}
    if(typeof t.customTo==="string"){state.customTo=t.customTo;localStorage.setItem("ebk_custom_to",t.customTo);}
    applyTheme(); state.budget=null; await loadAiSettings();
    closeSettings(); toast("设置已导入，AI API Key 未随备份导入");
  }catch(err){toast(err.message||"导入失败");}
});

/* ---- AI 识别设置：读取 / 保存 / 测试连接（配置存服务端 data/llm_config.php，属用户数据、不随清缓存消失） ---- */
let aiKeyCleared = false;      /* 用户点了「清除 Key」→ 保存时明确清空 */

function aiSetStatus(txt, tone){
  const el = document.getElementById("setAiStatus");
  if(el){ el.textContent = txt; if(tone) el.dataset.tone = tone; else delete el.dataset.tone; }
}
function aiSetTest(txt, tone){
  const el = document.getElementById("setAiTestDesc");
  if(el){ el.textContent = txt; if(tone) el.dataset.tone = tone; else delete el.dataset.tone; }
}
function aiSyncMode(){
  const seg = document.getElementById("setAiMode");
  if(!seg) return "";
  const mode = seg.querySelector("button.on")?.dataset.m || "upstream";
  document.getElementById("setAiModeDesc").textContent = mode === "custom"
    ? "用下面自己配置的接口"
    : "上游服务器上的配置";
  /* 「跟随上游」时折叠自定义字段，窗口不至于太长（点「自定义」即展开） */
  const box = document.getElementById("setAiCustom");
  if(box) box.hidden = (mode !== "custom");
  return mode;
}
function renderAiSettings(d){
  document.querySelectorAll("#setAiMode button").forEach(b=>{
    const on = b.dataset.m === d.mode;
    b.classList.toggle("on", on);
    b.setAttribute("aria-checked", on ? "true" : "false");
  });
  aiSyncMode();
  document.getElementById("setAiBase").value  = d.base_url || "";
  document.getElementById("setAiModel").value = d.model || "";
  const key = document.getElementById("setAiKey");
  key.value = "";
  key.placeholder = d.hasKey ? (d.keyMasked + "（已保存，留空不改）") : "粘贴你的 API Key";
  document.getElementById("setAiKeyHint").textContent = d.hasKey
    ? "当前 Key：" + d.keyMasked + " · 重新粘贴可覆盖"
    : "只存在服务器，不会下发到浏览器";
  document.getElementById("setAiKeyClear").hidden = !d.hasKey;
  aiKeyCleared = false;
  aiRenderMap(d.mapStats);
  aiSetStatus(d.mode === "custom"
    ? (d.ready ? "配置完整，识别将走自定义接口" : "配置不完整，识别会回落到上游")
    : "识别走上游；点上方「自定义」可换用别的接口");
}
/* 分类映射命中情况（内容由服务端 llm_category_map.php 维护，触发词可在映射窗口里改） */
let aiLastMapStats = null;      /* 编辑窗口保存后要就地刷新这行说明，所以留一份 */
function aiRenderMap(ms){
  const el = document.getElementById("setAiMapDesc");
  if(!el || ms === undefined) return;         // 保存响应里没有就当没变化
  aiLastMapStats = ms || null;
  el.removeAttribute("data-tone");
  if(!ms){ el.textContent = "先在记账页打开一次，才能对出命中情况"; return; }
  if(!ms.total){ el.textContent = "没读到分类，先在记账页打开一次"; return; }
  const configured = Number.isFinite(+ms.configured) ? +ms.configured : (ms.mapSize ? ms.hit : 0);
  const missing = Array.isArray(ms.unconfigured) ? ms.unconfigured : (ms.miss || []);
  const missingN = Number.isFinite(+ms.unconfiguredN) ? +ms.unconfiguredN : missing.length;
  if(configured >= ms.total){
    el.textContent = "已配置 " + configured + "/" + ms.total + "，全部分类都有触发词（映射表 " + ms.mapSize + " 条）";
    el.setAttribute("data-tone", "ok");
    return;
  }
  if(configured === 0){
    el.textContent = "尚未配置分类触发词（0/" + ms.total + "）";
    el.setAttribute("data-tone", "warn");
    return;
  }
  const names = missing.slice(0,4).join("、") + (missingN > missing.length ? " 等 " + missingN + " 个" : "");
  el.textContent = "已配置 " + configured + "/" + ms.total + "，未配置：" + names;
  el.setAttribute("data-tone", "warn");
}
async function loadAiSettings(){
  aiSetStatus("正在读取…");
  const mapEl = document.getElementById("setAiMapDesc");
  if(mapEl){ mapEl.textContent = "正在读取…"; mapEl.removeAttribute("data-tone"); }
  try{
    const r = await apiFetch("api.php?action=llm_settings_get", { cache:"no-store" });
    const j = await r.json();
    if(j.requireLogin){ setView(false); return; }
    if(!j.success) throw new Error(j.error || "读取失败");
    renderAiSettings(j.data);
  }catch(e){
    aiSetStatus(e.message || "读取失败", "err");
    if(mapEl) mapEl.textContent = "读取失败";
  }
}
async function saveAiSettings(){
  const btn = document.getElementById("setAiSave");
  const payload = {
    mode: aiSyncMode(),
    base_url: document.getElementById("setAiBase").value.trim(),
    model: document.getElementById("setAiModel").value.trim(),
    clearKey: aiKeyCleared
  };
  if(!aiKeyCleared) payload.api_key = document.getElementById("setAiKey").value.trim();
  btn.disabled = true; btn.textContent = "保存中…";
  try{
    const r = await apiFetch("api.php?action=llm_settings_save", { method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(payload) });
    const j = await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error || "保存失败");
    renderAiSettings(j.data);
    toast("AI 配置已保存");
  }catch(e){
    aiSetStatus(e.message || "保存失败", "err");
    toast(e.message || "保存失败");
  }finally{
    btn.disabled = false; btn.textContent = "保存";
  }
}
async function testAiConnection(){
  const btn = document.getElementById("setAiTest");
  const payload = {
    base_url: document.getElementById("setAiBase").value.trim(),
    model: document.getElementById("setAiModel").value.trim()
  };
  const typed = document.getElementById("setAiKey").value.trim();
  if(typed) payload.api_key = typed;
  btn.disabled = true; btn.textContent = "测试中…";
  aiSetTest("正在请求接口…");
  const t0 = Date.now();
  try{
    const r = await apiFetch("api.php?action=llm_test", { method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(payload) });
    const j = await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error || "测试失败");
    const secs = (j.data.ms ? (j.data.ms/1000) : (Date.now()-t0)/1000).toFixed(1);
    aiSetTest("连接正常 · " + secs + "s · " + (j.data.model || payload.model) + (j.data.reply ? " · 返回「" + j.data.reply + "」" : ""), "ok");
  }catch(e){
    aiSetTest("连接失败：" + (e.message || "未知错误"), "err");
  }finally{
    btn.disabled = false; btn.textContent = "测试连接";
  }
}

document.getElementById("setAiMode").addEventListener("click", e=>{
  const b = e.target.closest("button"); if(!b) return;
  document.querySelectorAll("#setAiMode button").forEach(x=>{
    x.classList.toggle("on", x === b);
    x.setAttribute("aria-checked", x === b ? "true" : "false");
  });
  aiSyncMode();
  aiSetStatus("识别方式已改，点「保存」生效");
});
document.getElementById("setAiKeyEye").onclick = () => {
  const key = document.getElementById("setAiKey");
  const eye = document.getElementById("setAiKeyEye");
  const show = key.type === "password";
  key.type = show ? "text" : "password";
  eye.textContent = show ? "隐藏" : "显示";
};
document.getElementById("setAiSave").onclick = saveAiSettings;
document.getElementById("setAiTest").onclick = testAiConnection;
document.getElementById("setAiKeyClear").onclick = () => {
  const key = document.getElementById("setAiKey");
  key.value = ""; key.type = "password";
  document.getElementById("setAiKeyEye").textContent = "显示";
  key.placeholder = "粘贴你的 API Key";
  document.getElementById("setAiKeyHint").textContent = "保存后将清空已存 Key";
  document.getElementById("setAiKeyClear").hidden = true;
  aiKeyCleared = true;
  aiSetStatus("点「保存」后 Key 将被清空");
};

/* ---- 分类映射编辑窗口：分类固定（不可增删改），只改触发词 ----
   改动直接保存到 data/llm_category_map.php，分类映射只有一份，便于备份和迁移。 */
const MP_TYPE_NAME = { 1:"收入", 2:"支出", 3:"转账" };
const MP_TIP = "分类由账本固定，只能改触发词。触发词会随请求一起给模型，帮它判断「这句话属于哪个分类」。";
let mpItems  = [];         /* 服务端全量条目，只在重新读取时才换 */
let mpVals   = {};         /* 类型|分类名 -> 输入框里的当前值（同名分类互不覆盖） */
let mpMax    = 400;        /* 触发词长度上限，服务端也会校验 */
let mpType   = "2";        /* 当前分页：2=支出 1=收入 3=转账 */
let mpMetaOk = true;       /* 有没有读到用户账本分类（决定「账本有/无」标签是否可信） */
let mpBusy   = false;

function mpEsc(s){
  return String(s==null?"":s).replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
}
/* 和服务端同一套归一：空白折叠 + 去首尾，否则「只多了个空格」会被判成改动，保存后又没变 */
function mpNorm(s){ return String(s==null?"":s).replace(/\s+/g," ").trim(); }
function mpKey(it){ return String(it?.key||it?.sub||""); }
function mpFind(key){ return mpItems.find(x=>mpKey(x) === key) || null; }
function mpPendingN(){ return mpItems.filter(it=>mpNorm(mpVals[mpKey(it)]) !== mpNorm(it.trig)).length; }
function mpRevertN(){ return mpItems.filter(it=>mpNorm(mpVals[mpKey(it)]) !== mpNorm(it.base)).length; }

function mpSyncSeg(){
  document.querySelectorAll("#mapSeg button").forEach(b=>{
    const on = b.dataset.t === mpType;
    b.classList.toggle("on", on);
    b.setAttribute("aria-checked", on ? "true" : "false");
    const n = mpItems.filter(it=>it.type === Number(b.dataset.t)).length;
    b.textContent = (MP_TYPE_NAME[b.dataset.t] || "") + (n ? " " + n : "");
  });
}
function mpHeadHtml(it){
  const key=mpKey(it), cur=mpVals[key] ?? it.trig;
  const pend = mpNorm(cur) !== mpNorm(it.trig);
  const rev  = mpNorm(cur) !== mpNorm(it.base);
  let h = '<span class="mp-sub">' + mpEsc(it.sub) + '</span>';
  if(pend) h += '<span class="mp-tag pend">未保存</span>';
  /* 只标「账本无」这个例外：真实账本里绝大多数都是「有」，全标出来只是噪声 */
  if(it.matched === false) h += '<span class="mp-tag idle">账本无</span>';
  if(rev) h += '<button type="button" class="mp-rev" data-rev="' + mpEsc(key) + '">还原</button>';
  return h;
}
function mpRowHtml(it){
  const key=mpKey(it), cur=mpVals[key] ?? it.trig;
  return '<div class="mp-row' + (mpNorm(cur) !== mpNorm(it.trig) ? ' dirty' : '') + '">'
    + '<div class="mp-rh">' + mpHeadHtml(it) + '</div>'
    + '<textarea class="mp-ta" data-key="' + mpEsc(key) + '" rows="2" maxlength="' + mpMax + '"'
    + ' placeholder="填触发词，用「、」分隔，如：超市、菜市场、便利店">' + mpEsc(cur) + '</textarea></div>';
}
/* 敲字时只重画这一行的头部（整表重绘会把焦点和光标弄丢） */
function mpAutoGrow(ta){
  ta.style.height = "auto";
  const need = ta.scrollHeight + 2;
  ta.style.height = Math.min(need, 220) + "px";
  ta.style.overflowY = need > 220 ? "auto" : "hidden";
}
function mpGrowAll(root){
  (root || document).querySelectorAll("#mapList .mp-ta").forEach(mpAutoGrow);
}
function mpSyncRow(key){
  const it = mpFind(key); if(!it) return;
  const list = document.getElementById("mapList");
  let ta = null;
  list.querySelectorAll("textarea[data-key]").forEach(t=>{ if(t.dataset.key === key) ta = t; });
  if(!ta) return;
  const row = ta.closest(".mp-row"); if(!row) return;
  row.classList.toggle("dirty", mpNorm(mpVals[key]) !== mpNorm(it.trig));
  const head = row.querySelector(".mp-rh");
  if(head) head.innerHTML = mpHeadHtml(it);
}
function mpSyncFoot(){
  const pend = mpPendingN(), rev = mpRevertN();
  const absent = mpItems.filter(it=>it.matched === false).length;
  const cnt  = document.getElementById("mapCount");
  const parts = ["共 " + mpItems.length + " 条"];
  if(mpMetaOk === false) parts.push("没读到你的账本分类，命中情况暂不可知");
  else if(absent) parts.push(absent + " 条账本里没有");
  if(pend) parts.push(pend + " 处未保存");
  cnt.textContent = parts.join(" · ");
  if(mpMetaOk === false) cnt.dataset.tone = "warn"; else cnt.removeAttribute("data-tone");
  document.getElementById("mapSave").disabled  = mpBusy || pend === 0;
  document.getElementById("mapReset").disabled = mpBusy || rev === 0;
}
function mpRender(){
  mpSyncSeg();
  const list = document.getElementById("mapList");
  if(!mpItems.length){
    list.innerHTML = '<div class="mp-empty">没读到映射表</div>';
    mpSyncFoot(); return;
  }
  const q = mpNorm(document.getElementById("mapSearch").value).toLowerCase();
  const rows = mpItems.filter(it=>it.type === Number(mpType));
  const shown = q ? rows.filter(it=>(it.sub + " " + it.group + " " + (mpVals[mpKey(it)] || "")).toLowerCase().includes(q)) : rows;
  if(!shown.length){
    list.innerHTML = '<div class="mp-empty">没找到匹配的分类</div>';
    mpSyncFoot(); return;
  }
  let html = "", lastG = null;
  shown.forEach(it=>{
    if(it.group !== lastG){ html += '<div class="mp-gh">' + mpEsc(it.group || "未分组") + '</div>'; lastG = it.group; }
    html += mpRowHtml(it);
  });
  list.innerHTML = html;
  mpGrowAll(list);
  mpSyncFoot();
}
async function mpLoad(reset){
  const tipEl = document.getElementById("mapTip");
  if(reset){
    document.getElementById("mapSearch").value = "";
    tipEl.textContent = MP_TIP;
    tipEl.removeAttribute("data-tone");
    document.getElementById("mapList").innerHTML = '<div class="mp-empty">正在读取…</div>';
  }
  mpBusy = true; mpSyncFoot();
  try{
    const r = await apiFetch("api.php?action=llm_map_get", { cache:"no-store" });
    const j = await r.json();
    if(j.requireLogin){ setView(false); mpBusy = false; closeMapEditor(true); return; }
    if(!j.success) throw new Error(j.error || "读取失败");
    mpItems  = j.data.items || [];
    mpMax    = j.data.max || 400;
    mpMetaOk = j.data.metaOk !== false;
    mpVals   = {};
    mpItems.forEach(it=>{ mpVals[mpKey(it)] = it.trig; });
    if(!mpItems.some(it=>it.type === Number(mpType)) && mpItems.length) mpType = String(mpItems[0].type);
    if(!mpMetaOk){
      tipEl.textContent = "没读到你的账本分类，下面的「账本有 / 账本无」暂时不准；触发词照旧可以改。";
      tipEl.dataset.tone = "warn";
    }
    mpRender();
  }catch(e){
    document.getElementById("mapList").innerHTML = '<div class="mp-empty">读取失败：' + mpEsc(e.message || "未知错误") + '</div>';
  }finally{
    mpBusy = false; mpSyncFoot();
  }
}
function openMapEditor(){
  document.getElementById("mapMask").hidden = false;
  return mpLoad(true);
}
async function closeMapEditor(force){
  if(!force){
    const n = mpPendingN();
    if(n > 0){
      const ok = await miniConfirm("放弃修改？", "有 " + n + " 处触发词改动还没保存，关闭后会丢失。", "放弃", true);
      if(!ok) return;
    }
  }
  document.getElementById("mapMask").hidden = true;
  mpItems = []; mpVals = {};
}
async function saveMapEditor(){
  const btn = document.getElementById("mapSave");
  const items = mpItems.map(it=>({ key:mpKey(it), sub:it.sub, trig:mpVals[mpKey(it)] ?? it.trig }));
  mpBusy = true; btn.disabled = true; btn.textContent = "保存中…";
  try{
    const r = await apiFetch("api.php?action=llm_map_save", { method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({ items }) });
    const j = await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error || "保存失败");
    const d = j.data || {};
    toast("映射表已保存" + (d.changed ? "（" + d.changed + " 条改动）" : ""));
    await mpLoad(false);          /* 重新拉一份当前主映射，避免继续显示「未保存」 */
    await loadAiSettings();
  }catch(e){
    toast(e.message || "保存失败");
  }finally{
    mpBusy = false; btn.textContent = "保存"; mpSyncFoot();
  }
}

document.getElementById("setAiMapRow").onclick = openMapEditor;
document.getElementById("mapClose").onclick = () => closeMapEditor();
document.getElementById("mapMask").addEventListener("click", e=>{ if(e.target.id === "mapMask") closeMapEditor(); });
document.getElementById("mapSearch").addEventListener("input", mpRender);
document.getElementById("mapSeg").addEventListener("click", e=>{
  const b = e.target.closest("button"); if(!b || b.dataset.t === mpType) return;
  mpType = b.dataset.t; mpRender();
});
document.getElementById("mapList").addEventListener("input", e=>{
  const ta = e.target.closest("textarea[data-key]"); if(!ta) return;
  mpVals[ta.dataset.key] = ta.value;
  mpAutoGrow(ta);
  mpSyncRow(ta.dataset.key);
  mpSyncFoot();
});
document.getElementById("mapList").addEventListener("click", e=>{
  const b = e.target.closest("button[data-rev]"); if(!b) return;
  const it = mpFind(b.dataset.rev); if(!it) return;
  const key=mpKey(it); mpVals[key] = it.base;
  document.getElementById("mapList").querySelectorAll("textarea[data-key]").forEach(t=>{
    if(t.dataset.key === key){ t.value = it.base; mpAutoGrow(t); }
  });
  mpSyncRow(key); mpSyncFoot();
});
document.getElementById("mapSave").onclick = saveMapEditor;
document.getElementById("mapReset").onclick = async () => {
  const n = mpRevertN();
  if(!n) return;
  const ok = await miniConfirm("全部还原", "将把 " + n + " 处触发词恢复成内置默认值，改完记得点「保存」。", "还原");
  if(!ok) return;
  mpItems.forEach(it=>{ mpVals[mpKey(it)] = it.base; });
  mpRender();
};

/* 轻确认：返回 Promise<boolean>，取消/确定/点遮罩/ESC 都会 resolve */
let miniResolve = null;
function miniConfirm(title, msg, okText, danger){
  document.getElementById("miniTitle").textContent = title;
  document.getElementById("miniMsg").textContent = msg;
  const ok = document.getElementById("miniOk");
  ok.textContent = okText || "确定";
  ok.classList.toggle("danger", !!danger);
  document.getElementById("miniMask").hidden = false;
  return new Promise(res => { miniResolve = res; });
}
function miniClose(v){
  document.getElementById("miniMask").hidden = true;
  const r = miniResolve; miniResolve = null;
  if(r) r(v);
}

document.getElementById("settingsBtn").onclick = openSettings;
document.getElementById("setClose").onclick = closeSettings;
document.getElementById("setMask").addEventListener("click", e=>{ if(e.target.id === "setMask") closeSettings(); });
document.getElementById("setThemeSeg").addEventListener("click", e=>{
  const b = e.target.closest("button"); if(!b) return;
  if(b.dataset.m === state.themeMode) return;
  state.themeMode = b.dataset.m;
  localStorage.setItem("ebk_theme", state.themeMode);
  applyTheme();
});

/* 清除缓存：本地离线缓存(CacheStorage) + 服务端 php/cache 的看板/元数据缓存；保留主题与登录态 */
document.getElementById("setClear").onclick = async () => {
  const ok = await miniConfirm("清除缓存", "将清理本地离线缓存与服务端数据缓存，下次打开会重新拉取最新数据。不会退出登录。", "清除");
  if(!ok) return;
  const btn = document.getElementById("setClear");
  btn.disabled = true; btn.textContent = "清理中…";
  /* 先注销 Service Worker：否则它会在删除过程中把缓存又写回来（实测清完仍残留 5 条） */
  try{
    const regs = await navigator.serviceWorker.getRegistrations();
    await Promise.all(regs.map(r => r.unregister()));
  }catch(e){}
  let removed = 0;
  try{
    const r = await apiFetch("api.php?action=clear_cache", { method:"POST", cache:"no-store" });
    const j = await r.json();
    if(j && j.data) removed = j.data.removed || 0;
  }catch(e){}
  /* 清 CacheStorage 放在最后：旧 SW 会连本次 clear_cache 的响应也缓存下来，
     所以服务端清理完成后再清本地，并补第二刀兜住迟到的在途写入 */
  const purgeCaches = async () => {
    try{ const ks = await caches.keys(); await Promise.all(ks.filter(k=>k.startsWith("ebk-pwa-")).map(k => caches.delete(k))); }catch(e){}
  };
  await purgeCaches();
  await new Promise(r => setTimeout(r, 350));
  await purgeCaches();
  btn.disabled = false; btn.textContent = "清除";
  closeSettings();
  toast("已清除缓存" + (removed ? "（服务端 " + removed + " 项）" : ""));
  setTimeout(()=>location.reload(), 600);   /* 重载后 SW 会重新注册并预缓存，离线能力自动恢复 */
};

document.getElementById("setLogout").onclick = async () => {
  const ok = await miniConfirm("退出登录", "确定要退出当前账号吗？", "退出", true);
  if(!ok) return;
  closeSettings();
  doLogout();
};

document.getElementById("miniCancel").onclick = () => miniClose(false);
document.getElementById("miniOk").onclick = () => miniClose(true);
document.getElementById("miniMask").addEventListener("click", e=>{ if(e.target.id === "miniMask") miniClose(false); });
document.addEventListener("keydown", e=>{
  if(e.key !== "Escape") return;
  if(!document.getElementById("miniMask").hidden){ miniClose(false); return; }
  if(!document.getElementById("mapMask").hidden){ closeMapEditor(); return; }
  if(!document.getElementById("setMask").hidden) closeSettings();
});

/* ================= 工具 ================= */
function symbol(){ const c = state.data?.profile?.defaultCurrency||"CNY"; return CURRENCY_SYMBOLS[c]||"¥"; }
function symbolOf(c){ return CURRENCY_SYMBOLS[c]|| (c ? c+" " : ""); }
function money(v,sign){ const s=v<0?"-":""; return sign?s+symbol()+nf.format(Math.abs(v)):s+symbol()+nf.format(Math.abs(v)); }
function moneyOf(v,currency){ const s=v<0?"-":""; return s+symbolOf(currency)+nf.format(Math.abs(v)); }
function moneyShort(v){
  const a=Math.abs(v), s=v<0?"-":"";
  if(a>=1e8) return s+symbol()+(a/1e8).toFixed(2)+"亿";
  if(a>=1e4) return s+symbol()+(a/1e4).toFixed(2)+"万";
  return s+symbol()+nf.format(a);
}
function moneyShort1(v){   /* 1 位小数版短金额（桑基图大类标签用，更省宽度）；万以下取整避免「¥2,240.97」式长串撑爆留白 */
  const a=Math.abs(v), s=v<0?"-":"";
  if(a>=1e8) return s+symbol()+(a/1e8).toFixed(1)+"亿";
  if(a>=1e4) return s+symbol()+(a/1e4).toFixed(1)+"万";
  if(a>=100) return s+symbol()+Math.round(a);
  return s+symbol()+nf.format(a);
}
function shortMonth(ym){ const [,m]=ym.split("-"); return parseInt(m,10)+"月"; }
function fmtDate(ts){ const d=new Date(ts*1000); return `${d.getMonth()+1}-${String(d.getDate()).padStart(2,"0")} ${String(d.getHours()).padStart(2,"0")}:${String(d.getMinutes()).padStart(2,"0")}`; }
function esc(s){ return String(s??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }
function countUp(el,target){
  const dur=700, t0=performance.now(), from=0;
  const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
  if(reduced){ el.textContent=money(target); return; }
  (function step(t){
    const p=Math.min(1,(t-t0)/dur), e=1-Math.pow(1-p,3);
    el.textContent=money(from+(target-from)*e);
    if(p<1) requestAnimationFrame(step);
  })(t0);
}
/* ============ 净资产 · 显示/隐藏（像素马赛克） ============ */
/* 眼睛图标：必须是「完整 <svg> 字符串」——netApply/netSetHidden 用 innerHTML 直接赋值给按钮，
   若只给裸 <path>/<circle> 会脱离 SVG 命名空间导致不渲染（此前“透明但可点”的根因） */
const EYE_ON  = '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/><path d="M3 3l18 18"/></svg>';
let netRaf=0, netSrc=null, netKey="", netData=null;
const netReduced = () => matchMedia("(prefers-reduced-motion: reduce)").matches;
function netToggle(){
  /* 净资产卡已移至资产页总览卡：直接切换状态并统一应用（马赛克动画仅首页旧布局使用） */
  netSetHidden(!state.netHidden);
  netApply();
}
function netSetHidden(h){
  state.netHidden=h;
  try{ localStorage.setItem("ebk_net_hidden", h?"1":"0"); }catch(e){}
  const b=document.getElementById("netEyeBtn");
  if(b){ b.innerHTML=state.netHidden?EYE_OFF:EYE_ON; b.title=state.netHidden?"显示净资产":"隐藏净资产"; }
  netSkpiSync(true);   /* 桑基图联动：汇总条模糊 + 图表标签/悬浮金额切换 */
}
/* 桑基图资产显示与净资产隐藏态联动：rerender=true 时重渲染图表（标签 formatter 内部读 state.netHidden）。
   资产总览卡隐藏态：银行 App 同款圆点遮罩（净资产/总资产/总负债互相可推导，全部遮罩） */
function netSkpiSync(rerender){
  const sk=document.getElementById("sankeyKpis");
  if(sk) sk.classList.toggle("net-masked",state.netHidden);
  const dots='<span class="mask-dots">••••••••</span>';
  const set=(id,html)=>{ const el=document.getElementById(id); if(el) el.innerHTML=html; };
  set("aoVal", state.netHidden?dots:money(state.data?.kpi?.netWorth||0));
  set("aoGross", state.netHidden?'<span class="mask-dots">¥ ••••</span>':money(state.aoGross||0,true));
  set("aoLiab", state.netHidden?'<span class="mask-dots">¥ ••••</span>':money(state.aoLiab||0,true));
  /* 账户余额列表同步遮罩（列表已渲染过才重渲，避免初始化时序问题） */
  const al=document.getElementById("acctList");
  if(state.data && al && al.children.length) renderAccounts(state.data);
  if(rerender&&state.data&&state.charts.sankeyChart&&!state.charts.sankeyChart.isDisposed())
    renderSankey(state.data,state._tt,state._C);
}
/* 依据状态即时呈现（无动画）：隐藏=整块马赛克，显示=数字文本 */
function netApply(){
  if(netRaf){ cancelAnimationFrame(netRaf); netRaf=0; }
  netSkpiSync(false);   /* 桑基汇总条/资产总览模糊先同步（与首页 KPI 元素解耦） */
  /* 眼睛按钮绑定/图标更新必须在前（首页净资产卡已移除，kpi_3 不存在时不阻断后续） */
  const b=document.getElementById("netEyeBtn");
  if(b){ b.innerHTML=state.netHidden?EYE_OFF:EYE_ON; b.title=state.netHidden?"显示净资产":"隐藏净资产"; b.onclick=netToggle; }
  const host=document.getElementById("kpi_3"), mask=document.getElementById("netMask");
  if(!host||!mask) return;   /* 首页旧卡已移除，此路径仅兼容旧结构 */
  if(state.netHidden){ host.classList.add("net-hidden"); mask.hidden=false; drawMosaic(0); }
  else{ host.classList.remove("net-hidden"); mask.hidden=true; }
}
/* 隐藏：马赛克墙从右向左推进，直到数字整体像素化 */
function netHide(){
  const host=document.getElementById("kpi_3"), mask=document.getElementById("netMask"), txt=document.getElementById("kpi_3_t");
  if(!host||!mask||!txt) return;
  netSetHidden(true);
  if(!txt.textContent.trim()||netReduced()){ netApply(); return; }
  host.classList.add("net-hidden"); mask.hidden=false;
  const W=host.clientWidth||1, dur=620, t0=performance.now();
  (function step(t){
    const p=Math.min(1,(t-t0)/dur), e=1-Math.pow(1-p,3);
    drawMosaic(W*(1-e));
    netRaf = p<1 ? requestAnimationFrame(step) : (netApply(), 0);
  })(t0);
}
/* 显示：马赛克从左向右揭开，数字逐渐恢复清晰 */
function netReveal(){
  const host=document.getElementById("kpi_3"), mask=document.getElementById("netMask"), txt=document.getElementById("kpi_3_t");
  if(!host||!mask||!txt) return;
  netSetHidden(false);
  if(!txt.textContent.trim()||netReduced()){ netApply(); return; }
  host.classList.add("net-hidden"); mask.hidden=false;      /* 动画期间仍以 canvas 呈现 */
  const W=host.clientWidth||1, dur=620, t0=performance.now();
  (function step(t){
    const p=Math.min(1,(t-t0)/dur), e=1-Math.pow(1-p,3);
    drawMosaic(W*e);
    netRaf = p<1 ? requestAnimationFrame(step) : (netApply(), 0);
  })(t0);
}
/* 核心：把净资产数字像素化。clearW 左侧为清晰区，右侧为马赛克砖区（砖缝透出卡片背景） */
function drawMosaic(clearW){
  const host=document.getElementById("kpi_3"), mask=document.getElementById("netMask"), txt=document.getElementById("kpi_3_t");
  if(!host||!mask||!txt) return;
  const W=host.clientWidth, H=host.clientHeight, text=txt.textContent||"";
  if(!W||!H) return;
  const dpr=Math.min(window.devicePixelRatio||1,2);
  mask.width=Math.round(W*dpr); mask.height=Math.round(H*dpr);
  const g=mask.getContext("2d"); g.setTransform(dpr,0,0,dpr,0,0);
  g.clearRect(0,0,W,H);
  if(!text.trim()) return;
  const cs=getComputedStyle(txt), font=cs.font||(cs.fontStyle+" "+cs.fontWeight+" "+cs.fontSize+" "+cs.fontFamily), color=cs.color;
  const key=W+"x"+H+"|"+text+"|"+font+"|"+color;
  if(!netSrc||netKey!==key){
    const src=document.createElement("canvas"); src.width=Math.round(W*dpr); src.height=Math.round(H*dpr);
    const s=src.getContext("2d",{willReadFrequently:true}); s.setTransform(dpr,0,0,dpr,0,0);
    s.font=font; s.fillStyle=color; s.textAlign="left"; s.textBaseline="alphabetic";
    const tm=s.measureText(text), sy=txt.offsetTop, sh=txt.offsetHeight;
    const asc=tm.actualBoundingBoxAscent||0, dsc=tm.actualBoundingBoxDescent||0;
    s.fillText(text, txt.offsetLeft, sy+(sh-(asc+dsc))/2+asc);      /* 与 DOM 文字视觉对齐 */
    netSrc=src; netKey=key; netData=s.getImageData(0,0,src.width,src.height).data;
  }
  const src=netSrc, sd=src.width/Math.max(1,W), P=netData;
  const B=10, gap=.8, step=Math.max(1,Math.round(sd));
  for(let by=0;by<H;by+=B){ const bh=Math.min(by+B,H)-by;
    for(let bx=0;bx<W;bx+=B){
      const x0=Math.floor(bx*sd), y0=Math.floor(by*sd), x1=Math.min(Math.ceil((bx+B)*sd),src.width), y1=Math.min(Math.ceil((by+B)*sd),src.height);
      let ink=false;
      for(let y=y0;y<y1&&!ink;y+=step){ const row=y*src.width*4;
        for(let px=x0;px<x1;px+=step){ if(P[row+px*4+3]>50){ ink=true; break; } } }
      if(!ink) continue;
      if(bx+B<=clearW){ g.drawImage(src,x0,y0,x1-x0,y1-y0,bx,by,(x1-x0)/sd,(y1-y0)/sd); continue; }
      if(bx<clearW) g.drawImage(src,x0,y0,x1-x0,y1-y0,bx,by,(x1-x0)/sd,(y1-y0)/sd);   /* 过渡块先铺清晰底 */
      g.globalAlpha=Math.min(1,Math.max(0,(bx+B-clearW)/B))*.9+.1;
      g.fillStyle=color;
      g.beginPath();
      if(g.roundRect) g.roundRect(bx+gap,by+gap,B-gap*2,bh-gap*2,1.8); else g.rect(bx+gap,by+gap,B-gap*2,bh-gap*2);
      g.fill(); g.globalAlpha=1;
    }
  }
}
function chartColors(){
  const cs=getComputedStyle(document.documentElement);
  return { text:cs.getPropertyValue("--text").trim(), sub:cs.getPropertyValue("--text-sub").trim(),
           dim:cs.getPropertyValue("--text-dim").trim(), grid:cs.getPropertyValue("--grid").trim(),
           tip:cs.getPropertyValue("--tip-bg").trim(), accent:cs.getPropertyValue("--accent").trim() };
}

/* ================= 数据拉取 ================= */
async function load(fresh=false){
  if(state.loading) return;
  state.loading=true;
  document.getElementById("refreshBtn").classList.add("spin");
  try{
    const qs = state.range==="custom" ? `&from=${state.customFrom}&to=${state.customTo}` : "";
    const r=await apiFetch(`api.php?action=dashboard&range=${state.range}${qs}${fresh?"&fresh=1":""}`,{cache:"no-store"});
    const j=await r.json();
    if(j.requireLogin){ setView(false); return; }
    if(!j.success) throw new Error(j.error||"未知错误");
    state.data=j.data;
    /* 刷新按钮：同步强刷当月 cal_month。
       这条曾经是「新账看不见」的**唯一解药**（那时写接口不失效 cal_ 缓存，只能靠这里强制 fresh=1）。
       现在 api.php 的 add_tx/edit_tx/delete_tx 都会自己失效缓存、前端也会 dropCalCache，
       普通刷新（F5）已经能看到最新数据；这里保留 fresh=1 是为了让「刷新同步」按钮
       语义上仍然是「无条件下拉最新」，不依赖任何缓存状态。 */
    if(fresh && state.calYM){
      try{ await ensureCalMonth(state.calYM, true); }catch(e){}
    }
    renderAll();
    warmMeta();          /* 看板就绪后顺手把记账卡片的数据源取回来（不阻塞首屏） */
    /* A2 预算：进度要拿服务端算好的 pct/level，单独一个轻请求。
       ⚠️ 必须放在 renderAll() 之后 —— 预算里的 spent 来自当月 dashboard 聚合，
       而 budget_get 读的是**服务端缓存**，所以要等上面这次 dashboard 响应把缓存写好了再问。
       失败静默：预算挂了不该让整个看板报错。 */
    fetchBudget().then(()=>{
      renderBudget();
      renderRank();          /* A2 联动：排行行尾的「预算 N%」标记依赖 state.budget，加载完补渲染一次 */
      const sd=document.getElementById("setBudDesc");
      if(sd) sd.textContent=(state.budget?.hasBudget)?`已设置 ${Object.keys(state.budget.config.monthly||{}).length} 项`:"设置每月总体与分类预算";
    }).catch(()=>{ renderBudget(); });
  }catch(e){ showError(e.message); }
  finally{
    state.loading=false;
    document.getElementById("refreshBtn").classList.remove("spin");
  }
}

/* ================= 财务健康度 ================= */
function renderHealth(d){
  const card=document.getElementById("healthCard"),host=document.getElementById("healthGrid");
  const h=d.health||[];
  if(!h.length){card.hidden=true;return}
  card.hidden=false;
  host.innerHTML=h.map(it=>{
    const hasNum = it.value!==null && it.value!==undefined && !Number.isNaN(+it.value);
    const num = !hasNum ? "—" : (it.unit==="%" ? (+it.value).toFixed(1) : Math.round(+it.value).toLocaleString());
    const unit = !hasNum ? "" : `<span class="hu">${esc(it.unit.trim())}</span>`;
    const full = `${esc(it.label)}${it.tag?` · ${esc(it.tag)}`:""}\n${esc(it.hint||"")}`;
    return `<div class="health-it" data-lv="${esc(it.level||"none")}">
      <div class="hl"><span class="st"></span>${esc(it.label)}<button class="hinfo" data-h="${esc(full)}" aria-label="查看口径说明"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></svg></button></div>
      <div class="hv">${num}${unit}</div>
      <div class="ht"><b>${esc(it.tag||"")}</b></div>
    </div>`;
  }).join("");
}

function showError(msg){
  document.getElementById("content").innerHTML =
    `<div class="card"><div class="errorbox"><div class="big">📡</div><p>${esc(msg)}</p><button id="errorReloadBtn">重新加载</button></div></div>`;
  document.getElementById("errorReloadBtn")?.addEventListener("click",()=>location.reload());
}

/* ================= 渲染 ================= */
function renderAll(){
  const d=state.data;
  document.getElementById("brandSub").textContent =
    d.profile.nickname ? `${d.profile.nickname} · ezBookkeeping` : "ezBookkeeping";
  /* 区间提示：自定义区间显示实际月份范围，其余保持默认 */
  const cm = d.range==="custom" && d.dateFrom ? `${d.dateFrom.slice(0,7)} ~ ${d.dateTo.slice(0,7)}` : null;
  document.getElementById("trendHint").textContent = cm || "近 12 个月";
  document.getElementById("assetHint").textContent = cm ? `日粒度 · ${d.dateFrom.slice(0,7)} ~ 今天` : "日粒度 · 近 12 个月";
  /* 首页日历/流水的显示月份：跟随 dashboard 区间最后一天；用户手动切月后保持所选 */
  if(!state.calYM) state.calYM = ((d.daily||[]).at(-1)?.date || new Date().toLocaleDateString("sv")).slice(0,7);
  renderHomeKPI(d);
  renderHealth(d);
  renderAssetsHead(d);
  renderCharts();
  renderAccounts(d);
  renderRank(d);
  renderCalendar();
  /* 不在这里调 initTodayOpen：此时 calCache 还没就位，会白白消耗掉「只初始化一次」的配额。
     renderTxByDay() 内部会调（那时数据已到），这里只需保证顺序即可。 */
  renderTxByDay();
  renderHomeKPI();
  /* footer 更新时间显示到秒：点刷新后同分钟内也能看出数据已更新 */
  const genDt=new Date(d.generatedAt*1000);
  const isToday=new Date().toLocaleDateString("sv")===genDt.toLocaleDateString("sv");
  document.getElementById("foot").innerHTML =
    `数据更新于 ${isToday?genDt.toLocaleTimeString("sv"):genDt.toLocaleString("sv")}${d.stale ? " · 当前为缓存快照，点右上刷新获取最新" : " · 数据来自 ezBookkeeping API"}`;
}
/* 资产页 · 资产总览卡：净资产大字 + 总资产/总负债（含隐藏切换与桑基联动） */
function renderAssetsHead(d){
  let gross=0, liab=0;
  (d.accounts_all||[]).forEach(a=>{
    if(a.hidden) return;
    if(a.isLiability) liab+=Math.abs(a.value||0);
    else gross+=Math.max(0,a.value||0);
  });
  state.aoGross=gross; state.aoLiab=liab;
  netApply();   // 数值填充与隐藏态渲染统一在 netSkpiSync（内部判空）
}
/* 首页四卡：支出/收入/结余/日均支出——calYM=dashboard 区间月时含环比，其它月从该月 daily 聚合 */
function renderHomeKPI(){
  const d=state.data, base=d?.kpi;
  if(!base) return;
  const ym=state.calYM;
  const dashYM=((d.daily||[]).at(-1)?.date||"").slice(0,7);
  const k={...base};
  const daily=state.calCache[ym]?.daily;
  if(daily && ym!==dashYM){                       // 切到历史月：从该月 daily 聚合（无环比）
    k.expense=round2(daily.reduce((s,x)=>s+x.expense,0));
    k.income=round2(daily.reduce((s,x)=>s+x.income,0));
    k.balance=round2(k.income-k.expense);
    k.savingRate=(k.income>=100&&k.income>=k.expense*0.05)?Math.round((k.income-k.expense)/k.income*1000)/10:null;
    delete k.expenseGrowth; delete k.incomeGrowth;
  }
  /* 日均支出：区间支出÷区间天数；本月 foot=「全月约」预测——移动端只显示「全月约 ¥x」，
     桌面显示完整「按 N 天平均 · 全月约 ¥x」（.dayavg-base 桌面外隐藏） */
  const days=Math.max(1,(d.daily||[]).length);
  const dayAvg=round2(k.expense/days);
  let dayFoot;
  if(d.range==="month"){
    const totalDays=new Date(+ym.slice(0,4),+ym.slice(5,7),0).getDate();
    dayFoot=`<span class="dayavg-base">按 ${days} 天平均 · </span>全月约 ${moneyShort(dayAvg*totalDays)}`;
  }else{
    dayFoot=`按 ${days} 天平均`;
  }
  const badge=(v,goodWhenUp)=>{
    if(v===null||v===undefined) return `<span style="color:var(--text-sub)">上期无数据</span>`;
    const up=v>0, cls=up?(goodWhenUp?"good":"bad"):(goodWhenUp?"bad":"good");
    const arrow=up?"▲":"▼";
    return `<span class="badge ${cls}">${arrow} ${Math.abs(v).toFixed(1)}%</span> 较上期`;
  };
  const footRate=k.savingRate!==null?`储蓄率 <b style="color:var(--text)">${k.savingRate}%</b>`:`<span style="color:var(--text-sub)" title="本期收入过小（不足 ¥100 或低于支出 5%），储蓄率不适用">储蓄率 —</span>`;
  const cards=[
    {label:"本期支出",color:"var(--rose)",v:k.expense,foot:k.expenseGrowth!==undefined?badge(k.expenseGrowth,false):`<span style="color:var(--text-sub)">月支出合计</span>`},
    {label:"本期收入",color:"var(--green)",v:k.income,foot:k.incomeGrowth!==undefined?badge(k.incomeGrowth,true):`<span style="color:var(--text-sub)">月收入合计</span>`},
    {label:"本期结余",color:"var(--accent)",v:k.balance,foot:footRate},
    {label:"日均支出",color:"var(--orange)",v:dayAvg,foot:dayFoot},
  ];
  document.getElementById("kpis").innerHTML=cards.map((c,i)=>{
    return `<div class="card kpi" style="animation-delay:${i*0.06}s">
      <div class="label"><span class="dot" style="background:${c.color}"></span>${c.label}</div>
      <div class="num" id="kpi_${i}">${money(0)}</div>
      <div class="foot">${c.foot}</div>
    </div>`;
  }).join("");
  cards.forEach((c,i)=>{ countUp(document.getElementById("kpi_"+i), c.v); });
}
function round2(v){ return Math.round(v*100)/100; }

/* ================= A2 · 预算管理 =================
   配置按账号存于服务端私有目录（键=分类名，大类写「餐饮」、小类写「餐饮/外卖点餐」）。
   进度由后端算（level/pct/over 都在后端），前端只负责渲染与编辑 —— 判定口径只有一处。 */

const BUD_EXPAND_KEY="ezbk_bud_expand";     // 「展开全部」是纯展示偏好，不必上云
state.budget=null;                           // 上次 budget_get 的 data
state.budRows=[];                            // 编辑弹窗里的行（未保存的工作副本）
let budExpanded=false;
let budSaving=false;

async function fetchBudget(){
    const ym=state.calYM||new Date().toLocaleDateString("sv").slice(0,7);
    const r=await apiFetch(`api.php?action=budget_get&ym=${encodeURIComponent(ym)}`,{cache:"no-store"});
  const j=await r.json();
  if(j.requireLogin){ setView(false); return null; }
  if(!j.success) throw new Error(j.error||"读取预算失败");
  state.budget=j.data;
  return j.data;
}
function budLevelOf(row){ return row.level||"none"; }

/* 首页预算卡。
   ⚠️ 无预算时不能隐藏整张卡 —— 那样用户永远发现不了这个功能。
   显示引导态，点「编辑」直接开弹窗。 */
function renderBudget(){
  const host=document.getElementById("budBody");
  const hint=document.getElementById("budHint");
  const b=state.budget;
  if(!host) return;
  if(!b){ host.innerHTML=`<div class="bud-loading">正在读取预算…</div>`; if(hint) hint.textContent=""; return; }

  if(!b.hasBudget){
    if(hint) hint.textContent="";
    host.innerHTML=`<div class="bud-empty"><p>还没有设置预算 · 设好额度就能在这里看到每月进度</p><button class="minibtn" id="budStartBtn">开始设置</button></div>`;
    const sb=document.getElementById("budStartBtn");
    if(sb) sb.onclick=()=>openBudget();
    return;
  }
  /* 进度依赖当月看板缓存；缓存还没生成时如实说明，不显示错的数字 */
  if(!b.progressOk){
    host.innerHTML=`<div class="bud-loading">正在等待本月账单数据…（先看一眼首页，稍后回来）</div>`;
    if(hint){ hint.textContent=""; }
    return;
  }
  const rows=b.progress||[];
  const total=rows.find(r=>r.isTotal);
  const cats=rows.filter(r=>!r.isTotal);
  if(hint) hint.textContent=`${b.month.replace("-","年")}月`;

  const bar=r=>{
    const w=Math.max(0,Math.min(100,r.pct));         // 超支时进度条封顶 100%，但百分比文字显示真实值
    return `<div class="bud-bar"><i style="width:${w}%"></i></div>`;
  };
  const line=r=>`<div class="bud-num"><span>${money(r.spent)}</span><span class="sep">/</span><span>${money(r.budget)}</span>${
    r.over>0?`<span class="left" style="color:var(--rose)">超出 ${money(r.over)}</span>`
            :`<span class="left">剩余 ${money(Math.max(0,r.budget-r.spent))}</span>`}</div>`;
  const one=(r,cls)=>`<div class="bud-it ${cls}" data-lv="${budLevelOf(r)}">
      <div class="bud-top">
        <span class="bud-nm">${r.color?`<span class="dot" style="background:${esc(r.color)}"></span>`:""}${
          esc(r.name.includes("/")?r.name.split("/").slice(1).join("/"):r.name)}${
          r.name.includes("/")?`<span class="sub">· ${esc(r.name.split("/")[0])}</span>`:""}</span>
        <span class="bud-pct">${r.pct>999?"999+":r.pct}%</span>
        ${r.over>0?`<span class="bud-over">超 ${money(r.over)}</span>`:""}
      </div>
      ${bar(r)}${line(r)}
    </div>`;

  const LIMIT=3;
  const show=budExpanded?cats:cats.slice(0,LIMIT);
  host.innerHTML=`<div class="bud-list">${total?one(total,"total"):""}${show.map(r=>one(r,"")).join("")}</div>${
    cats.length>LIMIT?`<button class="bud-more" id="budMoreBtn">${budExpanded?"收起":`展开全部 ${cats.length} 项`}</button>`:""}`;
  const mb=document.getElementById("budMoreBtn");
  if(mb) mb.onclick=()=>{ budExpanded=!budExpanded; renderBudget(); };
}

/* 打开编辑弹窗：用 budget_get 的配置铺行；每行带「当月实际支出」做参考。
   未保存的修改只活在 state.budRows 里，点取消直接丢弃。 */
let budPickingIdx=-1;     // 正在为哪一行选分类（-1 = 新增）
function openBudget(){
  if(!state.budget){
    fetchBudget().then(()=>openBudget()).catch(e=>toast(e.message||"读取预算失败"));
    return;
  }
  const b=state.budget;
  const spentOf=name=>{
    const r=(b.progress||[]).find(x=>x.name===name);
    return r?r.spent:0;
  };
  const known=new Set(Object.keys(b.config.monthly||{}).filter(k=>k!=="总体"));
  state.budRows=Object.entries(b.config.monthly||{})
    .filter(([k])=>k!=="总体")
    .map(([k,v])=>({name:k,budget:v,spent:spentOf(k),isNew:false}));
  document.getElementById("budTotalIn").value=(b.config.monthly||{})["总体"]||"";
  document.getElementById("budCur").textContent=symbol();
  document.getElementById("budErr").hidden=true;
  renderBudRows();
  document.getElementById("budMask").hidden=false;
  document.getElementById("budTotalIn").focus();
}
function closeBudget(){ document.getElementById("budMask").hidden=true; }

/* 分类名 → 显示用（小类显示名字，副标题标出大类），并带色点 */
function budCatInfo(name){
  const parts=name.split("/");
  const leaf=parts[parts.length-1];
  const top=parts.length>1?parts[0]:name;
  let color="";
  const gs=state.meta?.catGroups?.expense||[];
  for(const g of gs){
    if(g.gname===top||g.gname===name){
      if(parts.length>1){
        const it=(g.items||[]).find(x=>x.name===leaf);
        color=it?.color||g.gcolor||"";
      }else color=g.gcolor||"";
      break;
    }
  }
  return {leaf,top,sub:parts.length>1?top:"",color};
}
function renderBudRows(){
  const host=document.getElementById("budRows");
  const rows=state.budRows;
  if(!rows.length){
    host.innerHTML=`<div class="bud-loading">还没添加分类预算，点下面的按钮添加。</div>`;
    return;
  }
  host.innerHTML=rows.map((r,i)=>{
    const info=budCatInfo(r.name);
    return `<div class="bud-row">
      <div class="bi">
        <div class="bn">${info.color?`<span class="dot" style="background:${esc(info.color)}"></span>`:""}<span class="nm">${esc(info.leaf)}</span>${
          info.sub?`<span class="gone" style="background:transparent;color:var(--text-dim)">· ${esc(info.sub)}</span>`:""}${
          (!info.color&&!r.isNew)?`<span class="gone">分类不存在</span>`:""}</div>
        <div class="bs">本月已支出 ${money(r.spent||0)}</div>
      </div>
      <input class="money" inputmode="decimal" data-i="${i}" value="${r.budget||""}" placeholder="额度" autocomplete="off">
      <button class="del" data-del="${i}" title="删除" aria-label="删除"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>`;
  }).join("");
}

/* 分类选择：复用记账卡片的分类选择器（openPicker("cat")）。
   ⚠️ 两个坑（都实测过）：
   ① `renderPkRight` 的 click 处理直接写 `aiParsed.categoryId` —— **`aiParsed` 为 null 时抛 TypeError**。
      而 aiParsed 只在记账弹层打开时才被赋值，预算弹窗里它多半是 null。所以这里必须先垫一个对象。
   ② 点分类会**立刻调 closePicker()**，所以不能靠「轮询等弹窗关」来取结果（第一轮 poll 时已经关了、
      拿不到值）。改用**一次性钩子** `budPickHook`：拦住那一次 click 的结果再自己收尾。 */
let budPickHook=null;
function budPickCat(){
  if(!state.meta){ ensureMeta().then(()=>budPickCat()).catch(()=>toast("分类还没加载好")); return; }
  /* 垫一个最小的 aiParsed（type=3 支出树，pkGroups 会因此选 expense 树） */
  if(!aiParsed) aiParsed={type:3,categoryId:"",sourceAccountId:"",destinationAccountId:"0",sourceAmount:0,comment:"",tagIds:[],time:0};
  else aiParsed.type=3;
  aiParsed.categoryId="";
  budPickHook=(cid)=>{
    const info=budFindCatName(cid);
    if(!info){ toast("识别不到这个分类"); return; }
    if(state.budRows.some(r=>r.name===info)){ toast("该分类已经在列表里了"); return; }
    state.budRows.push({name:info,budget:"",spent:budSpentOf(info),isNew:true});
    renderBudRows();
  };
  openPicker("cat");
}
/* 分类 id → 「大类/小类」全名。大类自身记账时就是纯大类名（上游允许 category===parent） */
function budFindCatName(cid){
  cid=String(cid);
  const cm=state.meta?.catMap||{};
  const hit=cm[cid];
  if(!hit) return "";
  const pid=String(hit.parentId??"0");
  if(pid==="0"||!cm[pid]) return hit.name;      // 大类自身
  return cm[pid].name+"/"+hit.name;             // 小类
}
function budSpentOf(name){
  const r=(state.budget?.progress||[]).find(x=>x.name===name);
  return r?r.spent:0;
}

async function saveBudget(){
  if(budSaving) return;
  const btn=document.getElementById("budSave"), err=document.getElementById("budErr");
  const monthly={};
  const total=(document.getElementById("budTotalIn").value||"").trim();
  if(total!==""){
    const t=Number(total);
    if(!isFinite(t)||t<=0){ return budErr("总体预算要填一个大于 0 的数字，或留空表示不设"); }
    monthly["总体"]=round2(t);
  }
  for(const r of state.budRows){
    if(!r.name) continue;
    const raw=String(r.budget??"").trim();
    if(raw==="") continue;                       // 空额度 = 不设这一项，静默跳过
    const v=Number(raw);
    if(!isFinite(v)||v<=0){ return budErr(`「${r.name}」的额度要填大于 0 的数字，或留空不设`); }
    monthly[r.name]=round2(v);
  }
  err.hidden=true;
  budSaving=true; btn.disabled=true; btn.textContent="保存中…";
  try{
    const resp=await apiFetch("api.php?action=budget_save",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({monthly})});
    const j=await resp.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"保存失败");
    await fetchBudget();
    renderBudget();
    renderRank();          /* 排行行尾的「预算 N%」标记同步刷新 */
    /* 设置页那行描述同步一下，用户回到设置能看到当前状态 */
    const sd=document.getElementById("setBudDesc");
    if(sd) sd.textContent=(state.budget?.hasBudget)?`已设置 ${Object.keys(state.budget.config.monthly||{}).length} 项`:"设置每月总体与分类预算";
    closeBudget();
    toast("预算已保存");
  }catch(e){
    budErr(e.message||"保存失败");
  }finally{
    budSaving=false; btn.disabled=false; btn.textContent="保存";
  }
}
function budErr(msg){ const e=document.getElementById("budErr"); if(!e) return; e.textContent=msg; e.hidden=false; return false; }

/* 预算卡/弹窗的事件绑定（只绑一次） */
function bindBudget(){
  const eb=document.getElementById("budEditBtn");
  if(eb) eb.onclick=()=>openBudget();
  const sb=document.getElementById("setBudBtn");
  if(sb) sb.onclick=()=>{ closeSettings(); openBudget(); };
  const cl=document.getElementById("budClose"), cc=document.getElementById("budCancel");
  if(cl) cl.onclick=closeBudget;
  if(cc) cc.onclick=closeBudget;
  const sv=document.getElementById("budSave");
  if(sv) sv.onclick=saveBudget;
  const ad=document.getElementById("budAddBtn");
  if(ad) ad.onclick=()=>budPickCat();
  const mask=document.getElementById("budMask");
  if(mask) mask.onclick=e=>{ if(e.target===mask) closeBudget(); };
  /* 行内输入/删除用**事件委托**：行会被整体重绘，逐行绑监听必然失效 */
  const rows=document.getElementById("budRows");
  if(rows){
    rows.addEventListener("input",e=>{
      const inp=e.target.closest("input.money");
      if(!inp) return;
      const i=+inp.dataset.i;
      if(state.budRows[i]) state.budRows[i].budget=inp.value;
    });
    rows.addEventListener("click",e=>{
      const del=e.target.closest("[data-del]");
      if(!del) return;
      state.budRows.splice(+del.dataset.del,1);
      renderBudRows();
    });
  }
}

const LABELS={month:"本月",["3m"]:"近3月",["6m"]:"近6月",["1y"]:"近1年",year:"今年",custom:"自定义"};

/* 区间提示文案：自定义区间显示具体日期（后端 dateFrom/dateTo），其余用 LABELS */
function rangeText(d){
  return d.range==="custom" && d.dateFrom && d.dateTo ? `${d.dateFrom.slice(5)} ~ ${d.dateTo.slice(5)}` : LABELS[d.range];
}

/* ================= A3 · 图表下钻 =================
   「看到异常」→「处理异常」：点统计页/资产页的元素 → 跳流水页并带上筛选条件。
   传参走 sessionStorage（不是 URL query）：19 位分类 id 放 hash 里又长又难读，
   而且用户按返回键时不该再触发一次下钻。读完即删 → 一次性消费。 */
const DRILL_KEY="ezbk_drill";
function drillToTxs(filters){
  try{ sessionStorage.setItem(DRILL_KEY,JSON.stringify(filters)); }catch(e){}
  location.hash="#/txs";
}
/* 当前 dashboard 区间的起止日期（下钻时锁住区间，避免跳过去看到全量）。
   ⚠️ dateFrom/dateTo 只在 range==="custom" 时由后端下发，其余档位是 null。
   所以非自定义档位必须从 range 反推真实日期，否则下钻过去会丢掉日期条件
   （症状：点「本月餐饮」却查到全部历史支出）。 */
function drillRange(){
  const d=state.data;
  if(d?.dateFrom&&d?.dateTo) return {from:d.dateFrom, to:d.dateTo};
  const p=n=>String(n).padStart(2,"0");
  const now=new Date();
  const endStr=`${now.getFullYear()}-${p(now.getMonth()+1)}-${p(now.getDate())}`;
  const firstOfMonth=ym=>`${ym}-01`;
  const lastOfMonth=ym=>{ const[y,m]=ym.split("-").map(Number); return `${ym}-${p(new Date(y,m,0).getDate())}`; };
  const curYM=`${now.getFullYear()}-${p(now.getMonth()+1)}`;
  const back=n=>{ const t=new Date(now.getFullYear(),now.getMonth()-n,1); return `${t.getFullYear()}-${p(t.getMonth()+1)}`; };
  switch(d?.range){
    case "month": return {from:firstOfMonth(curYM), to:lastOfMonth(curYM)};
    /* 近N月：与后端 buildRanges 同口径——含当月，起点为 N-1 个月前的 1 日 */
    case "3m": case "6m": case "1y": {
      const n={"3m":3,"6m":6,"1y":12}[d.range];
      return {from:firstOfMonth(back(n-1)), to:endStr};
    }
    case "year": return {from:`${now.getFullYear()}-01-01`, to:endStr};
    default:
      /* 兜底：至少给出 dashboard 已返回的 daily 首尾，避免完全丢条件 */
      {
        const dl=d?.daily||[];
        if(dl.length) return {from:dl[0].date, to:dl[dl.length-1].date};
      }
      return {from:"", to:""};
  }
}
/* 流水页接管：回填筛选 → 展开「更多筛选」→ 自动查询。返回 true 表示确实消费了一次下钻 */
function txsApplyDrill(){
  let raw=null;
  try{ raw=sessionStorage.getItem(DRILL_KEY); }catch(e){ return false; }
  if(!raw) return false;
  try{ sessionStorage.removeItem(DRILL_KEY); }catch(e){}
  let f=null;
  try{ f=JSON.parse(raw); }catch(e){ return false; }
  if(!f||typeof f!=="object") return false;
  /* ⚠️ 分类/账户/标签的隐藏值控件必须先铺好，值必须已存在于 <option> 列表里，否则赋值静默变成 ""。
     而 fillTxsPickers() 挂在 ensureMeta().then() 上（异步，见 txsOnEnter），
     所以这里必须等 picker 铺好再回填——否则「点分类排行跳过去」会看不到分类条件，
     用户以为下钻坏了。txsOnEnter 已触发 fillTxsPickers，这里只等它就绪。 */
  const fill=()=>{
    const g=id=>document.getElementById(id);
    /* 兜底：分类/账户可能不在 option 里（分类树没覆盖到、账户被隐藏等）。
       <select> 赋值一个不存在的 value 会**静默变成 ""**，用户看到的就是「筛选没生效」。
       这里检测到没吃进去就补一个 option，保证下钻条件一定落到查询上。
       补进来的 option 打 data-dyn 标记，下次回填前先清掉，避免越积越多。 */
    const setSel=(sel,val,label)=>{
      const el=g(sel);
      el.querySelectorAll('option[data-dyn="1"]').forEach(o=>o.remove());
      if(!val){ el.value=""; return; }
      el.value=String(val);
      if(el.value!==String(val)){
        const o=document.createElement("option");
        o.value=String(val); o.textContent=label||`（已选 ${val}）`;
        o.dataset.dyn="1"; el.appendChild(o); el.value=String(val);
      }
    };
    g("txsQ").value=f.q||"";
    const t=+f.type||0;
    document.querySelectorAll("#txsTypeSeg button").forEach(b=>b.classList.toggle("on",+b.dataset.t===t));
    /* 下钻类型可能与进入前的筛选类型不同：先按新类型重建分类列表，避免收入分类残留在支出下。 */
    if(state.meta) fillTxsPickers();
    g("txsFrom").value=f.from||"";
    g("txsTo").value=f.to||"";
    setSel("txsCat",f.cat, f.catName);
    setSel("txsAcct",f.acct, f.acctName);
    setSel("txsTag",f.tag, f.tagName);
    syncTxsPickerUI();
    g("txsAmtMin").value=f.amtMin||"";
    g("txsAmtMax").value=f.amtMax||"";
    /* 有高级条件就必须展开「更多筛选」，否则用户看不到自己跳过来时带了什么条件 */
    const needMore=!!(f.from||f.to||f.cat||f.acct||f.tag||f.amtMin||f.amtMax);
    if(needMore){ g("txsMore").hidden=false; g("txsMoreBtn").textContent="收起筛选"; }
    if(txs.seq>0||needMore||f.q) txsSearch();
  };
  if(txs.metaFilled) fill();
  else ensureMeta().then(()=>{ fillTxsPickers(); fill(); }).catch(fill);   /* meta 失败也要回填，至少搜索框/日期能生效 */
  return true;
}

function renderCharts(){
  const d=state.data, C=chartColors();
  /* donutChart 单独处理：分类分析卡默认显示排行视图，环形容器 hidden 时 init 会得到 0 尺寸，
     仅在 tab 切到「环形」时才挂载（mountDonutChart） */
  ["trendChart","assetChart","dailyChart","sankeyChart","saveChart","dowChart","cmpChart","cumChart"].forEach(id=>{
    if(state.charts[id]) state.charts[id].dispose();
    state.charts[id]=echarts.init(document.getElementById(id),"wb");
    /* 容器尺寸变化（手机旋转/地址栏收展/分栏拖动）自动 resize，保证图表始终铺满居中 */
    const el=document.getElementById(id);
    if(window.ResizeObserver && !el.__ro){
      el.__ro=new ResizeObserver(()=>{ const c=state.charts[id]; if(c&&!c.isDisposed()) c.resize(); });
      el.__ro.observe(el);
    }
  });
  if(state.anaView==="donut") mountDonutChart();
  const tooltip={backgroundColor:C.tip,borderColor:C.grid,textStyle:{color:C.text,fontSize:12.5},
    extraCssText:"box-shadow:0 6px 20px rgba(0,0,0,.15);border-radius:10px;",
    confine:true,                                   /* 移动端 tooltip 限制在图表容器内，不溢出屏幕 */
    position:(p,params,dom,rect,size)=>{            /* 相对当前图表容器夹紧，不再固定参考 trendChart */
      const cw=(dom&&dom.parentNode?dom.parentNode.clientWidth:300)||300;
      return [Math.max(0,Math.min(p[0],cw-size.contentSize[0]-8)),10]}};

  /* --- 收支趋势 --- */
  /* 跨年时轴标签带年份（25/3），同年保持「3月」；跨年时 legend/grid 已有窄屏适配 */
  const trendYears=new Set(d.trends.map(t=>t.month.slice(0,4)));
  const trendCrossYear=trendYears.size>1;
  const months=d.trends.map(t=>trendCrossYear?t.month.slice(2).replace("-","/"):shortMonth(t.month));
  const trendNarrow=(document.getElementById("trendChart")?.clientWidth||400)<480;   /* 窄屏 legend 居中、留出轴标签空间 */
  state.charts.trendChart.setOption({
    color:["#18a768","#ef4d6e",C.accent],
    tooltip:{trigger:"axis",...tooltip,
      valueFormatter:v=>moneyShort(v)},
    legend:{top:0,...(trendNarrow?{left:"center",right:"auto"}:{right:0}),
      textStyle:{color:C.sub,fontSize:11.5},itemWidth:14,itemHeight:8,icon:"roundRect",itemGap:trendNarrow?14:10},
    grid:{left:8,right:trendNarrow?14:8,top:trendNarrow?40:34,bottom:0,containLabel:true},
    xAxis:{type:"category",data:months,axisLine:{lineStyle:{color:C.grid}},axisTick:{show:false},
      axisLabel:{color:C.dim,fontSize:11,hideOverlap:true}},   /* 标签自动隐藏防挤压重叠 */
    yAxis:{type:"value",splitLine:{lineStyle:{color:C.grid}},axisLabel:{color:C.dim,fontSize:11,
      formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
    series:[
      {name:"收入",type:"bar",data:d.trends.map(t=>t.income),barMaxWidth:14,itemStyle:{borderRadius:[4,4,0,0],opacity:.9}},
      {name:"支出",type:"bar",data:d.trends.map(t=>t.expense),barMaxWidth:14,itemStyle:{borderRadius:[4,4,0,0],opacity:.9}},
      {name:"结余",type:"line",data:d.trends.map(t=>+(t.income-t.expense).toFixed(2)),smooth:true,
        symbol:"circle",symbolSize:5,lineStyle:{width:2.2},
        areaStyle:{opacity:.08}},
    ]
  });

  /* --- 分类占比（环形视图时）--- */
  state._tt=tooltip; state._C=C;
  if(state.anaView==="donut") renderDonut(tooltip,C);

  /* --- 消费日历 --- */
  renderCalendar();

  /* --- 净资产走势 --- */
  const aDat=d.assets||[], aDates=aDat.map(a=>a.date.slice(5).replace("-","/")), aFew=aDat.length<=2;
  state.charts.assetChart.setOption({
    tooltip:{trigger:"axis",...tooltip,valueFormatter:v=>money(v,true)},
    graphic:aDat.length?[]:[{type:"text",left:"center",top:"middle",silent:true,
      style:{text:"暂无净资产数据（录入一笔交易后刷新）",fill:C.dim,fontSize:12.5}}],
    grid:{left:8,right:trendNarrow?16:14,top:20,bottom:0,containLabel:true},
    xAxis:{type:"category",data:aDates,boundaryGap:false,axisLine:{lineStyle:{color:C.grid}},
      axisTick:{show:false},axisLabel:{color:C.dim,fontSize:11,interval:Math.max(0,Math.ceil(aDates.length/8)-1),hideOverlap:true}},
    yAxis:{type:"value",scale:true,splitLine:{lineStyle:{color:C.grid}},
      axisLabel:{color:C.dim,fontSize:11,formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
    series:[{name:"净资产",type:"line",data:aDat.map(a=>a.net),smooth:true,
      symbol:aFew?"circle":"none",symbolSize:aFew?7:0,
      lineStyle:{width:2.4,color:C.accent},
      areaStyle:{color:new echarts.graphic.LinearGradient(0,0,0,1,[
        {offset:0,color:C.accent,opacity:.28},{offset:1,color:C.accent,opacity:0}])}}]
  });

  /* --- 资产组成（桑基图） --- */
  renderSankey(d,tooltip,C);

  /* --- 每日消费 --- */
  const days=d.daily.map(x=>x.date.slice(8)==="01"||d.daily.length<=40?x.date.slice(5).replace("-","/"):"");
  document.getElementById("dailyHint").textContent=`${rangeText(d)} · 支出`;
  const dailyNarrow=(document.getElementById("dailyChart")?.clientWidth||400)<480;
  state.charts.dailyChart.setOption({
    tooltip:{trigger:"axis",...tooltip,
      formatter:p=>{const x=d.daily[p[0].dataIndex];return `${esc(x.date)}<br/>支出 ${moneyShort(x.expense)}${x.income>0?`<br/>收入 ${moneyShort(x.income)}`:""}`}},
    grid:{left:8,right:dailyNarrow?12:8,top:14,bottom:d.daily.length>60?34:0,containLabel:true},
    dataZoom:d.daily.length>60?[{type:"inside"},{type:"slider",height:16,bottom:2,borderColor:C.grid,
      textStyle:{color:C.dim,fontSize:10},handleStyle:{color:C.accent},moveHandleSize:0}]:undefined,   /* 加 inside：手机上直接拖图表平移/缩放 */
    xAxis:{type:"category",data:days,axisLine:{lineStyle:{color:C.grid}},axisTick:{show:false},
      axisLabel:{color:C.dim,fontSize:dailyNarrow?10:10.5,interval:d.daily.length>40?"auto":0,hideOverlap:true,showMaxLabel:true}},   /* 长区间自动抽稀标签防重叠 */
    yAxis:{type:"value",splitLine:{lineStyle:{color:C.grid}},
      axisLabel:{color:C.dim,fontSize:11,formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
    series:[{type:"bar",data:d.daily.map(x=>x.expense),barMaxWidth:9,
      itemStyle:{borderRadius:[3,3,0,0],
        color:new echarts.graphic.LinearGradient(0,0,0,1,[{offset:0,color:C.accent},{offset:1,color:C.accent,opacity:.35}])}}]
  });

  /* --- 储蓄率趋势（月度折线 + 均值虚线） --- */
  const saveData=d.trends.map(t=>({m:shortMonth(t.month), v:t.income>0?Math.round((t.income-t.expense)/t.income*1000)/10:null}));
  const saveVals=saveData.map(x=>x.v).filter(v=>v!==null);
  const saveAvg=saveVals.length?Math.round(saveVals.reduce((s,v)=>s+v,0)/saveVals.length*10)/10:null;
  state.charts.saveChart.setOption({
    tooltip:{trigger:"axis",...tooltip,valueFormatter:v=>v===null||v===undefined?"—":v+"%"},
    grid:{left:8,right:14,top:30,bottom:0,containLabel:true},
    xAxis:{type:"category",data:saveData.map(x=>x.m),axisLine:{lineStyle:{color:C.grid}},axisTick:{show:false},
      axisLabel:{color:C.dim,fontSize:10.5,hideOverlap:true}},
    yAxis:{type:"value",min:0,max:100,splitLine:{lineStyle:{color:C.grid}},
      axisLabel:{color:C.dim,fontSize:10.5,formatter:"{value}%"}},
    series:[{type:"line",data:saveData.map(x=>x.v),smooth:true,symbol:"circle",symbolSize:6,connectNulls:true,
      lineStyle:{width:2.4,color:C.accent},itemStyle:{color:C.accent},
      areaStyle:{color:new echarts.graphic.LinearGradient(0,0,0,1,[{offset:0,color:C.accent,opacity:.22},{offset:1,color:C.accent,opacity:0}])},
      markLine:saveAvg!==null?{silent:true,symbol:"none",lineStyle:{type:"dashed",color:C.dim},
        label:{formatter:"均值 "+saveAvg+"%",color:C.dim,fontSize:10.5,position:"insideEndTop"},data:[{yAxis:saveAvg}]}:undefined}]
  });

  /* --- 星期消费分布（区间 daily 按星期聚合日均支出，峰值柱高亮） --- */
  const dowNames=["一","二","三","四","五","六","日"];
  const dowSum=Array(7).fill(0), dowCnt=Array(7).fill(0);
  (d.daily||[]).forEach(x=>{ if(!x.date) return; const dw=(new Date(x.date+"T00:00:00").getDay()+6)%7; dowSum[dw]+=x.expense; dowCnt[dw]++; });
  const dowAvg=dowSum.map((s,i)=>dowCnt[i]?Math.round(s/dowCnt[i]):0);
  const dowMax=Math.max(...dowAvg,1);
  state.charts.dowChart.setOption({
    tooltip:{trigger:"axis",...tooltip,valueFormatter:v=>moneyShort(v)},
    grid:{left:8,right:14,top:26,bottom:0,containLabel:true},
    xAxis:{type:"category",data:dowNames.map(n=>"周"+n),axisLine:{lineStyle:{color:C.grid}},axisTick:{show:false},
      axisLabel:{color:C.dim,fontSize:10.5}},
    yAxis:{type:"value",splitLine:{lineStyle:{color:C.grid}},
      axisLabel:{color:C.dim,fontSize:10.5,formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
    series:[{type:"bar",barMaxWidth:16,data:dowAvg.map(v=>({value:v,
      itemStyle:{borderRadius:[6,6,0,0],color:v===dowMax?"#ef4d6e":C.accent,opacity:v===dowMax?1:.72}})),
      label:{show:true,position:"top",color:C.dim,fontSize:10,formatter:p=>p.value?moneyShort(p.value):""}}]
  });

  /* --- 分类环比（本期 vs 上期 TOP8，涨红跌绿） --- */
  const cmpType=state.rankType;
  const cmpCur=d.categoryRank[cmpType]||[], cmpPrev=(d.catPrev&&d.catPrev[cmpType])||[];
  const prevMap={}; cmpPrev.forEach(x=>prevMap[x.id]=x.amount);
  const cmpRows=cmpCur.filter(x=>x.amount>0&&prevMap[x.id]!==undefined).map(x=>({
    name:x.name, cur:x.amount, prev:prevMap[x.id], diff:x.amount-prevMap[x.id]
  })).sort((a,b)=>Math.abs(b.diff)-Math.abs(a.diff)).slice(0,8);
  if(!cmpRows.length){
    state.charts.cmpChart.setOption({graphic:[{type:"text",left:"center",top:"middle",silent:true,
      style:{text:"暂无上期数据对比",fill:C.dim,fontSize:12.5}}]});
  }else{
    const rows=[...cmpRows].reverse();
    state.charts.cmpChart.setOption({
      tooltip:{trigger:"axis",...tooltip,formatter:ps=>{const p=ps[0], r=rows[p.dataIndex];
        const pct=r.prev>0?Math.round((r.cur-r.prev)/r.prev*100):null;
        return `${esc(r.name)}<br/>本期 ${moneyShort(r.cur)} · 上期 ${moneyShort(r.prev)}`+
          (pct!==null?`<br/>环比 <b style="color:${pct>0?"#ef4d6e":"#18a768"}">${pct>0?"+":""}${pct}%</b>`:"");}},
      grid:{left:8,right:44,top:10,bottom:0,containLabel:true},
      xAxis:{type:"value",splitLine:{lineStyle:{color:C.grid}},
        axisLabel:{color:C.dim,fontSize:10.5,formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
      yAxis:{type:"category",data:rows.map(r=>r.name),axisLine:{show:false},axisTick:{show:false},
        axisLabel:{color:C.sub,fontSize:11}},
      series:[{type:"bar",barMaxWidth:14,data:rows.map(r=>({value:r.cur,
        itemStyle:{borderRadius:[0,6,6,0],color:r.diff>=0?"#ef4d6e":"#18a768",opacity:.85}})),
        label:{show:true,position:"right",color:C.sub,fontSize:10.5,
          formatter:p=>{const r=rows[p.dataIndex];const pct=r.prev>0?Math.round((r.cur-r.prev)/r.prev*100):null;
            return (pct>0?"+":"")+pct+"%";}}}]
    });
  }

  /* --- 累计结余（区间内逐日累计） --- */
  const cumData=[]; let cum=0;
  (d.daily||[]).forEach(x=>{ cum+=x.income-x.expense; cumData.push({d:x.date.slice(5).replace("-","/"), v:Math.round(cum*100)/100}); });
  state.charts.cumChart.setOption({
    tooltip:{trigger:"axis",...tooltip,valueFormatter:v=>money(v,true)},
    grid:{left:8,right:14,top:20,bottom:0,containLabel:true},
    xAxis:{type:"category",data:cumData.map(x=>x.d),boundaryGap:false,axisLine:{lineStyle:{color:C.grid}},axisTick:{show:false},
      axisLabel:{color:C.dim,fontSize:10.5,interval:Math.max(0,Math.ceil(cumData.length/6)-1),hideOverlap:true}},
    yAxis:{type:"value",scale:true,splitLine:{lineStyle:{color:C.grid}},
      axisLabel:{color:C.dim,fontSize:10.5,formatter:v=>Math.abs(v)>=1e4?(v/1e4)+"万":v}},
    series:[{type:"line",data:cumData.map(x=>x.v),smooth:true,symbol:"none",
      lineStyle:{width:2.2,color:"#7a5af5"},
      areaStyle:{color:new echarts.graphic.LinearGradient(0,0,0,1,[{offset:0,color:"#7a5af5",opacity:.22},{offset:1,color:"#7a5af5",opacity:0}])}}]
  });

  /* ---- A3 下钻：给各图表挂点击 ---- */
  bindDrillHandlers(d);
}

/* ================= A3 · 图表点击绑定 =================
   统一在这里挂，避免散落在每个 setOption 旁边。
   每次 renderCharts() 都会重挂 → 必须先 off("click")，否则反复刷新会叠加多个监听，
   点一次跳多次（ECharts 不像 DOM 会去重）。 */
function bindDrillHandlers(d){
  const on=(id,fn)=>{
    const c=state.charts[id]; if(!c||c.isDisposed()) return;
    c.off("click"); c.on("click",fn);
    /* 鼠标变手型：告诉用户「这里可以点」，否则下钻功能等于不存在。
       ⚠️ 绝不能写 c.getZr().off("click") —— ECharts 的 c.on("click") 依赖
       ZRender 内部的 click 转发监听，off 掉之后 c.on 永远不会触发，
       表现为「按钮有、点了没反应」，且不报任何错。实测 clickFired=0。 */
    c.getZr().setCursorStyle("pointer");
  };
  const rg=()=>({from:d.dateFrom||"", to:d.dateTo||""});

  /* 每日消费：点某日柱 → 只看那一天 */
  on("dailyChart",p=>{
    const x=(d.daily||[])[p.dataIndex]; if(!x||!x.date) return;
    drillToTxs({from:x.date, to:x.date, type:3});
  });
  /* 收支趋势：点某月柱 → 该月全月（横轴是多序列柱，dataIndex 即月份下标） */
  on("trendChart",p=>{
    const t=(d.trends||[])[p.dataIndex]; if(!t||!t.month) return;
    const [y,m]=t.month.split("-");
    const last=new Date(+y,+m,0).getDate();     /* 该月最后一天 */
    drillToTxs({from:`${t.month}-01`, to:`${t.month}-${String(last).padStart(2,"0")}`, type:0});
  });
  /* 分类环比：点某条 → 该分类本期区间 */
  on("cmpChart",p=>{
    const type=state.rankType;
    const cur=(d.categoryRank?.[type])||[];
    const prevMap={}; ((d.catPrev&&d.catPrev[type])||[]).forEach(x=>prevMap[x.id]=x.amount);
    /* 与图表构建保持同一套排序/切片逻辑，否则点第 N 条会对到别的分类 */
    const rows=cur.filter(x=>x.amount>0&&prevMap[x.id]!==undefined)
      .map(x=>({o:x,diff:x.amount-prevMap[x.id]}))
      .sort((a,b)=>Math.abs(b.diff)-Math.abs(a.diff)).slice(0,8);
    const rowsAsc=[...rows].reverse();                 /* 图表里是反序画的 */
    const hit=rowsAsc[p.dataIndex]; if(!hit) return;
    drillToTxs({cat:String(hit.o.id), type:type==="expense"?3:2, from:rg().from, to:rg().to});
  });
  /* 星期消费分布：跨多个不连续日期，无法用单区间表达 → 不做下钻（保持 cursor 默认） */
  const dow=state.charts.dowChart;
  if(dow&&!dow.isDisposed()) dow.getZr().setCursorStyle("default");
}

/* 分类占比：默认大类视图，点击扇区下钻小类，‹大类 返回。
   视觉增强：更大环径 + 扇区外置标签（名称+占比），窄屏自动收起标签 */
function renderDonut(tooltip,C){
  tooltip=tooltip||state._tt; C=C||chartColors();
  const d=state.data;
  const tops=d.categoryRank[state.donutType]||[];
  /* 两侧都 String() 归一：dashboard SWR 缓存里可能还是旧版数字 id，单侧归一会变成
     「数字===字符串」恒 false → 点大类行没反应（2026-09-21 生产回归，勿只归一一侧） */
  const drilled=state.donutDrill!=null ? tops.find(x=>String(x.id)===String(state.donutDrill)) : null;
  document.getElementById("donutBack").hidden=!drilled;
  let list,top;
  if(drilled){
    list=drilled.subs||[];
    top=list.slice(0,8);
    if(list.length>8){
      const rest=list.slice(8).reduce((s,x)=>s+x.amount,0);
      top.push({name:"其他",amount:+rest.toFixed(2),color:"#8894b8"});
    }
  }else{
    list=tops;
    top=tops.slice(0,8);
    if(tops.length>8){
      const rest=tops.slice(8).reduce((s,x)=>s+x.amount,0);
      top.push({name:"其他",amount:+rest.toFixed(2),color:"#8894b8"});
    }
  }
  const total=list.reduce((s,x)=>s+x.amount,0);
  const el=document.getElementById("donutChart");
  const narrow=(el?.clientWidth||400)<380;   /* 手机上环形+图例紧凑化 */
  state.charts.donutChart.setOption({
    tooltip:{trigger:"item",...tooltip,
      formatter:p=>{
        const tail=!drilled&&p.data&&p.data.clickable
          ?"<span style='opacity:.55'>（点击看小类）</span>"
          :(p.data&&p.data.id!=null?"<span style='opacity:.55'>（点击看流水）</span>":"");
        return `${esc(p.name)}<br/>${money(p.value,true)} · ${p.percent}%${tail}`;
      }},
    legend:{bottom:0,left:"center",textStyle:{color:C.sub,fontSize:11},itemWidth:12,itemHeight:8,icon:"circle",
      type:"scroll",pageIconColor:C.sub,pageTextStyle:{color:C.dim},
      formatter:name=>{ const f=top.find(x=>x.name===name);
        return f?`${name} ${total>0?Math.round(f.amount/total*100):0}%`:name; }},
    title:{text:drilled?esc(drilled.name):moneyShort(total),
      subtext:drilled?(state.donutType==="expense"?"小类支出构成":"小类收入构成"):(state.donutType==="expense"?"总支出":"总收入"),
      left:"center",top:narrow?"34%":"36%",
      textStyle:{fontSize:narrow?14:16.5,fontWeight:700,color:C.text},subtextStyle:{fontSize:11.5,color:C.dim}},
    series:[{type:"pie",radius:narrow?["46%","66%"]:["52%","78%"],center:["50%",narrow?"42%":"45%"],avoidLabelOverlap:true,
      padAngle:1.2,itemStyle:{borderRadius:7,borderColor:C.tip,borderWidth:2},
      label:{show:!narrow&&top.length&&top.length<=9,formatter:"{b} {d}%",color:C.sub,fontSize:10.8,
        labelLine:{length:10,length2:8,lineStyle:{color:C.grid}}},
      emphasis:{scaleSize:7,label:{show:true,fontWeight:600,color:C.text}},
      data:top.length?top.map(x=>({name:x.name,value:x.amount,id:x.id,
        /* ⚠️ 不能用 subs.length 判断能不能下钻：大类可以自己记账，subs 里会含一条自身记录，
           没有子类的大类 subs 也是非空的 → 点了只会钻到「等于自己」的环，死循环。
           统一用后端给的 hasChildren（已排除自身）。旧缓存没有该字段时退化为「看 subs 里
           除自己以外还有没有别的」。 */
        clickable:!!((x.hasChildren!==undefined ? x.hasChildren : (x.subs||[]).some(s=>s.id!==x.id)) && !drilled),
        itemStyle:{color:x.color&&x.color!=="#"?x.color:PALETTE[0]}})):[{name:"暂无数据",value:0,tooltip:{show:false},itemStyle:{color:C.grid}}]}]
  });
}

/* 挂载环形容器：dispose 重建 + 点击下钻（切到「环形」tab 或每次 load 时调用） */
function mountDonutChart(){
  const el=document.getElementById("donutChart");
  if(state.charts.donutChart) state.charts.donutChart.dispose();
  state.charts.donutChart=echarts.init(el,"wb");
  /* 占比饼图点击下钻：大类扇区 → 小类视图（图表每次 dispose 重建，事件需在 init 后重挂）。
     注意：pie 的 click 参数没有 dataType==='series'（那是 graph/sankey 的 node/edge），用 componentType+seriesType 判断 */
  state.charts.donutChart.on("click",p=>{
    if(p.componentType==="series"&&p.seriesType==="pie"&&p.data&&p.data.clickable&&state.donutDrill==null){
      /* String() 归一：新 payload id 是字符串，旧缓存（dashboard SWR 24h）里可能还是数字，
         统一转字符串再存，renderDonut 里 find 时才能与两侧都匹配上 */
      state.donutDrill=String(p.data.id);
      renderDonut(state._tt,state._C);
      return;
    }
    /* A3 下钻：叶子扇区（无处可下钻）→ 直接跳流水页。
       「其他」是聚合出来的虚拟项，没有真实 id，必须排除，否则会拿 undefined 去查。 */
    if(p.componentType==="series"&&p.seriesType==="pie"&&p.data&&p.data.id!=null&&!p.data.clickable){
      drillToTxs({cat:String(p.data.id), catName:String(p.data.name||""), type:state.donutType==="expense"?3:2, ...drillRange()});
    }
  });
  /* 手型光标：有可点扇区才给 */
  state.charts.donutChart.getZr().setCursorStyle("pointer");
  if(window.ResizeObserver && !el.__ro){
    el.__ro=new ResizeObserver(()=>{ const c=state.charts.donutChart; if(c&&!c.isDisposed()) c.resize(); });
    el.__ro.observe(el);
  }
  if(state._tt) renderDonut(state._tt,state._C);
}

/* ================= 消费日历（热力图） ================= */
let calSel=null, calPeakHTML="";   // 点击选中的日期（YYYY-MM-DD）与默认峰值摘要缓存
/* 底部摘要条：未选中显示峰值，选中某天显示当日收支详情 */
function calDaySrc(ym){
  const cached=state.calCache[ym];
  return cached ? cached.daily : (state.data?.daily||[]);
}
function updateCalFoot(reset){
  const peakEl=document.getElementById("calPeak");
  if(!peakEl) return;
  if(reset) calSel=null;
  if(calSel){
    const rec=calDaySrc(state.calYM).find(x=>x.date===calSel);
    const [,m,dd]=calSel.split("-");
    peakEl.innerHTML=rec
      ?`<b>${+m}月${+dd}日</b> · 支出 <b>${money(rec.expense,true)}</b> · 收入 <b>${money(rec.income,true)}</b>`
      :`<b>${+m}月${+dd}日</b> · 无记录`;
    return;
  }
  peakEl.innerHTML=calPeakHTML;
}
/* 纯 CSS grid 手绘：7 列周一开头，色阶 4 档（0=无消费浅灰）。
   数据源：优先 cal_month 缓存（切月/点明细后加载），否则用 dashboard daily 过滤当月。
   格内日期数字 + 桌面悬停浮层 + 点击详情条 + 再点同天弹明细。 */
function renderCalendar(){
  const d=state.data; if(!d) return;
  const grid=document.getElementById("calGrid"), peakEl=document.getElementById("calPeak");
  if(!state.calYM){
    const last=(d.daily||[]).at(-1)?.date;
    state.calYM=(last||new Date().toLocaleDateString("sv")).slice(0,7);
  }
  const ym=state.calYM;
  const nowD=new Date();
  const nowYM=`${nowD.getFullYear()}-${String(nowD.getMonth()+1).padStart(2,"0")}`;
  const todayStr=`${nowD.getFullYear()}-${String(nowD.getMonth()+1).padStart(2,"0")}-${String(nowD.getDate()).padStart(2,"0")}`;
  document.getElementById("calYMBtn").textContent=`${+ym.slice(0,4)}年${+ym.slice(5,7)}月`;
  document.getElementById("calNextBtn").disabled = ym>=nowYM;   // 未来月不可前进
  const inMonth=calDaySrc(ym).filter(x=>x.date&&x.date.startsWith(ym));
  if(!inMonth.length){   // 该月无数据（空态）：渲染空网格
    grid.innerHTML=`<div class="cd-loading" style="grid-column:1/-1">该月暂无记录</div>`;
    grid.dataset.t=state.calType;
    peakEl.innerHTML="该月暂无记录";
    return;
  }
  const map={}; inMonth.forEach(x=>map[x.date]=x);
  const dim=new Date(+ym.slice(0,4),+ym.slice(5,7),0).getDate();
  const firstDow=(new Date(+ym.slice(0,4),+ym.slice(5,7)-1,1).getDay()+6)%7;   // 0=周一
  const max=Math.max(...inMonth.map(x=>Math.max(0,x[state.calType])),0);
  const safeMax=max>0.005?max:1;
  /* 连续热力：透明度按 v/max 线性（0.15~0.95），金额差异一眼可辨；明暗主题各自基色 */
  const dark=document.documentElement.dataset.theme==="dark";
  const heatRGB=state.calType==="expense"?(dark?"238,133,82":"224,81,46"):(dark?"47,202,133":"24,167,104");
  const heat=v=>v<=0.005?"":` style="background:rgba(${heatRGB},${(0.15+0.8*Math.min(1,v/safeMax)).toFixed(3)})"`;
  let html=["一","二","三","四","五","六","日"].map(w=>`<span class="wd">${w}</span>`).join("");
  for(let i=0;i<firstDow;i++) html+=`<div class="cal-pad"></div>`;   // 1 号前空位：仅占位对齐，不渲染成格子
  for(let day=1;day<=dim;day++){
    const ds=`${ym}-${String(day).padStart(2,"0")}`;
    const rec=map[ds];
    const v=rec?Math.max(0,rec[state.calType]):0;
    const isToday=ds===todayStr;
    const has=!!rec&&(rec.expense>0.005||rec.income>0.005);
    html+=`<div class="cal-cell${has?" has":""}${isToday?" today":""}${calSel===ds?" sel":""}"${heat(v)}${rec?` data-d="${+ym.slice(5,7)}/${day}" data-full="${ds}" data-exp="${rec.expense}" data-inc="${rec.income}"`:""}><span>${day}</span></div>`;
  }
  grid.innerHTML=html;
  grid.dataset.t=state.calType;
  updateCalFoot(true);
  const key=state.calType;
  const peak=inMonth.reduce((a,b)=>b[key]>a[key]?b:a,inMonth[0]);
  calPeakHTML=peak&&peak[key]>0.005
    ?`峰值 <b>${moneyShort(peak[key])}</b> · ${+peak.date.slice(5,7)}/${+peak.date.slice(8)}`
    :(state.calType==="expense"?"本月暂无支出":"本月暂无收入");
  if(calSel&&!map[calSel]) calSel=null;   // 选中日不在当前月份则清除
  updateCalFoot();
}
/* 日历浮层：仅桌面鼠标 hover 显示（点按查看金额由底部详情条承担）。
   触摸时 pointerover/pointerdown 直接跳过——否则 tap 会先弹浮层再被 click 隐藏，产生闪烁 */
(function(){
  const tip=document.createElement("div"); tip.id="calTip"; tip.hidden=true; document.body.appendChild(tip);
  const grid=()=>document.getElementById("calGrid");
  function move(e){
    tip.style.left=Math.min(e.clientX+12,window.innerWidth-tip.offsetWidth-8)+"px";
    tip.style.top=Math.min(e.clientY+14,window.innerHeight-tip.offsetHeight-8)+"px";
  }
  document.addEventListener("pointerover",e=>{
    const c=e.target.closest&&e.target.closest(".cal-cell.has");
    if(!c||!grid()||!grid().contains(c)) return;
    if((e.pointerType||"mouse")==="touch") return;   // 触摸走底部详情条
    tip.innerHTML=`<span class="d">${c.dataset.d}${c.classList.contains("today")?" 今天":""}</span>`+
      `支出 <b>${money(+c.dataset.exp,true)}</b>　收入 <b>${money(+c.dataset.inc,true)}</b>`;
    tip.hidden=false; move(e);
  });
  document.addEventListener("pointermove",e=>{
    if(tip.hidden) return;
    const c=e.target.closest&&e.target.closest(".cal-cell.has");
    if(c&&grid()&&grid().contains(c)) move(e); else tip.hidden=true;
  });
  document.addEventListener("pointerdown",e=>{
    if((e.pointerType||"mouse")==="touch") return;   // 触摸不弹浮层（防 tap 闪烁）
    const c=e.target.closest&&e.target.closest(".cal-cell.has");
    if(c&&grid()&&grid().contains(c)){
      tip.innerHTML=`<span class="d">${c.dataset.d}${c.classList.contains("today")?" 今天":""}</span>`+
        `支出 <b>${money(+c.dataset.exp,true)}</b>　收入 <b>${money(+c.dataset.inc,true)}</b>`;
      tip.hidden=false; move(e);
    } else tip.hidden=true;
  });
  /* 点击：首次选中（详情条）；再点同一天 → 打开当日明细弹窗；点空白取消选中 */
  document.addEventListener("click",e=>{
    const g=grid(); if(!g) return;
    const c=e.target.closest&&e.target.closest(".cal-cell.has");
    if(c&&g.contains(c)&&c.dataset.full){
      if(calSel===c.dataset.full){ tip.hidden=true; openDayDetail(c.dataset.full); return; }
      calSel=c.dataset.full;
      g.querySelectorAll(".cal-cell").forEach(x=>x.classList.toggle("sel",x.dataset.full===calSel));
      updateCalFoot();
      tip.hidden=true;   // 浮层与详情条二选一，点击后详情条接管
    } else if(calSel && (!e.target.closest||!e.target.closest("#calGrid"))){
      calSel=null; g.querySelectorAll(".cal-cell.sel").forEach(x=>x.classList.remove("sel")); updateCalFoot();
    }
  });
})();

/* ================= 日历切月 + 当日明细弹窗 ================= */
/* 按月拉取缓存（后端 cal_month：历史月缓存 1 年≈永久，当月短缓存）；前端内存再缓存一层 */
async function ensureCalMonth(ym, fresh=false){
  /* fresh=true 强制绕过服务端缓存（刷新按钮用）；
     有缓存且非强制、且**未被写操作标脏**时直接复用。
     calDirty[ym]：写交易后置位 —— 内存里那份是乐观更新的结果，本身没错，
     但一旦有别的路径（route/renderAll/切月回来）以 fresh=false 再取，必须让它真的回源，
     否则界面会长期停在一个「看起来是最新的」内存副本上（写接口已失效服务端缓存，却没人去读）。 */
  if(state.calCache[ym] && !fresh && !calDirty[ym]) return state.calCache[ym];
  const r=await apiFetch(`api.php?action=cal_month&ym=${ym}${fresh?"&fresh=1":""}`,{cache:"no-store"});
  const j=await r.json();
  if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
  if(!j.success) throw new Error(j.error||"加载失败");
  state.calCache[ym]=j.data;
  delete calDirty[ym];                    /* 已经拿到最新的了，脏标记清掉 */
  return j.data;
}
async function setCalYM(ym){
  if(!ym||state.calYM===ym) return;
  state.calYM=ym; calSel=null; calPeakHTML="";
  renderCalendar();                       // 先渲染（无数据时显示空态）
  if(!state.calCache[ym]){
    try{ await ensureCalMonth(ym); }
    catch(e){ resetTxOpenDays(ym); renderHomeKPI(); renderTxByDay(); return; }
  }
  resetTxOpenDays(ym);                    // 切月必须清空展开态：否则上个月的日期键残留
  renderCalendar();
  renderHomeKPI();                        // 首页 KPI 联动切月
  renderTxByDay();                        // 当月流水联动
  try{ await fetchBudget(); renderBudget(); }
  catch(e){ toast(e.message||"预算读取失败"); }
}
function shiftCalYM(delta){
  const [y,m]=state.calYM.split("-").map(Number);
  const d=new Date(y,m-1+delta,1);
  const ym=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}`;
  const now=new Date(); const nowYM=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,"0")}`;
  if(ym>nowYM) return;                    // 不能翻到未来
  setCalYM(ym);
}
document.getElementById("calPrevBtn").addEventListener("click",()=>shiftCalYM(-1));
document.getElementById("calNextBtn").addEventListener("click",()=>shiftCalYM(1));
/* 当日明细弹窗 */
function localDateStr(ts){
  const d=new Date(ts*1000);
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`;
}
async function openDayDetail(dateStr){
  const mask=document.getElementById("cdModalMask");
  document.getElementById("cdTitle").textContent=`${+dateStr.slice(5,7)}月${+dateStr.slice(8)}日`;
  document.getElementById("cdSub").textContent="加载中…";
  document.getElementById("cdList").innerHTML=`<div class="cd-loading">加载中…</div>`;
  document.getElementById("cdSum").innerHTML="";
  mask.hidden=false;
  try{
    const data=await ensureCalMonth(dateStr.slice(0,7));
    const txs=(data.transactions||[]).filter(t=>localDateStr(t.time)===dateStr);
    renderCalDetail(dateStr,txs);
  }catch(e){
    document.getElementById("cdSub").textContent="加载失败";
    document.getElementById("cdList").innerHTML=`<div class="cd-loading">${esc(e.message||"加载失败")}</div>`;
  }
}
function renderCalDetail(dateStr,txs){
  const wd="日一二三四五六"[new Date(dateStr+"T00:00:00").getDay()];
  document.getElementById("cdSub").textContent=`周${wd} · ${txs.length} 笔交易`;
  const list=document.getElementById("cdList");
  list.innerHTML = txs.length ? txs.map(t=>{
    const p=txParts(t,{timeFmt:"hm"});
    const meta=[p.acct,p.memo].filter(Boolean).join(" · ");
    /* 与最近交易同一套 tx 片段：pill 前置替代色点、次行「备注·流向 + 时间」 */
    return `<div class="cd-row"><div class="tx-crow">${p.pill}<span class="cnm">${p.label}</span>${p.amtStr}</div><div class="tx-mrow"><span class="meta1">${esc(meta)||"—"}</span><span class="tm">${p.timeStr}</span></div></div>`;
  }).join("") : `<div class="cd-loading">该日无交易记录</div>`;
  const inc=txs.filter(t=>t.type===2).reduce((s,t)=>s+t.amount,0);
  const exp=txs.filter(t=>t.type===3).reduce((s,t)=>s+t.amount,0);
  const bal=inc-exp;
  document.getElementById("cdSum").innerHTML=
    `<span>支出 <b>${money(exp,true)}</b></span><span>收入 <b>${money(inc,true)}</b></span>`+
    `<span style="margin-left:auto">结余 <b style="color:${bal<0?"var(--rose)":"var(--green)"}">${money(bal,true)}</b></span>`;
}
function closeDayDetail(){ document.getElementById("cdModalMask").hidden=true; }
document.getElementById("cdClose").addEventListener("click",closeDayDetail);
document.getElementById("cdModalMask").addEventListener("click",e=>{ if(e.target.id==="cdModalMask") closeDayDetail(); });
document.addEventListener("keydown",e=>{ if(e.key==="Escape"&&!document.getElementById("cdModalMask").hidden) closeDayDetail(); });

/* 月份选择器面板 */
let calYMPickYear=null;
function renderCalYMPicker(){
  document.getElementById("calYMYear").textContent=calYMPickYear+" 年";
  const now=new Date(); const nowYM=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,"0")}`;
  document.getElementById("calYMGrid").innerHTML=[...Array(12)].map((_,i)=>{
    const ym=`${calYMPickYear}-${String(i+1).padStart(2,"0")}`;
    const future=ym>nowYM, cur=ym===state.calYM;
    return `<button data-ym="${ym}"${future?" disabled":""}${cur?' class="on"':""}>${i+1}月</button>`;
  }).join("");
}
document.getElementById("calYMBtn").addEventListener("click",()=>{
  calYMPickYear=+state.calYM.slice(0,4);
  renderCalYMPicker();
  document.getElementById("calYMPanel").hidden=false;
});
document.getElementById("calYearPrev").addEventListener("click",()=>{ calYMPickYear--; renderCalYMPicker(); });
document.getElementById("calYearNext").addEventListener("click",()=>{ calYMPickYear++; renderCalYMPicker(); });
document.getElementById("calYMGrid").addEventListener("click",e=>{
  const b=e.target.closest("button[data-ym]"); if(!b||b.disabled) return;
  document.getElementById("calYMPanel").hidden=true;
  setCalYM(b.dataset.ym);
});
document.getElementById("calYMPanel").addEventListener("click",e=>{ if(e.target.id==="calYMPanel") e.target.hidden=true; });
document.addEventListener("keydown",e=>{ if(e.key==="Escape"&&!document.getElementById("calYMPanel").hidden) document.getElementById("calYMPanel").hidden=true; });

/* 分类排行：支出/收入类型与「环形」视图共用（anaTypeSeg），支持下钻小类 */
function renderRank(){
  const d=state.data;
  const tops=d.categoryRank[state.rankType]||[];
  const drilled=state.rankDrill!=null ? tops.find(x=>String(x.id)===String(state.rankDrill)) : null;
  document.getElementById("rankBack").hidden=!drilled;
  const list=drilled?(drilled.subs||[]):tops;
  const subTotal=list.reduce((s,x)=>s+x.amount,0);
  const max=Math.max(...list.map(x=>x.amount),1);
  document.getElementById("rankList").innerHTML = list.length ? list.map((x,i)=>{
    const color=x.color&&x.color!=="#"?x.color:PALETTE[i%PALETTE.length];
    const pct=drilled?(subTotal>0?Math.round(x.amount/subTotal*1000)/10:0):x.pct;
    const hasSubs=!drilled&&x.subs&&x.subs.length;
    /* A2 联动：该项设了预算时，在金额后挂一个小标记（只在支出视图、未下钻时显示，
       因为预算口径是「大类/子类」名，与小类下钻的行名对不上，硬标会误导）。 */
    const bp=(!drilled&&state.rankType==="expense")?budProgressOf(x.name):null;
    /* 百分比只展示到 999%（再多就是额度设得极小，读数已无意义），完整值放 title */
    const bpTxt=bp?(bp.pct>999?"999+":bp.pct):null;
    const budTag=bp?`<span class="rk-bud" data-lv="${esc(bp.level)}" title="预算 ${money(bp.budget)} · 已用 ${bp.pct}%">预算 ${bpTxt}%</span>`:"";
    /* 下钻按钮常驻在行尾（桌面 hover 才显形会让人以为没有这个功能；
       移动端没有 hover 更必须常驻）。小屏用 CSS 隐藏以省空间。 */
    return `<div class="rankrow" data-id="${x.id}" ${hasSubs?`title="点击看小类"`:""}>
      <span class="no">${i+1}</span>
      <span class="cd" style="background:${color}"></span>
      <span class="nm" title="${esc(x.name)}">${esc(x.name)}${hasSubs?` <span class="rk-sub-ico">${arrowSVG("right")}</span>`:""}</span>
      <div class="bar"><i style="width:${Math.round(x.amount/max*100)}%;background:${color}"></i></div>
      ${budTag}
      <span class="amt">${moneyShort(x.amount)}</span>
      <span class="pct">${pct}%</span>
      <button type="button" class="rk-go" title="查看${esc(x.name)}的流水" aria-label="查看${esc(x.name)}的流水">流水 ${arrowSVG("right")}</button>
    </div>`;
  }).join("") : `<div class="empty">本期暂无${state.rankType==="expense"?"支出":"收入"}记录</div>`;
}

/* 排行视图的预算标记查询：命中返回 {budget,pct,level}，未设预算返回 null。
   预算还没加载完（state.budget 为 null / 进度不可用）时静默返回 null，绝不抛错。 */
function budProgressOf(name){
  const b=state.budget;
  if(!b||!b.progressOk||!b.hasBudget) return null;
  const r=(b.progress||[]).find(x=>!x.isTotal&&x.name===name);
  return r?{budget:r.budget,pct:r.pct,level:r.level}:null;
}

/* ================= 资产组成 · 桑基图 ================= */
/* 结构：负债项 + 净资产 → 总资产 → 四大类 → 具体账户（复刻有知有行「家庭资产组成」）。
   节点色优先取账户在 ezBookkeeping 后台设置的颜色（与账户配色方案联动），缺省回退类别色。 */
const SK_CAT={1:["流动资金","#3f66f8"],2:["流动资金","#3f66f8"],4:["流动资金","#3f66f8"],
  8:["储蓄存款","#1565C0"],9:["定期存款","#C79100"],7:["投资理财","#e6a700"],
  6:["应收款项","#7a5af5"],3:["其他资产","#8894b8"],5:["其他资产","#8894b8"],0:["其他资产","#8894b8"]};

function renderSankey(d,tooltip,C){
  const el=document.getElementById("sankeyChart"); if(!el) return;
  /* 用视口宽度判断设备，不用图表容器宽度：资产页隐藏时容器宽度是 0，
     从其他页面进入资产页会被误判成移动端简版。桌面端始终显示账户明细。 */
  const catsOnly=(window.innerWidth||document.documentElement.clientWidth||560)<560;
  renderSankeyCore(d,tooltip,C,state.charts.sankeyChart,el,true,catsOnly);
}

/* 核心渲染：collapse=true 时窄容器做 TOP-N 收敛（画布内嵌视图），false 时完整节点（全屏弹窗）；
   catsOnly=true 时只画到大类层（移动竖屏极简视图），不再展开具体账户 */
function renderSankeyCore(d,tooltip,C,chart,el,collapse,catsOnly){
  const all=(d.accounts_all||[]).filter(a=>!a.hidden&&Math.abs(a.value)>=0.01);
  const liab=all.filter(a=>a.isLiability).sort((x,y)=>Math.abs(y.value)-Math.abs(x.value));
  const asset=all.filter(a=>!a.isLiability).sort((x,y)=>Math.abs(y.value)-Math.abs(x.value));
  const totalLiab=liab.reduce((s,a)=>s+Math.abs(a.value),0);
  const totalAsset=asset.reduce((s,a)=>s+a.value,0);
  const net=totalAsset-totalLiab;

  /* 顶部汇总条：与资产总览卡信息重复已移除 DOM；保留判空兼容 */
  if(collapse){ const skp=document.getElementById("sankeyKpis"); if(skp) skp.innerHTML=
    `<div class="it"><b>总资产</b><span class="v" style="color:var(--accent)">${moneyShort(totalAsset)}</span></div>`+
    `<div class="it"><b>总负债</b><span class="v" style="color:var(--rose)">${moneyShort(totalLiab)}</span></div>`+
    `<div class="it"><b>净资产</b><span class="v" style="color:var(--purple)">${moneyShort(net)}</span></div>`;
  }

  if(!asset.length&&!liab.length){
    chart.setOption({graphic:[{type:"text",left:"center",top:"middle",silent:true,
      style:{text:"暂无账户数据（新增账户后刷新）",fill:C.dim,fontSize:12.5}}]});
    return;
  }

  const narrow=(window.innerWidth||document.documentElement.clientWidth||560)<560;   /* 移动端视觉紧凑；桌面端始终使用详细布局 */
  const compact=narrow;
  const doCollapse=collapse&&compact;       /* 节点收敛：仅画布内嵌视图（弹窗显示全部节点，只借用紧凑视觉） */

  /* 画布内嵌视图节点收敛：两侧各保留 TOP-N 账户，其余并入聚合节点——防小屏节点过密、标签重叠。
     catsOnly 极简视图：两侧账户层整体跳过，只留 负债/净资产→总资产→大类 三层 */
  let liabList=liab, assetList=asset, liabRest=[], assetRest=[];
  if(catsOnly){
    liabList=[]; assetList=[];
  }else if(doCollapse){
    liabList=liab.slice(0,4);  liabRest=liab.slice(4);
    assetList=asset.slice(0,6); assetRest=asset.slice(6);
  }
  const tr=s=>compact&&s.length>6?s.slice(0,6)+"…":s;   /* 紧凑视图长账户名截断（两侧留白按 6 字预留） */
  const minL=doCollapse?Math.max(totalAsset,totalLiab)*0.025:0;   /* 仅画布内嵌收敛视图隐藏小额标签；弹窗全量显示 */

  /* 节点构建：内部 key 带类型前缀（S:汇总 C:类别 A:账户），展示名走 SK_LABEL 查表——
     防止账户名与汇总/类别重名（如账户「定期存款」vs 类别「定期存款」）产生自环导致 DAG 报错 */
  const nodes=[],idx={},SK_LABEL={};
  const nc=c=>{ if(typeof c!=="string") return null; const s=c.trim();
    return /^#[0-9a-fA-F]{6}$/.test(s)?s:(/^[0-9a-fA-F]{6}$/.test(s)?"#"+s:null); };   /* 兼容带/不带 # 的色值 */
  const pushNode=(key,disp,value,color,opts={})=>{
    SK_LABEL[key]=disp;
    if(idx[key]!==undefined){ nodes[idx[key]].value=+(nodes[idx[key]].value+value).toFixed(2); return; }
    idx[key]=nodes.length;
    const hide=!opts.bold&&!opts.keep&&value<minL;   /* 窄屏小额节点隐藏标签 */
    /* fontSize：完整视图加粗节点放大到 12.5；catsOnly 统一用系列级小字号（否则左标签的净资产继承 9.8、
       右侧加粗大类 12.5，大小不一——用户反馈「净资产的字体比其他的都小」的根因） */
    nodes.push({name:key,value:+value.toFixed(2),
      itemStyle:{color,borderRadius:3},
      label:opts.left?{show:!hide,position:"left",fontWeight:opts.bold?700:undefined}:(
             {show:!hide,fontWeight:opts.bold?700:undefined,fontSize:(opts.bold&&!catsOnly)?12.5:undefined})});
  };
  const links=[];
  liabList.forEach(a=>{ pushNode("A:"+a.name,tr(a.name),Math.abs(a.value),nc(a.color)||"#ef4d6e",{left:true}); });
  if(liabRest.length){   /* 移动端：其余负债并入单个聚合节点 */
    const v=liabRest.reduce((s,a)=>s+Math.abs(a.value),0);
    pushNode("A:其他负债",`其他负债${liabRest.length}项`,v,"#ef4d6e",{left:true,keep:true});
    links.push({source:"A:其他负债",target:"S:负债",value:+v.toFixed(2)});
  }
  if(totalLiab>0.01){
    liabList.forEach(a=>links.push({source:"A:"+a.name,target:"S:负债",value:+Math.abs(a.value).toFixed(2)}));
    pushNode("S:负债","负债",totalLiab,"#ef4d6e",{bold:true});
    links.push({source:"S:负债",target:"S:总资产",value:+totalLiab.toFixed(2)});
  }
  pushNode("S:净资产","净资产",Math.max(net,0.01),"#7a5af5",{left:true,bold:true});
  links.push({source:"S:净资产",target:"S:总资产",value:+Math.max(net,0.01).toFixed(2)});
  pushNode("S:总资产","总资产",Math.max(totalAsset,net+totalLiab,0.01),"#3f66f8",{bold:true});

  const catAgg={};
  asset.forEach(a=>{ const cm=SK_CAT[a.category]||["其他资产","#8894b8"];
    catAgg[cm[0]]=catAgg[cm[0]]||{v:0,color:cm[1]}; catAgg[cm[0]].v+=a.value; });
  Object.entries(catAgg).sort((x,y)=>y[1].v-x[1].v).forEach(([cn,m])=>{
    pushNode("C:"+cn,cn,m.v,m.color,{bold:true});
    links.push({source:"S:总资产",target:"C:"+cn,value:+m.v.toFixed(2)});
  });
  /* 大类极简视图到此为止（不展开账户层）；完整视图继续加账户/聚合账户节点 */
  if(!catsOnly){
  assetList.forEach(a=>{ const cn=(SK_CAT[a.category]||["其他资产"])[0];
    pushNode("A:"+a.name,tr(a.name),a.value,nc(a.color)||"#3f66f8",{left:false});
    links.push({source:"C:"+cn,target:"A:"+a.name,value:+a.value.toFixed(2)});
  });
  if(assetRest.length){   /* 移动端：其余资产并入聚合节点，按类别分流入边（与类别聚合口径一致） */
    const byCat={};
    assetRest.forEach(a=>{ const cn=(SK_CAT[a.category]||["其他资产","#8894b8"])[0]; byCat[cn]=(byCat[cn]||0)+a.value; });
    const v=assetRest.reduce((s,a)=>s+a.value,0);
    pushNode("A:其他账户",`其他账户${assetRest.length}项`,v,"#8894b8",{left:false,keep:true});
    Object.entries(byCat).forEach(([cn,v2])=>links.push({source:"C:"+cn,target:"A:其他账户",value:+v2.toFixed(2)}));
  }
  }

  /* 收敛视图动态高度：按节点数撑开画布（固定 440px 在账户多时会挤压重叠）；弹窗容器 flex 自适应不干预。
     大类极简视图自适应：取「视口剩余高度的 46%」与 260~380px 区间clamp，随屏幕和谐缩放 */
  if(collapse){
    if(catsOnly){
      const h=Math.round(Math.max(260,Math.min(380,innerHeight*0.46)));
      if(el.style.height!==h+"px"){ el.style.height=h+"px"; chart.resize(); }
    }else if(compact){
      const h=Math.max(360,Math.min(720,110+nodes.length*26));
      if(parseFloat(el.style.height||"0")!==h){ el.style.height=h+"px"; chart.resize(); }
    }else if(el.style.height&&el.style.height!=="440px"){
      el.style.height="440px"; chart.resize();   /* 从窄屏转回宽屏时复原 */
    }
  }

  /* 留白：大类极简视图两行标签（名上金额下）字号小、占宽少，两侧各留 66 让中段图形尽量宽；
     弹窗 180（右侧「大类+金额」标签较宽，170 会被裁）；桌面内嵌 150 / 紧凑 74 */
  const mrg=catsOnly?66:(compact?74:(collapse?180:170));
  chart.setOption({
    tooltip:{trigger:"item",...tooltip,
      formatter:p=>p.dataType==="edge"
        ?`${esc(SK_LABEL[p.source]||p.source)} → ${esc(SK_LABEL[p.target]||p.target)}<br/><b>${state.netHidden?"••••":money(p.value,true)}</b><span style='opacity:.6'>（流动的金额）</span>`
        :`${esc(SK_LABEL[p.name]||p.name)}<br/><b>${state.netHidden?"••••":money(p.value,true)}</b>`},
    graphic:(nodes.length?[]:[{type:"text",left:"center",top:"middle",silent:true,
      style:{text:"暂无资产数据",fill:C.dim,fontSize:12.5}}]),
    series:[{type:"sankey",left:mrg,right:mrg,top:catsOnly?18:8,bottom:catsOnly?18:8,
      nodeWidth:compact?10:13,nodeGap:compact?14:16,nodeAlign:"justify",draggable:false,
      emphasis:{focus:"adjacency"},
      lineStyle:{color:"gradient",opacity:.3,curveness:.55},
      /* 标签避让：节点密集时（弹窗全量账户视图）自动微移防重叠——「大类标签被账户标签挡住」的解法 */
      ...(!compact?{labelLayout:{hideOverlap:false,moveOverlap:"shiftY"}}:{}),
      label:{color:C.text,fontSize:compact?10.5:11.8,...(catsOnly?{fontSize:9.8,lineHeight:12.5}:{}),
        formatter:p=>{ const n=SK_LABEL[p.name]||p.name;
          /* 隐藏态/紧凑账户视图只显名称；大类极简视图名称在上、金额在下（两行更协调） */
          if(state.netHidden||(compact&&!catsOnly)) return n;
          return catsOnly?`${n}\n${moneyShort1(p.value)}`:`${n}  ${moneyShort(p.value)}`; }},
      data:nodes,links:links}]
  },true);   /* notMerge：主题切换重渲染时清掉旧 graphic 空态 */
}

function renderAccounts(d){
  const list=(d.accounts||[]).filter(a=>!a.hidden);
  const max=Math.max(...list.map(a=>Math.abs(a.value??a.balance)),1);
  document.getElementById("acctList").innerHTML = list.length ? list.map(a=>{
    const v=a.value??a.balance;
    const cls=v<0?"neg":(v>0?"pos":"");
    const w=Math.round(Math.abs(v)/max*100);
    const color=a.color&&a.color!=="#"?("#"+a.color.replace("#","")):"var(--accent)";
    /* 隐藏态：余额变圆点（与资产总览卡同语言），占比条保留（只泄露相对规模，不泄露绝对值） */
    const amt=state.netHidden?`<span class="mask-dots">¥ ••••</span>`:moneyOf(a.balance,a.currency);
    /* A3 下钻：整行可点 → 该账户的全部流水（不限日期，看账户历史更有意义）。
       隐藏净资产时不提供（用户明确表示不想看这个账户的金额）。 */
    const go=state.netHidden?"":` data-acct="${esc(String(a.id))}" title="查看该账户的流水"`;
    return `<div class="acctrow${state.netHidden?"":" clickable"}"${go}>
      <span class="cd" style="background:${color}"></span>
      <span class="nm" title="${esc(a.name)}${a.currency!==((state.data?.profile?.defaultCurrency)||"CNY")?` · ${a.currency}`:""}">${esc(a.name)}</span>
      <span class="amt ${cls}">${amt}</span>
    </div>`;
  }).join("") : `<div class="empty">暂无账户</div>`;
}
/* 账户行点击 → 下钻到该账户流水（事件委托；账本无账户时不挂） */
document.getElementById("acctList").addEventListener("click",e=>{
  const row=e.target.closest(".acctrow[data-acct]"); if(!row) return;
  const nm=(row.querySelector(".nm")?.getAttribute("title")||row.querySelector(".nm")?.textContent||"").split(" · ")[0].trim();
  drillToTxs({acct:String(row.dataset.acct), acctName:nm});
});

const TYPE_LABEL={1:"余额调整",2:"收入",3:"支出",4:"转账"};
/* 交易行统一片段：最近交易列表与明细弹窗共用——以后改交易行的展示内容（分类/pill/备注/账户/金额）只改这里 */
function txParts(t,o){
  o=o||{};
  const type=t.type;
  const cls=type===2?"in":(type===3?"out":"tr");
  const sign=type===2?"+":(type===3?"-":"");
  const color=t.categoryColor&&t.categoryColor!=="#"?t.categoryColor:"var(--accent)";
  const label=type===1?TYPE_LABEL[1]:esc(t.category);
  const memo=t.comment||"";
  const acct=type===4?`${esc(t.account)} → ${esc(t.destAccount||"")}`:esc(t.account);
  const parent=txParentInfo(t);
  const pill=parent.name?`<span class="pill" style="color:${parent.color||"var(--text-sub)"};background:${parent.color?parent.color+"1f":"var(--bg-soft)"}">${esc(parent.name)}</span>`:"";
  const dt=new Date(t.time*1000);
  const hm=String(dt.getHours()).padStart(2,"0")+":"+String(dt.getMinutes()).padStart(2,"0");
  const timeStr=o.timeFmt==="hm"?hm:fmtDate(t.time);
  const amtStr=`<span class="amt ${cls}">${sign}${moneyOf(Math.abs(t.amount),t.currency)}</span>`;
  return {cls,sign,color,label,memo,acct,pill,timeStr,amtStr};
}
/* 转账分类也有自己的分类树。兼容旧缓存中后端固定返回「转账」的记录，
   按 categoryId 从前端 transfer 分组补回真实大类名称和颜色。 */
function txParentInfo(t){
  const fallback={name:String(t.parent||""),color:String(t.parentColor||"")};
  if(+t.type!==4) return fallback;
  const sid=String(t.categoryId||"");
  const groups=state.meta?.catGroups?.transfer||[];
  if(sid){
    const group=groups.find(g=>(g.items||[]).some(c=>String(c.id)===sid));
    if(group) return {name:String(group.gname||fallback.name),color:String(group.gcolor||fallback.color)};
  }
  return fallback;
}
/* ============ 方向箭头图标（统一入口）============
   全站的方向指示一律用这套 SVG，**不要再用 ‹ › ▸ ▾ 之类字符**：
   字符的字面尺寸受字体影响、笔画只有 1px 上下，小字号（9~15px）下要么
   跟小数点一样看不出朝向，要么跟相邻文字基线对不齐（老大反馈过两次）。
   SVG 画在 24 格 viewBox 里，stroke-width 2.6 时缩到任何尺寸都保持清晰折线；
   且 svg 是替换元素，`vertical-align` 天然走 baseline 对齐，不会带上字体的行高偏移。

   用法：arrowSVG("down"|"up"|"left"|"right", "额外 class")
   方向靠 path 本身表达（不靠 CSS rotate），这样每个箭头都能独立用、不依赖祖先类。 */
const ARROW_PATHS={
  down:"M6 9l6 6 6-6",
  up:"M6 15l6-6 6 6",
  left:"M15 6l-6 6 6 6",
  right:"M9 6l6 6-6 6",
};
function arrowSVG(dir,cls){
  const d=ARROW_PATHS[dir]||ARROW_PATHS.down;
  return `<svg class="arw${cls?" "+cls:""}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
}
/* 日期条的折叠箭头：朝下基准 + CSS rotate 切换方向（这样能吃到 rotate 的过渡动画，
   比换 path 更适合「点一下转一下」的交互）。 */
const DAY_ARROW_SVG=`<svg class="dh-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${ARROW_PATHS.down}"/></svg>`;
/* 当月流水 · 按日分组（数据源：cal_month 全月交易；与明细弹窗共用 txParts/tx 结构） */
function renderTxByDay(){
  const host=document.getElementById("dayTxWrap");
  if(!host) return;
  const ym=state.calYM;
  const cached=state.calCache[ym];
  if(!cached){
    document.getElementById("dayTxHint").textContent=`${+ym.slice(0,4)}年${+ym.slice(5,7)}月 · 加载中…`;
    host.innerHTML=`<div class="cd-loading">加载中…</div>`;
    ensureCalMonth(ym).then(()=>{ if(state.calYM===ym) renderTxByDay(); }).catch(e=>{ host.innerHTML=`<div class="cd-loading">${esc(e.message||"加载失败")}</div>`; });
    return;
  }
  const txs=cached.transactions;
  if(!txs.length){
    host.innerHTML=`<div class="empty" style="min-height:90px">该月暂无交易记录</div>`;
    resetTxOpenDays(ym);                  // 空月不该留下任何展开态
    document.getElementById("dayTxHint").textContent=`${+ym.slice(0,4)}年${+ym.slice(5,7)}月 · 0 笔`;
    return;
  }
  const groups={};
  txs.forEach(t=>{ const ds=localDateStr(t.time); (groups[ds]=groups[ds]||[]).push(t); });
  const now=new Date(); const todayStr=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,"0")}-${String(now.getDate()).padStart(2,"0")}`;
  initTodayOpen(ym);                      /* 今天默认展开：必须在拿到 cached 之后算，否则拿不到当日有无流水 */
  const days=Object.keys(groups).sort().reverse();
  /* 展开态集合：只在当前月内有效。切月时由 setCalYM 复位（见下方 resetTxOpenDays），
     否则 9 月展开的 "2026-09-20" 会以字符串形式残留，翻到 8 月后键名永不匹配、
     但也不会崩 —— 属于「看着像对、其实状态脏了」的隐性 bug。 */
  const open=txOpenDaysOf(ym);
  host.innerHTML=days.map(ds=>{
    const g=groups[ds];
    const inc=g.filter(t=>t.type===2).reduce((s,t)=>s+t.amount,0);
    const exp=g.filter(t=>t.type===3).reduce((s,t)=>s+t.amount,0);
    const wd="日一二三四五六"[new Date(ds+"T00:00:00").getDay()];
    const isOpen=open.has(ds);
    const isToday=ds===todayStr;
    const incHtml=inc>0.005?`<span class="dh-in">收入 +${money(inc,true)}</span>`:"";
    /* 日期条：日历格 + 星期 + 今天徽标 + 笔数 + 双合计 + 折叠箭头。
       笔数写在日期条上，是为了让「收起态」依然能看出哪天有账、有几天忙。
       「支出」二字包在 .dh-lbl 里：窄屏靠 CSS 隐掉它，把宽度让给金额。
       箭头是 SVG（朝下基准），方向全靠 CSS 的 rotate 切，这里不输出字符。 */
    const head=`<div class="dayhead" role="button" tabindex="0" aria-expanded="${isOpen}"><b>${+ds.slice(5,7)}月${+ds.slice(8)}日</b><span class="dh-wd">周${wd}</span>${isToday?`<span class="dh-today">今天</span>`:""}<span class="dh-cnt">${g.length} 笔</span><span class="dh-amt">${incHtml}<span class="dh-exp"><span class="dh-lbl">支出 </span>${money(exp,true)}</span></span>${DAY_ARROW_SVG}</div>`;
    /* ⚠️ 收起态「根本不渲染」明细行，而不是渲染后用 display:none 藏起来。
       两者差别是实打实的：54 行隐藏 DOM 仍然占内存、仍会被 querySelectorAll 数到、
       仍可能被 tab 聚焦 —— 而且那样等于没省下任何渲染成本。
       展开时才把行注入 .dayrows（见 toggleTxDay）。
       data-filled 标记要与「行已渲染」保持一致，否则展开的日子再点一下会被误判为空、重画一遍。 */
    return `<div class="daygroup${isOpen?" open":""}" data-day="${ds}">${head}<div class="dayrows"${isOpen?' data-filled="1"':''}>${isOpen?txRowsHTML(g):""}</div></div>`;
  }).join("");
  syncTxDayHint();
}
/* 某天的明细行 HTML（只在展开时生成；点击展开时也复用它，保证两条路径产物一致） */
function txRowsHTML(g){
  return g.map(t=>{
    const p=txParts(t,{timeFmt:"hm"});
    const meta=[p.acct,p.memo].filter(Boolean).join(" · ");
    return `<div class="cd-row"><div class="tx-crow">${p.pill}<span class="cnm">${p.label}</span>${p.amtStr}</div><div class="tx-mrow"><span class="meta1">${esc(meta)||"—"}</span><span class="tm">${p.timeStr}</span></div></div>`;
  }).join("");
}
/* 某天的流水列表（按该日筛选，顺序与渲染一致） */
function txDayList(ds){
  const cached=state.calCache[state.calYM]; if(!cached) return [];
  return cached.transactions.filter(t=>localDateStr(t.time)===ds);
}
/* 展开集合：state.txOpenDays[ym] = Set(日期)。惰性建 Set，避免在 state 里存数组再反复转换。 */
function txOpenDaysOf(ym){
  if(!state.txOpenDays) state.txOpenDays={};
  if(!state.txOpenDays[ym]) state.txOpenDays[ym]=new Set();
  return state.txOpenDays[ym];
}
/* 切月必须清空展开态（含今天默认展开的重算），否则状态跨月残留 */
function resetTxOpenDays(ym){
  if(!state.txOpenDays) state.txOpenDays={};
  if(!state.txDayInited) state.txDayInited={};
  state.txOpenDays[ym]=new Set();
  state.txDayInited[ym]=0;                // 允许重新做一次「今天默认展开」
  initTodayOpen(ym);
}
/* 今天默认展开：让进页面第一眼就能看到最近一笔，不用先点一下。
   注意「今天」只在本月才有意义 —— 翻到历史月份时没有任何一天该默认展开。
   ⚠️ 只在「这个月第一次初始化」时加，不要每次渲染都加：
   用户可能手动把今天收起来了，重绘时再自动加回来 = 用户的操作被系统吃掉。
   ⚠️ 置标记必须在「确认缓存可用」之后。反过来的话，首次调用时 calCache 还没到，
      标记却已经置 1，之后永远提前 return —— 今天从头到尾都不会展开（本轮真踩过）。 */
function initTodayOpen(ym){
  const now=new Date();
  const todayYM=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,"0")}`;
  if(ym!==todayYM) return;
  if(!state.txDayInited) state.txDayInited={};
  if(state.txDayInited[ym]) return;       // 本月已经初始化过，尊重用户后续的手动开合
  const cached=state.calCache[ym]; if(!cached) return;   // 缓存未就位：不置标记，留给下一次调用
  state.txDayInited[ym]=1;
  const todayStr=`${todayYM}-${String(now.getDate()).padStart(2,"0")}`;
  if(cached.transactions.some(t=>localDateStr(t.time)===todayStr)) txOpenDaysOf(ym).add(todayStr);
}
/* 表头提示：月份 + 笔数 + 展开引导。收起态告诉用户「点日期展开明细」。 */
function syncTxDayHint(){
  const el=document.getElementById("dayTxHint"); if(!el) return;
  const ym=state.calYM; const cached=state.calCache[ym];
  const n=cached?cached.transactions.length:0;
  const openN=state.txOpenDays?.[ym]?.size||0;
  el.textContent=`${+ym.slice(0,4)}年${+ym.slice(5,7)}月 · ${cached?n+" 笔":"加载中…"}${n?(openN?` · 已展开 ${openN} 天`:" · 点日期展开明细"):""}`;
}
/* 日期条点击委托：展开/收起。用最近祖先 .daygroup 的 data-day 定位，
   不要用「第几个 .daygroup」的下标 —— 排序/过滤一变下标就错位。
   展开/收起只动这一天的 .dayrows（局部注入/清空），不整块重绘：
   整块重绘会丢掉滚动位置，也会让其它天的展开动画闪一下。 */
function toggleTxDay(ds, force){
  const grp=document.querySelector(`#dayTxWrap .daygroup[data-day="${ds}"]`); if(!grp) return;
  const set=txOpenDaysOf(state.calYM);
  const want=force===undefined?!set.has(ds):!!force;
  if(want) set.add(ds); else set.delete(ds);
  const rowsEl=grp.querySelector(".dayrows");
  if(want){
    /* 已经画过就别重画（避免把用户刚点开的行又刷掉） */
    if(!rowsEl.dataset.filled){ rowsEl.innerHTML=txRowsHTML(txDayList(ds)); rowsEl.dataset.filled="1"; }
  }else{
    rowsEl.innerHTML=""; rowsEl.dataset.filled="";
  }
  grp.classList.toggle("open",want);
  const head=grp.querySelector(".dayhead");
  /* 箭头不用手动改：它是 SVG，方向由 .daygroup.open 下的 CSS rotate 决定，
     切 class 就自动转了（还带上过渡动画）。这里只维护 aria 状态。 */
  if(head) head.setAttribute("aria-expanded",want?"true":"false");
  syncTxDayHint();
}
function bindTxDayToggle(){
  const host=document.getElementById("dayTxWrap"); if(!host||host.dataset.toggleBound) return;
  host.dataset.toggleBound="1";
  host.addEventListener("click",e=>{
    const head=e.target.closest(".dayhead"); if(!head) return;
    const grp=head.closest(".daygroup"); if(!grp||!grp.dataset.day) return;
    toggleTxDay(grp.dataset.day);
  });
  /* 键盘可达：日期条是 role=button，回车/空格要能开合 */
  host.addEventListener("keydown",e=>{
    if(e.key!=="Enter"&&e.key!==" ") return;
    const head=e.target.closest(".dayhead"); if(!head) return;
    e.preventDefault(); head.click();
  });
}

/* ================= 事件 ================= */
document.getElementById("rangeSeg").addEventListener("click",e=>{
  const btn=e.target.closest("button"); if(!btn) return;
  if(btn.dataset.r==="custom"){ openDatePanel(); return; }   /* 自定义：打开面板，确认后再切 */
  if(state.range===btn.dataset.r) return;
  document.querySelectorAll("#rangeSeg button").forEach(b=>b.classList.toggle("on",b===btn));
  state.range=btn.dataset.r;
  load();
});

/* ================= 自定义日期面板 ================= */
function fmtISO(d){ return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`; }
function openDatePanel(){
  const f=document.getElementById("dpFrom"), t=document.getElementById("dpTo");
  /* 预填：已保存的自定义区间，否则给个默认近30天 */
  if(state.customFrom && state.customTo){ f.value=state.customFrom; t.value=state.customTo; }
  else { const now=new Date(), s=new Date(now); s.setDate(s.getDate()-29); f.value=fmtISO(s); t.value=fmtISO(now); }
  document.getElementById("dpErr").hidden=true;
  document.getElementById("datePanel").hidden=false;
}
function closeDatePanel(){ document.getElementById("datePanel").hidden=true; }
function markSegActive(r){
  document.querySelectorAll("#rangeSeg button").forEach(b=>b.classList.toggle("on",b.dataset.r===r));
}
document.getElementById("customRangeBtn").addEventListener("dblclick",e=>{ e.preventDefault(); });
/* 快捷区间 */
document.querySelector(".dp-quick").addEventListener("click",e=>{
  const btn=e.target.closest("button"); if(!btn) return;
  const now=new Date(), t=fmtISO(now), f=document.getElementById("dpFrom"), to=document.getElementById("dpTo");
  const q=btn.dataset.q;
  if(q==="thisMonth"){ f.value=fmtISO(new Date(now.getFullYear(),now.getMonth(),1)); to.value=t; }
  else if(q==="lastMonth"){ f.value=fmtISO(new Date(now.getFullYear(),now.getMonth()-1,1)); to.value=fmtISO(new Date(now.getFullYear(),now.getMonth(),0)); }
  else if(q==="30d"){ const s=new Date(now); s.setDate(s.getDate()-29); f.value=fmtISO(s); to.value=t; }
  else if(q==="90d"){ const s=new Date(now); s.setDate(s.getDate()-89); f.value=fmtISO(s); to.value=t; }
  else if(q==="thisYear"){ f.value=fmtISO(new Date(now.getFullYear(),0,1)); to.value=t; }
});
/* 确认查询 */
document.getElementById("dpApply").addEventListener("click",()=>{
  const f=document.getElementById("dpFrom").value, t=document.getElementById("dpTo").value;
  const err=document.getElementById("dpErr");
  if(!f||!t){ err.textContent="请选择开始和结束日期"; err.hidden=false; return; }
  if(f>t){ err.textContent="开始日期不能晚于结束日期"; err.hidden=false; return; }
  state.customFrom=f; state.customTo=t;
  localStorage.setItem("ebk_custom_from",f);
  localStorage.setItem("ebk_custom_to",t);
  state.range="custom";
  markSegActive("custom");
  closeDatePanel();
  load();
});
document.getElementById("dpCancel").addEventListener("click",closeDatePanel);
document.getElementById("dpClose").addEventListener("click",closeDatePanel);
document.getElementById("datePanel").addEventListener("click",e=>{ if(e.target.id==="datePanel") closeDatePanel(); });
document.addEventListener("keydown",e=>{ if(e.key==="Escape"&&!document.getElementById("datePanel").hidden) closeDatePanel(); });
document.getElementById("refreshBtn").onclick=()=>load(true);
/* 消费日历：支出/收入切换 */
document.getElementById("calSeg").addEventListener("click",e=>{
  const btn=e.target.closest("button"); if(!btn) return;
  document.querySelectorAll("#calSeg button").forEach(b=>b.classList.toggle("on",b===btn));
  state.calType=btn.dataset.t;
  renderCalendar();
});
/* 分类分析：排行/环形视图切换（hidden 容器不能 init ECharts，切到环形时才挂载） */
function setAnaView(v){
  state.anaView=v;
  document.querySelectorAll("#anaSeg button").forEach(b=>b.classList.toggle("on",b.dataset.v===v));
  const isDonut=v==="donut";
  document.getElementById("donutChart").hidden=!isDonut;
  document.getElementById("rankList").hidden=isDonut;
  document.getElementById("donutBack").hidden=!isDonut||state.donutDrill==null;
  document.getElementById("rankBack").hidden=isDonut||state.rankDrill==null;
  if(isDonut) mountDonutChart();
}
document.getElementById("anaSeg").addEventListener("click",e=>{
  const btn=e.target.closest("button"); if(!btn||btn.dataset.v===state.anaView) return;
  setAnaView(btn.dataset.v);
});
/* 分类分析：支出/收入类型切换（排行与环形共用同一状态） */
document.getElementById("anaTypeSeg").addEventListener("click",e=>{
  const btn=e.target.closest("button"); if(!btn) return;
  document.querySelectorAll("#anaTypeSeg button").forEach(b=>b.classList.toggle("on",b===btn));
  state.donutType=state.rankType=btn.dataset.t;
  state.donutDrill=state.rankDrill=null;
  document.getElementById("donutBack").hidden=state.anaView!=="donut";
  document.getElementById("rankBack").hidden=state.anaView==="donut";
  if(state.anaView==="donut") renderDonut({backgroundColor:chartColors().tip,borderColor:chartColors().grid,
    textStyle:{color:chartColors().text,fontSize:12.5},
    extraCssText:"box-shadow:0 6px 20px rgba(0,0,0,.15);border-radius:10px;"},chartColors());
  else renderRank();
});
document.getElementById("donutBack").addEventListener("click",()=>{
  state.donutDrill=null; renderDonut(state._tt,state._C);
});
document.getElementById("rankBack").addEventListener("click",()=>{
  state.rankDrill=null; renderRank();
});
document.getElementById("rankList").addEventListener("click",e=>{
  const row=e.target.closest(".rankrow"); if(!row||!row.dataset.id) return;
  const tops=(state.data?.categoryRank?.[state.rankType])||[];
  /* ⚠️ 禁止 +row.dataset.id 数值强转：19 位雪花 id 超出 JS 安全整数（2^53），
     +"1723456789012345678" 会得到尾部被改写的数字，永远匹配不上。
     ⚠️ 也必须两侧都 String()：旧版缓存里 x.id 还是数字，只归一 dataset 一侧
     会变成「数字===字符串」恒 false → 点大类行没反应（09-21 生产回归）。 */
  const cat=tops.find(x=>String(x.id)===String(row.dataset.id));
  /* A3 下钻优先：点了「查看流水」按钮 → 跳流水页；否则维持原有「点行下钻小类」行为。
     两种行为必须在同一个点击入口里分流，否则加了下钻后老的下钻小类就点不动了。 */
  if(e.target.closest(".rk-go")){
    const id=row.dataset.id;
    /* 当前处于小类视图（rankDrill）时，点的是小类；否则是大类 */
    const nm=(row.querySelector(".nm")?.textContent||"").replace(/[›\s]+$/,"").trim();
    drillToTxs({cat:id, catName:nm, type:state.rankType==="expense"?3:2, ...drillRange()});
    return;
  }
  if(cat&&cat.subs&&cat.subs.length){ state.rankDrill=String(cat.id); renderRank(); }
});
window.addEventListener("resize",()=>{ Object.values(state.charts).forEach(c=>c.resize()); netApply(); });

/* ================= 健康度口径浮层 ================= */
/* 桌面：悬停 i 图标显示、移开消失；手机：点按显示、3.5s 自动消失（触摸手指抬起不算离开）。
   浮层显示在图标上方居中，越界自动下翻。 */
(function(){
  const tip=document.createElement("div"); tip.id="healthTip"; tip.hidden=true; document.body.appendChild(tip);
  let lastType="mouse", hideTimer=null;
  const show=b=>{ tip.textContent=b.dataset.h||""; tip.hidden=false; };
  const hide=()=>{ tip.hidden=true; if(hideTimer){clearTimeout(hideTimer);hideTimer=null;} };
  const scheduleHide=()=>{ if(hideTimer) clearTimeout(hideTimer); hideTimer=setTimeout(hide,3500); };
  const place=e=>{
    const w=tip.offsetWidth,h=tip.offsetHeight;
    tip.style.left=Math.max(8,Math.min(e.clientX-w/2,window.innerWidth-w-8))+"px";
    tip.style.top=(e.clientY-h-10<8 ? e.clientY+16 : e.clientY-h-10)+"px";
  };
  document.addEventListener("pointerover",e=>{
    const b=e.target.closest&&e.target.closest(".hinfo");
    if(!b) return;
    lastType=e.pointerType||"mouse";
    show(b); place(e);
    if(lastType!=="mouse") scheduleHide();
  });
  document.addEventListener("pointermove",e=>{
    if(tip.hidden||!e.target.closest||!e.target.closest(".hinfo")) return;
    place(e);
  });
  document.addEventListener("pointerout",e=>{
    const b=e.target.closest&&e.target.closest(".hinfo");
    if(!b) return;
    if((e.pointerType||lastType)==="touch") return;   // 触摸时手指抬起不触发隐藏
    hide();
  });
  document.addEventListener("pointerdown",e=>{
    const b=e.target.closest&&e.target.closest(".hinfo");
    if(b){ lastType=e.pointerType||"mouse"; show(b); place(e); if(lastType!=="mouse") scheduleHide(); }
    else if(!tip.hidden) hide();   // 点其它区域收起（移动端）
  });
})();

/* PWA：Service Worker 注册（离线缓存页面壳 + 最近一次 API 数据快照） */
if('serviceWorker' in navigator){
  window.addEventListener("load",()=>{ navigator.serviceWorker.register("sw.js").catch(()=>{}); });
}

/* ================= 弹窗滚动锁 ================= */
/* 任一弹窗（当日明细/月份选择器/桑基全屏/日期面板）打开时锁定背景滚动，全部关闭后恢复原位置。
   iOS 上 body overflow:hidden 不可靠，额外拦截触摸移动兜底；弹窗内部可滚动区（明细列表）与
   桑基全屏图表（fixed 覆盖层）放行。 */
const MODAL_IDS=["cdModalMask","calYMPanel","skModalHolder","datePanel","addMask","pickMask","setMask","budMask","miniMask","mapMask","txsOpMask"];
let bgLocked=false, lockY=0;
function applyScrollLock(on){
  document.documentElement.style.overflow=on?"hidden":"";
  document.body.style.overflow=on?"hidden":"";
}
new MutationObserver(()=>{
  const open=MODAL_IDS.some(id=>{ const el=document.getElementById(id); return el&&!el.hidden; });
  if(open&&!bgLocked){ bgLocked=true; lockY=window.scrollY; applyScrollLock(true); }
  else if(!open&&bgLocked){ bgLocked=false; applyScrollLock(false); window.scrollTo(0,lockY); }
}).observe(document.body,{attributes:true,attributeFilter:["hidden"],subtree:true,childList:false});
document.addEventListener("touchmove",e=>{
  if(!bgLocked) return;
  if(e.target.closest&&(e.target.closest(".cd-list")||e.target.closest(".sk-modal")||e.target.closest(".pick-left")||e.target.closest(".pick-right")||e.target.closest(".dp-card")||e.target.closest(".add-sheet"))) return;
  e.preventDefault();
},{passive:false});

/* ================= 一句话记账 + 编辑/删除（二期） ================= */
function toast(msg){
  const t=document.createElement("div"); t.className="toast"; t.textContent=msg;
  document.body.appendChild(t); setTimeout(()=>t.remove(),2400);
}
const AI_TYPE_LABEL={2:"收入",3:"支出",4:"转账"};
const ACCT_CAT_NAME={1:"现金",2:"借记卡",3:"信用卡",4:"虚拟账户",5:"负债",6:"应收款项",7:"投资账户",8:"储蓄账户",9:"其他"};
/* 账户类型是 EZBookKeeping 的固定枚举，不是用户自定义图标。
   一级分组用本地 SVG 表达，避免拿第一条账户图标冒充分组，也不增加网络请求。 */
const ACCT_CAT_ICON={
  1:'<path d="M3 7.5h15.5a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5.5a2.5 2.5 0 0 1-2.5-2.5V7.5Z"/><path d="M3.5 7.5V6a2 2 0 0 1 2-2h11"/><path d="M16 13h4.5"/>',
  2:'<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9h19M6 14h3"/>',
  3:'<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9h19M6 14h5"/><path d="M17 13v4M15 15h4"/>',
  4:'<path d="M6.5 18.5h10.8a4.2 4.2 0 0 0 .4-8.4A6.2 6.2 0 0 0 6.1 8.7a4.9 4.9 0 0 0 .4 9.8Z"/><path d="M9 14h6"/>',
  5:'<path d="M12 3.5 19 6v5.2c0 4.1-2.8 7.4-7 9.3-4.2-1.9-7-5.2-7-9.3V6l7-2.5Z"/><path d="M9 12h6M10 15h4"/>',
  6:'<path d="M4 17.5h16M6 17.5v-6h12v6M8 9l4-4 4 4"/><path d="M9 14h2M13 14h2"/>',
  7:'<path d="M3 19 9 13l4 3 8-9"/><path d="M16 7h5v5"/><path d="M3 21h18"/>',
  8:'<path d="M4 10.5c0-2.2 3.6-4 8-4s8 1.8 8 4v5c0 2.2-3.6 4-8 4s-8-1.8-8-4v-5Z"/><path d="M4 11c0 2.2 3.6 4 8 4s8-1.8 8-4M8 6.5V5M16 6.5V5"/>',
  9:'<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M8 4.5v15M3.5 10h17M14 10v9.5"/>'
};
function accountGroupIcon(group){
  const body=group.kind==="frequent"
    ? '<path d="m12 3 2.2 5 5.4.5-4.1 3.6 1.2 5.3-4.7-2.8-4.7 2.8 1.2-5.3-4.1-3.6 5.4-.5L12 3Z"/>'
    : (ACCT_CAT_ICON[group.category]||ACCT_CAT_ICON[9]);
  return `<span class="ico-cat" aria-hidden="true"><svg viewBox="0 0 24 24">${body}</svg></span>`;
}
let aiParsed=null, addMode="new", editTx=null, optBackup=null, optNewTx=null;
/* 被写操作（记账/改账/删账）标脏的月份：下次 ensureCalMonth(ym,false) 必须回源，不直接复用内存副本。
   只在内存里、不进 state（state 是纯数据，这个是取数策略）。见 dropCalCache / ensureCalMonth 注释。 */
const calDirty={};
const AI_TXT_ID="aiText";

/* 记账卡片的数据源（分类/账户/标签）。除了进页面就预热，请求本身也要去重：
   预热在飞的时候用户点了「记一笔」不该再打一次一样的上游请求。 */
let metaPromise=null;
async function ensureMeta(){
  if(state.meta) return state.meta;
  if(metaPromise) return metaPromise;
  metaPromise=(async()=>{
    try{
      const r=await apiFetch("api.php?action=meta",{cache:"no-store"});
      const j=await r.json();
      if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
      if(!j.success) throw new Error(j.error||"加载失败");
      const cats=j.data.categories||{};
      const catMap={};
      const walk=l=>{ (l||[]).forEach(c=>{ catMap[String(c.id)]={name:c.name,color:c.color?("#"+String(c.color).replace("#","")):"",icon:String(c.icon||"")}; if(c.subCategories) walk(c.subCategories); }); };
      walk(cats["1"]||[]); walk(cats["2"]||[]); walk(cats["3"]||[]);
      const groupOf=groups=>{ const out=[]; (groups||[]).forEach(p=>{
        const gcolor=p.color?("#"+String(p.color).replace("#","")):"";
        const kids=(p.subCategories&&p.subCategories.length)?p.subCategories:null;
        const items=(kids||[{id:p.id,name:p.name}]).map(c=>({id:String(c.id),name:c.name,color:c.color?("#"+String(c.color).replace("#","")):gcolor}));
        out.push({gname:p.name,gcolor,items});
      }); return out; };
      state.meta={
        catMap, catName:id=>catMap[String(id)]?.name||"",
        catGroups:{expense:groupOf(cats["2"]), income:groupOf(cats["1"]), transfer:groupOf(cats["3"])},
        catTree:{expense:cats["2"]||[], income:cats["1"]||[], transfer:cats["3"]||[]},
        accounts:(j.data.accounts||[]).map(a=>({id:String(a.id),name:a.name,category:+(a.category||0),hidden:!!a.hidden,icon:String(a.icon||"")})),
        tags:(j.data.tags||[]).filter(t=>!t.hidden).map(t=>({id:String(t.id),name:String(t.name||"")}))   /* 交易标签（meta 已缓存 1h） */
      };
      return state.meta;
    }finally{
      metaPromise=null;    /* 失败也要清掉，否则一次抖动会永久卡住后续重试 */
    }
  })();
  return metaPromise;
}
/* 预热：清缓存后第一次点「记一笔 / 点流水编辑」原本要现场等一次上游往返（实测 493ms/3 枪），
   现在页面一就绪就先把这块数据取回内存，用户点的时候是 0 网络。失败静默 —— 真需要用的时候还会再拉一次。
   timeout 兜底：浏览器迟迟不进 idle 也要在 2.5s 内跑起来，否则预热等于没做。 */
function warmMeta(){
  if(state.meta) return;
  const go=()=>{ if(!state.meta) ensureMeta().catch(()=>{}); };
  if(window.requestIdleCallback) requestIdleCallback(go,{timeout:2500});
  else setTimeout(go,400);
}
/* 标签查找（标签数量少，线性查足够） */
function tagById(id){ return (state.meta?.tags||[]).find(t=>t.id===String(id||"")); }
/* 常用账户：当月使用频次 TOP5 */
function topAccountIds(){
  const cached=state.calCache[state.calYM]; if(!cached) return [];
  const freq={};
  cached.transactions.forEach(t=>{ const id=String(t.sourceAccountId||""); if(id) freq[id]=(freq[id]||0)+1; });
  return Object.entries(freq).sort((a,b)=>b[1]-a[1]).slice(0,5).map(([id])=>id);
}
/* 乐观更新：本地立即增删改 + 失败回滚 */
function optimistic(mutate){
  const cache=state.calCache[state.calYM];
  optBackup=JSON.parse(JSON.stringify(cache));
  mutate(cache);
  renderTxByDay(); renderHomeKPI(); renderCalendar();
}
function rollbackOpt(){
  if(!optBackup) return;
  state.calCache[state.calYM]=optBackup; optBackup=null; optNewTx=null;
  renderTxByDay(); renderHomeKPI(); renderCalendar();
}
/* 写成功后把受影响的月份标记为「脏」，让下次 ensureCalMonth(ym,false) 真正回源。
   ⚠️ 为什么是「标记脏」而不是「delete state.calCache[ym]」：
     calCache[ym] 这个对象被一堆渲染函数当成**必然存在**来读（renderTxByDay / renderHomeKPI /
     renderCalendar / openDayDetail 都直接取 .transactions、.daily）。整个删掉会让它们读到 undefined，
     轻则空白/报错，重则把刚记的账从界面上抹掉 —— 我们修的是「刷新丢数据」，不能顺手造一个更大的。
   标记脏的语义：对象还在、界面照常画（乐观更新后的内存数据本来就是对的，与写后的服务端一致），
   但下次要取数时会绕过它去回源。两全。
   ⚠️ 只标受影响的月份：跨月编辑要标新旧两个月，否则旧月份留着一笔幽灵账。 */
function dropCalCache(...yms){
  yms.filter(Boolean).forEach(ym=>{ calDirty[ym]=1; });
}
function sheetLoading(on,text){
  const l=document.getElementById("sheetLoading"); if(!l) return;
  l.hidden=!on; if(text) document.getElementById("sheetLoadingT").textContent=text;
}
/* 送上游的交易时间（epoch 秒）。
   为什么不直接用输入框的值：<input type="datetime-local"> 只有分钟精度，取值会把时间截断到 :00，
   最多比真实时刻早 59 秒。一旦账户的「余额变更」锚点落在这 59 秒内（典型场景：刚建完账户就过来记一笔），
   上游会以 cannot add transaction before balance modification transaction 拒绝，而用户完全看不出原因。
   所以：用户手动改过 → 尊重输入；没改过 → 新建取「此刻」精确到秒，
   编辑取原交易的精确时间（否则按分钟截断会把原交易时间悄悄往前挪）。 */
function aiTimeEpoch(){
  const el=document.getElementById("aiTime");
  if(el&&el.dataset.userEdited){
    const t=Math.floor(new Date(el.value).getTime()/1000);
    if(t>0) return t;
  }
  if(addMode==="edit"&&editTx){
    const t=Math.floor(editTx.time||0);
    if(t>0) return t;
  }
  /* 识别给出的时间：精确到秒，直接采用（表单里显示的就是它）。
     不走这里的话「昨天午饭」会被静默换成「现在」，且显示/保存两个时间对不上。 */
  if(addMode==="new"&&aiParsed&&aiParsed.timeFromAI&&+aiParsed.time>0){
    return +aiParsed.time;
  }
  return Math.floor(Date.now()/1000);
}
/* 用户一动时间字段就标记，aiTimeEpoch 据此决定是否采用输入框的值 */
(function(){
  const el=document.getElementById("aiTime");
  if(el) el.addEventListener("input",()=>{ el.dataset.userEdited="1"; });
})();
function prepareEntryForm(){
  /* 一句话是可选的预填充入口，手动字段始终在同一张确认表单中。 */
  document.getElementById("aiRow").hidden=false;
  document.getElementById("aiHint").textContent="可先说一句话自动填入，也可直接填写下方表单";
}
function openAddSheet(){
  addMode="new"; editTx=null; aiParsed=null;
  const g=id=>document.getElementById(id);
  g("addMask").hidden=false;
  sheetLoading(false);   /* 打开即干净状态：loading 属于「每次操作」而非「弹层」，上一笔的「删除中/保存中」绝不能带进来
                            （deleteTx 成功路径只关弹层不复位，曾导致删完一笔再打开另一笔一直显示「删除中…」） */
  g("addTitle").textContent="记一笔";
  g("aiDel").hidden=true;
  g("aiText").value="";
  const err=g("addErr"); err.hidden=true;
  const accountHint=g("aiAccountHint"); if(accountHint){ accountHint.hidden=true; accountHint.textContent=""; accountHint.removeAttribute("data-tone"); }
  prepareEntryForm();
  ["aiCatTxt","aiAcctTxt","aiDstTxt","aiTagTxt","aiMemo","aiAmt","aiTime"].forEach(id=>{ const e=g(id); if(e){ e.value=""; delete e.dataset.userEdited; } });   // 重置值+标记（防上一次编辑残留）
  /* 默认确认卡常驻：手填底板（分类「外出就餐」/账户「招商银行卡」/金额空/时间当前） */
  const cold=!state.meta;
  if(cold) sheetLoading(true,"加载分类与账户…");     /* 冷缓存下别让卡片空着不动，给个明确反馈 */
  ensureMeta().then(()=>{
    if(addMode!=="new"||aiParsed) return;                 // 期间已识别/切编辑则跳过
    ["aiCatTxt","aiAcctTxt","aiDstTxt","aiTagTxt","aiMemo","aiAmt","aiTime"].forEach(id=>{ const e=g(id); if(e){ e.value=""; delete e.dataset.userEdited; } });
    aiParsed={type:3, categoryId:findCatByName("外出就餐",3), sourceAccountId:findAcctByName("招商银行卡"), destinationAccountId:"0", sourceAmount:0, comment:"", tagIds:[], time:Math.floor(Date.now()/1000)};
    renderConfirm();                                      /* 金额留空待填（renderConfirm 已跳过 0 值填充） */
  }).catch(e=>{ g("aiHint").textContent=e.message; })
    .finally(()=>{ if(cold) sheetLoading(false); });
  setTimeout(()=>g("aiText").focus(),80);
}
/* 编辑交易：从当月流水数据填充 */
async function openEditSheet(txid,txObj){
  /* txObj：搜索结果等不在当月缓存里的交易直接传对象（与 cal_month 归一化结构一致），省一次反查 */
  let tx=txObj||null;
  if(!tx){
    const cached=state.calCache[state.calYM];
    tx=cached?.transactions.find(t=>String(t.id)===String(txid))||null;
  }
  if(!tx) return;
  addMode="edit"; editTx=tx;
  const g=id=>document.getElementById(id);
  /* 先把弹层打开再等 meta：以前是 await 之后才显示，冷缓存下「点了没反应」要等一整个上游往返 */
  g("addMask").hidden=false;
  sheetLoading(false);   /* 同 openAddSheet：打开即干净状态，不依赖上一次关闭路径记得清理 */
  g("addTitle").textContent="编辑交易";
  const cold=!state.meta;
  if(cold) sheetLoading(true,"加载分类与账户…");
  try{
    await ensureMeta();
    g("aiRow").hidden=true; g("aiDel").hidden=false;
    const err=g("addErr"); err.hidden=true;
    g("aiHint").textContent="修改后点「确认保存」；不需要可点「删除」";
    ["aiCatTxt","aiAcctTxt","aiDstTxt","aiTagTxt","aiMemo","aiAmt","aiTime"].forEach(id=>{ const e=g(id); if(e){ e.value=""; delete e.dataset.userEdited; } });   // 重置值+标记（防上一次编辑残留）
    aiParsed={type:tx.type, categoryId:catIdFromTx(tx), sourceAccountId:String(tx.sourceAccountId||""), destinationAccountId:String(tx.destinationAccountId||"0"), sourceAmount:Math.round((tx.amount||0)*100), comment:tx.comment||"", tagIds:(tx.tagIds||[]).map(String), time:tx.time};
    renderConfirm();
  }catch(e){
    g("aiHint").textContent=e.message||"加载失败";
  }finally{
    if(cold) sheetLoading(false);
  }
  setTimeout(()=>g("aiAmt").focus(),80);
}
/* 图标徽章：EZBK 自定义图标走后端代理，无图标回落首字 */
function icoHTML(icon,name){
  if(icon) return `<img src="api.php?action=icon&id=${encodeURIComponent(icon)}" alt="" loading="lazy" decoding="async" width="22" height="22">`;
  return `<span class="ico-fb">${esc((name||"?").slice(0,1))}</span>`;
}
function catById(id){ return state.meta.catMap[String(id)]; }
function acctById(id){ return state.meta.accounts.find(x=>x.id===String(id)); }
function catKind(type){ return +type===2?"income":(+type===4?"transfer":"expense"); }
/* 分类完整元数据：名称/色 + 所属大类名/大类色（乐观更新补 pill 字段用） */
function catMetaOf(id,type){
  const m=state.meta, sid=String(id||"");
  const c=m.catMap[sid];
  if(!c) return null;
  const kind=catKind(type);
  const groups=m.catGroups[kind]||[];
  const tree=m.catTree?.[kind]||[];
  let gr=groups.find(g=>g.items.some(x=>String(x.id)===sid));
  /* 大类自身在 catGroups 里是「分组」而非 items 成员，按子项找不到它 →
     用树的下标把它自己所属的组补上（否则大类会被标成「未分类」、且丢了大类色） */
  if(!gr){ const gi=tree.findIndex(p=>String(p.id)===sid); gr=groups[gi]; }
  return {name:c.name, color:c.color||"", parent:gr?gr.gname:"", parentColor:gr?gr.gcolor:""};
}
/* 按名称找分类/账户 id（LLM 失败手动兜底的默认值；找不到返回空串） */
function findCatByName(name,type){
  const groups=state.meta.catGroups[catKind(type)]||[];
  for(const gr of groups){ const hit=gr.items.find(c=>c.name===name); if(hit) return hit.id; }
  return "";
}
function findAcctByName(name){
  return state.meta.accounts.find(a=>a.name===name&&!a.hidden)?.id||"";
}
/* 交易 → categoryId：cal_month 交易无 categoryId 字段，按大类名+小类名反查（同组内取第一个） */
function catIdFromTx(tx){
  if(tx.categoryId) return String(tx.categoryId);
  const m=state.meta;
  const kind=catKind(tx.type), groups=m.catGroups[kind]||[];
  const cname=tx.category||"";
  if(!cname) return "";
  if(tx.parent){
    const gr=groups.find(g=>g.gname===tx.parent);
    const hit=gr?.items.find(c=>c.name===cname);
    if(hit) return hit.id;
  }
  for(const gr of groups){ const hit=gr.items.find(c=>c.name===cname); if(hit) return hit.id; }
  /* 大类自身（餐饮/购物/交通…）在树里是「分组」而不是子项，g.items 里没有它 →
     只按子项名反查会落空，只能再按大类名查一次；否则 renderConfirm 会兜底成第一个子类，
     用户只改金额保存就把大类改掉了 */
  const tree=m.catTree?.[kind]||[];
  const top=tree.find(p=>p.name===cname);
  if(top) return String(top.id);
  return "";
}
/* 乐观同步 dashboard KPI：本期支出/收入/结余/储蓄率 + state.data.daily 当日条目（sign: +1 新增 / -1 删除） */
function applyKpiDelta(type, amount, time, sign){
  const d=state.data, k=d?.kpi;
  if(!k) return;
  const dashYM=((d.daily||[]).at(-1)?.date||"").slice(0,7);
  const ds=localDateStr(time);
  if(ds.slice(0,7)!==dashYM) return;              /* 非当前区间月：KPI 由 calCache 聚合路径覆盖 */
  const t=+type;
  if(t===3) k.expense=round2((k.expense||0)+sign*amount);
  else if(t===2) k.income=round2((k.income||0)+sign*amount);
  else return;                                    /* 转账不影响收支 KPI */
  k.balance=round2((k.income||0)-(k.expense||0));
  if((k.income||0)>=100&&(k.income||0)>=(k.expense||0)*0.05) k.savingRate=Math.round((k.income-k.expense)/k.income*1000)/10;
  const day=(d.daily||[]).find(x=>x.date===ds);
  if(day){ if(t===2) day.income=round2((day.income||0)+sign*amount); else day.expense=round2((day.expense||0)+sign*amount); }
}
/* 确认卡：只读输入框回显（分类/账户均只显示末级名，大类在选择器内体现） */
function refreshCatUI(){
  document.getElementById("aiCatTxt").value=catById(aiParsed?.categoryId)?.name||"";
}
function refreshAcctUI(){
  const a=acctById(aiParsed?.sourceAccountId);
  document.getElementById("aiAcctTxt").value=a?a.name:"";
}
function renderAccountHint(){
  const el=document.getElementById("aiAccountHint"); if(!el) return;
  const p=aiParsed||{};
  el.hidden=!p.accountWarning;
  el.removeAttribute("data-tone");
  if(!p.accountWarning) return;
  el.textContent=p.accountWarning;
  if(p.accountSource==="text") el.setAttribute("data-tone","ok");
}
function refreshDstUI(){
  const a=acctById(aiParsed?.destinationAccountId);
  document.getElementById("aiDstTxt").value=a?a.name:"";
}
/* 标签回显：多个用「、」连接（只显示名称，id 存 aiParsed.tagIds） */
function refreshTagUI(){
  const names=(aiParsed?.tagIds||[]).map(id=>tagById(id)?.name).filter(Boolean);
  const el=document.getElementById("aiTagTxt"); if(el) el.value=names.join("、");
}
/* 分类 id 是否属于指定类型：catMap 同时含收支两棵树，切类型时必须按类型判组（否则收入交易会挂着支出分类） */
function catInGroups(id,type){
  const sid=String(id||"");
  if(!sid||sid==="0") return false;
  const m=state.meta;
  const kind=catKind(type), groups=m.catGroups[kind]||[];
  return groups.some(gr=>(gr.items||[]).some(c=>String(c.id)===sid));
}
function firstCatId(type){
  const groups=state.meta.catGroups[catKind(type)]||[];
  return groups[0]?.items[0]?.id||"";
}
/* 交易类型切换：支出(3)/收入(2)/转账(4)。切类型必须重选分类（收支分类树不同），金额/时间/备注保留 */
const TX_TYPES=[3,2,4];
function syncTypeSeg(){
  const seg=document.getElementById("aiTypeSeg"); if(!seg) return;
  const t=+aiParsed?.type;
  seg.querySelectorAll("button").forEach(b=>{
    b.textContent=AI_TYPE_LABEL[+b.dataset.t]||b.textContent;   /* 文案单一来源 */
    b.classList.toggle("on",+b.dataset.t===t);
  });
  const card=document.getElementById("aiResult"); if(card) card.dataset.t=String(t||"");   /* 供 ¥ 着色 */
}
function setTxType(t){
  if(!aiParsed) return;
  t=+t; if(TX_TYPES.indexOf(t)<0) return;
  aiParsed.type=t;
  if(!catInGroups(aiParsed.categoryId,t)) aiParsed.categoryId=firstCatId(t);
  renderConfirm();
}
document.getElementById("aiTypeSeg").addEventListener("click",e=>{
  const b=e.target.closest("button"); if(b) setTxType(b.dataset.t);
});
function renderConfirm(){
  if(!aiParsed) return;
  const m=state.meta, t=+aiParsed.type;
  const g=id=>document.getElementById(id);
  /* 类型切换器：同步选中态 */
  syncTypeSeg();
  /* 分类：必须属于当前类型（收支是两棵独立的树），否则回落该类型第一组第一项 */
  if(!catInGroups(aiParsed.categoryId,t)) aiParsed.categoryId=firstCatId(t);
  /* 账户：识别结果无效时回落第一可用账户 */
  if(!acctById(aiParsed.sourceAccountId)){
    const f=m.accounts.find(x=>!x.hidden); if(f) aiParsed.sourceAccountId=f.id;
  }
  /* 转账同样需要分类；仅额外显示转入账户行。 */
  const isTx=t===4;
  g("catWrap").hidden=false;
  g("aiDstField").hidden=!isTx;
  if(isTx){
    if(!acctById(aiParsed.destinationAccountId)||String(aiParsed.destinationAccountId)===String(aiParsed.sourceAccountId)){
      const f=m.accounts.find(x=>!x.hidden&&String(x.id)!==String(aiParsed.sourceAccountId));
      if(f) aiParsed.destinationAccountId=f.id;
    }
    refreshDstUI();
  }
  refreshCatUI();
  refreshAcctUI();
  renderAccountHint();
  refreshTagUI();
  /* 时间/备注/金额（仅首次填充，用户输入不覆盖） */
  const d=new Date((aiParsed.time||Math.floor(Date.now()/1000))*1000);
  const pad=n=>String(n).padStart(2,"0");
  if(!g("aiTime").value) g("aiTime").value=`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  if(!g("aiMemo").value) g("aiMemo").value=aiParsed.comment||"";
  if(!g("aiAmt").value&&(aiParsed.sourceAmount||0)>0) g("aiAmt").value=(aiParsed.sourceAmount/100).toFixed(2);   /* 0/未识别金额留空待填 */
  g("aiResult").hidden=false;
}
/* ── 分类/账户选择器（底部双栏弹窗：左大类右子项，点击选中） ── */
let pickMode=null, pkGi=0, tagBusy=false;   // mode: cat | acct | dst | tag | txcat | txacct
function txsTypeValue(){
  return +(document.querySelector("#txsTypeSeg button.on")?.dataset.t||0);
}
function txsCategoryGroups(){
  const m=state.meta; if(!m) return [];
  const out=[];
  const addTree=(tree,label)=>{ (tree||[]).forEach((p,i)=>{
    const parentId=String(p.id||"");
    const items=(p.subCategories||[]).map(c=>({id:String(c.id),name:c.name,icon:catById(c.id)?.icon||""}));
    if(parentId&&!items.some(x=>x.id===parentId)) items.unshift({id:parentId,name:p.name,icon:catById(parentId)?.icon||""});
    if(items.length) out.push({label:`${label} · ${p.name}`,color:p.color?(`#${String(p.color).replace("#","")}`):"",icon:items[0].icon,items});
  }); };
  const type=txsTypeValue();
  if(type===0||type===3) addTree(m.catTree?.expense,"支出");
  if(type===0||type===2) addTree(m.catTree?.income,"收入");
  if(type===0||type===4) addTree(m.catTree?.transfer,"转账");
  return out;
}
function pkGroups(){
  /* 返回 [{label,color,icon,items:[{id,name,icon}]}] */
  const m=state.meta;
  if(pickMode==="txcat") return txsCategoryGroups();
  if(pickMode==="cat"){
    const kind=catKind(aiParsed?.type);
    const groups=m.catGroups[kind]||[];
    const tree=m.catTree?.[kind]||[];
    return groups.map((gr,i)=>{
      const p=(tree||[])[i]||{};
      const items=gr.items.map(c=>({id:String(c.id),name:c.name,icon:catById(c.id)?.icon||""}));
      return {label:gr.gname,color:gr.gcolor,icon:items[0]?items[0].icon:"",items};
    });
  }
  /* 账户/转入：⚡常用置顶 + 账户大类分组 */
  const freqId=topAccountIds();
  const pool=m.accounts.filter(x=>!x.hidden&&(pickMode!=="dst"||String(x.id)!==String(aiParsed?.sourceAccountId)));
  const gs=[];
  const freq=pool.filter(a=>freqId.includes(a.id));
  if(freq.length) gs.push({label:"⚡ 常用",kind:"frequent",color:"",icon:"",items:freq.map(a=>({id:a.id,name:a.name,icon:a.icon}))});
  Object.keys(ACCT_CAT_NAME).filter(c=>pool.some(x=>String(x.category)===String(c))).forEach(c=>{
    gs.push({label:ACCT_CAT_NAME[c],category:String(c),color:"",icon:"",items:pool.filter(x=>String(x.category)===String(c)).map(a=>({id:a.id,name:a.name,icon:a.icon}))});
  });
  return gs;
}
function pkCurId(){
  if(pickMode==="txcat") return String(document.getElementById("txsCat")?.value||"");
  if(pickMode==="txacct") return String(document.getElementById("txsAcct")?.value||"");
  return String(pickMode==="cat"?aiParsed?.categoryId:(pickMode==="dst"?aiParsed?.destinationAccountId:aiParsed?.sourceAccountId)||"");
}
function openPicker(mode){
  pickMode=mode;
  const isTag=mode==="tag";
  const gs=isTag?[]:pkGroups();
  pkGi=0;
  const cur=isTag?"":pkCurId();
  const gi=gs.findIndex(gr=>gr.items.some(x=>x.id===cur));
  if(gi>=0) pkGi=gi;
  document.getElementById("pkTitle").textContent=(mode==="cat"||mode==="txcat")?"选择分类":(mode==="dst"?"转入账户":(isTag?"选择标签":"选择账户"));
  document.getElementById("pkLeft").hidden=isTag;              /* 标签是平铺列表，不需要左栏 */
  const done=document.getElementById("pkClose");
  done.textContent=isTag?"完成":"✕";                            /* 多选语义：主动作是「完成」而非「关闭」 */
  done.classList.toggle("pk-done",isTag);
  document.getElementById("pickSheet").classList.toggle("tag-mode",isTag);
  if(isTag) renderPkTags(); else { document.getElementById("pkSub").hidden=true; renderPkLeft(); renderPkRight(); }
  document.getElementById("pickMask").hidden=false;
  document.getElementById("pickSheet").hidden=false;
}
function closePicker(){
  document.getElementById("pickMask").hidden=true;
  document.getElementById("pickSheet").hidden=true;
  pickMode=null;
}
/* 标签选择：平铺胶囊多选（点击即写入 aiParsed，点「完成」关闭）＋末尾虚线胶囊内联新建 */
function updPkSub(){
  const sub=document.getElementById("pkSub"); if(!sub) return;
  const n=(aiParsed?.tagIds||[]).length;
  sub.textContent=n?`已选 ${n}`:"";
  sub.hidden=!(pickMode==="tag"&&n>0);
}
function renderPkTags(){
  const sel=(aiParsed?.tagIds||[]).map(String);
  const tags=state.meta?.tags||[];
  const chips=tags.map(t=>{
    const on=sel.includes(t.id);
    return `<div class="pk-tag${on?" on":""}" data-id="${esc(t.id)}">${on?'<span class="ck">✓</span>':""}<span>${esc(t.name)}</span></div>`;
  }).join("");
  document.getElementById("pkRight").innerHTML=`<div class="pk-tags">${chips}<div class="pk-tag new" id="pkTagNew">＋ 新建标签</div><input id="pkTagInput" class="pk-tagin" maxlength="20" placeholder="标签名，回车创建" hidden></div>`;
  updPkSub();
}
function toggleTag(id){
  if(!aiParsed) return;
  id=String(id);
  const arr=(aiParsed.tagIds||[]).map(String);
  const i=arr.indexOf(id);
  if(i>=0) arr.splice(i,1); else arr.push(id);
  aiParsed.tagIds=arr;
  refreshTagUI(); renderPkTags();
}
function showTagInput(){
  const inp=document.getElementById("pkTagInput"), nw=document.getElementById("pkTagNew");
  if(!inp) return;
  if(nw) nw.hidden=true;
  inp.hidden=false; inp.value=""; inp.focus();
}
function hideTagInput(){
  const inp=document.getElementById("pkTagInput"), nw=document.getElementById("pkTagNew");
  if(inp){ inp.hidden=true; inp.value=""; inp.disabled=false; }
  if(nw) nw.hidden=false;
}
async function createTag(name){
  if(tagBusy) return;
  const v=(name||"").trim();
  if(!v){ hideTagInput(); return; }
  const dup=(state.meta?.tags||[]).find(t=>t.name===v);
  if(dup){                                                       /* 已有同名：直接选中 */
    if(!(aiParsed.tagIds||[]).map(String).includes(dup.id)) toggleTag(dup.id);
    hideTagInput(); return;
  }
  tagBusy=true;
  const inp=document.getElementById("pkTagInput");
  if(inp) inp.disabled=true;
  try{
    const r=await apiFetch("api.php?action=tag_add",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({name:v})});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"新建标签失败");
    const t=j.data||{};
    if(!t.id) throw new Error("新建标签失败");
    state.meta.tags.push({id:String(t.id),name:String(t.name||v)});
    aiParsed.tagIds=(aiParsed.tagIds||[]).map(String).concat([String(t.id)]);
    refreshTagUI(); hideTagInput(); renderPkTags();
    toast(`标签「${String(t.name||v)}」已创建`);
  }catch(e){
    if(inp) inp.disabled=false;
    toast(e.message||"新建标签失败");
  }finally{ tagBusy=false; }
}
function renderPkLeft(){
  const gs=pkGroups();
  document.getElementById("pkLeft").innerHTML=gs.map((gr,i)=>
    `<div class="pk-g${i===pkGi?" on":""}" data-i="${i}">${gr.icon?icoHTML(gr.icon,gr.label):((gr.category||gr.kind==="frequent")?accountGroupIcon(gr):`<span class="ico-dot"${gr.color?` style="background:${gr.color}"`:""}></span>`)}<span class="pk-gl">${esc(gr.label)}</span><span class="arr">›</span></div>`).join("");
}
function renderPkRight(){
  const gr=pkGroups()[pkGi]||{items:[]};
  const cur=pkCurId();
  const isTxFilter=pickMode==="txcat"||pickMode==="txacct";
  const allLabel=pickMode==="txcat"?"全部分类":"全部账户";
  const all=isTxFilter?`<div class="pk-i pk-all${cur?"":" on"}" data-clear-picker="1"><span>${allLabel}</span></div>`:"";
  document.getElementById("pkRight").innerHTML=all+gr.items.map(x=>
    `<div class="pk-i${x.id===cur?" on":""}" data-id="${x.id}">${icoHTML(x.icon,x.name)}<span>${esc(x.name)}</span></div>`).join("");
}
/* 选择器事件（委托 + 只读输入框触发） */
document.getElementById("pkLeft").addEventListener("click",e=>{
  const row=e.target.closest(".pk-g"); if(!row) return;
  pkGi=+row.dataset.i; renderPkLeft(); renderPkRight();
});
document.getElementById("pkRight").addEventListener("click",e=>{
  if(!pickMode) return;
  if(pickMode==="tag"){                                    /* 标签：点击胶囊即切换选中，不关弹层 */
    const chip=e.target.closest(".pk-tag[data-id]");
    if(chip){ toggleTag(chip.dataset.id); return; }
    if(e.target.closest("#pkTagNew")){ showTagInput(); return; }
    return;
  }
  const clear=e.target.closest("[data-clear-picker]");
  if(clear&&(pickMode==="txcat"||pickMode==="txacct")){
    document.getElementById(pickMode==="txcat"?"txsCat":"txsAcct").value="";
    syncTxsPickerUI();
    closePicker();
    return;
  }
  const row=e.target.closest(".pk-i"); if(!row) return;
  const id=row.dataset.id;
  if(pickMode==="txcat"||pickMode==="txacct"){
    document.getElementById(pickMode==="txcat"?"txsCat":"txsAcct").value=id;
    syncTxsPickerUI();
    closePicker();
    return;
  }
  if(pickMode==="cat") aiParsed.categoryId=id;
  else if(pickMode==="dst") aiParsed.destinationAccountId=id;
  else { aiParsed.sourceAccountId=id; aiParsed.accountNeedsConfirm=false; aiParsed.accountWarning=""; aiParsed.accountSource="manual"; }
  /* 预算弹窗借用了这个选择器：它只关心「选了哪个分类」，且不消费 aiParsed。
     钩子必须在 closePicker() 之前取走结果 —— 关窗后 pickMode 就成 null 了。 */
  if(pickMode==="cat"&&typeof budPickHook==="function"){
    const hook=budPickHook; budPickHook=null;
    refreshCatUI(); refreshAcctUI(); refreshDstUI();
    closePicker();
    hook(id);
    return;
  }
  refreshCatUI(); refreshAcctUI(); refreshDstUI(); renderAccountHint();
  closePicker();
});
/* 新建标签输入：回车创建、Esc 取消、失焦时若非空则创建 */
document.getElementById("pkRight").addEventListener("keydown",e=>{
  if(e.target.id!=="pkTagInput") return;
  if(e.key==="Enter"){ e.preventDefault(); createTag(e.target.value); }
  else if(e.key==="Escape"){ e.preventDefault(); hideTagInput(); }
});
document.getElementById("pkRight").addEventListener("focusout",e=>{
  if(e.target.id!=="pkTagInput") return;
  const v=(e.target.value||"").trim();
  if(v) createTag(v); else hideTagInput();
});
document.getElementById("pkClose").onclick=closePicker;
document.getElementById("pickMask").addEventListener("click",closePicker);
document.getElementById("aiCatTxt").onclick=()=>aiParsed&&openPicker("cat");
document.getElementById("aiAcctTxt").onclick=()=>aiParsed&&openPicker("acct");
document.getElementById("aiDstTxt").onclick=()=>aiParsed&&openPicker("dst");
document.getElementById("aiTagTxt").onclick=()=>aiParsed&&openPicker("tag");
async function aiRecognize(){
  if(addMode==="edit") return;
  const g=id=>document.getElementById(id);
  const text=g("aiText").value.trim();
  const err=g("addErr"), hint=g("aiHint"), btn=g("aiBtn");
  const showErr=m=>{ err.hidden=false; err.textContent=m; };
  if(btn.disabled) return;
  if(!text) return showErr("先写下一句话，比如「昨天午饭花了35元」");
  err.hidden=true; btn.disabled=true; btn.textContent="识别中…";
  hint.textContent="AI 正在解析（大模型响应通常需要 5~20 秒），请稍候…";
  const controller=new AbortController();
  let timedOut=false;
  const timeoutId=setTimeout(()=>{ timedOut=true; controller.abort(); },45000);
  try{
    const r=await apiFetch("api.php?action=ai_recognize",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({text}),signal:controller.signal});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"识别失败");
    aiParsed=j.data;
    /* 标记：这个 aiParsed 的时间来自识别（区别于 openAddSheet 的「当前时刻」默认底板），
       aiTimeEpoch 据此在保存时采用识别时间 —— 否则「昨天午饭」会被静默存成「现在」，
       且表单显示的时间与实际保存的不一致（显示 11:46 存成 10:46 这类怪象）。 */
    aiParsed.timeFromAI=1;
    /* 标签：仅保留本地存在的标签 id（LLM 可能给出不存在的标签，避免提交脏 id） */
    aiParsed.tagIds=(Array.isArray(aiParsed.tagIds)?aiParsed.tagIds:[]).map(String).filter(id=>!!tagById(id));
    ["aiCatTxt","aiAcctTxt","aiDstTxt","aiTagTxt","aiMemo","aiAmt","aiTime"].forEach(id=>{ const el=g(id); if(el){ el.value=""; delete el.dataset.userEdited; } });
    renderConfirm();
    hint.textContent="识别完成，核对无误后保存（所有字段可修改）";
  }catch(e){
    if(timedOut){
      showErr("AI 识别超时，请检查模型服务后重试");
      hint.textContent="识别超时，可重试或直接手动填写";
    }else{
      showErr(e.message||"识别失败");
      hint.textContent="识别失败，可直接手动填写下方内容保存，或修改文字重试";
    }
    /* 确认卡已常驻，不重置用户已填内容 */
  }
  finally{ clearTimeout(timeoutId); btn.disabled=false; btn.textContent="识别"; }
}
/* 关弹层 = 完全复位：loading 只属于「这一次操作」，不能跟着下次打开复活
   （deleteTx 成功路径只调这里、从不清 loading，曾导致删完一笔再打开另一笔一直显示「删除中…」） */
/* 关弹层 = 完全复位：loading 只属于「这一次操作」，不能跟着下次打开复活
   （deleteTx 成功路径只调这里、从不清 loading，曾导致删完一笔再打开另一笔一直显示「删除中…」） */
function closeAddSheet(){ document.getElementById("addMask").hidden=true; sheetLoading(false); }
/* 提交（新建/编辑） */
async function submitTx(){
  const g=id=>document.getElementById(id);
  const amt=parseFloat(g("aiAmt").value);
  const err=g("addErr");
  const showErr=m=>{ err.hidden=false; err.textContent=m; };
  if(!(amt>0)) return showErr("金额需大于 0");
  const isEdit=addMode==="edit";
  if(aiParsed.accountNeedsConfirm) return showErr("请先点击账户栏，选择正确的账户");
  if(!aiParsed.categoryId||String(aiParsed.categoryId)==="0") return showErr("请选择分类");
  err.hidden=true;
  /* 编辑跨币种转账时按原交易汇率同步目标金额；只改备注/分类不会破坏原来的换算关系。 */
  let destinationAmount=0;
  if(+aiParsed.type===4){
    const oldSrc=+editTx?.amount||0, oldDst=+editTx?.destinationAmount||0;
    destinationAmount=isEdit&&oldSrc>0&&oldDst>0?round2(amt*oldDst/oldSrc):amt;
  }
  const body={
    type:+aiParsed.type, amount:amt,
    categoryId:aiParsed.categoryId||"0",
    sourceAccountId:aiParsed.sourceAccountId||"0",
    destinationAccountId:+aiParsed.type===4?(aiParsed.destinationAccountId||"0"):"0",     /* 非转账不带转入账户 */
    destinationAmount,
    comment:g("aiMemo").value.trim(),
    time:aiTimeEpoch(),
    tagIds:(aiParsed.tagIds||[]).map(String)                                              /* 标签（可空数组） */
  };
  if(isEdit) body.id=String(editTx.id);
  /* 带上原时间：编辑可能**跨月**（用户改了时间），服务端要据此把旧月份的聚合缓存一并失效，
     不然旧月份会留着一笔已经不存在的账（F5 后表现为「这笔账同时出现在两个月份」）。 */
  if(isEdit&&editTx.time) body.oldTime=editTx.time;
  /* ezBookKeeping 对「只修改分类」的 transactions/modify.json 请求可能返回
     nothing will be updated。此时改用单笔批量分类接口；金额、备注、账户或时间
     同时变化时仍走完整交易修改接口。 */
  let editAction=isEdit?"edit_tx":"add_tx";
  if(isEdit){
    /* 编辑表单可能原样提交。提前比较完整交易字段，避免上游返回
       "nothing will be updated"，并给用户一个明确提示。 */
    const oldCat=String(editTx.categoryId||catIdFromTx(editTx)||"");
    const newCat=String(body.categoryId||"");
    const tags=(v=>(v||[]).map(String).sort().join(","));
    const unchanged=+editTx.type===+body.type
      && Math.abs((+editTx.amount||0)-amt)<0.005
      && Math.abs((+editTx.destinationAmount||0)-(+body.destinationAmount||0))<0.005
      && String(editTx.categoryId||catIdFromTx(editTx)||"")===newCat
      && String(editTx.sourceAccountId||"")===String(body.sourceAccountId||"")
      && String(editTx.destinationAccountId||"0")===String(body.destinationAccountId||"0")
      && Math.floor(+editTx.time||0)===Math.floor(+body.time||0)
      && String(editTx.comment||"").trim()===String(body.comment||"").trim()
      && tags(editTx.tagIds)===tags(body.tagIds);
    if(unchanged) return showErr("没有修改任何内容");
    const onlyCategory=oldCat!==""&&newCat!==""&&oldCat!==newCat
      && +editTx.type===+body.type
      && Math.abs((+editTx.amount||0)-amt)<0.005
      && Math.abs((+editTx.destinationAmount||0)-(+body.destinationAmount||0))<0.005
      && String(editTx.sourceAccountId||"")===String(body.sourceAccountId||"")
      && String(editTx.destinationAccountId||"0")===String(body.destinationAccountId||"0")
      && Math.floor(+editTx.time||0)===Math.floor(+body.time||0)
      && String(editTx.comment||"").trim()===String(body.comment||"").trim()
      && tags(editTx.tagIds)===tags(body.tagIds);
    if(onlyCategory) editAction="edit_tx_category";
  }
  sheetLoading(true, "保存中…");
  /* 乐观更新：本地立即生效（失败回滚） */
  const cache=state.calCache[state.calYM];
  if(!isEdit&&cache){
    const acct=(state.meta?.accounts||[]).find(a=>a.id===String(body.sourceAccountId));
    const cm=catMetaOf(body.categoryId, body.type);
    const cat=cm?cm.name:"未分类";
    const ds=localDateStr(body.time); const day=cache.daily.find(x=>x.date===ds);
    if(day&&body.type!==4) day[body.type===2?"income":"expense"]=(day[body.type===2?"income":"expense"]||0)+amt;
    cache.transactions.unshift(optNewTx={id:"tmp-"+Date.now(), type:+body.type, time:body.time, categoryId:String(body.categoryId), sourceAccountId:String(body.sourceAccountId), destinationAccountId:String(body.destinationAccountId), destinationAmount:body.destinationAmount, amount:amt, comment:body.comment, tagIds:(body.tagIds||[]).map(String), account:acct?acct.name:"", category:cat, categoryColor:cm?cm.color:"", parent:cm?cm.parent:"", parentColor:cm?cm.parentColor:""});
    if(body.type!==4) applyKpiDelta(body.type, amt, body.time, +1);
  }
  if(isEdit&&cache){
    /* old 可能不存在：流水搜索能查到跨月/其它月的账，而 calCache 只装当前月。
       以前直接 Object.assign(undefined,…) 会抛「Cannot convert undefined or null to object」，
       整个提交中断 → 弹层不关、用户以为没保存。改：找不到旧条就跳过本地乐观更新，
       上游成功与否以接口返回为准（下方 catch 会统一兜底）。 */
    const old=cache.transactions.find(t=>String(t.id)===String(editTx.id));
    if(old){
      if(old.type===2) cache.daily.forEach(x=>{ if(localDateStr(old.time)===x.date) x.income-=old.amount; }); else if(old.type===3) cache.daily.forEach(x=>{ if(localDateStr(old.time)===x.date) x.expense-=old.amount; }); applyKpiDelta(old.type, old.amount, old.time, -1);
      const cmE=catMetaOf(body.categoryId, body.type);
      Object.assign(old, {type:body.type, time:body.time, amount:amt, destinationAmount:body.destinationAmount, comment:body.comment, sourceAccountId:body.sourceAccountId, destinationAccountId:body.destinationAccountId, categoryId:body.categoryId, category:cmE?cmE.name:(state.meta.catName(body.categoryId)||old.category), categoryColor:cmE?cmE.color:old.categoryColor, parent:cmE?cmE.parent:old.parent, parentColor:cmE?cmE.parentColor:old.parentColor});
      if(old.type===2) cache.daily.forEach(x=>{ if(localDateStr(old.time)===x.date) x.income+=old.amount; }); else if(old.type===3) cache.daily.forEach(x=>{ if(localDateStr(old.time)===x.date) x.expense+=old.amount; });
      applyKpiDelta(body.type, amt, body.time, +1);
    }
  }
  renderTxByDay(); renderHomeKPI(); renderCalendar();
  try{
    const r=await apiFetch(`api.php?action=${editAction}`,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"保存失败");
    /* 乐观更新用的是 tmp- 临时 id，必须换成服务端真实 id：
       否则用户立刻点这条刚记的账去编辑/删除，会把 "tmp-…" 当 id 发出去 → 上游「交易不存在」 */
    if(!isEdit&&optNewTx){
      const realId=j.data&&j.data.id!=null?String(j.data.id):"";
      if(realId) optNewTx.id=realId;
    }
    optNewTx=null;
    /* 写成功 → 丢掉受影响月份的内存缓存（跨月编辑丢新旧两个月），
       让后续任何 fresh=false 的取数真正回源，而不是拿写入前的旧内存副本。 */
    dropCalCache(localDateStr(body.time).slice(0,7), isEdit&&editTx&&editTx.time?localDateStr(editTx.time).slice(0,7):"");
    closeAddSheet();
    toast(isEdit?`已更新 ${money(amt)}`:`已记账 ${money(amt)}`);
    txsMaybeRefresh();
  }catch(e){
    rollbackOpt();
    sheetLoading(false);
    showErr(e.message||"保存失败");
    return;
  }
  sheetLoading(false);
}
/* 删除 */
async function deleteTx(){
  if(!editTx) return;
  /* 二次确认：单笔删除没有批量场景的密码兜底，误触就是真删（首页/编辑弹层共用此入口）。
     miniConfirm z-index 130 > 记账弹层 90，确认框会盖在弹层上面。 */
  const ok=await miniConfirm("删除这笔交易？","删除后不可恢复，确定要删除吗？","删除",true);
  if(!ok) return;
  sheetLoading(true,"删除中…");
  optimistic(cache=>{
    cache.transactions=cache.transactions.filter(t=>String(t.id)!==String(editTx.id));
    const ds=localDateStr(editTx.time); const day=cache.daily.find(x=>x.date===ds);
    if(day&&editTx.type!==4) day[editTx.type===2?"income":"expense"]=Math.max(0,(day[editTx.type===2?"income":"expense"]||0)-editTx.amount);
  });
  applyKpiDelta(editTx.type, editTx.amount, editTx.time, -1);
  try{
    const r=await apiFetch("api.php?action=delete_tx",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:String(editTx.id),time:+editTx.time||0})});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"删除失败");
    /* 删成功 → 丢掉该月内存缓存（删除接口只发 id、服务端已兜底清全部 cal_，这里至少把当月丢掉） */
    dropCalCache(localDateStr(editTx.time).slice(0,7));
    closeAddSheet(); toast("已删除");
    txsMaybeRefresh();
  }catch(e){
    rollbackOpt(); sheetLoading(false);
    const err=document.getElementById("addErr"); err.hidden=false; err.textContent=e.message||"删除失败";
  }
}
/* 绑定 */
document.getElementById("fabAdd").onclick=openAddSheet;
document.getElementById("addClose").onclick=closeAddSheet;
document.getElementById("addMask").addEventListener("click",e=>{ if(e.target.id==="addMask") closeAddSheet(); });
document.getElementById("aiBtn").onclick=aiRecognize;
document.getElementById("aiText").addEventListener("keydown",e=>{ if(e.key==="Enter") aiRecognize(); });
document.getElementById("aiSave").onclick=submitTx;
document.getElementById("aiDel").onclick=deleteTx;
bindTxDayToggle();   /* 日期条点开/收起 · 委托一次即可，内部有 dataset.toggleBound 幂等守卫 */
/* 当月流水行点击 → 编辑
   ⚠️ 必须按「所在日期块 + 块内第几行」定位，不能用「全页第几行」的全局下标。
   折叠上线后 .cd-row 是动态增删的 —— 收起几天再数下标，会把点击算到别的账上。
   取数走 txDayList()，与渲染共用同一套筛选，杜绝「画的是 A、点开的是 B」。 */
document.getElementById("dayTxWrap").addEventListener("click",e=>{
  const row=e.target.closest(".cd-row"); if(!row) return;
  const grp=row.closest(".daygroup"); if(!grp||!grp.dataset.day) return;
  const idx=[...grp.querySelectorAll(".dayrows .cd-row")].indexOf(row); if(idx<0) return;
  const tx=txDayList(grp.dataset.day)[idx];
  if(tx) openEditSheet(String(tx.id));
});

/* ================= 页面路由（hash） ================= */
const PAGE_IDS={home:"pageHome",txs:"pageTxs",stats:"pageStats",assets:"pageAssets"};
const PAGE_CHARTS={stats:["trendChart","donutChart","dailyChart","saveChart","dowChart","cmpChart","cumChart"],assets:["assetChart","sankeyChart"],txs:[],home:[]};
let currentPage="home";
function route(){
  const page=(location.hash||"#/home").replace("#/","")||"home";
  if(!PAGE_IDS[page]) return;
  /* 离开流水页必须收尾：批量条与批量弹窗挂在 .page 容器之外（fixed 浮层），
     不收起就会跟着飘到首页/统计页上，且此时批量操作作用在看不见的行上 */
  if(currentPage==="txs"&&page!=="txs"&&typeof txs!=="undefined") txsExit();
  currentPage=page;
  if(page==="txs"&&typeof txs!=="undefined") txsOnEnter();   /* 首次进入流水页时懒加载 meta 填筛选下拉 */
  /* A3 下钻：只有真从图表跳过来时才有暂存数据；txsApplyDrill 内部自行判空。
     ⚠️ route() 定义在 let txs 之前，这里必须走 typeof 守卫（同 txsOnEnter），
     否则深链 #/txs 刷新会触发 TDZ，其后整段脚本静默不执行。 */
  if(page==="txs"&&typeof txs!=="undefined"&&typeof txsApplyDrill==="function") txsApplyDrill();
  Object.entries(PAGE_IDS).forEach(([p,id])=>{ const el=document.getElementById(id); if(el) el.classList.toggle("on",p===page); });
  document.querySelectorAll("#pageTabs button,#tabbar button").forEach(b=>{
    const on=b.dataset.p===page;
    b.classList.toggle("on",on);
    if(on) b.setAttribute("aria-current","page"); else b.removeAttribute("aria-current");
  });
  /* 顶栏放大镜是全局搜索快捷方式；当前页状态由桌面/移动页签统一表达。 */
  const entry=document.getElementById("txsEntryBtn");
  if(entry) entry.classList.remove("on");
  const slider=document.querySelector(".tab-slider");
  const idx=["home","txs","stats","assets"].indexOf(page);
  if(slider){
    slider.dataset.i=Math.max(0,idx);
  }
  /* 隐藏页签里的 ECharts 尺寸为 0，切页显示后补一次 resize */
  setTimeout(()=>{ (PAGE_CHARTS[page]||[]).forEach(id=>{ const c=state.charts[id]; if(c&&!c.isDisposed()) c.resize(); }); },60);
}
window.addEventListener("hashchange",route);
document.getElementById("pageTabs").addEventListener("click",e=>{
  const b=e.target.closest("button[data-p]"); if(!b) return;
  location.hash="#/"+b.dataset.p;
});
document.getElementById("tabbar").addEventListener("click",e=>{
  const b=e.target.closest("button[data-p]"); if(!b) return;
  location.hash="#/"+b.dataset.p;
});
/* 顶栏搜索入口：已在流水页则把焦点交给搜索框（避免二次点击无反馈） */
document.getElementById("txsEntryBtn").addEventListener("click",()=>{
  if(currentPage==="txs"){ const q=document.getElementById("txsQ"); if(q) q.focus(); }
  else location.hash="#/txs";
});
/* 注意：route() 的首次调用放在流水页代码之后（见文件末尾「路由初始化」）——
   route() 会用到 txs（let 声明，存在 TDZ），深链 #/txs 直接刷新时若提前调用会抛
   ReferenceError 并中断其后的整段脚本（登录绑定等全部失效）。 */

/* ================= 流水页：搜索 + 批量整理 ================= */
const TXS_PAGE=50;                       /* 客户端分页：上游 list/all 一次给全量，前端按 50 笔逐步展示 */
let txs={items:[],total:0,shown:0,selMode:false,sel:new Set(),metaFilled:false,seq:0};
function txsOnEnter(){
  if(!state.loggedIn) return;
  /* 首次进入先铺引导文案：否则「搜索结果」卡片是一片空白，看着像坏了 */
  if(!txs.seq&&!document.getElementById("txsList").children.length) txsReset(TXS_IDLE);
  if(txs.metaFilled&&state.meta) return;
  ensureMeta().then(()=>{ if(state.meta) fillTxsPickers(); }).catch(()=>{});   /* 失败静默：meta 懒加载，真要用时还会再拉（更多筛选面板打开时也有兜底重试） */
  if(!txs.seq){
    const ym=state.calYM||new Date().toLocaleDateString("sv").slice(0,7);
    const last=new Date(+ym.slice(0,4),+ym.slice(5,7),0).getDate();
    document.getElementById("txsFrom").value=`${ym}-01`;
    document.getElementById("txsTo").value=`${ym}-${String(last).padStart(2,"0")}`;
    document.getElementById("txsMore").hidden=false;
    document.getElementById("txsMoreBtn").textContent="收起筛选";
    txsSearch();
  }
}
/* 结果区空态统一收口：清空列表 + 收起「加载更多/多选」+ 退出残留多选态。
   无结果时不给「多选」，免得用户对着空列表进入多选、批量条空转 */
function txsReset(msg){
  txs.items=[]; txs.total=0; txs.shown=0;
  if(txs.selMode) setSelMode(false);
  document.getElementById("txsList").innerHTML=`<div class="txs-empty">${msg}</div>`;
  document.getElementById("txsHint").textContent="";
  document.getElementById("txsLoadBtn").hidden=true;
  document.getElementById("txsSelBtn").hidden=true;
  syncSelAllLabel(); renderTxsBarState();
  syncTxsExportBtn();   /* 结果清空 → 导出入口同步置灰 */
}
const TXS_IDLE='输入条件后点「查询」<br><b>例：</b>关键词「外卖」 · 类型「支出」 · 金额 ≥ 100';
function syncTxsPickerUI(){
  const catSel=document.getElementById("txsCat");
  const catBtn=document.getElementById("txsCatPicker");
  const catText=document.getElementById("txsCatPickerText");
  const type=txsTypeValue();
  if(catBtn) catBtn.disabled=!state.meta;
  if(catText){
    const opt=catSel?.selectedOptions?.[0];
    catText.textContent=opt?.value?String(opt.textContent).replace(/^\s+/g,""):"全部分类";
  }
  const acctSel=document.getElementById("txsAcct");
  const acctText=document.getElementById("txsAcctPickerText");
  if(acctText){
    const opt=acctSel?.selectedOptions?.[0];
    acctText.textContent=opt?.value?String(opt.textContent):"全部账户";
  }
}
/* 筛选数据源：分类按当前流水类型过滤；分类与账户使用统一底部双栏选择器。 */
function fillTxsPickers(){
  const m=state.meta; if(!m) return;
  txs.metaFilled=true;
  const reset=sel=>{ const keep=sel.querySelector('option[value=""]'); sel.innerHTML=""; if(keep) sel.appendChild(keep); };
  const hasOption=(sel,value)=>Array.from(sel.options).some(o=>o.value===String(value));
  const catSel=document.getElementById("txsCat");
  const previousCat=catSel.value;
  reset(catSel);
  const addTree=(tree,label)=>{ (tree||[]).forEach(p=>{
    const og=document.createElement("optgroup"); og.label=`${label} · ${p.name}`;
    const po=document.createElement("option");
    po.value=String(p.id); po.textContent=`${p.name}（整个大类）`;
    og.appendChild(po);
    (p.subCategories||[]).forEach(c=>{
      const o=document.createElement("option");
      o.value=String(c.id); o.textContent=`　${c.name}`;
      og.appendChild(o);
    });
    catSel.appendChild(og);
  });};
  const type=txsTypeValue();
  if(type===0||type===3) addTree(m.catTree.expense,"支出");
  if(type===0||type===2) addTree(m.catTree.income,"收入");
  if(type===0||type===4) addTree(m.catTree.transfer,"转账");
  catSel.value=hasOption(catSel,previousCat)?previousCat:"";

  const acctSel=document.getElementById("txsAcct");
  const previousAcct=acctSel.value;
  reset(acctSel);
  (m.accounts||[]).filter(a=>!a.hidden).forEach(a=>{ const o=document.createElement("option"); o.value=a.id; o.textContent=a.name; acctSel.appendChild(o); });
  acctSel.value=hasOption(acctSel,previousAcct)?previousAcct:"";

  const tagSel=document.getElementById("txsTag");
  const previousTag=tagSel.value;
  reset(tagSel);
  (m.tags||[]).forEach(t=>{ const o=document.createElement("option"); o.value=t.id; o.textContent=t.name; tagSel.appendChild(o); });
  tagSel.value=hasOption(tagSel,previousTag)?previousTag:"";
  syncTxsPickerUI();
}
document.getElementById("txsTypeSeg").addEventListener("click",e=>{
  const b=e.target.closest("button[data-t]"); if(!b) return;
  document.querySelectorAll("#txsTypeSeg button").forEach(x=>x.classList.toggle("on",x===b));
  if(state.meta) fillTxsPickers(); else syncTxsPickerUI();
});
document.getElementById("txsMoreBtn").addEventListener("click",()=>{
  const m=document.getElementById("txsMore");
  m.hidden=!m.hidden;
  document.getElementById("txsMoreBtn").textContent=m.hidden?"更多筛选":"收起筛选";
  /* 兜底重试：进入页面那一刻 meta 可能还没铺上（网络抖动/首拉失败被静默吞掉），
     用户展开「更多筛选」看见分类/账户/标签「只有全部」会以为功能坏了 ——
     打开面板这一刻补一次拉取（fillTxsPickers 幂等，重复调用无害） */
  if(!txs.metaFilled||!state.meta) ensureMeta().then(()=>{ if(state.meta) fillTxsPickers(); }).catch(()=>{});
});
document.getElementById("txsCatPicker").addEventListener("click",()=>{
  if(!document.getElementById("txsCatPicker").disabled) openPicker("txcat");
});
document.getElementById("txsAcctPicker").addEventListener("click",()=>openPicker("txacct"));
document.getElementById("txsQ").addEventListener("keydown",e=>{ if(e.key==="Enter"){ e.preventDefault(); txsSearch(); } });
document.getElementById("txsGoBtn").addEventListener("click",()=>txsSearch());
function txsReadFilters(){
  const g=id=>document.getElementById(id);
  return {
    q:g("txsQ").value.trim(),
    type:+(document.querySelector("#txsTypeSeg button.on")?.dataset.t||0),
    from:g("txsFrom").value||"", to:g("txsTo").value||"",
    cat:g("txsCat").value||"", acct:g("txsAcct").value||"",
    tag:g("txsTag").value||"", tagMode:g("txsTagMode").value||"any",
    amtMin:g("txsAmtMin").value.trim(), amtMax:g("txsAmtMax").value.trim()
  };
}

/* ================= A1 · 流水导出 =================
   数据源是 txs.items —— tx_search 一次就把筛选全量返回（TXS_PAGE 只是前端切片，
   后端没有分页），所以导出必须在浏览器端拼，不再跑一趟上游。
   两个硬约束（不满足会被用户当成 bug）：
     ① UTF-8 BOM：不加，Excel 双击打开中文就是乱码；
     ② 19 位雪花 ID 加双引号：不加，Excel 显示成 1.23E+18，用户复制出来就是错的。 */
const TXS_EXPORT_HEAD=['日期','时间','类型','大类','分类','金额','币种','账户','转入账户','标签','备注','交易ID'];
const TXS_TYPE_NAME={1:'余额调整',2:'收入',3:'支出',4:'转账'};
function txsExportRows(){
  return (txs.items||[]).map(t=>{
    const d=new Date((t.time||0)*1000);
    const pad=n=>String(n).padStart(2,'0');
    /* 日期/时间在本地时区拆（不能用 toISOString，那会转成 UTC，跨零点会差一天） */
    const date=Number.isNaN(d.getTime())?'':`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    const clock=Number.isNaN(d.getTime())?'':`${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    const tagNames=(t.tagIds||[]).map(id=>tagById(id)?.name).filter(Boolean).join(' ');
    return [
      date, clock,
      TXS_TYPE_NAME[t.type]||'',
      t.parent||'',
      t.category||'',
      (Math.round((t.amount||0)*100)/100).toFixed(2),   /* 纯数字，Excel 可直接 SUM */
      t.currency||'',
      t.account||'',
      t.destAccount||'',
      tagNames,
      t.comment||'',
      String(t.id||''),
    ];
  });
}
/* CSV 转义：值里含逗号/引号/换行时必须用双引号包裹，内部引号翻倍 */
function csvCell(v){
  const s=String(v==null?'':v);
  return /[",\r\n]/.test(s) ? '"'+s.replace(/"/g,'""')+'"' : s;
}
function txsExportCsvText(){
  /* ID 列强制加引号：19 位数字不加引号会被 Excel 当科学计数法；加了引号 CSV 里
     解析出来仍是纯数字串，Excel 会识别为文本 */
  const lines=[TXS_EXPORT_HEAD.join(',')];
  txsExportRows().forEach(r=>{
    const cells=r.map((v,ci)=>{
      /* 最后一列是交易ID：无条件加引号锁成文本。
         不能按「纯数字才加」判断——真实雪花 ID 是 19 位纯数字（不加引号 Excel 会显示成
         1.23E+18），而本地 mock 的 id 形如 2026-09-01-1；两种都必须当文本，否则
         用户从 Excel 复制出来的 ID 就是错的。 */
      if(ci===r.length-1) return '"'+String(v).replace(/"/g,'""')+'"';
      return csvCell(v);
    });
    lines.push(cells.join(','));
  });
  return lines.join('\r\n')+'\r\n';   /* CRLF：Excel 友好 */
}
/* 无筛选日期时用「全部」，有则用区间；文件名带时分秒，连点两次不覆盖 */
function txsExportFilename(ext){
  const f=txsReadFilters();
  const stamp=new Date();
  const p=n=>String(n).padStart(2,'0');
  const ts=`${stamp.getFullYear()}${p(stamp.getMonth()+1)}${p(stamp.getDate())}-${p(stamp.getHours())}${p(stamp.getMinutes())}${p(stamp.getSeconds())}`;
  const span=(f.from||f.to)?`${f.from||'起始'}_${f.to||'至今'}`:'全部';
  return `ezBookDash-流水-${span}-${ts}.${ext}`;
}
/* 触发下载：BOM 直接拼在 Blob 内容最前（不是单独一个 Blob），否则某些浏览器会丢 */
function txsDownload(text,filename){
  const blob=new Blob(["\uFEFF"+text],{type:'text/csv;charset=utf-8'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url; a.download=filename; a.style.display='none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1500);
}
/* 剪贴板兜底：iOS Safari / 微信内置浏览器对 a[download] 支持不完整 */
async function txsExportClipboard(){
  const rows=txsExportRows();
  /* 制表符分隔——粘进 Excel 会自动分列 */
  const text=[TXS_EXPORT_HEAD.join('\t'),...rows.map(r=>r.map(v=>String(v).replace(/[\t\r\n]/g,' ')).join('\t'))].join('\n');
  try{
    if(navigator.clipboard&&window.isSecureContext){ await navigator.clipboard.writeText(text); }
    else{
      const ta=document.createElement('textarea');
      ta.value=text; ta.style.position='fixed'; ta.style.left='-9999px';
      document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove();
    }
    txsExportTip('已复制 '+rows.length+' 笔，可直接粘贴到 Excel');
  }catch(e){
    txsExportTip('复制失败，请改用「导出 CSV」','err');
  }
}
/* 轻提示：复用 .txs-tip 位置，不新建浮层（避免再加一层 z-index） */
let txsExpTipTimer=0;
function txsExportTip(msg,bad){
  const tip=document.querySelector('.txs-tip');
  if(!tip) return;
  if(!tip.dataset.orig) tip.dataset.orig=tip.textContent;
  tip.textContent=msg;
  tip.style.color=bad?'var(--rose)':'var(--green)';
  clearTimeout(txsExpTipTimer);
  txsExpTipTimer=setTimeout(()=>{
    tip.textContent=tip.dataset.orig;
    tip.style.color='';
  },3200);
}
/* 导出按钮可用性：必须「查过一次且结果非空」。
   注意 txs.seq 只在真正发起查询时自增；空条件引导态不会查 → 按钮保持禁用。 */
function syncTxsExportBtn(){
  const btn=document.getElementById('txsExportBtn');
  if(!btn) return;
  const n=(txs.items||[]).length;
  const ok=txs.seq>0&&n>0;
  btn.disabled=!ok;
  btn.title=ok?`导出当前筛选的全部 ${n} 笔`:'请先查询';
  const c=document.getElementById('txsExpCount');
  if(c) c.textContent=ok?`共 ${n.toLocaleString('zh-CN')} 笔`:'—';
  if(!ok) closeTxsExportMenu();
}
function closeTxsExportMenu(){ const m=document.getElementById('txsExportMenu'); if(m) m.hidden=true; }
document.getElementById('txsExportBtn').addEventListener('click',()=>{
  const m=document.getElementById('txsExportMenu');
  if(m.hidden&&(txs.items||[]).length>20000){
    /* 5 万笔级别先告知：生成时页面会短暂无响应，用户要知道是正常的 */
    if(!confirm(`共 ${txs.items.length.toLocaleString('zh-CN')} 笔，文件较大，生成时页面会短暂停顿。继续？`)) return;
  }
  m.hidden=!m.hidden;
});
document.getElementById('txsExportMenu').addEventListener('click',e=>{
  const b=e.target.closest('.txs-expitem'); if(!b) return;
  closeTxsExportMenu();
  if(b.dataset.act==='csv') txsDownload(txsExportCsvText(),txsExportFilename('csv'));
  else if(b.dataset.act==='clip') txsExportClipboard();
});
/* 点空白处收起导出菜单 */
document.addEventListener('click',e=>{
  const m=document.getElementById('txsExportMenu');
  if(!m||m.hidden) return;
  if(e.target.closest('#txsExportMenu')||e.target.closest('#txsExportBtn')) return;
  m.hidden=true;
});
async function txsSearch(){
  const f=txsReadFilters();
  const list=document.getElementById("txsList");
  /* 空态/错误态一律走 txsReset()（见上），这里只负责真正发起查询 */
  /* 日期区间反了要拦住：上游只会静默返回空集，用户会误以为「这段没有流水」 */
  if(f.from&&f.to&&f.from>f.to){ txsReset('开始日期不能晚于结束日期'); return; }
  const qs=new URLSearchParams();
  if(f.q) qs.set("q",f.q);
  if(f.type) qs.set("type",String(f.type));
  if(f.from) qs.set("from",f.from);
  if(f.to) qs.set("to",f.to);
  if(f.cat) qs.set("cat",f.cat);
  if(f.acct) qs.set("acct",f.acct);
  if(f.tag){ qs.set("tags",f.tag); if(f.tagMode!=="any") qs.set("tagMode",f.tagMode); }
  if(f.amtMin!=="") qs.set("amtMin",f.amtMin);
  if(f.amtMax!=="") qs.set("amtMax",f.amtMax);
  if(![...qs.keys()].length){ txsReset(TXS_IDLE); return; }   /* 至少一个条件：避免无意识全量拉取 */
  const seq=++txs.seq;   /* 竞态护栏：连点「查询」/反复回车时只认最后一次响应，避免旧结果覆盖新结果 */
  if(txs.selMode) setSelMode(false);
  txs.sel.clear();
  document.getElementById("txsLoadBtn").hidden=true;
  document.getElementById("txsSelBtn").hidden=true;
  list.innerHTML=`<div class="cd-loading">搜索中…</div>`;
  try{
    const r=await apiFetch(`api.php?action=tx_search&${qs}`,{cache:"no-store"});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(seq!==txs.seq) return;
    if(!j.success) throw new Error(j.error||"搜索失败");
    txs.items=j.data.items||[]; txs.total=txs.items.length;
    txs.shown=Math.min(TXS_PAGE,txs.items.length);
    txs.sel.clear();
    renderTxs();
  }catch(e){
    if(seq!==txs.seq) return;
    txsReset(esc(e.message||"搜索失败"));
  }
  renderTxsBarState();
}
/* 结果渲染：行片段复用 txParts（与当月流水/明细弹窗同一套视觉），勾选框仅多选模式显示 */
function renderTxs(){
  const host=document.getElementById("txsList");
  const loadBtn=document.getElementById("txsLoadBtn");
  const shown=txs.items.slice(0,txs.shown);
  if(!shown.length){ txsReset('没有符合条件的流水<br>换个关键词或放宽条件试试'); return; }
  host.innerHTML=shown.map(t=>{
    const p=txParts(t,{});                      /* 默认 timeFmt 带日期：跨月搜索需要 */
    const meta=[p.acct,p.memo].filter(Boolean).join(" · ");
    const on=txs.sel.has(String(t.id));
    return `<div class="cd-row${on?" on":""}" data-id="${esc(String(t.id))}" role="button" tabindex="0"><div class="tx-crow"><span class="txs-ck">✓</span>${p.pill}<span class="cnm">${p.label}</span>${p.amtStr}</div><div class="tx-mrow"><span class="meta1">${esc(meta)||"—"}</span><span class="tm">${p.timeStr}</span></div></div>`;
  }).join("");
  loadBtn.hidden=txs.shown>=txs.items.length;
  if(!loadBtn.hidden) loadBtn.textContent=`加载更多（还有 ${txs.items.length-txs.shown} 笔）`;
  /* 汇总（全量而非当前页）：收入/支出合计，转账与余额调整不计入 */
  const inc=txs.items.filter(t=>t.type===2).reduce((s,t)=>s+(t.amount||0),0);
  const exp=txs.items.filter(t=>t.type===3).reduce((s,t)=>s+(t.amount||0),0);
  document.getElementById("txsHint").textContent=`共 ${txs.items.length} 笔 · 收入 ${money(inc,true)} · 支出 ${money(exp,true)}`;
  document.getElementById("txsSelBtn").hidden=false;   /* 有结果才提供「多选」 */
  syncTxsExportBtn();                                  /* 有结果才允许导出（A1） */
  syncSelAllLabel();
}
document.getElementById("txsLoadBtn").addEventListener("click",()=>{
  txs.shown=Math.min(txs.shown+TXS_PAGE,txs.items.length); renderTxs();
});
document.getElementById("txsList").addEventListener("click",e=>{
  const row=e.target.closest(".cd-row[data-id]"); if(!row) return;
  const id=String(row.dataset.id);
  if(txs.selMode){
    if(txs.sel.has(id)) txs.sel.delete(id); else txs.sel.add(id);
    row.classList.toggle("on",txs.sel.has(id));
    renderTxsBarState();
  }else{
    const tx=txs.items.find(t=>String(t.id)===id);
    if(tx) openEditSheet(id,tx);
  }
});
document.getElementById("txsList").addEventListener("keydown",e=>{
  if(e.key!=="Enter"&&e.key!==" ") return;
  const row=e.target.closest(".cd-row[data-id]");
  if(!row) return;
  e.preventDefault(); row.click();
});
/* ---- 多选模式 ---- */
function setSelMode(on){
  txs.selMode=on;
  if(!on) txs.sel.clear();
  document.getElementById("txsList").classList.toggle("sel-mode",on);
  document.getElementById("txsSelBtn").textContent=on?"退出多选":"多选";
  document.getElementById("txsSelAll").hidden=!on;
  document.getElementById("txsBar").hidden=!on;
  /* 多选时收起「记一笔」FAB：它浮在列表右下角会压住最后几行的金额，此模式下也用不到 */
  const fab=document.getElementById("fabAdd");
  if(fab) fab.hidden=on||!state.loggedIn;
  renderTxsBarState(); syncSelAllLabel();
}
/* 离开流水页的收尾：退出多选 + 关掉可能开着的批量弹窗。
   两者都挂在 .page 容器之外（fixed 浮层），不收拾就会飘到首页/统计页上并作用于看不见的行 */
function txsExit(){
  if(txs.selMode) setSelMode(false);
  if(!document.getElementById("txsOpMask").hidden) closeTxsOp();
}
/* 在流水页对单笔做了编辑/删除/新增后，搜索结果必须重查：
   否则列表里留着的还是旧金额/旧分类，删掉的那行甚至还在（用户会以为没删掉）。
   seq>0 表示确实查过一次（空条件引导态不查，避免白跑一次上游） */
function txsMaybeRefresh(){ if(currentPage==="txs"&&txs.seq>0) txsSearch(); }
function renderTxsBarState(){ document.getElementById("txsBarN").textContent=String(txs.sel.size); }
document.getElementById("txsSelBtn").addEventListener("click",()=>setSelMode(!txs.selMode));
document.getElementById("txsCancelBtn").addEventListener("click",()=>setSelMode(false));
/* 「全选」只作用于当前已展示的行（50 笔一页，不是全部搜索结果）——文案与状态必须同源推导。
   旧写法只在点击时改文案，退出多选/重新搜索后不会回退，会出现「一行没选却写着取消全选」 */
function syncSelAllLabel(){
  const btn=document.getElementById("txsSelAll"); if(!btn) return;
  const all=txs.items.slice(0,txs.shown);
  btn.textContent=(all.length&&all.every(t=>txs.sel.has(String(t.id))))?"取消本页":"全选本页";
}
document.getElementById("txsSelAll").addEventListener("click",()=>{
  const all=txs.items.slice(0,txs.shown);
  const every=all.length&&all.every(t=>txs.sel.has(String(t.id)));
  if(every) all.forEach(t=>txs.sel.delete(String(t.id)));
  else all.forEach(t=>txs.sel.add(String(t.id)));
  renderTxs(); renderTxsBarState();
});
/* ---- 批量操作弹窗（一壳三态） ---- */
let txsOp="";
async function openTxsOp(op){
  if(!txs.sel.size){ toast("请先勾选流水"); return; }
  txsOp=op;
  const g=id=>document.getElementById(id);
  g("txsOpErr").hidden=true;
  g("txOpCat").hidden=op!=="category";
  g("txOpTag").hidden=op!=="tagAdd";
  g("txOpDel").hidden=op!=="delete";
  if(op==="category"){
    g("txsOpTitle").textContent="批量改分类";
    /* 类型构成提示 + 预设分段：多数类型优先，防手滑把支出改成收入分类 */
    const types={};
    txs.items.forEach(t=>{ if(txs.sel.has(String(t.id))) types[t.type]=(types[t.type]||0)+1; });
    const parts=[["3","支出"],["2","收入"],["4","转账"]].filter(([t])=>types[t]).map(([t,n])=>`${n} ${types[t]} 笔`);
    g("txOpCatTip").textContent=(parts.length?`所选：${parts.join(" · ")}`:"")+" · 改后类型仍按所选流水自身，分类类型需与之匹配";
    const maj=(+types[3]||0)>=(+types[2]||0)?3:2;
    document.querySelectorAll("#txOpCatSeg button").forEach(b=>b.classList.toggle("on",+b.dataset.t===maj));
    fillTxOpCat(maj);
  }else if(op==="tagAdd"){
    g("txsOpTitle").textContent="批量加标签";
    g("txOpTagList").innerHTML=`<div class="txs-empty">标签加载中…</div>`;
  }else if(op==="delete"){
    g("txsOpTitle").textContent="批量删除";
    g("txOpDelN").textContent=String(txs.sel.size);
    g("txOpPwd").value="";
  }
  g("txsOpMask").hidden=false;                        /* 先开窗再补数据：meta 冷缓存时别让用户干等 */
  if(op==="delete") setTimeout(()=>g("txOpPwd").focus(),80);
  /* 标签清单依赖懒加载的 meta：冷缓存时补拉一次，
     否则会把「还没加载」显示成「账本还没有标签」（用户会以为标签丢了） */
  if(op==="tagAdd"&&!state.meta){
    try{ await ensureMeta(); }catch(_){ }
    if(txsOp!=="tagAdd") return;                      /* 拉取期间用户可能已关窗或换了操作 */
  }
  if(op==="tagAdd"){
    const m=state.meta||{tags:[]};
    g("txOpTagList").innerHTML=(m.tags||[]).map(t=>`<div class="txs-tagit" data-id="${esc(t.id)}"><span class="ck">✓</span><span>${esc(t.name)}</span></div>`).join("")
      || `<div class="txs-empty">账本还没有标签</div>`;
  }
}
function fillTxOpCat(type){
  const m=state.meta; if(!m) return;
  const sel=document.getElementById("txOpCatSel");
  sel.innerHTML="";
  ((type===2?m.catTree.income:m.catTree.expense)||[]).forEach(p=>{
    const og=document.createElement("optgroup"); og.label=p.name;
    const kids=(p.subCategories&&p.subCategories.length)?p.subCategories:[p];
    kids.forEach(c=>{ const o=document.createElement("option"); o.value=String(c.id); o.textContent=c.name; og.appendChild(o); });
    sel.appendChild(og);
  });
}
document.getElementById("txOpCatSeg").addEventListener("click",e=>{
  const b=e.target.closest("button[data-t]"); if(!b) return;
  document.querySelectorAll("#txOpCatSeg button").forEach(x=>x.classList.toggle("on",x===b));
  fillTxOpCat(+b.dataset.t);
});
document.getElementById("txOpTagList").addEventListener("click",e=>{
  const it=e.target.closest(".txs-tagit"); if(!it) return;
  it.classList.toggle("on");
});
function closeTxsOp(){ document.getElementById("txsOpMask").hidden=true; txsOp=""; }
document.getElementById("txsOpClose").addEventListener("click",closeTxsOp);
document.getElementById("txsOpCancel").addEventListener("click",closeTxsOp);
document.getElementById("txsOpMask").addEventListener("click",e=>{ if(e.target===e.currentTarget) closeTxsOp(); });
document.addEventListener("keydown",e=>{ if(e.key==="Escape"&&!document.getElementById("txsOpMask").hidden) closeTxsOp(); });
function txsOpErr(msg){ const e=document.getElementById("txsOpErr"); e.textContent=msg; e.hidden=false; }
document.getElementById("txsOpOk").addEventListener("click",()=>{
  const g=id=>document.getElementById(id);
  if(txsOp==="category"){
    const cat=g("txOpCatSel").value;
    if(!cat){ txsOpErr("请选择分类"); return; }
    txsBatch("category",{categoryId:cat});
  }else if(txsOp==="tagAdd"){
    const tagIds=[...document.querySelectorAll("#txOpTagList .txs-tagit.on")].map(x=>x.dataset.id);
    if(!tagIds.length){ txsOpErr("请至少勾选一个标签"); return; }
    txsBatch("tagAdd",{tagIds});
  }else if(txsOp==="delete"){
    const pwd=g("txOpPwd").value;
    if(!pwd){ txsOpErr("请输入登录密码"); return; }
    txsBatch("delete",{password:pwd});
  }
});
async function txsBatch(op,extra){
  extra=extra||{};
  const okBtn=document.getElementById("txsOpOk");
  okBtn.disabled=true; const oldTxt=okBtn.textContent; okBtn.textContent="处理中…";
  try{
    const r=await apiFetch("api.php?action=tx_batch",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({op,ids:[...txs.sel],...extra})});
    const j=await r.json();
    if(j.requireLogin){ setView(false); throw new Error("登录已失效，请重新登录"); }
    if(!j.success) throw new Error(j.error||"操作失败");
    closeTxsOp();
    setSelMode(false);
    const verb=op==="category"?"修改分类":op==="tagAdd"?"添加标签":op==="tagClear"?"清除标签":"删除";
    toast(`已${verb} ${j.data.affected} 笔`);
    /* 服务端已失效 cal_/dash_ 缓存：本地同步清 + 看板重拉（SWR 秒开）+ 搜索结果重查。
       ⚠️ 这里是「整块清空 + 立刻 load() 重拉」的模式，与写交易的 dropCalCache（只标脏）
       是两种策略，不要互相套用：批量操作后会立刻重拉，所以清空是安全的；
       而记账路径不能清空 —— 那时没有紧跟的重拉，渲染函数会读到 undefined。 */
    state.calCache={};
    Object.keys(calDirty).forEach(k=>delete calDirty[k]);   // 全清了，脏标记无意义，一并复位
    load();
    txsSearch();
  }catch(e){
    const msg=e.message||"操作失败";
    if(!document.getElementById("txsOpMask").hidden) txsOpErr(msg);
    else toast(msg);
  }finally{
    okBtn.disabled=false; okBtn.textContent=oldTxt;
  }
}
document.getElementById("txsBarCat").addEventListener("click",()=>openTxsOp("category"));
document.getElementById("txsBarTag").addEventListener("click",()=>openTxsOp("tagAdd"));
document.getElementById("txsBarDel").addEventListener("click",()=>openTxsOp("delete"));
document.getElementById("txsBarClr").addEventListener("click",async()=>{
  if(!txs.sel.size){ toast("请先勾选流水"); return; }
  const ok=await miniConfirm("清空标签",`将移除所选 ${txs.sel.size} 笔流水的全部标签。`,"清空",true);
  if(ok) txsBatch("tagClear",{});
});

/* ================= 路由初始化 =================
   必须放在流水页代码之后：route() 会用到 txs（let 声明，存在暂时性死区），
   用户深链 #/txs 刷新时若在 txs 定义前调用，会抛 ReferenceError 并中断其后的整段脚本
   （登录绑定、setView 等全部失效，页面看似「按钮都没反应」）。 */
route();

/* ================= 登录 / 退出 ================= */
function setView(loggedIn){
  if(!loggedIn&&state.loggedIn) state.authEpoch++;
  state.loggedIn = loggedIn;
  document.getElementById("rangeSeg").hidden = !loggedIn;
  document.getElementById("pageTabs").hidden = !loggedIn;
  document.getElementById("txsEntryBtn").hidden = !loggedIn;
  document.getElementById("refreshBtn").hidden = !loggedIn;
  document.getElementById("settingsBtn").hidden = !loggedIn;
  document.getElementById("content").hidden = !loggedIn;
  document.getElementById("tabbar").hidden = !loggedIn;
  document.getElementById("fabAdd").hidden = !loggedIn;
  document.getElementById("loginView").hidden = loggedIn;
  if(loggedIn&&currentPage==="txs") setTimeout(()=>txsOnEnter(),0);
  if(!loggedIn){
    document.getElementById("brandSub").textContent = "ezBookkeeping";
    state.data = null;
    state.meta = null;
    metaPromise = null;
    state.budget = null;                 /* 登出清掉预算缓存，换账号别串数据 */
    state.budRows = [];
    state.calYM = null;
    state.calCache = {};
    state.txOpenDays = {};
    aiParsed=null; editTx=null; optBackup=null; optNewTx=null;
    MODAL_IDS.forEach(id=>{ const el=document.getElementById(id); if(el) el.hidden=true; });
    document.getElementById("pickSheet").hidden=true;
    Object.values(state.charts).forEach(c=>{ try{ if(c&&!c.isDisposed()) c.clear(); }catch(e){} });
    ["kpis","dayTxWrap","calGrid","rankList","acctList","healthGrid"].forEach(id=>{ const el=document.getElementById(id); if(el) el.innerHTML=""; });
    document.getElementById("budBody").innerHTML='<div class="bud-loading">登录后读取预算…</div>';
    document.getElementById("aoVal").textContent="—";
    document.getElementById("aoGross").textContent="—";
    document.getElementById("aoLiab").textContent="—";
    document.getElementById("healthCard").hidden=true;
    document.getElementById("foot").textContent="";
    Object.keys(calDirty).forEach(k=>delete calDirty[k]);
    txs.items=[]; txs.total=0; txs.shown=0; txs.seq=0; txs.metaFilled=false; txs.sel.clear();
    ["txsQ","txsFrom","txsTo","txsAmtMin","txsAmtMax"].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=""; });
    ["txsCat","txsAcct","txsTag"].forEach(id=>{ const el=document.getElementById(id); if(el) [...el.options].slice(1).forEach(o=>o.remove()); });
    try{ sessionStorage.removeItem(DRILL_KEY); }catch(e){}
    if(txs.selMode) setSelMode(false);   /* 退出登录时收起批量层，重新登录别残留上次的多选态 */
    txsReset(TXS_IDLE);
  }
}

function showLoginError(msg){
  const el = document.getElementById("inLoginError");
  el.textContent = msg||"";
  el.hidden = !msg;
}

async function checkStatus(){
  try{
    const r = await apiFetch("api.php?action=status",{cache:"no-store"});
    const j = await r.json();
    state.csrf=j.csrf||"";
    document.getElementById("baseUrlNotice").hidden = !!j.baseUrlConfigured;
    if(j.loggedIn){
      setView(true);
      load();
    }else{
      setView(false);
      if(j.defaultMode){
        document.getElementById("inDefaultBtn").dataset.mode = j.defaultMode;
        document.getElementById("inDefaultBtn").hidden = false;
      }
    }
  }catch(e){
    setView(false);
    showLoginError("无法连接服务，请稍后重试");
  }
}

async function doLogin(payload){
  if(state.loginBusy) return;
  state.loginBusy = true;
  const btn = document.getElementById("inLoginBtn");
  btn.disabled = true;
  btn.textContent = "登录中…";
  showLoginError("");
  try{
    const r = await apiFetch("api.php?action=login",{
      method:"POST", headers:{"Content-Type":"application/json"},
      body:JSON.stringify(payload)
    });
    const j = await r.json();
    if(!j.success) throw new Error(j.error||"登录失败");
    state.csrf=j.csrf||state.csrf;
    setView(true);
    load();
  }catch(e){
    showLoginError(e.message);
  }finally{
    state.loginBusy = false;
    btn.disabled = false;
    btn.textContent = "登 录";
  }
}

async function doLogout(){
  state.authEpoch++;                 // 立即废弃仍在途的旧账号请求
  try{ await apiFetch("api.php?action=logout",{method:"POST",cache:"no-store"}); }catch(e){}
  state.csrf="";
  if("caches" in window){ try{ await caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith("ebk-pwa-")).map(k=>caches.delete(k)))); }catch(e){} }
  setView(false);
}

/* 退出登录入口已收敛到设置窗口（#setLogout → doLogout） */

document.getElementById("loginTabs").addEventListener("click",e=>{
  const b = e.target.closest("button"); if(!b) return;
  state.loginMode = b.dataset.m;
  document.querySelectorAll("#loginTabs button").forEach(x=>x.classList.toggle("on",x===b));
  document.getElementById("fPassword").hidden = state.loginMode!=="password";
  document.getElementById("fToken").hidden = state.loginMode!=="token";
  showLoginError("");
});

document.getElementById("inLoginBtn").onclick = ()=>{
  const payload = { mode: state.loginMode };
  if(state.loginMode==="password"){
    payload.loginName = document.getElementById("inLoginName").value.trim();
    payload.password  = document.getElementById("inLoginPassword").value;
    if(!payload.loginName || !payload.password){ showLoginError("请输入用户名和密码"); return; }
  }else{
    payload.apiToken = document.getElementById("inLoginToken").value.trim();
    if(!payload.apiToken){ showLoginError("请输入 API 令牌"); return; }
  }
  doLogin(payload);
};

document.getElementById("inDefaultBtn").onclick = ()=> doLogin({ mode: document.getElementById("inDefaultBtn").dataset.mode });

["inLoginName","inLoginPassword","inLoginToken"].forEach(id=>{
  document.getElementById(id).addEventListener("keydown",e=>{
    if(e.key==="Enter"){ e.preventDefault(); document.getElementById("inLoginBtn").click(); }
  });
});

/* ================= 启动 ================= */
applyTheme();
bindBudget();
checkStatus();
setInterval(()=>{ if(state.loggedIn) load(); }, 5*60*1000+2000); // 与服务端缓存同步自动刷新

/* ================= 桑基图全屏弹窗（移动端） ================= */
/* 弹窗内独立 ECharts 实例：完整节点（不做 TOP-N 收敛），随横竖屏 resize；
   关闭时 dispose 释放画布，重开时按当前数据重建。
   竖屏持握（portrait 视口）加 force-ls 强制旋转 90°——手机横过来看即桌面版布局，不依赖系统自动旋转。 */
let skModalChart=null;
function skModalIsPortrait(){ return window.innerHeight>window.innerWidth; }
function skModalRender(){
  const holder=document.getElementById("skModalHolder"), modal=document.getElementById("skModal");
  const host=document.getElementById("skModalChart");
  if(!holder||!modal||!host||!state.data) return;
  if(skModalChart){ skModalChart.dispose(); skModalChart=null; }
  host.innerHTML="";
  skModalChart=echarts.init(host,"wb");
  const tooltip={backgroundColor:chartColors().tip,borderColor:chartColors().grid,
    textStyle:{color:chartColors().text,fontSize:12.5},
    extraCssText:"box-shadow:0 6px 20px rgba(0,0,0,.15);border-radius:10px;"};
  renderSankeyCore(state.data,tooltip,chartColors(),skModalChart,host,false);
  skModalChart.resize();
}
function skModalOpen(){
  const holder=document.getElementById("skModalHolder");
  holder.classList.toggle("force-ls",skModalIsPortrait());   /* 竖屏视口→旋转 90° 强制横屏 */
  document.getElementById("skModalLsTip").hidden=!holder.classList.contains("force-ls");
  holder.hidden=false;
  document.getElementById("skModal").classList.add("on");
  document.body.style.overflow="hidden";          /* 锁背景滚动 */
  skModalGoFullscreen();                          /* 请求全屏+横屏锁定：成功时浏览器工具栏隐藏（需用户手势，此处正有点击） */
  setTimeout(skModalRender,30);                   /* 等布局生效后量取尺寸 */
  /* 提示条 2.6s 后自动淡出，不遮挡图表 */
  clearTimeout(skModalOpen._tipT1); clearTimeout(skModalOpen._tipT2);
  const tip=document.getElementById("skModalLsTip");
  tip.classList.remove("fade");
  skModalOpen._tipT1=setTimeout(()=>tip.classList.add("fade"),2600);
  skModalOpen._tipT2=setTimeout(()=>{ tip.hidden=true; tip.classList.remove("fade"); },3300);
}
/* 全屏 + 横屏锁定（尽力而为）：Fullscreen API 隐藏浏览器工具栏，screen.orientation.lock 锁横屏；
   两者都可能被浏览器拒绝（不支持/未授权），静默降级——已有 force-ls CSS 旋转兜底 */
function skModalGoFullscreen(){
  const el=document.getElementById("skModalHolder");
  const req=el.requestFullscreen||el.webkitRequestFullscreen||el.webkitRequestFullScreen;
  if(!req) return;
  const done=()=>{
    /* 全屏生效后若系统可锁横屏则锁定（Android Chrome 支持；iOS Safari 不支持会 reject，静默忽略） */
    try{ screen.orientation.lock("landscape").catch(()=>{}); }catch(e){}
    setTimeout(skModalRender,120);   /* 全屏后视口尺寸变化，重量取渲染 */
  };
  try{ const p=req.call(el); if(p&&p.then) p.then(done,()=>{}); else done(); }catch(e){}
}
function skModalExitFullscreen(){
  try{
    const fs=document.fullscreenElement||document.webkitFullscreenElement;
    if(fs){ const ext=document.exitFullscreen||document.webkitExitFullscreen; if(ext) ext.call(document); }
    try{ screen.orientation.unlock(); }catch(e){}
  }catch(e){}
}
function skModalClose(){
  skModalExitFullscreen();
  document.getElementById("skModal").classList.remove("on");
  document.getElementById("skModalHolder").hidden=true;
  document.body.style.overflow="";
  if(skModalChart){ skModalChart.dispose(); skModalChart=null; }
}
document.getElementById("skZoomBtn").onclick=skModalOpen;
document.getElementById("skModalClose").onclick=skModalClose;
document.getElementById("skModalHolder").addEventListener("click",e=>{ if(e.target.id==="skModalHolder") skModalClose(); });
document.addEventListener("keydown",e=>{ if(e.key==="Escape"&&document.getElementById("skModal").classList.contains("on")) skModalClose(); });
window.addEventListener("orientationchange",()=>{
  setTimeout(()=>{
    if(!document.getElementById("skModal").classList.contains("on")) return;
    const holder=document.getElementById("skModalHolder");
    holder.classList.toggle("force-ls",skModalIsPortrait());   /* 旋转后同步强制横屏状态 */
    document.getElementById("skModalLsTip").hidden=true;       /* 旋转后不再提示 */
    skModalRender();   /* 容器尺寸变化，重建图表实例 */
  },350);
});
</script>
</body>
</html>
