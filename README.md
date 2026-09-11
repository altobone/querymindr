# Housemagik WordPress Plugin

Natural-language home search powered by AI for housemagik.ai.

**Version:** 1.0.0  
**Copyright:** 2026 Redlake Marketing  
**Brokerage:** Keys of Dreams Brokery / Suzanne Gonzalez  
**Lead Inbox:** mlake@redlake.tv

---

## Features

✅ Natural language search with AI parsing (Anthropic Claude Haiku)  
✅ Sample Arizona listings for demo/testing  
✅ ARMLS/Spark Web API interface ready for live feed  
✅ Listing cards with AI-generated "why this one" explanations  
✅ Detail pages with photos, facts, and broker contact  
✅ Per-listing reactions (what I like / what I don't like)  
✅ Registration popup (dismissible once per visit)  
✅ Real WP-Cron alert system with deduplication  
✅ Broker brief email on registration  
✅ Security: nonces, honeypot, rate limits, sanitization  
✅ Admin interface for all settings  
✅ `[housemagik]` shortcode for easy embedding

---

## Installation

### 1. Upload Plugin

- Upload the `housemajik/` folder to `/wp-content/plugins/`
- Or zip the folder and install via WordPress admin → Plugins → Add New → Upload

### 2. Activate Plugin

- Go to WordPress admin → Plugins
- Activate "Housemagik"
- Database tables will be created automatically

### 3. Configure Anthropic API Key

**Required for AI features.** Add to `wp-config.php`:

```php
define( 'ANTHROPIC_API_KEY', 'sk-ant-your-key-here' );
```

Get your key from: https://console.anthropic.com/

### 4. Add Shortcode to Page

Create or edit a page and add:

```
[housemagik]
```

This renders the complete search interface.

### 5. Configure Settings

Go to WordPress admin → **Housemagik** → Settings

**General Tab:**
- Data Source: `Sample Data` (for testing) or `ARMLS Feed` (when credentials available)
- IDX Disclaimer: Required MLS attribution text
- Google Maps API Key: Optional, for maps on detail pages
- Rate Limit: Prevent abuse (default: 10 searches per hour)

**AI Tab:**
- API Key status (must be in wp-config.php)
- AI Model: Claude 3 Haiku (recommended)
- Daily Usage Cap: Limit API calls per day

**ARMLS Tab:**
- Endpoint URL, Username, Password (leave empty to use sample data)

**Broker Tab:**
- Broker Name: Suzanne Gonzalez
- Brokerage Name: Keys of Dreams Brokery
- Broker Email: mlake@redlake.tv (where leads are sent)

**Email Tab:**
- Sender Name/Email
- Reply-To Email
- **Note:** Install SMTP plugin (e.g., WP Mail SMTP) for production reliability

**Alerts Tab:**
- Frequency: Daily (only option in v1)

---

## Email Setup (Production)

WordPress `wp_mail()` is used for all emails. In production:

1. Install an SMTP plugin: **WP Mail SMTP** or **Easy WP SMTP**
2. Configure SMTP credentials in that plugin
3. Test email delivery from Housemagik → Settings → Email

---

## WP-Cron Setup (Production)

By default, WP-Cron runs on page visits. For reliable alerts:

### Option 1: System Cron (Recommended)

Add to server crontab (runs every hour):

```bash
0 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Or using `wp-cli`:

```bash
0 * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

Then disable default WP-Cron in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

### Option 2: Default WP-Cron

Leave default (no changes needed). Cron runs when site receives traffic.

### Check Status

Go to **Housemagik** → **Cron Status** to:
- View next scheduled run
- See recent run logs
- Trigger manual test run

---

## Usage

### For Visitors

1. Visit page with `[housemagik]` shortcode
2. Fill out search form:
   - What is your dream home? (required)
   - What don't you want? (optional)
   - Location (required)
   - Beds, Baths, Max Price, Garage
3. Click "Show me houses"
4. See 3 listing cards with AI "why this one" explanations
5. Registration popup appears (dismissible)
6. Add optional notes to each listing (what I like / don't like)
7. Register to save search and get alerts
8. Click "View Full Details" for detail page with photos, facts, map placeholder, and broker contact

### For Admin

- **Housemagik → Settings:** Configure all options
- **Housemagik → Alerts:** View all saved search alerts
- **Housemagik → Cron Status:** Monitor alert system health

---

## Sample Data vs ARMLS Feed

### Sample Data Mode (Default)

- 8 Arizona-flavored demo listings
- Clearly labeled as "Sample Data Mode"
- Perfect for testing the full flow
- No API credentials required

### ARMLS Mode (Production)

When ARMLS credentials are provided:
1. Go to Settings → ARMLS
2. Enter Endpoint URL, Username, Password
3. Go to Settings → General → Data Source: **ARMLS Feed**
4. Save settings
5. Plugin will query live listings

**Note:** ARMLS interface is ready but field mappings may need adjustment when actual feed structure is available.

---

## Switching to ARMLS

When ARMLS credentials arrive:

1. Review `includes/class-housemagik-armls.php`
2. Verify field mappings in `parse_armls_response()` match actual API response
3. Test with a few searches
4. Update IDX disclaimer if needed
5. Switch Data Source to ARMLS in settings

---

## Security Features

✅ **Nonces:** All AJAX requests verified  
✅ **Honeypot:** Hidden field catches bots  
✅ **Rate Limiting:** IP-based limits per hour  
✅ **Sanitization:** All inputs cleaned  
✅ **Escaping:** All outputs escaped  
✅ **API Key:** Must be in wp-config.php (never options table)  
✅ **Daily AI Cap:** Prevent runaway API costs  
✅ **Sessions:** PHP sessions for temporary data before registration

---

## Broker Brief Email

Sent to `mlake@redlake.tv` when visitor registers.

**Includes:**
- Contact info (name, email, phone)
- AI summary of what they want
- Search criteria (dream home, don't want, location, beds/baths/price)
- All listings shown
- Per-listing feedback (likes/dislikes)
- AI pattern analysis (themes from their notes)

**Follow-Up:**
- When user updates notes, broker receives update email (not full resend)

---

## Alert System

### How It Works

1. User saves search → alert record created
2. Daily cron checks each active alert
3. Query listings matching saved criteria
4. Filter out already-sent listings (deduplication)
5. Generate AI "why we sent this" for each new match
6. Email buyer with up to 5 new matches
7. Copy broker on same email
8. Mark listings as sent
9. Log run status

### Email Contents

**To Buyer:**
- Listing cards with photos
- AI why-line using original search AND their previous feedback
- Link to detail page
- Unsubscribe/manage links

**To Broker:**
- Copy of buyer email
- Subject line shows name and match count

### Monitoring

Check **Housemagik → Cron Status** for:
- Last run time and status
- Alerts processed count
- Matches sent count
- Error messages if any

---

## Detail Pages

Listings automatically get detail pages at:

```
https://yoursite.com/property/LISTING-ID
```

**Includes:**
- Photo gallery (click thumbs to change main photo)
- Address, city, price
- Facts: beds, baths, sqft, year, garage, lot size
- Full property description (MLS remarks)
- Map placeholder (configure Google Maps API key to enable)
- MLS# (if ARMLS mode and not sample)
- IDX disclaimer
- Sidebar with broker info and "Ask Suzanne" email link
- Back to search link

---

## File Structure

```
housemagik/
├── housemagik.php               # Main plugin file
├── housemajik.php               # Legacy loader (keeps existing WP activations working)
├── README.md                    # This file
├── readme.txt                   # WordPress.org format (if submitting)
├── uninstall.php                # Cleanup on uninstall
├── includes/
│   ├── class-housemagik-activator.php
│   ├── class-housemagik-deactivator.php
│   ├── class-housemagik-core.php
│   ├── class-housemagik-ajax.php
│   ├── class-housemagik-shortcode.php
│   ├── class-housemagik-admin.php
│   ├── class-housemagik-ai.php
│   ├── class-housemagik-sample-data.php
│   ├── class-housemagik-armls.php
│   ├── class-housemagik-alerts.php
│   ├── class-housemagik-email.php
│   └── class-housemagik-security.php
├── admin/
│   ├── css/housemagik-admin.css
│   ├── js/housemagik-admin.js
│   └── views/
├── public/
│   ├── css/housemagik-public.css
│   ├── js/housemagik-public.js
│   └── views/
│       ├── search-form.php
│       └── detail-page.php
```

---

## Database Tables

Created on activation:

- `wp_housemajik_alerts` – Saved search alerts
- `wp_housemajik_sent_listings` – Deduplication tracking
- `wp_housemajik_reactions` – User notes on listings
- `wp_housemajik_sessions` – Search sessions before registration
- `wp_housemajik_cron_log` – Alert cron run history

Tables are NOT deleted on deactivation. Uncomment uninstall.php code to remove on full uninstall.

---

## Troubleshooting

### Search Returns Nothing

- Check Data Source setting (Sample vs ARMLS)
- If ARMLS: verify credentials and endpoint
- If Sample: check location spelling (Phoenix, Scottsdale, etc.)
- Look at browser console for JavaScript errors

### AI Features Not Working

- Verify `ANTHROPIC_API_KEY` is defined in wp-config.php
- Check API key has credits at https://console.anthropic.com/
- Review daily usage cap in Settings → AI
- Check for PHP errors in debug.log

### Emails Not Sending

- Install and configure SMTP plugin
- Test with WP Mail SMTP → Email Test
- Check spam folder
- Verify broker email in Settings → Broker
- Review server mail logs

### Alerts Not Running

- Check WP-Cron is enabled (not disabled in wp-config)
- Set up system cron for reliability (see WP-Cron Setup above)
- Go to Cron Status and trigger manual run
- Check recent run logs for errors

### Registration Popup Not Showing

- Clear browser cookies for the site
- Check browser console for JavaScript errors
- Verify registration popup hasn't been dismissed this session

---

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ or MariaDB 10.0+
- Anthropic API key (for AI features)
- SMTP plugin recommended for production emails

---

## Support

For questions or issues:

- Review this README
- Check plugin settings
- Review cron status logs
- Contact: mlake@redlake.tv

---

## Changelog

### 1.0.0 – Initial Release

- Natural language search with Claude Haiku
- Sample Arizona listings
- ARMLS interface ready
- Real WP-Cron alerts with deduplication
- Broker brief emails
- Registration popup
- Per-listing reactions
- Admin settings interface
- Security: nonces, honeypot, rate limits
- Detail pages with photos and facts

---

## License

GPL-2.0+

---

## Credits

**Copyright:** 2026 Redlake Marketing  
**Built for:** Housemagik / housemagik.ai  
**Brokerage:** Keys of Dreams Brokery  
**Broker:** Suzanne Gonzalez  
**AI:** Anthropic Claude Haiku  
**Lead Inbox:** mlake@redlake.tv
