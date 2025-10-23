# phpPgAdmin Frequently Asked Questions

## Installation Errors

### Q: I've installed phpPgAdmin but when I try to use it I get an error message telling me that I have not compiled proper database support into my PHP installation.

**A:** This means that PostgreSQL support is not enabled in your PHP installation. PostgreSQL support can be provided either by compiling PHP with `--with-pgsql` or by loading the `pgsql` extension dynamically.

Modern distributions ship PHP extensions as separate packages. Install the required extensions:

- **Debian / Ubuntu:**
    ```bash
    sudo apt install php-pgsql php-mbstring php-sodium
    ```
- **Fedora / Rocky / Alma / CentOS 8+:**
    ```bash
    sudo dnf install php-pgsql php-mbstring php-sodium
    ```
- **Older CentOS / RHEL 7:**
    ```bash
    sudo yum install php-pgsql php-mbstring php-sodium
    ```

**phpPgAdmin requires the `pgsql`, `mbstring`, and `sodium` extensions.**

If you install extensions manually, edit the loaded `php.ini` and uncomment the relevant lines:

```ini
;extension=php_pgsql.dll    ; Windows
;extension=pgsql.so         ; Linux
```

So it looks like:

```ini
extension=php_pgsql.dll     ; Windows
extension=pgsql.so          ; Linux
```

To find the loaded `php.ini`:

```bash
php -i | grep "Loaded Configuration"
```

See the [PHP PostgreSQL setup documentation](https://www.php.net/manual/en/pgsql.setup.php) for more information.

---

### Q: I get a warning like this when using phpPgAdmin on Windows:

```
Warning: session_start() [function.session-start]:
  open(/tmp\sess_5a401ef1e67fb7a176a95236116fe348, O_RDWR) failed
```

**A:** You need to edit your `php.ini` file and change this line:

```ini
session.save_path = "/tmp"
```

To a directory that exists and is writable by the web server, for example:

```ini
session.save_path = "C:\php\temp"
```

Make sure the folder actually exists.

Alternatively, configure phpPgAdmin to use its own session path. In `config.inc.php`:

```php
$conf['session_path'] = sys_get_temp_dir() . '/phppgadmin_sessions';
```

The directory will be created automatically if it does not exist.

---

## Login Errors

### Q: I always get "Login failed" even though I'm _sure_ I'm using the right username and password.

**A:** There are several reasons why you might not be able to connect, typically having nothing to do with phpPgAdmin itself. First, check the PostgreSQL log on your server. It should contain a `FATAL` error message detailing the exact reason why the login is failing.

You will probably need to either:

- Adjust the username or password
- Add LOGIN permissions to the role
- Adjust your `pg_hba.conf` file in your PostgreSQL data directory

Follow the directions laid out in the FATAL error message.

**How to locate `pg_hba.conf`**

The location depends on how PostgreSQL was installed. To find the actual path, use one of the following methods.

**Method 1 — Ask PostgreSQL itself (recommended)**

Connect to the database and run:

```sql
SHOW hba_file;
```

Example using `psql`:

```bash
psql -U postgres -c "SHOW hba_file;"
```

**Method 2 — Search the filesystem**

Linux / macOS:

```bash
find / -name pg_hba.conf 2>/dev/null
```

Windows (PowerShell):

```powershell
Get-ChildItem -Path C:\ -Filter pg_hba.conf -Recurse -ErrorAction SilentlyContinue
```

Windows (CMD):

```cmd
dir C:\pg_hba.conf /s /b 2>nul
```

**Method 3 — Check the PostgreSQL data directory**

`pg_hba.conf` always lives in the PostgreSQL data directory. To find that directory:

```sql
SHOW data_directory;
```

Or check the service / process:

- Linux: `ps aux | grep postgres` or `systemctl status postgresql`
- macOS (Homebrew): `brew services info postgresql`
- Windows: `Get-Process postgres | Select-Object Path`

If you do not have any FATAL error messages and you have verified that you are looking at the properly configured log file, then this means you are not connecting to your database.

**If connecting via TCP/IP sockets** (for example, if phpPgAdmin is installed on a different computer than your database):

Make sure PostgreSQL is accepting connections over TCP/IP. In `postgresql.conf`:

```
listen_addresses = '*'    # or specific IP addresses
```

**Important:** Be sure to restart PostgreSQL after changing this setting.

If that still doesn't get you connected, there is likely something interfering between PHP and PostgreSQL:

- Check for firewalls preventing connectivity
- Check for security policies (e.g., SELinux) that prevent PHP from connecting
- Verify network connectivity between the web server and database server

---

### Q: For some users I get a "Login disallowed for security" message.

**A:** Logins via phpPgAdmin with no password or certain usernames (`pgsql`, `postgres`, `root`, `administrator`) are denied by default for security reasons.

Before changing this behavior (setting `$conf['extra_login_security']` to `false` in `config.inc.php`), please read the [PostgreSQL documentation about client authentication](https://www.postgresql.org/docs/current/client-authentication.html) and understand how to change PostgreSQL's `pg_hba.conf` to enable password-protected local connections.

---

### Q: I can use any password to log in!

**A:** PostgreSQL may run in "trust" mode for local connections by default. This means that it doesn't ask for passwords. We highly recommend that you:

1. Edit your `pg_hba.conf` file
2. Change the login type to `scram-sha-256` (PostgreSQL 10+) or `md5` (older versions)

**Note:** If you change the `local` login type to require passwords, you might need to enter a password to start PostgreSQL. Work around this by using a `.pgpass` file — see the [PostgreSQL documentation](https://www.postgresql.org/docs/current/libpq-pgpass.html) for details.

---

## Other Errors

### Q: When I enter non-ASCII data into the database via a form, it's inserted as hexadecimal or `&#1234;` format!

**A:** You have not created your database in the correct encoding. This problem will occur when you try to enter:

- An umlaut into an `SQL_ASCII` database
- SJIS Japanese into an `EUC-JP` database
- Any character outside the database's encoding

Solution: Recreate your database with the correct encoding:

```sql
CREATE DATABASE mydb ENCODING 'UTF8' LOCALE 'en_US.utf8';
```

---

### Q: When I drop and re-create a table with the same name, it fails.

**A:** You need to drop the sequence attached to the `SERIAL` column of the table as well.

Modern PostgreSQL versions handle this automatically. If you have upgraded from a very old version, you may need to check your dependency records.

---

### Q: When browsing a table, the 'edit' and 'delete' links do not appear.

**A:** In order of preference, phpPgAdmin uses the following as unique row identifiers:

1. **Primary Keys** (preferred)
2. **Unique Keys** (cannot be partial or expressional indexes)
3. **OID Column** (requires a sequential scan to update, unless you index the OID column)

Additionally:

- Any `NULL` values in the unique index will make that row uneditable
- Since OIDs can become duplicated in a table, phpPgAdmin will alter the row and check that exactly one row has been modified — otherwise it will rollback

To ensure rows can be edited, make sure your tables have:

- A primary key, OR
- A unique constraint with no `NULL` values

---

## Questions on Dumps

### Q: What happened to the database dump feature?

**A:** phpPgAdmin can either use its internal PHP dumper or the external `pg_dump` / `pg_dumpall` utilities.

- **Internal dumper:** Works without any external tools, but may not cover every PostgreSQL feature.
- **External `pg_dump`:** More complete, but requires the binaries to be available.

By default, phpPgAdmin attempts to auto-detect `pg_dump` and `pg_dumpall`. You can also specify their paths explicitly in `config.inc.php`:

```php
$conf['servers'][0]['pg_dump_path'] = '/usr/bin/pg_dump';
$conf['servers'][0]['pg_dumpall_path'] = '/usr/bin/pg_dumpall';
```

---

### Q: I would like to use the pg_dump integration for database and table dumps on Windows. How do I get pg_dump.exe on Windows?

**A:** To get the `pg_dump` utilities on Windows:

1. Install **PostgreSQL** for Windows (we recommend the latest release)
    - Download from the [PostgreSQL website](https://www.postgresql.org/download/windows)

2. Set the `pg_dump` and `pg_dumpall` locations in `config.inc.php`:

    ```php
    $conf['servers'][0]['pg_dump_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
    $conf['servers'][0]['pg_dumpall_path'] = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dumpall.exe';
    ```

    Adjust the version number (18) and path as appropriate for your installation.

---

### Q: Why can't I reload the SQL script I dumped in the SQL window?

**A:** The following limitations currently exist in SQL script execution:

- **COPY commands:** Supported via uploaded SQL scripts
- **psql commands:** Commands such as `\connect` will not work
- **Multiline statements:** Statements split across multiple lines may not work in some contexts
- **Database/user switching:** You cannot change the current database or current user during script execution

For these limitations, we recommend using the `psql` utility to restore your full SQL dumps:

```bash
psql -U username -d database_name -f dump.sql
```

---

## Other Questions

### Q: When inserting a row, what does the 'Value' or 'Expression' box mean?

**A:** There are two options for entering data:

- **'Expression':** You can use functions, operators, field names, etc. in your value
    - You must properly quote any literal values yourself
    - Example: `current_timestamp`, `table2.column_name`, `UPPER('text')`

- **'Value':** The data is inserted as-is into the database
    - No interpretation occurs
    - Example: The string `123` is inserted as text, not a number

Choose 'Expression' for database functions and references, 'Value' for literal data.

---

### Q: Why is there never any information on the 'Info' page of a table?

**A:** The Info page displays:

- Tables that have foreign keys to the current table
- Data from the PostgreSQL statistics collector

If the statistics collector is not enabled, enable it in `postgresql.conf`:

```
track_activities = on
track_counts = on
track_io_timing = on
track_functions = 'all'
```

Then restart PostgreSQL.

---

### Q: Why can't I download data from queries executed in the SQL window?

**A:** You need to check the **'Paginate results'** option to allow downloads.

When 'Paginate results' is enabled, phpPgAdmin will provide download options for query results in various formats (SQL, CSV, JSON, etc.).

---

### Q: The time shown is UTC. How do I change it to my timezone?

**A:** phpPgAdmin uses PHP's `date.timezone` setting, which comes from the `php.ini` file loaded by the PHP SAPI that runs the web application (usually PHP-FPM or Apache, not the CLI).

**Step 1 — Find the loaded php.ini**

```bash
php -i | grep "Loaded Configuration"
```

On Windows (PowerShell):

```powershell
php -i | Select-String "Loaded Configuration"
```

The output shows the exact `php.ini` path, for example:

```
Loaded Configuration File => /etc/php/8.5/fpm/php.ini
```

**Note:** If phpPgAdmin runs under PHP-FPM or Apache, the `php.ini` used by the web server may be different from the CLI one. To see the one the web server actually uses, create a temporary file containing:

```php
<?php phpinfo();
```

Access it in the browser and look for **Loaded Configuration File**.

**Step 2 — Set the timezone**

Edit the `php.ini` found above and set:

```ini
date.timezone = Your/Timezone
```

Replace `Your/Timezone` with a valid identifier from the [PHP Supported Timezones](https://www.php.net/manual/en/timezones.asia.php) list, for example `UTC`, `Europe/Berlin`, `America/New_York`, `Asia/Tokyo`, etc.

**Step 3 — Restart the PHP service**

Restart the PHP SAPI that serves phpPgAdmin (for example PHP-FPM, Apache, or IIS).

**Step 4 — Verify**

```bash
php -i | grep "date.timezone"
```

or reload the `phpinfo()` page and check the `date.timezone` value.

**Note:** In an international deployment, keeping the default `UTC` and letting each user's browser convert the displayed time is often preferable to hard-coding a single timezone.

---

### Q: How do I use encrypted credentials or automatic login?

**A:** phpPgAdmin supports three authentication modes per server:

- **`form`** — Standard login form (default)
- **`http`** — HTTP Basic Authentication
- **`config`** — Credentials stored in `config.inc.php`, optionally encrypted

For `config` auth, first generate an encryption key:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Or:

```bash
php bin/encrypt-password.php --generate-key
```

Store the key in the environment variable `PHPPGADMIN_ENCRYPTION_KEY` (recommended) or in `config.inc.php`:

```php
$conf['encryption_key'] = 'your_generated_key_here';
```

Then set up the server:

```php
$conf['servers'][0]['auth_type'] = 'config';
$conf['servers'][0]['username'] = 'myuser';
$conf['servers'][0]['password'] = 'ENCRYPTED:base64encodedencryptedpassword';
```

Generate the encrypted password with:

```bash
php bin/encrypt-password.php --password "your_password"
```

**Important:** If the encryption key changes, all sessions are invalidated and users must re-authenticate.

---

### Q: How do I contribute to phpPgAdmin?

**A:** We really would like your help! Please read the following files:

- [DEVELOPERS](DEVELOPERS) — Development guidelines, git workflow, and coding standards
- [TRANSLATORS](TRANSLATORS) — How to contribute translations
- [README.md](README.md) — Contributing section with more details

**Quick start:**

1. Fork the repository on [GitHub](https://github.com/pgadminpanel/phppgadmin)
2. Read [DEVELOPERS](DEVELOPERS) for the development workflow
3. Make your changes on a feature branch
4. Submit a Pull Request with a clear description

We welcome contributions in many areas:

- Bug fixes
- New features
- Documentation improvements
- Translations
- Theme designs
- Test cases

See [README.md - Contributing](README.md#contributing) for detailed guidelines.

---

## Need More Help?

If your question isn't answered here:

1. Check the [PostgreSQL documentation](https://www.postgresql.org/docs/)
2. Review the [README.md](README.md)
3. Search [GitHub Issues](https://github.com/pgadminpanel/phppgadmin/issues)
4. Check the PostgreSQL and PHP server logs for error messages
5. Create a [new issue](https://github.com/pgadminpanel/phppgadmin/issues/new) with detailed information

When reporting issues, please include:

- phpPgAdmin version
- PostgreSQL version
- PHP version
- Detailed error messages
- Steps to reproduce the problem