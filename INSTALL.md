# phpPgAdmin Installation Guide

This guide provides step-by-step instructions for installing and configuring phpPgAdmin.

## Prerequisites

Before installing phpPgAdmin, ensure you have:

- **PHP 7.4 or higher** (8.3+ recommended)
- **PostgreSQL 9.0 or higher** (12+ recommended)
- **PHP Extensions:**
    - `ext-pgsql` - PostgreSQL database functions
    - `ext-mbstring` - Multibyte string handling
    - `ext-sodium` - Encryption for password storage
- **Web Server** - Apache, Nginx, or any PHP-capable server
- **Composer** - For dependency management
- **Git** (optional, for cloning from GitHub)

## Step 1: Download and Unpack phpPgAdmin

### Option A: From GitHub (Recommended)

```bash
git clone https://github.com/phppgadmin/phppgadmin.git
cd phppgadmin
```

### Option B: Download Archive

Choose your preferred archive format:

#### tar.gz (Linux/Mac/Unix)

```bash
wget https://github.com/phppgadmin/phppgadmin/archive/refs/heads/master.tar.gz
gunzip master.tar.gz
tar -xvf master.tar
cd phppgadmin-master
```

#### tar.bz2 (Linux/Mac/Unix)

```bash
wget https://github.com/phppgadmin/phppgadmin/archive/refs/heads/master.tar.bz2
bunzip2 master.tar.bz2
tar -xvf master.tar
cd phppgadmin-master
```

#### ZIP (Windows/All Platforms)

```bash
# Download from GitHub, then:
unzip master.zip
cd phppgadmin-master
```

## Step 2: Configure phpPgAdmin

### Create Configuration File

Copy the distribution configuration file:

```bash
cp conf/config-dist.inc.php conf/config.inc.php
```

### Edit Configuration

Open `conf/config.inc.php` in your editor and configure your PostgreSQL server(s):

```php
// Server 0 - Local PostgreSQL
$conf['servers'][0]['desc'] = 'PostgreSQL';
$conf['servers'][0]['host'] = 'localhost';    // '' for Unix domain socket
$conf['servers'][0]['port'] = 5432;
$conf['servers'][0]['sslmode'] = 'allow';     // disable, allow, prefer, require
$conf['servers'][0]['defaultdb'] = 'postgres';
```

#### Multiple Server Example

```php
// Server 1 - Production
$conf['servers'][1]['desc'] = 'Production Database';
$conf['servers'][1]['host'] = 'prod-db.example.com';
$conf['servers'][1]['port'] = 5432;
$conf['servers'][1]['sslmode'] = 'require';

// Server 2 - Development
$conf['servers'][2]['desc'] = 'Development Database';
$conf['servers'][2]['host'] = 'dev-db.example.com';
$conf['servers'][2]['port'] = 5432;
```

#### Server Groups (Optional)

Organize servers into groups:

```php
$conf['srv_groups'][0]['desc'] = 'Production Servers';
$conf['srv_groups'][0]['servers'] = '0,1';

$conf['srv_groups'][1]['desc'] = 'Development Servers';
$conf['srv_groups'][1]['servers'] = '2';
```

#### Authentication Configuration

By default, phpPgAdmin uses cookie-based authentication. For other options:

**HTTP Basic Authentication:**

```php
$conf['servers'][0]['auth_type'] = 'http';
```

**Config-based Authentication (encrypted credentials):**

```php
// Generate encryption key:
// php bin/encrypt-password.php --generate-key

$conf['encryption_key'] = '0123456789abcdef...'; // 64-char hex key

$conf['servers'][0]['auth_type'] = 'config';
$conf['servers'][0]['username'] = 'myuser';
$conf['servers'][0]['password'] = 'ENCRYPTED:base64string...';
```

See [README.md - Configuration](README.md#configuration) for detailed configuration options.

### Reset Configuration

If you mess up the configuration file, you can restore it from the distribution file:

```bash
cp conf/config-dist.inc.php conf/config.inc.php
```

## Step 4: Configure PostgreSQL Statistics Collector

phpPgAdmin displays table, index, and query performance statistics when enabled.

Enable statistics collector in `postgresql.conf`:

```ini
track_activities = on
track_counts = on
track_io_timing = on
track_functions = 'all'     # optional: tracks function execution times
```

The PostgreSQL statistics collector is usually enabled by default. Restart PostgreSQL:

```bash
# Linux/Unix
sudo systemctl restart postgresql
# or
sudo service postgresql restart

# Mac (Homebrew)
brew services restart postgresql

# Windows (from command line)
net stop "PostgreSQL (version)"
net start "PostgreSQL (version)"
```

## Step 5: Configure PostgreSQL Authentication

### IMPORTANT - Security

PostgreSQL by default does not require passwords for local connections. This is a significant security risk.

**We STRONGLY recommend:**

1. **Enable password authentication** in `pg_hba.conf`
2. **Set a password** for the default superuser account

### Configure pg_hba.conf

Edit your PostgreSQL `pg_hba.conf` file (location depends on your PostgreSQL installation):

- **Linux:** `/var/lib/pgsql/data/pg_hba.conf`
- **Mac (Homebrew):** `/usr/local/var/postgres/pg_hba.conf`
- **Windows:** `C:\Program Files\PostgreSQL\<version>\data\pg_hba.conf`

Change local connections to use `scram-sha-256` (or `md5` for PostgreSQL <10):

```
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             all                                     scram-sha-256
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
```

### Set Superuser Password

```bash
psql -U postgres -c "ALTER USER postgres WITH PASSWORD 'strong-password-here';"
```

Or in the PostgreSQL shell:

```sql
ALTER USER postgres WITH PASSWORD 'strong-password-here';
```

### Restart PostgreSQL

After changes to `pg_hba.conf`, restart PostgreSQL:

```bash
sudo systemctl restart postgresql
```

## Step 6: Configure Extra Login Security (Optional)

By default, phpPgAdmin has `extra_login_security` enabled, which prevents logging in as:

- `root`
- `administrator`
- `pgsql`
- `postgres`

And blocks logins with empty passwords.

**Do NOT disable this unless you have properly secured PostgreSQL with passwords.**

To disable (only after securing PostgreSQL):

```php
$conf['extra_login_security'] = false;
```

## Step 7: Optional - Configure PostgreSQL Dump Utilities

By default, phpPgAdmin automatically searches the system PATH for the `pg_dump` and `pg_dumpall` utilities. **Configuration is only necessary if:**

- The utilities are not in your system PATH
- You have multiple PostgreSQL installations
- Auto-detection fails

If needed, manually specify the path to these utilities:

```php
$conf['servers'][0]['pg_dump_path'] = '/usr/bin/pg_dump';
$conf['servers'][0]['pg_dumpall_path'] = '/usr/bin/pg_dumpall';
```

On Windows:

```php
$conf['servers'][0]['pg_dump_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
$conf['servers'][0]['pg_dumpall_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dumpall.exe';
```

Common paths:

- **Linux:** `/usr/bin/pg_dump` (standard install) or `/usr/lib/postgresql/<version>/bin/pg_dump`
- **Mac (Homebrew):** `/usr/local/bin/pg_dump`
- **Windows:** `C:\Program Files\PostgreSQL\<version>\bin\pg_dump.exe`

## Step 8: Web Server Configuration

### Apache

If using a subdirectory (recommended):

```apache
<Directory /var/www/html/phppgadmin>
    Require all granted

    # Prevent access to config files
    <FilesMatch "\.(inc|conf|dist)$">
        Deny from all
    </FilesMatch>

    # Enable .htaccess overrides if needed
    AllowOverride All
</Directory>
```

### Nginx

```nginx
location /phppgadmin/ {
    root /var/www/html;
    index index.php;

    # Deny access to config files
    location ~ \.(inc|conf|dist)$ {
        deny all;
    }

    # Forward PHP requests
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### IIS (Windows)

1. Create a new Application in IIS
2. Point the physical path to phpPgAdmin directory
3. Set Application Pool to use PHP handler
4. Add HTTP redirect rule to block `*.inc`, `*.conf`, `*.dist` files

## Step 9: Access phpPgAdmin

Open your web browser and navigate to:

```
http://localhost/phppgadmin/
```

Or:

```
http://your-domain.com/phppgadmin/
```

### First Login

1. Select your configured server from the server list
2. Enter your PostgreSQL username and password
3. Select a database (or use the default)
4. Click "Login"

## Troubleshooting

### PHP Extension Errors

**Error:** "PHP does not have PostgreSQL support"

**Solution:** Ensure `ext-pgsql` is installed and enabled:

```bash
# Check installed extensions
php -m | grep pgsql

# Linux - install if missing
sudo apt install php-pgsql      # Debian/Ubuntu
sudo yum install php-pgsql      # CentOS/RHEL
sudo dnf install php-pgsql      # Fedora

# Mac - using Homebrew
brew install php@8.2
# Then enable in php.ini
```

### Session Directory Error

**Error:** "Warning: session*start(): open(/tmp/sess*..., O_RDWR) failed"

**Solution:** Create and configure session directory:

```bash
mkdir -p /var/lib/phppgadmin/sessions
chmod 700 /var/lib/phppgadmin/sessions
chown www-data:www-data /var/lib/phppgadmin/sessions
```

Then in `conf/config.inc.php`:

```php
session_save_path('/var/lib/phppgadmin/sessions');
```

### Login Failed

**Common causes:**

1. **PostgreSQL not accepting connections** - Check `pg_hba.conf`
2. **Wrong credentials** - Verify username and password
3. **PostgreSQL not running** - Start the PostgreSQL service
4. **Network connectivity** - Check firewall rules
5. **Password not set** - Set superuser password (see Step 5)

**Debug steps:**

1. Check PostgreSQL logs
2. Test connection with `psql`:
    ```bash
    psql -h localhost -U postgres -c "SELECT version();"
    ```
3. Check web server error logs
4. Enable debug mode in phpPgAdmin (see README.md)

### Statistics Not Showing

If the Info page has no statistics:

1. Verify `track_activities` and `track_counts` are enabled in `postgresql.conf`
2. Restart PostgreSQL
3. Wait a few minutes for statistics to accumulate

### Configuration File Issues

Restore default configuration:

```bash
cp conf/config-dist.inc.php conf/config.inc.php
```

Then edit with correct settings.

## Upgrading phpPgAdmin

### From Previous Version

1. **Backup current installation:**

    ```bash
    cp -r phppgadmin phppgadmin.backup
    ```

2. **Download new version**

3. **Copy new files:**

    ```bash
    cp -r new-phppgadmin/* phppgadmin/
    ```

4. **Preserve configuration:**

    ```bash
    cp phppgadmin.backup/conf/config.inc.php phppgadmin/conf/
    ```

5. **Reinstall dependencies:**

    ```bash
    cd phppgadmin
    composer install --no-dev --optimize-autoloader
    ```

6. **Review HISTORY file** for breaking changes or new configuration options

## Next Steps

- Read [README.md](README.md) for feature overview and usage guide
- Check [FAQ.md](FAQ.md) for common questions
- Review [Configuration section](README.md#configuration) in README for advanced settings
- See [Development section](README.md#development) if you want to contribute

## Getting Help

- **GitHub Issues:** https://github.com/phppgadmin/phppgadmin/issues
- **PostgreSQL Docs:** https://www.postgresql.org/docs/
- **PHP Docs:** https://www.php.net/manual/

When requesting help, provide:

- phpPgAdmin version
- PostgreSQL version
- PHP version (`php -v`)
- Web server logs
- PostgreSQL logs
- Steps to reproduce the issue
