=== Housemagik ===
Contributors: housemagik
Tags: real estate, mls, ai, search, listings
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Natural-language home search powered by AI for housemagik.ai.

== Description ==

Housemagik brings AI-powered natural language search to your real estate website. Visitors describe their dream home in plain English, and Claude Haiku AI finds the best matches with personalized explanations.

**Features:**

* Natural language search interface
* AI-powered listing ranking and "why this one" explanations
* Sample Arizona listings included for demo
* ARMLS/Spark Web API integration ready
* Listing detail pages with photos and facts
* Registration popup for lead capture
* Real WP-Cron alert system with deduplication
* Broker brief email on registration
* Per-listing visitor feedback (likes/dislikes)
* Security: nonces, honeypot, rate limits
* Simple [housemagik] shortcode

**Copyright:** 2026 Redlake Marketing

**Brokerage:**
Keys of Dreams Brokery / Suzanne Gonzalez

== Installation ==

1. Upload `housemajik` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Add to wp-config.php: `define( 'ANTHROPIC_API_KEY', 'your-key' );`
4. Add `[housemagik]` shortcode to any page
5. Configure settings in WordPress admin → Housemagik

See README.md for detailed setup instructions.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes, an Anthropic API key is required for AI features. Get one at https://console.anthropic.com/

= Can I test without ARMLS credentials? =

Yes! Plugin includes sample Arizona listings. Switch to ARMLS when credentials are available.

= How do alerts work? =

Daily WP-Cron checks saved searches, finds new matches, and emails buyers and broker. Deduplication prevents repeat sends.

= Do I need an SMTP plugin? =

Recommended for production. Plugin uses wp_mail() which works but SMTP is more reliable.

== Screenshots ==

1. Search form with natural language input
2. Listing cards with AI "why this one" explanations
3. Registration popup
4. Detail page with photos and facts
5. Admin settings interface
6. Alerts management
7. Cron status dashboard

== Changelog ==

= 1.0.0 =
* Initial release
* Natural language search with Claude Haiku
* Sample listings and ARMLS interface
* Alert system with deduplication
* Broker brief emails
* Admin interface

== Upgrade Notice ==

= 1.0.0 =
Initial release.
