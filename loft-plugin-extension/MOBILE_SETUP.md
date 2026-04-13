# The Loft — Mobile App Setup Guide

This guide explains how to install the REST API extension for The Loft plugin so the mobile app can connect to Musicsavvy.com.

---

## Step 1 — Upload the files

Two files from this folder go onto your server:

1. **`class-loft-rest-api.php`** → upload to:
   ```
   wp-content/plugins/the-loft/includes/class-loft-rest-api.php
   ```

2. **`the-loft.php`** (this is a replacement for your main plugin file, with one extra line added to load the REST API class) → upload to:
   ```
   wp-content/plugins/the-loft/the-loft.php
   ```
   This replaces your existing `the-loft.php`. The only change is the addition of these three lines at the bottom of the require_once block at the top of the file:
   ```php
   if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php' ) ) {
       require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php';
   }
   ```
   Everything else in the file is identical to your current version.

---

## Step 2 — Verify the endpoints are live

Open a browser and visit:

```
https://musicsavvy.com/wp-json/the-loft/v1/instruments
```

You should see a JSON list of your instruments, for example:

```json
[
  { "id": 1, "name": "Guitar" },
  { "id": 2, "name": "Piano" },
  ...
]
```

If you see a 404 or an empty page, double-check that both files were uploaded to the correct locations.

---

## Step 3 — Generate Application Passwords for your users

The mobile app logs in using **WordPress Application Passwords**, which are built into WordPress (no extra plugins needed). Each member needs to generate one from their account page.

**Tell your members:**

1. Log into Musicsavvy.com and go to:
   `https://musicsavvy.com/wp-admin/profile.php`  
   *(or: Dashboard → Users → Your Profile)*
2. Scroll to the **Application Passwords** section near the bottom of the page.
3. In the **New Application Password Name** field, type something like `Loft Mobile App`.
4. Click **Add New Application Password**.
5. WordPress shows the password — **copy it now** (it will not be shown again).
6. Enter that password in the mobile app's login screen along with their regular WordPress username.

> **Note:** Application Passwords look like `xxxx xxxx xxxx xxxx xxxx xxxx` with spaces. The app accepts them with or without spaces.

---

## API reference (for developers)

All endpoints are under `https://musicsavvy.com/wp-json/the-loft/v1/`.

Protected endpoints use HTTP Basic Auth:
- **Username:** WordPress username
- **Password:** Application Password generated in Step 3

---

### GET /instruments
Returns the instrument list for the mobile picker. No authentication required.

**Response:**
```json
[{ "id": 12, "name": "Guitar" }, ...]
```

---

### POST /presign
Returns a pre-signed S3 URL so the mobile app can upload audio **directly to S3** without routing the file through WordPress. This avoids slow server-side uploads.

**Authentication:** Required

**Request body:**
```json
{ "filename": "my-recording.m4a" }
```

**Response:**
```json
{
  "upload_url": "https://s3.amazonaws.com/your-bucket/loft-submissions/42/1713000000-my-recording.m4a?...",
  "object_key": "loft-submissions/42/1713000000-my-recording.m4a"
}
```

**Mobile app upload flow:**
1. Call `POST /presign` with the filename.
2. `PUT` the raw audio bytes directly to `upload_url`. Set the `Content-Type` header to `audio/mp4` (M4A) or `audio/mpeg` (MP3).
3. Pass the returned `object_key` in the `s3_object_key` field of the `/submit` call.

The pre-signed URL is valid for **15 minutes**.

---

### POST /submit
Creates the Loft submission. Identical behavior to the website form.

**Authentication:** Required

**Request body (JSON):**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `s3_object_key` | string | If no video | Key returned by `/presign` |
| `submission_video_url` | string | If no audio | Full YouTube/Vimeo URL |
| `submission_video_sample_start` | string | No | e.g. `"4:15"` — only with video |
| `what_doesnt_feel_right` | string | Yes | |
| `what_would_you_like_to_improve` | string | Yes | |
| `instrument` | integer | Yes | Term ID from `/instruments` |
| `loft_hp_field` | string | No | Leave empty. Honeypot — if non-empty the request silently succeeds without creating a post (bot trap). |

**Response:**
```json
{
  "success": true,
  "message": "Submission correctly recorded.",
  "post_id": 1234
}
```

On success, the admin notification email is sent to `mlake@redlake.tv` exactly as it is from the website form.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| 404 on all `/the-loft/v1/` routes | Files not uploaded, or `the-loft.php` not replaced |
| 401 Unauthorized | Application Password not entered correctly, or Application Passwords disabled in WordPress settings |
| 403 Membership required | User's WooCommerce membership (Essentials or Mastery) is not active |
| 500 on `/presign` | AWS credentials not configured — set `LOFT_S3_KEY`, `LOFT_S3_SECRET`, `LOFT_S3_BUCKET` in `wp-config.php` |
| S3 PUT returns 403 | S3 bucket CORS policy needs to allow `PUT` — see below |

---

## S3 CORS configuration (if presign uploads fail from mobile)

If the mobile app's direct audio upload to S3 is rejected, add this CORS rule to your S3 bucket:

**AWS Console → S3 → your bucket → Permissions tab → CORS → Edit**

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["PUT"],
    "AllowedOrigins": ["*"],
    "ExposeHeaders": []
  }
]
```

This allows the mobile app to PUT audio files directly from the phone to S3.
