<?php
/**
 * Plugin Name: Rank Math FAQ → SEOPress FAQ Migrator (Batch + Scheduled)
 * Description: Converts Rank Math FAQ blocks (rank-math/faq-block) into SEOPress FAQ blocks (wpseopress/faq-block-v2). Supports dry-run, apply, batching, and recurring WP-Cron runs.
 * Version: 1.2.0
 *
 * Includes fix for "u003c / u003e / u0022" garbage output by decoding both:
 * - Proper JSON escapes: \u003c
 * - Loose escapes: u003c
 */

if (!defined('ABSPATH')) exit;

define('GT_FAQ_MIGRATOR_OPT', 'gt_faq_migrator_options');
define('GT_FAQ_MIGRATOR_LAST_ID_OPT', 'gt_faq_migrator_last_processed_id');
define('GT_FAQ_MIGRATOR_CRON_HOOK', 'gt_faq_migrator_cron_run');

/**
 * Add a 15-minute schedule.
 */
add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['gt_15min'])) {
    $schedules['gt_15min'] = [
      'interval' => 15 * 60,
      'display'  => 'Every 15 minutes (GT)',
    ];
  }
  return $schedules;
});

/**
 * Admin UI.
 */
add_action('admin_menu', function () {
  add_management_page(
    'FAQ Migrator',
    'FAQ Migrator',
    'manage_options',
    'gt-faq-migrator',
    'gt_faq_migrator_page'
  );
});

/**
 * Cron handler.
 */
add_action(GT_FAQ_MIGRATOR_CRON_HOOK, function () {
  $opts = gt_faq_migrator_get_options();

  // Scheduled runs always APPLY.
  $batch_size     = max(1, intval($opts['batch_size']));
  $max_this_run   = max(1, intval($opts['max_posts_this_run']));
  $post_type      = sanitize_text_field($opts['post_type']);
  $status         = sanitize_text_field($opts['status']);

  gt_faq_migrator_run($batch_size, $post_type, $status, 'apply', $max_this_run, true);
});

/**
 * Options helpers.
 */
function gt_faq_migrator_default_options() {
  return [
    'post_type'          => 'any',
    'status'             => 'publish',
    'batch_size'         => 20,
    'max_posts_this_run' => 100,
    'schedule_enabled'   => 0,
    'schedule_interval'  => 'hourly',
  ];
}

function gt_faq_migrator_get_options() {
  $saved = get_option(GT_FAQ_MIGRATOR_OPT, []);
  return wp_parse_args(is_array($saved) ? $saved : [], gt_faq_migrator_default_options());
}

function gt_faq_migrator_update_options(array $new) {
  $opts = wp_parse_args($new, gt_faq_migrator_get_options());
  update_option(GT_FAQ_MIGRATOR_OPT, $opts, false);
}

function gt_faq_migrator_get_last_id() {
  return intval(get_option(GT_FAQ_MIGRATOR_LAST_ID_OPT, 0));
}

function gt_faq_migrator_set_last_id($id) {
  update_option(GT_FAQ_MIGRATOR_LAST_ID_OPT, max(0, intval($id)), false);
}

/**
 * Schedule management.
 */
function gt_faq_migrator_is_scheduled() {
  return (bool) wp_next_scheduled(GT_FAQ_MIGRATOR_CRON_HOOK);
}

function gt_faq_migrator_schedule($interval) {
  gt_faq_migrator_unschedule();
  if (!in_array($interval, ['gt_15min','hourly','twicedaily','daily'], true)) $interval = 'hourly';
  wp_schedule_event(time() + 60, $interval, GT_FAQ_MIGRATOR_CRON_HOOK);
}

function gt_faq_migrator_unschedule() {
  $timestamp = wp_next_scheduled(GT_FAQ_MIGRATOR_CRON_HOOK);
  while ($timestamp) {
    wp_unschedule_event($timestamp, GT_FAQ_MIGRATOR_CRON_HOOK);
    $timestamp = wp_next_scheduled(GT_FAQ_MIGRATOR_CRON_HOOK);
  }
}

/**
 * Admin page renderer.
 */
function gt_faq_migrator_page() {
  if (!current_user_can('manage_options')) return;

  $opts = gt_faq_migrator_get_options();
  $ran = false;
  $results = [];
  $message = '';

  if (!empty($_POST['gt_faq_action']) && check_admin_referer('gt_faq_migrator_nonce')) {
    $action = sanitize_text_field($_POST['gt_faq_action']);

    if ($action === 'save_settings') {
      $post_type  = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'any';
      $status     = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'publish';
      $batch_size = isset($_POST['batch_size']) ? max(1, intval($_POST['batch_size'])) : 20;
      $max_run    = isset($_POST['max_posts_this_run']) ? max(1, intval($_POST['max_posts_this_run'])) : 100;

      $schedule_enabled  = !empty($_POST['schedule_enabled']) ? 1 : 0;
      $schedule_interval = isset($_POST['schedule_interval']) ? sanitize_text_field($_POST['schedule_interval']) : 'hourly';

      gt_faq_migrator_update_options([
        'post_type'          => $post_type,
        'status'             => $status,
        'batch_size'         => $batch_size,
        'max_posts_this_run' => $max_run,
        'schedule_enabled'   => $schedule_enabled,
        'schedule_interval'  => $schedule_interval,
      ]);

      $opts = gt_faq_migrator_get_options();

      if ($schedule_enabled) {
        gt_faq_migrator_schedule($schedule_interval);
        $message = 'Settings saved. Recurring migration scheduled.';
      } else {
        gt_faq_migrator_unschedule();
        $message = 'Settings saved. Recurring migration disabled.';
      }
    }

    if ($action === 'reset_progress') {
      gt_faq_migrator_set_last_id(0);
      $message = 'Progress reset. Next run will start from the beginning.';
    }

    if ($action === 'run_now') {
      $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'dry';
      $ran = true;
      $results = gt_faq_migrator_run(
        max(1, intval($opts['batch_size'])),
        sanitize_text_field($opts['post_type']),
        sanitize_text_field($opts['status']),
        $mode,
        max(1, intval($opts['max_posts_this_run'])),
        false
      );
    }

    if ($action === 'run_cron_once') {
      $ran = true;
      $results = gt_faq_migrator_run(
        max(1, intval($opts['batch_size'])),
        sanitize_text_field($opts['post_type']),
        sanitize_text_field($opts['status']),
        'apply',
        max(1, intval($opts['max_posts_this_run'])),
        true
      );
    }
  }

  $post_types = get_post_types(['public' => true], 'objects');
  $last_id = gt_faq_migrator_get_last_id();
  $is_scheduled = gt_faq_migrator_is_scheduled();
  $next_run = wp_next_scheduled(GT_FAQ_MIGRATOR_CRON_HOOK);
  ?>
  <div class="wrap">
    <h1>Rank Math FAQ → SEOPress FAQ Migrator</h1>

    <?php if ($message): ?>
      <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <p><strong>Important:</strong> Back up your DB. Run Dry Run before Apply.</p>

    <form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:12px;">
      <?php wp_nonce_field('gt_faq_migrator_nonce'); ?>
      <input type="hidden" name="gt_faq_action" value="save_settings" />

      <h2 style="margin-top:0;">Settings</h2>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="post_type">Post type</label></th>
          <td>
            <select name="post_type" id="post_type">
              <option value="any" <?php selected($opts['post_type'], 'any'); ?>>Any (public)</option>
              <?php foreach ($post_types as $pt) : ?>
                <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($opts['post_type'], $pt->name); ?>>
                  <?php echo esc_html($pt->labels->singular_name . " ({$pt->name})"); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="status">Post status</label></th>
          <td>
            <select name="status" id="status">
              <option value="publish" <?php selected($opts['status'], 'publish'); ?>>publish</option>
              <option value="draft" <?php selected($opts['status'], 'draft'); ?>>draft</option>
              <option value="any" <?php selected($opts['status'], 'any'); ?>>any</option>
            </select>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="batch_size">Posts per batch</label></th>
          <td>
            <input type="number" name="batch_size" id="batch_size" value="<?php echo esc_attr(intval($opts['batch_size'])); ?>" min="1" max="500" />
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="max_posts_this_run">Max posts this run</label></th>
          <td>
            <input type="number" name="max_posts_this_run" id="max_posts_this_run" value="<?php echo esc_attr(intval($opts['max_posts_this_run'])); ?>" min="1" max="5000" />
          </td>
        </tr>
      </table>

      <h2>Recurring Run (WP-Cron)</h2>
      <p style="margin:0 0 8px;">
        Status: <strong><?php echo $is_scheduled ? 'Scheduled' : 'Not scheduled'; ?></strong>
        <?php if ($is_scheduled && $next_run): ?>
          (Next: <?php echo esc_html(date_i18n('Y-m-d H:i:s', $next_run)); ?>)
        <?php endif; ?>
      </p>

      <label style="display:block;margin:8px 0;">
        <input type="checkbox" name="schedule_enabled" value="1" <?php checked(intval($opts['schedule_enabled']), 1); ?> />
        Enable recurring migration
      </label>

      <label for="schedule_interval">Run every</label>
      <select name="schedule_interval" id="schedule_interval">
        <option value="gt_15min" <?php selected($opts['schedule_interval'], 'gt_15min'); ?>>15 minutes</option>
        <option value="hourly" <?php selected($opts['schedule_interval'], 'hourly'); ?>>hourly</option>
        <option value="twicedaily" <?php selected($opts['schedule_interval'], 'twicedaily'); ?>>twice daily</option>
        <option value="daily" <?php selected($opts['schedule_interval'], 'daily'); ?>>daily</option>
      </select>

      <p style="margin-top:12px;">
        <button type="submit" class="button button-primary">Save settings</button>
      </p>
    </form>

    <div style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin-top:12px;">
      <h2 style="margin-top:0;">Progress</h2>
      <p style="margin:0 0 8px;">Last processed post ID: <strong><?php echo esc_html($last_id); ?></strong></p>

      <form method="post" style="display:inline-block;margin-right:8px;">
        <?php wp_nonce_field('gt_faq_migrator_nonce'); ?>
        <input type="hidden" name="gt_faq_action" value="reset_progress" />
        <button type="submit" class="button">Reset progress</button>
      </form>

      <form method="post" style="display:inline-block;">
        <?php wp_nonce_field('gt_faq_migrator_nonce'); ?>
        <input type="hidden" name="gt_faq_action" value="run_cron_once" />
        <button type="submit" class="button">Run Apply once (like cron)</button>
      </form>
    </div>

    <div style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin-top:12px;">
      <h2 style="margin-top:0;">Manual Run</h2>
      <form method="post">
        <?php wp_nonce_field('gt_faq_migrator_nonce'); ?>
        <input type="hidden" name="gt_faq_action" value="run_now" />

        <fieldset>
          <label><input type="radio" name="mode" value="dry" checked /> Dry Run</label><br/>
          <label><input type="radio" name="mode" value="apply" /> Apply</label>
        </fieldset>

        <p style="margin-top:12px;">
          <button type="submit" class="button button-primary">Run now</button>
        </p>
      </form>
    </div>

    <?php if ($ran): ?>
      <div style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin-top:12px;">
        <h2 style="margin-top:0;">Results</h2>
        <p style="margin:0 0 8px;">
          Mode: <strong><?php echo esc_html($results['mode']); ?></strong>,
          Posts scanned: <strong><?php echo esc_html($results['scanned']); ?></strong>,
          Posts matched: <strong><?php echo esc_html($results['matched']); ?></strong>,
          Posts changed: <strong><?php echo esc_html($results['changed']); ?></strong>,
          Blocks converted: <strong><?php echo esc_html($results['blocks_converted']); ?></strong>
        </p>

        <?php if (!empty($results['items'])): ?>
          <h3>Details</h3>
          <?php foreach ($results['items'] as $item): ?>
            <div style="border:1px solid #ccd0d4;padding:10px;margin:10px 0;">
              <p style="margin:0 0 6px;">
                <strong>Post:</strong>
                <a href="<?php echo esc_url(get_edit_post_link($item['post_id'])); ?>">
                  <?php echo esc_html($item['post_title']); ?>
                </a>
                (ID: <?php echo esc_html($item['post_id']); ?>)
              </p>
              <p style="margin:0 0 6px;">
                RM blocks found: <strong><?php echo esc_html($item['rm_blocks']); ?></strong>,
                converted: <strong><?php echo esc_html($item['converted']); ?></strong>,
                changed: <strong><?php echo esc_html($item['changed'] ? 'yes' : 'no'); ?></strong>
              </p>

              <?php if (!empty($item['note'])): ?>
                <p style="margin:0 0 6px;color:#b32d2e;"><strong>Note:</strong> <?php echo esc_html($item['note']); ?></p>
              <?php endif; ?>

              <?php if (!empty($item['preview'])): ?>
                <details>
                  <summary><strong>Dry-run preview (first conversion)</strong></summary>
                  <pre style="white-space:pre-wrap;word-break:break-word;background:#f6f7f7;border:1px solid #ccd0d4;padding:10px;margin-top:10px;"><?php echo esc_html($item['preview']); ?></pre>
                </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($results['errors'])): ?>
          <h3>Errors</h3>
          <ul>
            <?php foreach ($results['errors'] as $err): ?>
              <li><?php echo esc_html($err); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * MAIN runner
 */
function gt_faq_migrator_run($batch_size, $post_type, $status, $mode, $max_this_run, $is_cron) {
  $out = [
    'mode'             => $mode,
    'scanned'          => 0,
    'matched'          => 0,
    'changed'          => 0,
    'blocks_converted' => 0,
    'items'            => [],
    'errors'           => [],
  ];

  $last_id = gt_faq_migrator_get_last_id();
  $processed = 0;

  while ($processed < $max_this_run) {
    $limit = min($batch_size, $max_this_run - $processed);
    $ids = gt_faq_migrator_fetch_ids_sql($limit, $last_id, $post_type, $status);

    if (empty($ids)) break;

    foreach ($ids as $post_id) {
      $processed++;
      $out['scanned']++;

      $post = get_post($post_id);
      if (!$post) continue;

      $content = $post->post_content;
      if (strpos($content, 'wp:rank-math/faq-block') === false) {
        $last_id = max($last_id, $post_id);
        gt_faq_migrator_set_last_id($last_id);
        continue;
      }

      $original = $content;
      $rm_blocks_found = 0;
      $converted = 0;
      $preview = '';
      $note = '';

      $pattern = '/<!--\s*wp:rank-math\/faq-block\s+(\{.*?\})\s*-->.*?<!--\s*\/wp:rank-math\/faq-block\s*-->/s';

      $content = preg_replace_callback($pattern, function ($m) use (&$rm_blocks_found, &$converted, &$out, &$preview, &$note, $post_id) {
        $rm_blocks_found++;

        $json = $m[1] ?? '';
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['questions']) || !is_array($data['questions'])) {
          $note = $note ?: 'Could not parse Rank Math FAQ JSON for at least one block; left block unchanged.';
          return $m[0];
        }

        $seopress_block = gt_build_seopress_faq_block($data['questions'], $post_id);

        if (!$seopress_block) {
          $note = $note ?: 'Failed converting at least one block; left that block unchanged.';
          return $m[0];
        }

        $converted++;
        $out['blocks_converted']++;

        if ($preview === '') $preview = $seopress_block;

        return $seopress_block;
      }, $content);

      $matched = ($rm_blocks_found > 0);
      if ($matched) $out['matched']++;

      $changed = ($content !== $original);

      if ($changed && $mode === 'apply') {
        $updated = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
        if (is_wp_error($updated)) {
          $out['errors'][] = "Post {$post_id}: " . $updated->get_error_message();
          $changed = false;
        }
      }

      if ($changed) $out['changed']++;

      $last_id = max($last_id, $post_id);
      gt_faq_migrator_set_last_id($last_id);

      $item = [
        'post_id'    => $post_id,
        'post_title' => get_the_title($post_id),
        'rm_blocks'  => $rm_blocks_found,
        'converted'  => $converted,
        'changed'    => $changed,
        'note'       => $note,
      ];

      if ($mode === 'dry' && $preview !== '') $item['preview'] = $preview;
      if (!$is_cron || count($out['items']) < 20) $out['items'][] = $item;
    }
  }

  return $out;
}

/**
 * SQL fetcher
 */
function gt_faq_migrator_fetch_ids_sql($limit, $last_id, $post_type, $status) {
  global $wpdb;

  $limit = max(1, intval($limit));
  $last_id = max(0, intval($last_id));
  $like = '%wp:rank-math/faq-block%';

  $types = ($post_type === 'any')
    ? array_map('sanitize_key', get_post_types(['public' => true], 'names'))
    : [sanitize_key($post_type)];

  $st = ($status === 'any')
    ? array_map('sanitize_key', array_keys(get_post_stati()))
    : [sanitize_key($status)];

  $types_sql = implode(',', array_fill(0, count($types), '%s'));
  $st_sql    = implode(',', array_fill(0, count($st), '%s'));

  $sql = "
    SELECT ID
    FROM {$wpdb->posts}
    WHERE ID > %d
      AND post_type IN ($types_sql)
      AND post_status IN ($st_sql)
      AND post_content LIKE %s
    ORDER BY ID ASC
    LIMIT %d
  ";

  $params = array_merge([$last_id], $types, $st, [$like, $limit]);
  $prepared = $wpdb->prepare($sql, $params);

  return array_map('intval', (array) $wpdb->get_col($prepared));
}

/**
 * Build SEOPress FAQ wrapper + details blocks.
 */
function gt_build_seopress_faq_block(array $questions, $post_id) {
  $items_markup = '';

  foreach ($questions as $q) {
    if (!is_array($q)) continue;

    $question = isset($q['title']) ? wp_strip_all_tags($q['title']) : '';
    $answer_raw = isset($q['content']) ? $q['content'] : '';

    if ($question === '' || $answer_raw === '') continue;

    $answer_html = gt_normalize_answer_html($answer_raw);

    $base = sanitize_title($question);
    if ($base === '') $base = 'faq';
    $hash = substr(md5($post_id . '|' . $question), 0, 6);
    $id = $base . '-' . $hash;

    $items_markup .= "\n\n  <!-- wp:details {\"placeholder\":\"Type a question\"} -->\n";
    $items_markup .= '  <details id="' . esc_attr($id) . "\" class=\"wp-block-details\"><summary>" . esc_html($question) . "</summary>\n";
    $items_markup .= "  <!-- wp:paragraph {\"placeholder\":\"Add your answer\"} -->\n";
    $items_markup .= '  ' . $answer_html . "\n";
    $items_markup .= "  <!-- /wp:paragraph --></details>\n";
    $items_markup .= "  <!-- /wp:details -->";
  }

  if (trim($items_markup) === '') return '';

  $block  = "<!-- wp:wpseopress/faq-block-v2 -->\n";
  $block .= "<div class=\"wp-block-wpseopress-faq-block-v2\">";
  $block .= $items_markup . "\n";
  $block .= "</div>\n";
  $block .= "<!-- /wp:wpseopress/faq-block-v2 -->";

  return $block;
}

/**
 * NEW: Fix for the "u003c u003e u0022" mess.
 * Decodes both:
 *  - Proper JSON escapes: \u003c
 *  - Broken/loose escapes: u003c
 */
function gt_decode_loose_unicode_escapes($s) {
  if (!is_string($s) || $s === '') return $s;

  // Decode proper JSON style: \uXXXX
  if (strpos($s, '\\u') !== false) {
    $tmp = json_decode('"' . addcslashes($s, "\\\"\n\r\t") . '"');
    if (is_string($tmp)) $s = $tmp;
  }

  // Decode broken style: uXXXX (no leading backslash)
  // Only if it looks like the common HTML escape soup.
  if (strpos($s, 'u003c') !== false || strpos($s, 'u003e') !== false || strpos($s, 'u0022') !== false) {
    $s = preg_replace_callback('/u([0-9a-fA-F]{4})/', function ($m) {
      return html_entity_decode('&#x' . $m[1] . ';', ENT_QUOTES, get_bloginfo('charset'));
    }, $s);
  }

  return $s;
}

/**
 * Normalize answers: decode escapes FIRST, then sanitize, then wrap.
 */
function gt_normalize_answer_html($answer_raw) {
  $html = gt_decode_loose_unicode_escapes((string)$answer_raw);
  $html = html_entity_decode($html, ENT_QUOTES, get_bloginfo('charset'));
  $html = wp_kses_post($html);
  $html = force_balance_tags($html);

  $trim = trim($html);

  if (preg_match('/^\s*<(p|ul|ol|div|blockquote|h[1-6]|details|summary)\b/i', $trim)) {
    return $trim;
  }

  return '<p>' . $trim . '</p>';
}