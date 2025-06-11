# Chicago Loft Search – WordPress Plugin  
Natural-language search & filtering for Chicago loft listings powered by ChatGPT.

---

## 1  | Plugin Overview & Features
Chicago Loft Search brings the power of OpenAI’s ChatGPT directly to your WordPress site, allowing visitors to ask questions such as:

* “Show me West Loop lofts under \$500 k with exposed brick.”
* “Which River North lofts have two parking spaces?”
* “Find historic lofts (built < 1920) over 2 000 sq ft.”

Key capabilities  
- 🔍 **Natural-language search** over your private MLS data  
- 🧠 **ChatGPT (GPT-4o / GPT-4-Turbo / GPT-3.5-Turbo)** back-end  
- 🗂 **Custom database** for fast structured filtering (price, beds, baths, SF, year, features…)  
- 🛡 **Security & rate limiting** (daily / monthly quotas, throttling, reCAPTCHA, IP/keyword blocking)  
- 👛 **Cost control** – token usage dashboard & limit rules  
- 🖥 **Beautiful UI** with example questions, loading states, search history & dark-mode  
- ⚙️ **Admin dashboard**: import MLS data, view logs, export settings, manual/auto sync  
- 🛠 **Developer-friendly** (REST endpoints, hooks & filters)

---

## 2  | Installation

1. **Download** the plugin ZIP or clone the repo:  
   `git clone https://github.com/your-org/chicago-loft-search-plugin.git`
2. Upload the folder to `/wp-content/plugins/` **or** install via **Plugins → Add New → Upload**.
3. Activate **Chicago Loft Search** in **Plugins**.
4. On first activation the plugin:
   - Creates three DB tables (`_chicago_loft_listings`, `_chicago_loft_search_usage`, `_chicago_loft_search_logs`)
   - Adds default settings & schedules daily/monthly reset jobs.

---

## 3  | Quick Configuration Guide

1. Go to **Settings → Chicago Loft Search** (or **Loft Search → Settings**).
2. Complete the tabs left-to-right:
   1. **API Settings** – add your OpenAI key & choose a model.
   2. **Rate Limiting** – set daily/monthly quotas.
   3. **User Permissions** – decide which roles (or visitors) can search.
   4. **Advanced** – adjust system prompt / temperature / max tokens.
   5. **Security** – pick security level, enable CAPTCHA, block IPs/keywords.
   6. **Import/Export** – configure MLS endpoint & schedule auto-sync.

Save changes. You’re ready to embed the search interface.

---

## 4  | OpenAI API Setup

| Step | Action |
|------|--------|
| 1 | Sign in to <https://platform.openai.com/account/api-keys> |
| 2 | **Create new secret key** (save it securely). |
| 3 | In WordPress > **API Settings** paste the key in **OpenAI API Key**. |
| 4 | Click **Verify API Key**. You should see “API key is valid!”. |
| 5 | Choose a **Model**. _GPT-4o_ is most accurate, _GPT-3.5-Turbo_ is the cheapest. |
| 6 | Monitor token usage in the built-in dashboard. |

Token costs are charged by OpenAI, not by this plugin. The usage dashboard shows today & this-month queries / tokens / estimated cost.

---

## 5  | MLS Data Integration

There are **two ways** to feed listings:

### A. REST Import Endpoint  
POST `https://your-site.com/wp-json/chicago-loft-search/v1/import`  
`Authorization: Bearer <your-WP nonce>` (see admin “Import MLS Data”).  
Body (JSON):

```json
{
  "listings": [
    {
      "mls_id": "123456",
      "address": "123 W Example St 3E",
      "neighborhood": "West Loop",
      "price": 475000,
      "bedrooms": 2,
      "bathrooms": 2,
      "square_feet": 1650,
      "year_built": 1915,
      "features": "exposed brick, timber, balcony",
      "description": "Bright corner loft...",
      "image_urls": [
        "https://images.example.com/123456-1.jpg",
        "https://images.example.com/123456-2.jpg"
      ],
      "status": "active"
    }
  ]
}
```

### B. Manual CSV/JSON Upload  
1. Go to **Loft Search → Import MLS Data**.  
2. Upload file, map columns, run import.

Fields are upserted on `mls_id`; inactive listings can be flagged via `status`.

### Sync Schedule  
Enable **Automatic Sync** & pick frequency (hourly → weekly). Last sync timestamp shows on settings header.

---

## 6  | Security & Best Practices

| Feature | Description |
|---------|-------------|
| Rate limits | Daily & monthly per user/IP counters reset by WP-Cron. |
| Throttling | Optional X-second cooldown between searches. |
| reCAPTCHA | Enforced on High security level for guests. |
| Blocklists | IP & keyword blocking to drop malicious queries. |
| Roles | Limit usage to logged-in roles or allow visitors. |
| Logs | All queries / responses (optional) with token count. |
| Data privacy | Loft data stays in your DB; only relevant rows are summarized & sent to OpenAI over HTTPS. |

**Tip:** keep your OpenAI key in a dedicated account/project with billing alerts.

---

## 7  | Usage Examples & Shortcodes

Embed the search anywhere:

```html
[chicago_loft_search]
```

Attributes:

| Attribute | Default | Description |
|-----------|---------|-------------|
| `title` | “Chicago Loft Search” | Panel heading. |
| `placeholder` | “Search for lofts in Chicago…” | Input placeholder |
| `button_text` | “Search” | Submit button label |
| `show_examples` | `yes` | Display example question buttons |

Example:

```
[chicago_loft_search title="Find Your Dream Loft" placeholder="Try: West Loop lofts < $600k" show_examples="no"]
```

---

## 8  | Troubleshooting

| Symptom | Resolution |
|---------|------------|
| “Security check failed.” | Page cache serving stale nonce. Exclude page from cache or refresh. |
| “API key not configured.” | Add/verify key in **Settings → API**. |
| No results / empty answer | Confirm MLS table has listings with `status = active`; check error logs. |
| Daily limit reached too quickly | Raise limit or exempt admin/editor roles. |
| reCAPTCHA not loading | Ensure Site Key is correct; domain allowed in Google Console. |
| Cron jobs not running | Set up a real server-side cron or use WP-Crontrol to diagnose. |

Enable WP_DEBUG for detailed logs or inspect **Loft Search → Logs**.

---

## 9  | FAQ

**Q – Does this expose my private MLS data publicly?**  
A – No. Raw data never leaves your server. Only a trimmed JSON excerpt (max 50 records) is sent to OpenAI per query.

**Q – How much will this cost?**  
Depends on model & volume. GPT-4o ≈ \$5-10 per 1k queries (assuming 1 k tokens per response). Use the built-in dashboard to monitor.

**Q – Can I style the UI?**  
Yes. The markup uses BEM-like classes (`.chicago-loft-search-*`). Override in your theme / custom CSS.

**Q – Multisite support?**  
Works per-site. Each blog keeps its own settings & tables.

**Q – Can I translate labels?**  
All strings are internationalised (`__()` / `_e()`). Use Loco Translate or PoEdit.

---

## 10  | Support & Contribution

### Need Help?  
1. Check the **Troubleshooting** section & logs.  
2. Search existing issues on the GitHub repo.  
3. Create a new issue with detailed steps & environment info.

### Contributing  
Pull requests are welcome!

1. Fork the repo & create a branch `feature/my-change`.  
2. Follow WP coding standards (`phpcs`).  
3. Run unit tests (`composer install && composer test`).  
4. Submit PR – describe what it does & link related issues.

### License  
GPL-2.0+ – Free as in freedom.  

---

Made with ❤️ by the Factory team.  