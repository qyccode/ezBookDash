# ezBookDash

基于 ezBookKeeping API 的自托管记账客户端，包含流水、统计、资产、预算与 AI 辅助记账。

## 运行要求

- PHP 8.1+
- PHP 扩展：`curl`、`json`、`mbstring`
- 可访问 ezBookKeeping 服务
- Web 服务建议使用 HTTPS；仅可信内网测试可使用 HTTP

## 界面预览

以下为各页面的截图占位图。

| 页面 | 电脑端 | 移动端 |
| --- | --- | --- |
| 首页 | <img src="https://i.ibb.co/tMYhsVwx/01-desktop.png" width="360" alt="首页电脑端"> | <img src="https://i.ibb.co/gbYrMXVv/01-mobile.png" width="195" alt="首页移动端"> |
| 流水 | <img src="https://i.ibb.co/4wgQhqxW/02-desktop.png" width="360" alt="流水电脑端"> | <img src="https://i.ibb.co/js7xHBc/02-mobile.png" width="195" alt="流水移动端"> |
| 统计 | <img src="https://i.ibb.co/cHQfc6C/03-desktop.png" width="360" alt="统计电脑端"> | <img src="https://i.ibb.co/ymhZSRZ7/03-mobile.png" width="195" alt="统计移动端"> |
| 资产 | <img src="https://i.ibb.co/wGnFJsz/04-desktop.png" width="360" alt="资产电脑端"> | <img src="https://i.ibb.co/0yW7mVxx/04-mobile.png" width="195" alt="资产移动端"> |
| 记账弹层 | <img src="https://i.ibb.co/hFBTwRY3/05-desktop.png" width="360" alt="记账弹层电脑端"> | <img src="https://i.ibb.co/gbfBG3St/05-mobile.png" width="195" alt="记账弹层移动端"> |
| 设置 | <img src="https://i.ibb.co/WN9sPD8n/06-desktop.png" width="360" alt="设置电脑端"> | <img src="https://i.ibb.co/0pwBBdMM/06-mobile.png" width="195" alt="设置移动端"> |

## PHP 部署

1. 将本目录中的程序文件上传到 PHP 网站目录。
2. 编辑 `config.php`，填写 ezBookKeeping 地址：

```php
'base_url' => 'https://ezbookkeeping.example.com',
```

3. 确保 `runtime/`（或 `EBK_STORAGE_DIR` 指向的目录）对 PHP 进程可读写。
4. Apache 可直接使用项目中的 `.htaccess`。使用 Nginx 时请禁止访问私有目录和配置文件：

```nginx
location ~ ^/(cache|data|runtime)/ { deny all; }
location ~ ^/(config|bootstrap)\.php$ { deny all; }
```

首次打开时，请先填写你自己的 ezBookKeeping 地址，再使用账号登录。

## 更新流程

适用于 PHP 部署。更新前先备份私有数据，再替换程序文件：

1. 备份 `runtime/`（如果设置了 `EBK_STORAGE_DIR`，备份该目录）以及当前的 `config.php`。
2. 从 GitHub 下载新版本文件，将新版本程序文件上传并覆盖旧版本。
3. 保留现有的 `config.php` 和 `runtime/`（或 `EBK_STORAGE_DIR` 指向的目录），不要用公开仓库中的空配置或示例数据覆盖它们。
4. 确认 PHP 进程对运行数据目录具有读写权限，然后重新打开网站。
5. 登录后检查首页、流水、统计、资产、预算和设置；确认正常后再删除旧备份。

更新只替换程序代码，账本数据仍保存在 ezBookKeeping 中，预算、AI 配置和分类映射保存在运行数据目录中。

## Docker 部署

```bash
docker run -d \
  --name ezbookdash \
  --restart unless-stopped \
  -p 8088:80 \
  -e EBK_BASE_URL=http://ezbookkeeping:8080 \
  -e EBK_TIMEZONE=Asia/Shanghai \
  -v ezbookdash-runtime:/var/www/html/runtime \
  qyccode/ezbookdash:latest
```

如果使用 Docker Compose，可以使用以下最小配置：

```yaml
services:
  ezbookdash:
    image: qyccode/ezbookdash:latest
    restart: unless-stopped
    ports:
      - "8088:80"
    environment:
      EBK_BASE_URL: "http://ezbookkeeping:8080"
      EBK_TIMEZONE: "Asia/Shanghai"
    volumes:
      - ezbookdash-runtime:/var/www/html/runtime

volumes:
  ezbookdash-runtime:
```

将 `EBK_BASE_URL` 改成你的 ezBookKeeping 地址。没有填写时，登录页会提示你完成配置。

## 数据与安全

- `runtime/` 保存缓存、Session、预算和 AI 配置，必须持久化挂载，不能使用临时容器目录。
- 不要将 API Token、密码或 AI Key 写入公开仓库。
- 生产环境保持 HTTPS 和 `ssl_verify` 开启。
- `config.php`、`bootstrap.php` 和 `runtime/` 不应通过 Web 直接访问；如果服务器上还保留旧版根目录 `cache/`、`data/`，也应继续禁止访问。

## Docker 镜像

Docker Hub：[`qyccode/ezbookdash:latest`](https://hub.docker.com/r/qyccode/ezbookdash)
