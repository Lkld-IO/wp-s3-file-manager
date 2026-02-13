# WP S3 File Manager

A WordPress plugin that connects an Amazon S3 bucket for large file storage with access control and authenticated file sharing URLs.

## Features

- **S3 Integration** — Upload, delete, and manage files in your Amazon S3 bucket directly from the WordPress admin
- **Authenticated Access URLs** — Generate shareable file URLs using your site's domain that only logged-in users can access
- **Drag-and-Drop Upload** — Interactive upload area with real-time progress bar
- **Role-Based Access Control** — Admins manage files; authenticated users view files; unauthenticated users are blocked
- **No AWS SDK Required** — Uses native AWS Signature v4 signing via the WordPress HTTP API
- **Connection Testing** — Verify your S3 credentials with a single click

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- An Amazon S3 bucket with valid AWS credentials

## Installation

1. Download or clone this repository
2. Copy the `src/` directory contents into `wp-content/plugins/wp-s3-file-manager/`
3. Activate **WP S3 File Manager** in the WordPress admin under Plugins
4. Go to **S3 Files > Settings** in the admin sidebar to configure your AWS credentials

## Configuration

Navigate to **S3 Files > Settings** and enter:

| Setting | Description |
|---------|-------------|
| AWS Access Key ID | Your IAM user's access key |
| AWS Secret Access Key | Your IAM user's secret key |
| AWS Region | The region where your S3 bucket is located |
| S3 Bucket Name | The name of your S3 bucket |
| Path Prefix | Optional folder prefix for uploaded files (e.g. `uploads/`) |

After saving, click **Test Connection** to verify your credentials.

### AWS IAM Permissions

Your IAM user needs the following S3 permissions on the target bucket:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::YOUR-BUCKET-NAME",
                "arn:aws:s3:::YOUR-BUCKET-NAME/*"
            ]
        }
    ]
}
```

## Usage

### Uploading Files

1. Go to **S3 Files > Manage Files** in the admin sidebar
2. Drag and drop a file onto the upload area, or click **Select File**
3. Watch the progress bar as the file uploads to S3
4. Once complete, the file appears in the table with its access URL

### Managing Files

The file table shows all uploaded files with:
- File name, size, and MIME type
- Access URL (click to copy to clipboard)
- Upload date and uploader
- Delete button (with confirmation dialog)

### Sharing Files

Each uploaded file gets a unique access URL in the format:

```
https://yoursite.com/wps3fm-file/{access_token}
```

Share this URL with any authenticated user on your site. When they visit the URL:
- **Logged-in users** are redirected to a time-limited presigned S3 URL (5-minute expiry)
- **Logged-out users** are redirected to the WordPress login page
- **Invalid tokens** return a 404 error

## Access Control

| User Type | Settings Page | Upload/Delete | View Files via URL |
|-----------|:---:|:---:|:---:|
| Admin (`manage_options`) | Yes | Yes | Yes |
| Authenticated (non-admin) | No | No | Yes |
| Unauthenticated | No | No | No |

## Plugin Structure

```
wp-s3-file-manager/
├── wp-s3-file-manager.php       # Main plugin file (activation, autoloader)
├── includes/
│   ├── Settings.php             # WordPress Settings API integration
│   ├── S3Client.php             # AWS S3 communication (Signature v4)
│   ├── FileManager.php          # File CRUD and AJAX endpoints
│   ├── Admin.php                # Admin menu and page registration
│   └── AccessController.php     # Rewrite rules and auth-gated file access
├── templates/
│   ├── settings-page.php        # Settings page template
│   └── files-page.php           # File management page template
└── assets/
    ├── js/admin.js              # Upload progress, file listing, URL copy
    └── css/admin.css            # Admin interface styles
```

## License

GPL-2.0+ — See [LICENSE](http://www.gnu.org/licenses/gpl-2.0.txt) for details.
