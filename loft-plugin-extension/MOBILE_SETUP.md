# The Loft — Mobile App Setup Guide

This guide explains how to install the REST API extension for The Loft plugin so the mobile app can connect to Musicsavvy.com.

---

## Step 1 — Install the PHP file

1. Connect to your web server (via FTP, SFTP, or your host's file manager).
2. Navigate to your WordPress plugins folder:
   ```
   wp-content/plugins/the-loft/includes/
   ```
3. Upload `class-loft-rest-api.php` into that `includes/` folder.

---

## Step 2 — Register the file in the main plugin

1. Open `wp-content/plugins/the-loft/the-loft.php` in a text editor.
2. Find the block of `require_once` lines near the top (they look like the ones loading `class-loft-submission-form.php`, etc.).
3. Add this line right after one of those existing `require_once` lines:

   ```php
   if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php' ) ) {
       require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php';
   }
   ```

4. Save the file.

---

## Step 3 — Verify the endpoints are live

Open a browser (or use a tool like Postman) and visit:

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

If you see a JSON error or a 404, double-check that the file was uploaded to the correct folder and the require_once line was added correctly.

---

## Step 4 — Generate Application Passwords for your users

The mobile app logs in using **WordPress Application Passwords**, which are built into WordPress (no extra plugins needed). Each member needs to generate one from their account page.

**Tell your members:**

1. Go to `https://musicsavvy.com/wp-admin/profile.php` while logged in  
   *(or: My Account → top of page → your username)*
2. Scroll to the **Application Passwords** section near the bottom of the page.
3. In the **New Application Password Name** field, type something like `Loft Mobile App`.
4. Click **Add New Application Password**.
5. WordPress shows a password — **copy it now** (it will not be shown again).
6. Enter that password in the mobile app's login screen along with their usual WordPress username.

> **Note:** Application Passwords look like `xxxx xxxx xxxx xxxx xxxx xxxx` with spaces. The app accepts them with or without spaces.

---

## API reference (for developers)

All endpoints are under `https://musicsavvy.com/wp-json/the-loft/v1/`.

Authentication for protected endpoints uses HTTP Basic Auth:
- **Username:** WordPress username
- **Password:** Application Password generated in Step 4

### GET /instruments
Returns the instrument list. No authentication required.

**Response:**
```json
[{ "id": 12, "name": "Guitar" }, ...]
```

---

### POST /presign
Returns a pre-signed S3 URL so the mobile app can upload audio directly to S3.

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

**Mobile app flow:**
1. Call `/presign` with the filename.
2. PUT the audio file bytes directly to `upload_url` with the matching `Content-Type` header (`audio/mp4` for M4A, `audio/mpeg` for MP3).
3. Use the returned `object_key` in the `/submit` call.

---

### POST /submit
Creates the Loft submission. Identical behavior to the website form.

**Authentication:** Required

**Request body (JSON):**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `s3_object_key` | string | If no video | Key returned by `/presign` |
| `submission_video_url` | string | If no audio | Full YouTube/Vimeo URL |
| `submission_video_sample_start` | string | No | e.g. `"4:15"` |
| `what_doesnt_feel_right` | string | Yes | Up to ~35 words |
| `what_would_you_like_to_improve` | string | Yes | Up to ~35 words |
| `instrument` | integer | Yes | Term ID from `/instruments` |

**Response:**
```json
{
  "success": true,
  "message": "Submission correctly recorded.",
  "post_id": 1234
}
```

On success the admin notification email is sent to `mlake@redlake.tv` exactly as it is from the website form.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| 404 on all `/the-loft/v1/` routes | File not uploaded, or `require_once` line missing |
| 401 Unauthorized | Application Password not entered correctly, or Application Passwords are disabled in WordPress settings |
| 403 Membership required | User's WooCommerce membership (Essentials or Mastery) is not active |
| 500 on `/presign` | AWS credentials not configured — set `LOFT_S3_KEY`, `LOFT_S3_SECRET`, `LOFT_S3_BUCKET` in `wp-config.php` |
| Pre-signed URL upload returns 403 | S3 bucket CORS policy needs to allow `PUT` from the mobile app origin |

---

## S3 CORS configuration (if presign uploads fail)

If the mobile app's audio upload to S3 is rejected with a CORS error, add this CORS rule to your S3 bucket (AWS Console → S3 → your bucket → Permissions → CORS):

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
