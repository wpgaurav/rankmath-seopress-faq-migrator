# Rank Math FAQ → SEOPress FAQ Migrator

A WordPress plugin that seamlessly converts **Rank Math FAQ blocks** (`rank-math/faq-block`) into **SEOPress FAQ blocks** (`wpseopress/faq-block-v2`). Perfect for sites migrating from Rank Math to SEOPress who want to preserve their FAQ structured data.

---

## ✨ Features

- **Batch Processing** — Process posts in configurable batch sizes to avoid server timeouts
- **Dry Run Mode** — Preview changes before applying them
- **WP-Cron Support** — Schedule recurring migrations (every 15 minutes, hourly, twice daily, or daily)
- **Progress Tracking** — Resume from where you left off; never process the same post twice
- **Post Type Filtering** — Target specific post types or process all public post types
- **Post Status Filtering** — Filter by `publish`, `draft`, or `any` status
- **Unicode Fix** — Handles malformed JSON unicode escapes (`u003c`, `\u003c`, etc.)
- **Detailed Logging** — See exactly which posts were changed and preview the converted blocks

---

## 📋 Requirements

- **WordPress** 5.0+
- **PHP** 7.4+
- **SEOPress** plugin (installed and activated)

---

## 🚀 Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/rankmath-seopress-faq-migrator/`
3. Activate the plugin through the **Plugins** menu in WordPress
4. Navigate to **Tools → FAQ Migrator** to configure and run migrations

---

## 🛠️ Usage

### Admin Interface

Access the migrator at **Tools → FAQ Migrator** in your WordPress admin.

#### Settings

| Option               | Description                                        | Default   |
|----------------------|----------------------------------------------------|-----------|
| **Post Type**        | Target post type to scan (`any` for all public)    | `any`     |
| **Post Status**      | Filter by post status (`publish`, `draft`, `any`)  | `publish` |
| **Posts per Batch**  | Number of posts to process per batch               | `20`      |
| **Max Posts per Run**| Maximum posts to process in a single run           | `100`     |

#### Manual Run

1. Select **Dry Run** to preview changes, or **Apply** to make actual changes
2. Click **Run now**
3. Review the results displayed on the page

> ⚠️ **Important:** Always run a **Dry Run** first and back up your database before applying changes!

#### Scheduled Runs (WP-Cron)

1. Check **Enable recurring migration**
2. Select the interval:
   - Every 15 minutes
   - Hourly
   - Twice daily
   - Daily
3. Click **Save settings**

The cron job will automatically apply changes (not dry run) and track progress.

---

## 🔄 How It Works

1. **Scans** posts containing `wp:rank-math/faq-block` in their content
2. **Parses** the Rank Math FAQ block JSON to extract questions and answers
3. **Converts** each FAQ item into SEOPress-compatible `wp:details` blocks
4. **Wraps** the items in a `wp:wpseopress/faq-block-v2` wrapper
5. **Updates** the post content (in Apply mode)

### Block Conversion

**Rank Math FAQ Block:**
```html
<!-- wp:rank-math/faq-block {"questions":[{"title":"Question?","content":"Answer"}]} -->
...
<!-- /wp:rank-math/faq-block -->
```

**Converts to SEOPress FAQ Block:**
```html
<!-- wp:wpseopress/faq-block-v2 -->
<div class="wp-block-wpseopress-faq-block-v2">
  <!-- wp:details {"placeholder":"Type a question"} -->
  <details id="question-abc123" class="wp-block-details">
    <summary>Question?</summary>
    <!-- wp:paragraph {"placeholder":"Add your answer"} -->
    <p>Answer</p>
    <!-- /wp:paragraph -->
  </details>
  <!-- /wp:details -->
</div>
<!-- /wp:wpseopress/faq-block-v2 -->
```

---

## 📊 Progress Tracking

The plugin tracks the last processed post ID, allowing you to:

- **Pause and resume** migrations across multiple sessions
- **Avoid reprocessing** already-migrated posts
- **Reset progress** to start over from the beginning

---

## 🐛 Troubleshooting

### Unicode Garbage Characters

If you see characters like `u003c`, `u003e`, or `u0022` in your content, the plugin automatically decodes these malformed unicode escapes.

### Blocks Not Converting

- Ensure the Rank Math FAQ block JSON is valid
- Check that questions have both `title` and `content` fields
- Review the "Note" column in results for specific errors

### Cron Not Running

- Verify WP-Cron is enabled on your server
- Use a plugin like [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/) to debug cron events

---

## 📄 Changelog

### 1.2.0
- Added fix for loose unicode escapes (`u003c` without backslash)
- Improved answer HTML normalization
- Added 15-minute cron schedule option

### 1.1.0
- Added recurring WP-Cron support
- Added progress tracking (resume capability)
- Added post type and status filters

### 1.0.0
- Initial release
- Basic batch migration with dry run/apply modes

---

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

---

## 📜 License

This plugin is licensed under the GPL v2 or later.

---

## 👤 Author

**Gaurav Tiwari**

---

## ⚠️ Disclaimer

Always backup your database before running migrations. While this plugin includes a dry-run feature, data modifications are irreversible once applied.