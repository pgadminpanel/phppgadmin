# phpPgAdmin 常见问题解答

## 安装错误

### Q: 我安装了 phpPgAdmin，但使用时收到错误信息，提示我的 PHP 安装没有编译正确的数据库支持。

**A:** 这说明你的 PHP 没有启用 PostgreSQL 支持。PostgreSQL 支持可以通过编译 PHP 时加 `--with-pgsql` 启用，也可以动态加载 `pgsql` 扩展。

现代发行版把 PHP 扩展作为独立软件包提供。安装所需扩展：

- **Debian / Ubuntu：**
    ```bash
    sudo apt install php-pgsql php-mbstring php-sodium
    ```
- **Fedora / Rocky / Alma / CentOS 8+：**
    ```bash
    sudo dnf install php-pgsql php-mbstring php-sodium
    ```
- **较旧的 CentOS / RHEL 7：**
    ```bash
    sudo yum install php-pgsql php-mbstring php-sodium
    ```

**phpPgAdmin 需要 `pgsql`、`mbstring` 和 `sodium` 扩展。**

如果你手动安装扩展，编辑已加载的 `php.ini`，取消相关行的注释：

```ini
;extension=php_pgsql.dll    ; Windows
;extension=pgsql.so         ; Linux
```

改成：

```ini
extension=php_pgsql.dll     ; Windows
extension=pgsql.so          ; Linux
```

查看当前加载的 `php.ini`：

```bash
php -i | grep "Loaded Configuration"
```

更多信息见 [PHP PostgreSQL 安装文档](https://www.php.net/manual/en/pgsql.setup.php)。

---

### Q: 在 Windows 上使用 phpPgAdmin 时，我收到这样的警告：

```
Warning: session_start() [function.session-start]:
  open(/tmp\sess_5a401ef1e67fb7a176a95236116fe348, O_RDWR) failed
```

**A:** 你需要编辑 `php.ini`，把这一行：

```ini
session.save_path = "/tmp"
```

改成一个存在且 Web 服务器可写的目录，例如：

```ini
session.save_path = "C:\php\temp"
```

确保该目录确实存在。

或者，配置 phpPgAdmin 使用自己的会话目录。在 `config.inc.php` 中：

```php
$conf['session_path'] = sys_get_temp_dir() . '/phppgadmin_sessions';
```

目录不存在时会自动创建。

---

## 登录错误

### Q: 我一直收到 "Login failed"，但我确定用户名和密码是对的。

**A:** 连不上的原因有很多，通常和 phpPgAdmin 本身无关。首先查看服务器上的 PostgreSQL 日志，里面应该有一条 `FATAL` 错误，说明登录失败的具体原因。

你可能需要：

- 调整用户名或密码
- 给角色添加 LOGIN 权限
- 调整 PostgreSQL 数据目录里的 `pg_hba.conf`

按照 FATAL 错误信息里的提示操作。

**如何找到 `pg_hba.conf`**

它的位置取决于 PostgreSQL 的安装方式。要找到实际路径，用下面任意一种方法。

**方法 1 — 直接问 PostgreSQL（推荐）**

连接数据库并执行：

```sql
SHOW hba_file;
```

用 `psql` 的示例：

```bash
psql -U postgres -c "SHOW hba_file;"
```

**方法 2 — 在文件系统里搜索**

Linux / macOS：

```bash
find / -name pg_hba.conf 2>/dev/null
```

Windows (PowerShell)：

```powershell
Get-ChildItem -Path C:\ -Filter pg_hba.conf -Recurse -ErrorAction SilentlyContinue
```

Windows (CMD)：

```cmd
dir C:\pg_hba.conf /s /b 2>nul
```

**方法 3 — 查看 PostgreSQL 数据目录**

`pg_hba.conf` 总是在 PostgreSQL 的数据目录里。查看数据目录：

```sql
SHOW data_directory;
```

或者查看服务 / 进程：

- Linux：`ps aux | grep postgres` 或 `systemctl status postgresql`
- macOS (Homebrew)：`brew services info postgresql`
- Windows：`Get-Process postgres | Select-Object Path`

如果没有 FATAL 错误，而且你确认看的是正确配置的日志文件，那说明你根本没有连到数据库。

**如果通过 TCP/IP 连接**（比如 phpPgAdmin 和数据库不在同一台机器上）：

确保 PostgreSQL 接受 TCP/IP 连接。在 `postgresql.conf` 中：

```
listen_addresses = '*'    # 或指定 IP
```

**重要：** 改完这个设置后一定要重启 PostgreSQL。

如果还是连不上，可能是 PHP 和 PostgreSQL 之间有东西在干扰：

- 检查防火墙是否阻止连接
- 检查安全策略（如 SELinux）是否阻止 PHP 连接
- 验证 Web 服务器和数据库服务器之间的网络连通性

---

### Q: 某些用户会收到 "Login disallowed for security" 消息。

**A:** 出于安全原因，phpPgAdmin 默认禁止空密码登录，也禁止使用某些用户名（`pgsql`、`postgres`、`root`、`administrator`）登录。

在改变这个行为之前（把 `config.inc.php` 里的 `$conf['extra_login_security']` 设为 `false`），请先阅读 [PostgreSQL 客户端认证文档](https://www.postgresql.org/docs/current/client-authentication.html)，并理解如何修改 `pg_hba.conf` 来启用带密码的本地连接。

---

### Q: 我用任意密码都能登录！

**A:** PostgreSQL 本地连接默认可能运行在 "trust" 模式，也就是不要求密码。我们强烈建议你：

1. 编辑 `pg_hba.conf`
2. 把登录方式改成 `scram-sha-256`（PostgreSQL 10+）或 `md5`（更早版本）

**注意：** 如果你把 `local` 登录方式改成需要密码，启动 PostgreSQL 时可能也需要输入密码。可以用 `.pgpass` 文件绕过——详见 [PostgreSQL 文档](https://www.postgresql.org/docs/current/libpq-pgpass.html)。

---

## 其他错误

### Q: 我通过表单输入非 ASCII 数据时，插入的是十六进制或 `&#1234;` 格式！

**A:** 你的数据库没有用正确的编码创建。以下情况会出现这个问题：

- 向 `SQL_ASCII` 数据库插入带变音符号的字符
- 向 `EUC-JP` 数据库插入 SJIS 日文
- 插入任何超出数据库编码范围的字符

解决方法：用正确的编码重建数据库：

```sql
CREATE DATABASE mydb ENCODING 'UTF8' LOCALE 'en_US.utf8';
```

---

### Q: 删除同名表后重新创建，会失败。

**A:** 你还需要删除表上 `SERIAL` 列关联的序列。

现代 PostgreSQL 版本会自动处理。如果你从很旧的版本升级上来，可能需要检查一下依赖记录。

---

### Q: 浏览表时，'编辑' 和 '删除' 链接不显示。

**A:** phpPgAdmin 按以下优先级使用唯一行标识：

1. **主键**（首选）
2. **唯一键**（不能是部分索引或表达式索引）
3. **OID 列**（更新时需要顺序扫描，除非你给 OID 列建了索引）

另外：

- 唯一索引里有任何 `NULL` 值都会让该行无法编辑
- 因为 OID 在表中可能重复，phpPgAdmin 会修改行并检查是否恰好只改了一行——否则回滚

要确保行可编辑，请让表具备：

- 主键，或者
- 没有 `NULL` 值的唯一约束

---

## 关于转储的问题

### Q: 数据库转储功能去哪了？

**A:** phpPgAdmin 可以使用内置的 PHP 转储器，也可以使用外部的 `pg_dump` / `pg_dumpall` 工具。

- **内置转储器：** 不依赖任何外部工具，但可能无法覆盖所有 PostgreSQL 特性。
- **外部 `pg_dump`：** 更完整，但需要可用的二进制文件。

默认情况下，phpPgAdmin 会尝试自动检测 `pg_dump` 和 `pg_dumpall`。你也可以在 `config.inc.php` 里显式指定路径：

```php
$conf['servers'][0]['pg_dump_path'] = '/usr/bin/pg_dump';
$conf['servers'][0]['pg_dumpall_path'] = '/usr/bin/pg_dumpall';
```

---

### Q: 我想在 Windows 上使用 pg_dump 集成来做数据库和表转储。怎么在 Windows 上获得 pg_dump.exe？

**A:** 在 Windows 上获得 `pg_dump` 工具：

1. 安装 **PostgreSQL** for Windows（推荐最新版本）
    - 从 [PostgreSQL 官网](https://www.postgresql.org/download/windows) 下载

2. 在 `config.inc.php` 里设置 `pg_dump` 和 `pg_dumpall` 路径：

    ```php
    $conf['servers'][0]['pg_dump_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
    $conf['servers'][0]['pg_dumpall_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dumpall.exe';
    ```

    根据你的实际安装调整版本号（18）和路径。

---

### Q: 为什么我不能在 SQL 窗口重新载入我转储的 SQL 脚本？

**A:** SQL 脚本执行目前有以下限制：

- **COPY 命令：** 通过上传的 SQL 脚本支持
- **psql 命令：** 像 `\connect` 这样的命令不起作用
- **多行语句：** 跨多行的语句在某些情况下可能不起作用
- **数据库/用户切换：** 脚本执行期间不能切换当前数据库或当前用户

针对这些限制，我们建议使用 `psql` 工具恢复完整的 SQL 转储：

```bash
psql -U username -d database_name -f dump.sql
```

---

## 其他问题

### Q: 插入行时，'Value' 或 'Expression' 框是什么意思？

**A:** 有两种数据输入方式：

- **'Expression'：** 值里可以使用函数、运算符、字段名等
    - 字面值必须自己正确加引号
    - 例如：`current_timestamp`、`table2.column_name`、`UPPER('text')`

- **'Value'：** 数据原样插入数据库
    - 不做任何解释
    - 例如：字符串 `123` 作为文本插入，而不是数字

数据库函数和引用用 'Expression'，字面数据用 'Value'。

---

### Q: 为什么表的 'Info' 页面从来没有信息？

**A:** Info 页面显示：

- 有外键指向当前表的表
- 来自 PostgreSQL 统计收集器的数据

如果统计收集器没启用，在 `postgresql.conf` 中启用：

```
track_activities = on
track_counts = on
track_io_timing = on
track_functions = 'all'
```

然后重启 PostgreSQL。

---

### Q: 为什么我不能下载在 SQL 窗口执行的查询数据？

**A:** 你需要勾选 **'Paginate results'** 选项才能下载。

启用 'Paginate results' 后，phpPgAdmin 会为查询结果提供多种格式的下载选项（SQL、CSV、JSON 等）。

---

### Q: 时间显示的是 UTC，怎么改成我的时区？

**A:** phpPgAdmin 使用 PHP 的 `date.timezone` 设置，它来自运行 Web 应用的 PHP SAPI 加载的 `php.ini` 文件（通常是 PHP-FPM 或 Apache，不是 CLI）。

**第 1 步 — 找到加载的 php.ini**

```bash
php -i | grep "Loaded Configuration"
```

Windows（PowerShell）：

```powershell
php -i | Select-String "Loaded Configuration"
```

输出会显示确切的 `php.ini` 路径，例如：

```
Loaded Configuration File => /etc/php/8.5/fpm/php.ini
```

**注意：** 如果 phpPgAdmin 跑在 PHP-FPM 或 Apache 下，Web 服务器用的 `php.ini` 可能和 CLI 的不一样。要查看 Web 服务器实际用的那个，创建一个临时文件：

```php
<?php phpinfo();
```

在浏览器里访问它，查看 **Loaded Configuration File**。

**第 2 步 — 设置时区**

编辑上面找到的 `php.ini`，设置：

```ini
date.timezone = Your/Timezone
```

把 `Your/Timezone` 换成 [PHP 支持的时区列表](https://www.php.net/manual/zh/timezones.asia.php) 里的有效标识符，例如 `UTC`、`Europe/Berlin`、`America/New_York`、`Asia/Tokyo` 等。

**第 3 步 — 重启 PHP 服务**

重启为 phpPgAdmin 提供服务的 PHP SAPI（例如 PHP-FPM、Apache 或 IIS）。

**第 4 步 — 验证**

```bash
php -i | grep "date.timezone"
```

或者刷新 `phpinfo()` 页面，查看 `date.timezone` 的值。

**注意：** 在国际化部署中，保持默认 `UTC`，让每个用户的浏览器转换显示时间，通常比硬编码单一固定时区更合适。

---

### Q: 如何使用加密凭据或自动登录？

**A:** phpPgAdmin 支持每台服务器三种认证模式：

- **`form`** —— 标准登录表单（默认）
- **`http`** —— HTTP Basic 认证
- **`config`** —— 凭据存储在 `config.inc.php` 中，可选加密

对于 `config` 认证，首先生成加密密钥：

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

或者：

```bash
php bin/encrypt-password.php --generate-key
```

把密钥存到环境变量 `PHPPGADMIN_ENCRYPTION_KEY`（推荐）或 `config.inc.php`：

```php
$conf['encryption_key'] = 'your_generated_key_here';
```

然后设置服务器：

```php
$conf['servers'][0]['auth_type'] = 'config';
$conf['servers'][0]['username'] = 'myuser';
$conf['servers'][0]['password'] = 'ENCRYPTED:base64encodedencryptedpassword';
```

用以下命令生成加密密码：

```bash
php bin/encrypt-password.php --password "your_password"
```

**重要：** 如果加密密钥变了，所有会话都会失效，用户必须重新认证。

---

### Q: 如何为 phpPgAdmin 做贡献？

**A:** 我们非常欢迎你的帮助！请阅读以下文件：

- [DEVELOPERS](DEVELOPERS) —— 开发指南、git 工作流和编码规范
- [TRANSLATORS](TRANSLATORS) —— 如何贡献翻译
- [README.md](README.md) —— 贡献章节，有更多细节

**快速开始：**

1. 在 [GitHub](https://github.com/pgadminpanel/phppgadmin) 上 fork 仓库
2. 阅读 [DEVELOPERS](DEVELOPERS) 了解开发流程
3. 在功能分支上做修改
4. 提交带清晰描述的 Pull Request

我们欢迎多个领域的贡献：

- Bug 修复
- 新功能
- 文档改进
- 翻译
- 主题设计
- 测试用例

详细指南见 [README.md - Contributing](README.md#contributing)。

---

## 需要更多帮助？

如果你的问题这里没解答：

1. 查看 [PostgreSQL 文档](https://www.postgresql.org/docs/)
2. 阅读 [README.md](README.md)
3. 搜索 [GitHub Issues](https://github.com/pgadminpanel/phppgadmin/issues)
4. 检查 PostgreSQL 和 PHP 服务器日志中的错误信息
5. 创建 [新 issue](https://github.com/pgadminpanel/phppgadmin/issues/new)，附上详细信息

报告问题时，请包含：

- phpPgAdmin 版本
- PostgreSQL 版本
- PHP 版本
- 详细错误信息
- 复现步骤