# ezBookDash

基于 ezBookKeeping API 的自托管记账客户端，包含流水、统计、资产、预算与 AI 辅助记账。

## 运行要求

- PHP 8.1+
- PHP 扩展：`curl`、`json`、`mbstring`
- 可访问 ezBookKeeping 服务
- Web 服务建议使用 HTTPS；仅可信内网测试可使用 HTTP

## PHP 部署

1. 将本目录中的程序文件上传到 PHP 网站目录。
2. 编辑 `config.php`，填写 ezBookKeeping 地址：

```php
'base_url' => 'https://ezbookkeeping.example.com',
```

也可以通过环境变量配置：

```text
EBK_BASE_URL=https://ezbookkeeping.example.com
EBK_TIMEZONE=Asia/Shanghai
EBK_STORAGE_DIR=/var/lib/ezbookdash
```

3. 确保 `runtime/`（或 `EBK_STORAGE_DIR` 指向的目录）对 PHP 进程可读写。
4. Apache 可直接使用项目中的 `.htaccess`。使用 Nginx 时请禁止访问私有目录和配置文件：

```nginx
location ~ ^/(cache|data|runtime)/ { deny all; }
location ~ ^/(config|bootstrap)\.php$ { deny all; }
```

公开版默认不会连接任何 ezBookKeeping 地址。未填写地址时，登录页会提示先配置 `EBK_BASE_URL` 或 `config.php`。

## Docker 部署

无需下载 Docker 源码，直接使用 Docker Hub 镜像 `qyccode/ezbookdash:latest`：

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

将 `EBK_BASE_URL` 改成你的 ezBookKeeping 地址。公开版默认地址为空，不会自动连接官方演示站。

## 数据与安全

- `runtime/` 保存缓存、Session、预算和 AI 配置，必须持久化挂载，不能使用临时容器目录。
- 不要将 API Token、密码或 AI Key 写入公开仓库。
- 生产环境保持 HTTPS 和 `ssl_verify` 开启。
- `config.php`、`bootstrap.php`、`cache/`、`data/`、`runtime/` 不应通过 Web 直接访问。

## Docker 镜像

Docker Hub：[`qyccode/ezbookdash:latest`](https://hub.docker.com/r/qyccode/ezbookdash)
