# cPanel Deployment Guide for IT Helpdesk Portal

## Overview
This guide will help you deploy your IT Helpdesk Portal from Supabase (PostgreSQL) to cPanel hosting with MySQL/phpMyAdmin.

## Files Created
1. `mysql_schema.sql` - Complete MySQL database schema
2. `php/config/database.php` - Database configuration and connection class
3. `php/api/auth.php` - Authentication endpoints
4. `php/api/tickets.php` - Ticket management endpoints
5. `php/api/dashboard.php` - Dashboard statistics
6. `php/api/categories.php` - Category management
7. `php/api/users.php` - User management
8. `php/api/faqs.php` - FAQ management

## Step 1: Setup Database in cPanel

### 1.1 Create MySQL Database
1. Log into your cPanel account
2. Go to **MySQL Databases**
3. Create a new database: `your_username_itsm_helpdesk`
4. Create a database user with full privileges
5. Note down the database name, username, and password

### 1.2 Import Database Schema
1. Go to **phpMyAdmin** in cPanel
2. Select your database
3. Click **Import** tab
4. Upload the `mysql_schema.sql` file
5. Click **Go** to execute the import

## Step 2: Configure PHP Backend

### 2.1 Update Database Configuration
Edit `php/config/database.php` and update these values:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_username_itsm_helpdesk'); // Your actual database name
define('DB_USER', 'your_cpanel_username_itsm'); // Your database username  
define('DB_PASS', 'your_database_password'); // Your database password
```

### 2.2 Upload PHP Files
1. Upload the entire `php/` folder to your cPanel **public_html** directory
2. Set proper permissions:
   - Folders: 755
   - PHP files: 644
3. Create an `uploads/` directory with 755 permissions for file attachments

## Step 3: Configure Frontend

### 3.1 Update API Base URL
In your frontend JavaScript/React files, update the API base URL to point to your cPanel domain:
```javascript
const API_BASE_URL = 'https://cybaemtech.com/php/api';
```

### 3.2 Update API Endpoints
Replace all Node.js API calls with PHP equivalents:

**Authentication:**
- POST `/php/api/auth.php?action=login`
- POST `/php/api/auth.php?action=register` 
- POST `/php/api/auth.php?action=logout`
- GET `/php/api/auth.php` (get current user)

**Tickets:**
- GET `/php/api/tickets.php`
- POST `/php/api/tickets.php`
- PUT `/php/api/tickets.php?id={id}`
- DELETE `/php/api/tickets.php?id={id}`

**Dashboard:**
- GET `/php/api/dashboard.php`

**Categories:**
- GET `/php/api/categories.php`
- POST `/php/api/categories.php`
- PUT `/php/api/categories.php?id={id}`
- DELETE `/php/api/categories.php?id={id}`

**Users:**
- GET `/php/api/users.php`
- POST `/php/api/users.php`
- PUT `/php/api/users.php?id={id}`
- DELETE `/php/api/users.php?id={id}`

**FAQs:**
- GET `/php/api/faqs.php`
- POST `/php/api/faqs.php`
- PUT `/php/api/faqs.php?id={id}`
- DELETE `/php/api/faqs.php?id={id}`

## Step 4: Test Deployment

### 4.1 Test Database Connection
Create a test file `test_db.php`:
```php
<?php
require_once 'php/config/database.php';
try {
    $db = getDb();
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM users");
    echo "Database connected successfully. Users count: " . $result['count'];
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage();
}
?>
```

### 4.2 Test API Endpoints
Use browser developer tools or Postman to test:
1. `GET /php/api/auth.php` - Should return 401 (not authenticated)
2. `POST /php/api/auth.php?action=login` with demo credentials
3. `GET /php/api/dashboard.php` - Should return statistics

## Step 5: Security Considerations

### 5.1 Disable PHP Error Display
Add to `.htaccess`:
```apache
php_value display_errors 0
php_value log_errors 1
```

### 5.2 Protect Configuration Files
Add to `.htaccess`:
```apache
<Files "database.php">
    Order Allow,Deny
    Deny from all
</Files>
```

### 5.3 Enable HTTPS
Ensure your cPanel hosting has SSL certificate installed and force HTTPS redirects.

## Demo Credentials
After importing the database, you can login with:
- **Admin**: username `admin`, password `admin123`
- **Agent**: username `agent`, password `agent123`  
- **User**: username `user`, password `user123`

## Troubleshooting

### Common Issues:
1. **Database connection failed**: Check database credentials in `config/database.php`
2. **CORS errors**: Ensure CORS headers are properly set in PHP files
3. **Session issues**: Check PHP session configuration in cPanel
4. **File upload issues**: Verify `uploads/` directory permissions
5. **API 500 errors**: Check PHP error logs in cPanel

### Performance Optimization:
1. Enable PHP OPcache in cPanel
2. Use MySQL query caching
3. Compress static assets
4. Implement proper database indexing

## Maintenance

### Regular Tasks:
1. **Backup database** regularly via phpMyAdmin
2. **Update PHP version** as supported by cPanel
3. **Monitor error logs** for issues
4. **Clean up old session data** periodically

### Database Maintenance:
```sql
-- Clean old sessions (run monthly)
DELETE FROM sessions WHERE expires < UNIX_TIMESTAMP();

-- Optimize tables (run quarterly)
OPTIMIZE TABLE users, tickets, categories, comments, faqs;
```

Your IT Helpdesk Portal is now ready for production use on cPanel hosting!