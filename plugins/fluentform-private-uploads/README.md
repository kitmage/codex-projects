# Fluent Forms – Private Uploads (Admin-Only Access)

This plugin moves Fluent Forms file uploads **outside of the web root** and serves them through a controlled WordPress route so files **cannot be accessed by guessing URLs**.

It is designed specifically for environments where you **cannot use nginx or `.htaccess` rules** and need PHP-level access control.

---

## What This Solves

By default, Fluent Forms stores uploads under:

```
/wp-content/uploads/
```

Those files are:

* Publicly accessible
* Guessable
* Not protected by WordPress auth

This plugin:

* Stores uploads in a **private filesystem directory**
* Prevents direct public access
* Allows **only logged-in admins** to download files
* Works with Fluent Forms’ internal upload token system
* Does **not** rely on server config changes

---

## How It Works (High Level)

1. **During upload**

   * Fluent Forms uploads are intercepted
   * Files are saved to:

     ```
     /private_html/fluentforms-uploads/YYYY/MM/
     ```
   * Fluent Forms still receives a URL, but it points to a fake route:

     ```
     /__ff_private_uploads__/...
     ```

2. **During submission**

   * Fluent Forms replaces the stored upload URL with a short token:

     ```
     /__ff_private_uploads__/fluentform/Xiw==
     ```

3. **When an admin clicks the file**

   * WordPress intercepts the fake URL
   * The plugin resolves the token to the real file by scanning the private directory
   * The file is streamed securely via PHP
   * Non-admins receive a 403

---

## Requirements

* WordPress
* Fluent Forms
* PHP access to a directory **outside public web root**
* Ability to write to that directory
* Admin users downloading files

---

## Installation

1. Create a new plugin file:

```
wp-content/plugins/ff-private-uploads/ff-private-uploads.php
```

2. Paste the full plugin code into that file.

3. Edit this constant to match your environment:

```php
const PRIVATE_BASEDIR = '/home/your-account/private_html/fluentforms-uploads';
```

4. Activate the plugin.

---

## Directory Structure

Files are stored like this:

```
private_html/
└── fluentforms-uploads/
    └── 2026/
        └── 02/
            └── ff-<hash>-original-filename.ext
```

Nothing inside this directory is publicly accessible.

---

## Access Rules

| User Type                | Access               |
| ------------------------ | -------------------- |
| Logged-in admin          | ✅ Can download       |
| Logged-in non-admin      | ❌ 403                |
| Logged-out user          | ❌ 403                |
| Direct filesystem access | ❌ Not web-accessible |

---

## Supported URL Forms

The plugin correctly handles:

```
/__ff_private_uploads__/2026/02/filename.ext
/__ff_private_uploads__/fluentform/Xiw==
```

Fluent Forms internally stores only the **token form**, which is why suffix-matching logic is used.

---

## Debug Mode

Debug logging is enabled by default:

```php
const DEBUG = true;
```

Logs are written to:

```
wp-content/debug.log
```

When you’re satisfied everything works, **set this to false**:

```php
const DEBUG = false;
```

---

## Security Notes

* Files are never exposed via `wp-content/uploads`
* URLs cannot be guessed to retrieve files
* PHP validates:

  * Logged-in status
  * Admin capability
  * Path traversal
  * Realpath containment
* Files are streamed, not redirected

---

## Performance Notes

* Token resolution scans only:

  ```
  PRIVATE_BASEDIR/YYYY/MM/*
  ```
* This is fast even with thousands of uploads
* If needed, the resolver can later be optimized with an index map

---

## Known Limitations

* Admin-only access (by design)
* Does not expose files to front-end users
* Assumes Fluent Forms upload behavior remains consistent

---

## Why This Approach

Fluent Forms intentionally tokenizes upload references.
Because the real filename is **not stored in the submission**, the only safe, reliable solution without modifying Fluent Forms core is:

> **Resolve token → real file at request time**

This plugin does exactly that.

---