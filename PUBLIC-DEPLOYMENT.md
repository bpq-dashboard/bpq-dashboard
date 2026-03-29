# Public Internet Deployment Guide

**For exposing BPQ Dashboard on the public internet**

---

## Overview

By default, BPQ Dashboard runs in `local` mode with full features enabled. For public internet access, use `public` mode which automatically:

- Disables write operations (send/delete messages)
- Disables BBS posting from weather dashboard
- Enables rate limiting (30 requests/minute)
- Restricts CORS to specified origins

## Quick Setup

1. **Enable HTTPS** (required for public deployment)

2. **Edit config.php:**
   ```php
   'security_mode' => 'public',
   
   'cors' => [
       'allow_all' => false,
       'allowed_origins' => ['https://yourdomain.com'],
   ],
   ```

3. **Optional: Add HTTP Basic Auth**

## Detailed Steps

### 1. Enable HTTPS with Let's Encrypt

```bash
# Install certbot
sudo apt install certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d bpq.yourdomain.com

# Auto-renewal is configured automatically
```

### 2. Configure Public Mode

Edit `/var/www/html/bpq/config.php`:

```php
return [
    // Enable public mode
    'security_mode' => 'public',
    
    // Restrict CORS
    'cors' => [
        'allow_all' => false,
        'allowed_origins' => [
            'https://bpq.yourdomain.com',
        ],
    ],
    
    // Adjust rate limits if needed
    'rate_limit' => [
        'enabled' => true,  // Auto-enabled in public mode anyway
        'requests_per_minute' => 30,
        'burst_limit' => 10,
    ],
    
    // ... rest of config
];
```

### 3. Add HTTP Basic Authentication (Recommended)

**Apache:**

```bash
# Create password file
sudo htpasswd -c /etc/apache2/.htpasswd admin

# Edit Apache config or .htaccess
sudo nano /var/www/html/bpq/.htaccess
```

Add to `.htaccess`:
```apache
AuthType Basic
AuthName "BPQ Dashboard"
AuthUserFile /etc/apache2/.htpasswd
Require valid-user
```

**Nginx:**

```bash
# Create password file
sudo htpasswd -c /etc/nginx/.htpasswd admin

# Edit nginx config
sudo nano /etc/nginx/sites-available/bpq
```

Add inside `location /`:
```nginx
auth_basic "BPQ Dashboard";
auth_basic_user_file /etc/nginx/.htpasswd;
```

### 4. Apache Security Headers

Create `/var/www/html/bpq/.htaccess`:

```apache
# Security headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Block access to sensitive files
<FilesMatch "\.(php\.example|log|bak|config)$">
    Require all denied
</FilesMatch>

# Protect config.php (allow PHP execution but not direct download)
<Files "config.php">
    Require all denied
</Files>
```

### 5. Firewall Configuration

```bash
# Allow only HTTPS
sudo ufw allow 443/tcp
sudo ufw deny 80/tcp  # Or redirect to HTTPS
sudo ufw enable
```

### 6. Fail2ban (Brute Force Protection)

```bash
sudo apt install fail2ban

# Create custom jail
sudo nano /etc/fail2ban/jail.local
```

Add:
```ini
[apache-auth]
enabled = true
port = http,https
filter = apache-auth
logpath = /var/log/apache2/error.log
maxretry = 5
bantime = 3600
```

## What Public Mode Disables

| Feature | Local Mode | Public Mode |
|---------|------------|-------------|
| View messages | ✅ | ✅ |
| Read message body | ✅ | ✅ |
| Send messages | ✅ | ❌ |
| Delete messages | ✅ | ❌ |
| View bulletins | ✅ | ✅ |
| View weather alerts | ✅ | ✅ |
| Post to BBS | ✅ | ❌ |
| Test endpoint | Optional | ❌ |
| Rate limiting | Optional | ✅ Enforced |

## Security Checklist

- [ ] HTTPS enabled with valid certificate
- [ ] `security_mode` set to `public`
- [ ] CORS restricted to your domain
- [ ] Password changed from default
- [ ] HTTP Basic Auth enabled (recommended)
- [ ] Firewall configured
- [ ] Fail2ban installed
- [ ] `.htaccess` security rules in place
- [ ] Config file permissions: `chmod 640 config.php`

## Testing

```bash
# Verify public mode
curl https://yourdomain.com/bpq/api/config.php
# Should show: "mode": "public"

# Test rate limiting (make 35 rapid requests)
for i in {1..35}; do curl -s https://yourdomain.com/bpq/api/config.php; done
# Should get "Rate limit exceeded" after 30

# Test write block
curl -X POST https://yourdomain.com/bpq/bbs-messages.php \
     -H "Content-Type: application/json" \
     -d '{"action":"send","to":"TEST","subject":"Test","body":"Test"}'
# Should return: "error": "Write operations are disabled"
```

## Sharing with Other Operators

If you want to distribute BPQ Dashboard to other amateur radio operators:

1. **They get the full package** - same files work for local or public
2. **Default is local mode** - safe for home networks out of the box
3. **They just edit config.php** - one file to customize
4. **Public mode is opt-in** - they change one setting if needed

The configuration system is designed so operators don't need to understand security to use the dashboard safely on their home network, but advanced users can easily enable public mode with proper security.
