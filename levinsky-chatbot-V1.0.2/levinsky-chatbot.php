<?php
/**
 * Plugin Name: Levinsky Chatbot (All-in-One)
 * Description: All-in-one WordPress chatbot plugin: corner bubble UI + WP REST endpoint + OpenAI calls + server-side limits + DB logs/history. No local files.
 * Version: 1.0.2
 */


/**
 * OVERVIEW
 * -------
 * This plugin is an "all-in-one" implementation:
 * - Frontend: a corner bubble UI (CSS + JS injected inline).
 * - Backend: a public WP REST endpoint (/wp-json/levinsky/v1/chat) that calls OpenAI.
 * - Storage: usage counters, history, and logs are stored in WordPress DB tables.
 *
 * DESIGN GOALS
 * - No external Python service.
 * - No writing to local server files (logs/DB are in MySQL/MariaDB via $wpdb).
 * - Simple admin UX for non-technical clinic staff (Settings, Logs, Usage, Export).
 *
 * IMPORTANT NOTES
 * - Do not store secrets in the code. Prefer LEVINSKY_OPENAI_API_KEY in wp-config.php.
 * - This code stores user messages in the DB logs. Use retention controls and restrict access.
 */
if (!defined('ABSPATH')) exit;

class LevinskyChatbotAllInOne {
  const OPT_KEY = 'levinsky_chatbot_settings';

  // DB tables (with WP prefix)
  private $t_events;
  private $t_user_usage;
  private $t_global_usage;
  private $t_history;

  /**
   * Constructor.
   *
   * - Initializes table names using the active WordPress DB prefix.
   * - Registers hooks for activation, REST routes, frontend assets, and admin pages.
   * - Registers admin-post handlers used by the export/reset actions.
   */
  public function __construct() {
    global $wpdb;
    $this->t_events       = $wpdb->prefix . 'levinsky_chat_events';
    $this->t_user_usage   = $wpdb->prefix . 'levinsky_chat_usage_user';
    $this->t_global_usage = $wpdb->prefix . 'levinsky_chat_usage_global';
    $this->t_history      = $wpdb->prefix . 'levinsky_chat_history';

    register_activation_hook(__FILE__, [$this, 'on_activate']);

    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_footer', [$this, 'render_bubble']);

    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_register_settings']);
    add_action('admin_post_levinsky_chatbot_export_logs', [$this, 'handle_export_logs']);
    add_action('admin_post_levinsky_chatbot_export_usage', [$this, 'handle_export_usage']);
    add_action('admin_post_levinsky_chatbot_reset_user', [$this, 'handle_reset_user']);
  }

  // -------------------------
  // Defaults
  // -------------------------
  /**
   * Returns the plugin default configuration.
   *
   * These defaults are merged with the saved WP option in get_settings().
   * All values are stored under the option key defined by OPT_KEY.
   */
  private function defaults() {
    return [
      'enabled' => 1,
      'openai_api_key' => '', // Prefer wp-config constant instead
      'openai_model_triage' => 'gpt-4o',
      'openai_model_merge'  => 'gpt-4o',
      'temperature_triage' => 0.0,
      'temperature_merge'  => 0.3,

      // Limits: 24h rolling window
      'user_limit_24h' => 3,
      'global_limit_24h' => 100,
      'window_seconds' => 86400,

      // UI
      'bubble_title' => 'Chat',
      'opening_line' => "שלום וברוכים הבאים למרפאת לוינסקי 🙂 כאן הצ'אטבוט של המרפאה. אם יש לכם בעיה ותרצו להתייעץ, אשמח לעזור לכם. אנא כתבו לי בפירוט מה מטריד אתכם.",
      'max_message_chars' => 800,

      // Security
      'trust_x_forwarded_for' => 0, // only enable if you control/know your proxy
      'require_same_origin' => 1,

      // Behavior
      'enable_merge_call' => 1, // if off: only triage assistant_answer + clinic guidance
      'store_history_pairs' => 2, // last N Q/A pairs
      'log_enabled' => 1,

      // Admin
      'log_retention_days' => 30,
      'error_notification_gmails' => [],
    ];
  }

  /**
   * Loads and returns the effective plugin configuration.
   *
   * - Reads the saved settings from the WP options table.
   * - Merges with defaults so missing keys are filled safely.
   */
  private function get_settings() {
    $saved = get_option(self::OPT_KEY, []);
    $cfg = wp_parse_args(is_array($saved) ? $saved : [], $this->defaults());
    return $cfg;
  }

  /**
   * Returns the public URL of the bubble icon.
   *
   * The icon file is expected to be in the same plugin folder as this PHP file
   * (e.g. bubble-icon.png).
   */
  private function bubble_icon_url() {
    return plugins_url('bubble-icon.png', __FILE__);
  }

  // -------------------------
  // Activation: create tables
  // -------------------------
  /**
   * Plugin activation hook.
   *
   * Creates (or updates) the required DB tables using dbDelta() and ensures
   * there is a single row for the global usage counter.
   */
  public function on_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Events / logs
    $sql1 = "CREATE TABLE {$this->t_events} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      event_type VARCHAR(64) NOT NULL,
      user_key VARCHAR(64) NOT NULL,
      ip VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      message TEXT NULL,
      reply_preview TEXT NULL,
      triage_json LONGTEXT NULL,
      meta_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY created_at (created_at),
      KEY user_key (user_key),
      KEY event_type (event_type)
    ) $charset_collate;";

    // Per-user usage (rolling 24h window)
    $sql2 = "CREATE TABLE {$this->t_user_usage} (
      user_key VARCHAR(64) NOT NULL,
      window_start INT UNSIGNED NOT NULL,
      count INT UNSIGNED NOT NULL,
      PRIMARY KEY (user_key)
    ) $charset_collate;";

    // Global usage (rolling 24h window)
    $sql3 = "CREATE TABLE {$this->t_global_usage} (
      id TINYINT UNSIGNED NOT NULL,
      window_start INT UNSIGNED NOT NULL,
      count INT UNSIGNED NOT NULL,
      PRIMARY KEY (id)
    ) $charset_collate;";

    // Server-side history (last pairs)
    $sql4 = "CREATE TABLE {$this->t_history} (
      user_key VARCHAR(64) NOT NULL,
      history_json LONGTEXT NOT NULL,
      updated_at INT UNSIGNED NOT NULL,
      PRIMARY KEY (user_key)
    ) $charset_collate;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);

    // Initialize global row if missing
    $row = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->t_global_usage} WHERE id=%d", 1));
    if ((int)$row === 0) {
      $wpdb->insert($this->t_global_usage, [
        'id' => 1,
        'window_start' => time(),
        'count' => 0
      ], ['%d','%d','%d']);
    }
  }

  // -------------------------
  // Admin UI
  // -------------------------
  /**
   * Registers the plugin admin pages under Settings.
   *
   * - Main settings page.
   * - Logs viewer page.
   * - Usage viewer page.
   */
  public function admin_menu() {
    // Settings page (existing)
    add_options_page(
      'Levinsky Chatbot',
      'Levinsky Chatbot',
      'manage_options',
      'levinsky-chatbot',
      [$this, 'admin_page']
    );

    // Sub-pages under Settings -> Levinsky Chatbot
    add_submenu_page(
      'options-general.php',
      'Levinsky Chatbot Logs',
      'Levinsky Chatbot Logs',
      'manage_options',
      'levinsky-chatbot-logs',
      [$this, 'admin_logs_page']
    );

    add_submenu_page(
      'options-general.php',
      'Levinsky Chatbot Usage',
      'Levinsky Chatbot Usage',
      'manage_options',
      'levinsky-chatbot-usage',
      [$this, 'admin_usage_page']
    );
  }

  /**
   * Registers the settings group and sanitation callback.
   *
   * Settings are stored as a single array option under OPT_KEY.
   */
  public function admin_register_settings() {
    register_setting('levinsky_chatbot_group', self::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => $this->defaults(),
    ]);
  }

  /**
   * Sanitizes and normalizes values coming from the settings form.
   *
   * Important:
   * - This function should never trust user input.
   * - It clamps numbers to reasonable ranges and uses WP sanitation helpers.
   */
  public function sanitize_settings($input) {
    $d = $this->defaults();
    $out = [];

    $out['enabled'] = empty($input['enabled']) ? 0 : 1;
    $out['openai_api_key'] = isset($input['openai_api_key']) ? trim((string)$input['openai_api_key']) : '';
    $out['openai_model_triage'] = isset($input['openai_model_triage']) ? trim((string)$input['openai_model_triage']) : $d['openai_model_triage'];
    $out['openai_model_merge']  = isset($input['openai_model_merge']) ? trim((string)$input['openai_model_merge']) : $d['openai_model_merge'];

    $out['temperature_triage'] = isset($input['temperature_triage']) ? max(0.0, min(1.0, floatval($input['temperature_triage']))) : $d['temperature_triage'];
    $out['temperature_merge']  = isset($input['temperature_merge'])  ? max(0.0, min(1.0, floatval($input['temperature_merge'])))  : $d['temperature_merge'];

    $out['user_limit_24h'] = isset($input['user_limit_24h']) ? max(0, intval($input['user_limit_24h'])) : $d['user_limit_24h'];
    $out['global_limit_24h'] = isset($input['global_limit_24h']) ? max(0, intval($input['global_limit_24h'])) : $d['global_limit_24h'];
    $out['window_seconds'] = isset($input['window_seconds']) ? max(60, intval($input['window_seconds'])) : $d['window_seconds'];

    $out['bubble_title'] = isset($input['bubble_title']) ? sanitize_text_field($input['bubble_title']) : $d['bubble_title'];
    $out['opening_line'] = isset($input['opening_line']) ? sanitize_textarea_field($input['opening_line']) : $d['opening_line'];
    $out['max_message_chars'] = isset($input['max_message_chars']) ? max(50, intval($input['max_message_chars'])) : $d['max_message_chars'];

    $out['trust_x_forwarded_for'] = empty($input['trust_x_forwarded_for']) ? 0 : 1;
    $out['require_same_origin'] = empty($input['require_same_origin']) ? 0 : 1;

    $out['enable_merge_call'] = empty($input['enable_merge_call']) ? 0 : 1;
    $out['store_history_pairs'] = isset($input['store_history_pairs']) ? max(0, min(5, intval($input['store_history_pairs']))) : $d['store_history_pairs'];
    $out['log_enabled'] = empty($input['log_enabled']) ? 0 : 1;
    $out['log_retention_days'] = isset($input['log_retention_days']) ? max(1, intval($input['log_retention_days'])) : $d['log_retention_days'];
    $out['error_notification_gmails'] = $this->sanitize_gmail_addresses(isset($input['error_notification_gmails']) ? $input['error_notification_gmails'] : []);

    return $out;
  }

  /**
   * Sanitizes a list of Gmail addresses from the admin settings form.
   *
   * Accepts either a textarea string (one email per line) or an array and
   * returns a unique, lowercased list containing only valid gmail.com
   * addresses.
   */
  private function sanitize_gmail_addresses($value) {
    if (is_string($value)) {
      $value = preg_split('/[\r\n,;]+/', $value);
    }

    if (!is_array($value)) {
      return [];
    }

    $emails = [];
    foreach ($value as $item) {
      $email = strtolower(trim((string)$item));
      if ($email === '') continue;
      if (!is_email($email)) continue;
      if (!preg_match('/@gmail\.com$/', $email)) continue;
      $emails[] = $email;
    }

    return array_values(array_unique($emails));
  }

  /**
   * Renders the main plugin Settings page (wp-admin).
   *
   * This is where staff configure:
   * - API key (or constant in wp-config.php)
   * - model names and temperatures
   * - limits and UI text
   * - security toggles
   * - logging retention
   */
  public function admin_page() {
    if (!current_user_can('manage_options')) return;
    $cfg = $this->get_settings();

    ?>
    <div class="wrap">
      <h1>Levinsky Chatbot</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('levinsky_chatbot_group');
          $opt = self::OPT_KEY;
        ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Enabled</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($cfg['enabled'], 1); ?>> Enable chatbot</label></td>
          </tr>

          <tr>
            <th scope="row">OpenAI API key</th>
            <td>
              <input type="password" style="width:420px;" name="<?php echo esc_attr($opt); ?>[openai_api_key]" value="<?php echo esc_attr($cfg['openai_api_key']); ?>" autocomplete="off">
              <p class="description">
                It's recommended to define in wp-config.php: <code>define('LEVINSKY_OPENAI_API_KEY','sk-...');</code> and then to leave this setting empty.
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">Models</th>
            <td>
              Triage model: <input type="text" name="<?php echo esc_attr($opt); ?>[openai_model_triage]" value="<?php echo esc_attr($cfg['openai_model_triage']); ?>">
              <br><br>
              Merge model: <input type="text" name="<?php echo esc_attr($opt); ?>[openai_model_merge]" value="<?php echo esc_attr($cfg['openai_model_merge']); ?>">
            </td>
          </tr>

          <tr>
            <th scope="row">Limits (rolling 24h)</th>
            <td>
              Per-user: <input type="number" min="0" name="<?php echo esc_attr($opt); ?>[user_limit_24h]" value="<?php echo (int)$cfg['user_limit_24h']; ?>">
              <br><br>
              Global: <input type="number" min="0" name="<?php echo esc_attr($opt); ?>[global_limit_24h]" value="<?php echo (int)$cfg['global_limit_24h']; ?>">
              <br><br>
              Window seconds (default 86400): <input type="number" min="60" name="<?php echo esc_attr($opt); ?>[window_seconds]" value="<?php echo (int)$cfg['window_seconds']; ?>">
            </td>
          </tr>

          <tr>
            <th scope="row">UI</th>
            <td>
              Bubble title: <input type="text" name="<?php echo esc_attr($opt); ?>[bubble_title]" value="<?php echo esc_attr($cfg['bubble_title']); ?>">
              <br><br>
              Opening line:<br>
              <textarea rows="3" style="width:520px;" name="<?php echo esc_attr($opt); ?>[opening_line]"><?php echo esc_textarea($cfg['opening_line']); ?></textarea>
              <br><br>
              Max message chars: <input type="number" min="50" name="<?php echo esc_attr($opt); ?>[max_message_chars]" value="<?php echo (int)$cfg['max_message_chars']; ?>">
            </td>
          </tr>

          <tr>
            <th scope="row">Security</th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[require_same_origin]" value="1" <?php checked($cfg['require_same_origin'], 1); ?>> Require same-origin (Origin/Referer check)</label>
              <br>
              <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[trust_x_forwarded_for]" value="1" <?php checked($cfg['trust_x_forwarded_for'], 1); ?>> Trust X-Forwarded-For (enable only if you control proxy)</label>
            </td>
          </tr>

          <tr>
            <th scope="row">Behavior</th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enable_merge_call]" value="1" <?php checked($cfg['enable_merge_call'], 1); ?>> Enable empathy merge call (2nd OpenAI call)</label>
              <br><br>
              Store last Q/A pairs: <input type="number" min="0" max="5" name="<?php echo esc_attr($opt); ?>[store_history_pairs]" value="<?php echo (int)$cfg['store_history_pairs']; ?>">
            </td>
          </tr>

          <tr>
            <th scope="row">Logging</th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[log_enabled]" value="1" <?php checked($cfg['log_enabled'], 1); ?>> Enable DB logs</label>
              <br><br>
              Retention days: <input type="number" min="1" name="<?php echo esc_attr($opt); ?>[log_retention_days]" value="<?php echo (int)$cfg['log_retention_days']; ?>">
              <p class="description">The logs are saved in the DB and are automatically deleted (if needed) when calling the chat.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Error notifications</th>
            <td>
              <textarea rows="5" style="width:520px;" name="<?php echo esc_attr($opt); ?>[error_notification_gmails]"><?php echo esc_textarea(implode("\n", isset($cfg['error_notification_gmails']) && is_array($cfg['error_notification_gmails']) ? $cfg['error_notification_gmails'] : [])); ?></textarea>
              <p class="description">Add one Gmail address per line. These addresses will be notified whenever the bot returns: <code>מצטער/ת, הייתה תקלה. נסו שוב בעוד רגע.</code></p>
            </td>
          </tr>
        </table>

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  // -------------------------
  // Frontend assets + bubble
  // -------------------------
  /**
   * Enqueues the bubble UI CSS/JS on the public site.
   *
   * - Injects CSS/JS inline.
   * - Exposes the REST endpoint and max message length to the frontend script.
   */
  public function enqueue_assets() {
    $cfg = $this->get_settings();
    if (empty($cfg['enabled'])) return;

    wp_register_style('levinsky-chatbot-style', false);
    wp_enqueue_style('levinsky-chatbot-style');
    wp_add_inline_style('levinsky-chatbot-style', $this->css());

    wp_register_script('levinsky-chatbot-script', false, [], false, true);
    wp_enqueue_script('levinsky-chatbot-script');

    $endpoint = wp_make_link_relative(rest_url('levinsky/v1/chat'));
    wp_add_inline_script(
      'levinsky-chatbot-script',
      'window.LEVINSKY_CHAT_ENDPOINT=' . wp_json_encode($endpoint) . ';' .
      'window.LEVINSKY_CHAT_MAX_CHARS=' . intval($cfg['max_message_chars']) . ';',
      'before'
    );

    wp_add_inline_script('levinsky-chatbot-script', $this->js());
  }

  /**
   * Outputs the bubble HTML markup into the site footer.
   *
   * The UI is rendered only when the plugin is enabled.
   */
  public function render_bubble() {
    $cfg = $this->get_settings();
    if (empty($cfg['enabled'])) return;

    $title = esc_html($cfg['bubble_title']);
    $opening = esc_html($cfg['opening_line']);
    ?>
    <div id="lv-bubble" class="lv-bubble" aria-label="Open chat" role="button" tabindex="0">
      <img src="<?php echo esc_url($this->bubble_icon_url()); ?>" alt="Chat" class="lv-bubble-icon">
    </div>

    <div id="lv-panel" class="lv-panel" aria-hidden="true">
      <div class="lv-header">
        <div class="lv-title"><?php echo $title; ?></div>
        <button id="lv-close" class="lv-close" type="button" aria-label="Close chat">✕</button>
      </div>

      <div id="lv-messages" class="lv-messages">
        <div class="lv-msg lv-msg-bot"><?php echo $opening; ?></div>
      </div>

      <form id="lv-form" class="lv-form">
        <div class="lv-input-wrap">
          <textarea id="lv-input" class="lv-input" rows="1" placeholder="כתוב/כתבי הודעה..." autocomplete="off"></textarea>
          <div class="lv-counter" id="lv-counter"></div>
        </div>
        <button class="lv-send" type="submit">שלח</button>
      </form>
    </div>
    <?php
  }

  /**
   * Returns the bubble UI stylesheet as a string.
   *
   * It is injected as inline CSS via wp_add_inline_style().
   */
  private function css() {
    return "
    .lv-bubble{
      position:fixed; right:18px; bottom:18px;
      width:68px; height:68px; border-radius:999px;
      display:flex; align-items:center; justify-content:center;
      cursor:pointer; user-select:none;
      background:#111; color:#fff; box-shadow:0 10px 25px rgba(0,0,0,.25);
      z-index:999999; overflow:hidden;
      transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
    }

    .lv-bubble:hover{
      transform: scale(1.04);
    }

    .lv-bubble.lv-busy{
      animation: lv-bubble-pulse 1.1s infinite ease-in-out;
      opacity:.92;
    }

    .lv-bubble-icon{
      width: 78px;
      height: 78px;
      object-fit: cover;
      display: block;
      pointer-events: none;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .lv-panel{
      position:fixed; right:18px; bottom:84px;
      width:320px; max-width:calc(100vw - 36px);
      height:420px; max-height:calc(100vh - 140px);
      background:#fff; border-radius:14px; box-shadow:0 14px 35px rgba(0,0,0,.28);
      overflow:hidden; display:none; z-index:999999;
      border:1px solid rgba(0,0,0,.08);
    }
    .lv-panel.lv-open{ display:flex; flex-direction:column; }

    .lv-header{
      padding:10px 12px; display:flex; align-items:center; justify-content:space-between;
      border-bottom:1px solid rgba(0,0,0,.08); background:#fafafa;
    }
    .lv-title{ font-weight:600; font-size:14px; }
    .lv-close{ border:0; background:transparent; cursor:pointer; font-size:16px; padding:6px 8px; line-height:1; }

    .lv-messages{
      padding:12px; flex:1; overflow:auto;
      display:flex; flex-direction:column; gap:10px; background:#fff;
    }

    .lv-msg{
      max-width:82%;
      padding:10px 10px; border-radius:12px;
      font-size:13px; line-height:1.35;
      border:1px solid rgba(0,0,0,.06);
      word-wrap:break-word; white-space:pre-wrap;
    }
    .lv-msg-bot{ background:#f6f6f6; align-self:flex-start; }
    .lv-msg-user{ background:#111; color:#fff; align-self:flex-end; border-color:#111; }

    .lv-form{
      display:flex; gap:8px; padding:10px;
      border-top:1px solid rgba(0,0,0,.08); background:#fafafa;
      align-items:flex-end;
    }

    .lv-input-wrap{ flex:1; display:flex; flex-direction:column; gap:6px; }
    .lv-input{
      width:100%;
      border:1px solid rgba(0,0,0,.18);
      border-radius:10px; padding:10px 10px; font-size:13px; outline:none;
      resize:none; overflow:hidden; min-height:40px; max-height:120px; line-height:1.25;
    }
    .lv-counter{
      font-size:11px; color:rgba(0,0,0,.55); text-align:left;
      min-height:12px;
    }
    .lv-counter.lv-limit{ color:#b42318; font-weight:600; }

    .lv-send{
      border:0; border-radius:10px;
      padding:10px 12px; cursor:pointer;
      background:#111; color:#fff; font-size:13px; height:40px;
    }

    .lv-input:disabled{
      background:#f3f4f6;
      cursor:not-allowed;
      opacity:.85;
    }

    .lv-send:disabled{
      opacity:.55;
      cursor:not-allowed;
    }

    .lv-typing{ display:inline-flex; align-items:center; gap:6px; height:14px; }
    .lv-dot{
      width:6px; height:6px; border-radius:999px; background:rgba(0,0,0,.55);
      display:inline-block; animation: lv-bounce 1s infinite ease-in-out;
    }
    .lv-dot:nth-child(2){ animation-delay: 0.15s; }
    .lv-dot:nth-child(3){ animation-delay: 0.30s; }
    @keyframes lv-bounce{
      0%,80%,100% { transform: translateY(0); opacity:.45; }
      40% { transform: translateY(-5px); opacity:1; }
    }

    @keyframes lv-bubble-pulse{
      0%, 100% { transform: scale(1); box-shadow:0 10px 25px rgba(0,0,0,.25); }
      50% { transform: scale(1.07); box-shadow:0 12px 30px rgba(0,0,0,.35); }
    }

    @media (max-width:420px){
      .lv-panel{ width:calc(100vw - 36px); height:60vh; }
    }
    ";
  }

  /**
   * Returns the bubble UI JavaScript as a string.
   *
   * It is injected as inline JS via wp_add_inline_script().
   * The script:
   * - Opens/closes the panel
   * - Enforces max length on the client
   * - Disables input while a request is pending
   * - Calls the WP REST endpoint and displays the reply
   */
  private function js() {
    return <<<'JS'
(function(){
  const bubble = document.getElementById('lv-bubble');
  const panel  = document.getElementById('lv-panel');
  const close  = document.getElementById('lv-close');
  const form   = document.getElementById('lv-form');
  const input  = document.getElementById('lv-input');
  const msgs   = document.getElementById('lv-messages');
  const counter= document.getElementById('lv-counter');
  const sendBtn = form.querySelector('.lv-send');

  if (!bubble || !panel || !close || !form || !input || !msgs || !sendBtn) return;

  const TEXTAREA_MAX_PX = 120;
  const MAX_CHARS = Number.isFinite(window.LEVINSKY_CHAT_MAX_CHARS) ? window.LEVINSKY_CHAT_MAX_CHARS : 800;
  let pending = false;

  function setPendingState(isPending){
    pending = !!isPending;
    sendBtn.disabled = pending;
    input.disabled = pending;
    bubble.classList.toggle('lv-busy', pending);
    panel.setAttribute('aria-busy', pending ? 'true' : 'false');

    if (!pending) {
      input.focus();
      autoResize();
    }
  }

  function autoResize(){
    input.style.height = 'auto';
    const h = Math.min(input.scrollHeight, TEXTAREA_MAX_PX);
    input.style.height = h + 'px';
  }

  function updateCounter(){
    const len = (input.value || '').length;
    if (!counter) return;
    counter.textContent = `${len}/${MAX_CHARS}`;
    if (len >= MAX_CHARS) counter.classList.add('lv-limit');
    else counter.classList.remove('lv-limit');
  }

  // block extra typing beyond MAX_CHARS, allow deletions
  function enforceMaxChars(){
    const v = input.value || '';
    if (v.length > MAX_CHARS) input.value = v.slice(0, MAX_CHARS);
  }

  input.addEventListener('input', () => {
    enforceMaxChars();
    autoResize();
    updateCounter();
  });

  // Allow paste but clamp
  input.addEventListener('paste', () => {
    setTimeout(() => {
      enforceMaxChars();
      autoResize();
      updateCounter();
    }, 0);
  });

  autoResize();
  updateCounter();

  // Enter sends, Shift+Enter newline
  input.addEventListener('keydown', (e) => {
    if (pending) {
      e.preventDefault();
      return;
    }

    const len = (input.value || '').length;

    // prevent adding more chars at limit (except navigation/backspace/delete)
    const allowedKeys = ['Backspace','Delete','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End','Tab'];
    if (len >= MAX_CHARS && !allowedKeys.includes(e.key) && !(e.ctrlKey || e.metaKey)) {
      if (e.key.length === 1) { // printable
        e.preventDefault();
        return;
      }
    }

    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (form.requestSubmit) form.requestSubmit();
      else form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });

  function openChat(){
    panel.classList.add('lv-open');
    panel.setAttribute('aria-hidden','false');
    if (!pending) input.focus();
    autoResize();
  }
  function closeChat(){
    panel.classList.remove('lv-open');
    panel.setAttribute('aria-hidden','true');
  }
  function toggleChat(){
    panel.classList.contains('lv-open') ? closeChat() : openChat();
  }

  bubble.addEventListener('click', toggleChat);
  bubble.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleChat(); }
  });
  close.addEventListener('click', closeChat);

  // click outside closes
  document.addEventListener('click', (e) => {
    if (!panel.classList.contains('lv-open')) return;
    if (panel.contains(e.target) || bubble.contains(e.target)) return;
    closeChat();
  });

  function appendMessage(text, who){
    const el = document.createElement('div');
    el.className = 'lv-msg ' + (who === 'user' ? 'lv-msg-user' : 'lv-msg-bot');
    el.textContent = text;
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    return el;
  }

  function appendTyping(){
    const el = document.createElement('div');
    el.className = 'lv-msg lv-msg-bot';
    const typing = document.createElement('span');
    typing.className = 'lv-typing';
    typing.innerHTML = '<span class="lv-dot"></span><span class="lv-dot"></span><span class="lv-dot"></span>';
    el.appendChild(typing);
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    return el;
  }

  async function sendToBot(text){
    const endpoint = window.LEVINSKY_CHAT_ENDPOINT || '/wp-json/levinsky/v1/chat';
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text })
    });

    if (!res.ok) {
      const raw = await res.text().catch(() => '');
      throw new Error(raw || ('HTTP ' + res.status));
    }
    return await res.json();
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (pending) return;

    let text = (input.value || '').trim();
    if (!text) return;
    if (text.length > MAX_CHARS) text = text.slice(0, MAX_CHARS);

    setPendingState(true);

    appendMessage(text, 'user');
    input.value = '';
    autoResize();
    updateCounter();

    const typingEl = appendTyping();

    try {
      const data = await sendToBot(text);
      const reply = (data.reply || '').toString().trim() || 'מצטער/ת, לא התקבלה תשובה.';
      typingEl.textContent = reply;
      msgs.scrollTop = msgs.scrollHeight;
    } catch (err) {
      console.error('Chat error:', err);
      typingEl.textContent = 'מצטער/ת, הייתה תקלה בשליחת ההודעה. נסו שוב בעוד רגע.';
      msgs.scrollTop = msgs.scrollHeight;
    } finally {
      setPendingState(false);
    }
  });
})();
JS;
  }

  // -------------------------
  // REST API
  // -------------------------
  /**
   * Registers public REST endpoints:
   *
   * - POST /wp-json/levinsky/v1/chat
   * - GET  /wp-json/levinsky/v1/health
   */
  public function register_routes() {
    register_rest_route('levinsky/v1', '/chat', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_chat'],
      'permission_callback' => '__return_true', // public chat
      'args' => [
        'message' => [
          'required' => true,
          'type' => 'string',
        ],
      ],
    ]);

    register_rest_route('levinsky/v1', '/health', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_health'],
      'permission_callback' => '__return_true',
    ]);
  }

  /**
   * Health endpoint.
   *
   * Returns basic configuration and current global counter state.
   * Useful for smoke tests and monitoring.
   */
  public function rest_health(\WP_REST_Request $req) {
    $cfg = $this->get_settings();
    $global = $this->get_global_usage(false);

    return new \WP_REST_Response([
      'ok' => true,
      'enabled' => (bool)$cfg['enabled'],
      'limits' => [
        'user_limit_24h' => (int)$cfg['user_limit_24h'],
        'global_limit_24h' => (int)$cfg['global_limit_24h'],
        'window_seconds' => (int)$cfg['window_seconds'],
      ],
      'global' => $global,
    ], 200);
  }

  /**
   * Checks whether this is the first message for the given user.
   *
   * Implementation note:
   * - Uses the history table as the source of truth.
   * - If there is no history row for this user_key, we treat it as the first message.
   */
  private function is_first_message_for_user($user_key) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$this->t_history} WHERE user_key=%s",
      $user_key
  ));
  return ((int)$exists === 0);
}

  /**
   * Counts words in a user message.
   *
   * - Normalizes punctuation to spaces.
   * - Splits on whitespace.
   * - Designed for Hebrew/Unicode input (uses \p{L} and \p{N}).
   */
  private function count_words($text) {
    $text = trim((string)$text);
    if ($text === '') return 0;
    $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $parts = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}

  /**
   * Main chat endpoint.
   *
   * High-level flow:
   * 1) Validate and optionally truncate the incoming message.
   * 2) Identify the user (hashed from IP + User-Agent).
   * 3) If first message is too short, reply with a guidance message (no OpenAI call, no limits).
   * 4) Enforce global limit, then per-user limit (rolling window).
   * 5) Build context from limited server-side history.
   * 6) Call OpenAI triage, optionally call OpenAI merge.
   * 7) Save history, increment counters, write a log event.
   */
  public function rest_chat(\WP_REST_Request $req) {
    $cfg = $this->get_settings();

    if (empty($cfg['enabled'])) {
      return new \WP_REST_Response(['reply' => 'השירות אינו פעיל כרגע.'], 503);
    }

    // Best-effort cleanup logs
    $this->cleanup_logs_if_needed();

    // Same-origin check (best effort)
    if (!empty($cfg['require_same_origin']) && !$this->is_same_origin_request()) {
      $this->log_event('blocked_origin', null, null, $this->safe_excerpt((string)$req['message'], 500), null, null, ['reason' => 'origin_mismatch']);
      return new \WP_REST_Response(['reply' => 'לא ניתן לעבד את הבקשה.'], 403);
    }

    $message = trim((string)$req['message']);
    if ($message === '') {
      return new \WP_REST_Response(['reply' => 'אנא כתבו הודעה כדי שנוכל לעזור.'], 400);
    }

    // Server-side message length limit
    $maxChars = (int)$cfg['max_message_chars'];
    if ($maxChars > 0 && mb_strlen($message, 'UTF-8') > $maxChars) {
      $message = mb_substr($message, 0, $maxChars, 'UTF-8');
    }

    // Identify user
    [$user_key, $ip, $ua] = $this->get_user_identity();
    if ($this->is_first_message_for_user($user_key)) {
      $wc = $this->count_words($message);
    if ($wc > 0 && $wc < 5) {
      $reply = "כדי שאוכל לעזור בצורה טובה יותר, כתבו בבקשה שאלה מפורטת יותר. למשל: מה קרה, מתי זה התחיל, האם יש תסמינים, והאם הייתה חשיפה אפשרית.";
      $this->log_event('first_message_too_short', $user_key, $ip, $this->safe_excerpt($message, 500), $this->safe_excerpt($reply, 500), null, [
      'word_count' => $wc
    ]);
    return new \WP_REST_Response(['reply' => $reply], 200);
    }
  }
    // 1) Global limit (rolling window)
    $g = $this->get_global_usage(true);
    if (!$g['allowed']) {
      $reply = "נכון להיום השירות עמוס והגענו למגבלת הפניות ב-24 השעות האחרונות. אפשר לנסות שוב מאוחר יותר. אם יש תסמינים חריגים או דאגה רפואית, מומלץ לפנות לרופא/ה או למוקד רפואי.";
      $this->log_event('blocked_global_limit', $user_key, $ip, $this->safe_excerpt($message, 1200), $this->safe_excerpt($reply, 1200), null, [
        'global_count' => $g['count'],
        'global_remaining' => $g['remaining'],
      ]);
      return new \WP_REST_Response(['reply' => $reply], 200);
    }

    // 2) User limit (rolling window)
    $u = $this->get_user_usage($user_key, true);
    if (!$u['allowed']) {
      $reply = "הגעת למגבלת ההודעות ל-24 שעות בערוץ הזה. אם יש תסמינים או אם יש דאגה מחשיפה, מומלץ לפנות לרופא/ה בקהילה או למרפאה לבריאות מינית.";
      $this->log_event('blocked_user_limit', $user_key, $ip, $this->safe_excerpt($message, 1200), $this->safe_excerpt($reply, 1200), null, [
        'user_count' => $u['count'],
        'user_remaining' => $u['remaining'],
      ]);
      return new \WP_REST_Response(['reply' => $reply], 200);
    }

    // Load history (server-side)
    $history = $this->load_history($user_key, (int)$cfg['store_history_pairs']);
    $context_text = $this->build_context_user_text($message, $history);

    // Call OpenAI triage
    $start = microtime(true);

    try {
      $triage = $this->openai_triage($context_text, $cfg);
      [$final_reply, $assistant_answer_for_history, $triage_no_answer] = $this->compose_final_answer($triage, $cfg);

      // Save history (store final reply as "a")
      $history[] = ['q' => $message, 'a' => $assistant_answer_for_history];
      $this->save_history($user_key, $history, (int)$cfg['store_history_pairs']);

      // increment counters AFTER success
      $this->increment_user_usage($user_key, $cfg);
      $this->increment_global_usage($cfg);

      $latency_ms = (int)((microtime(true) - $start) * 1000);

      $this->log_event('allowed_success', $user_key, $ip, $this->safe_excerpt($message, 1200), $this->safe_excerpt($final_reply, 1200), $triage_no_answer, [
        'latency_ms' => $latency_ms,
        'ua' => $ua,
      ]);

      return new \WP_REST_Response(['reply' => $final_reply], 200);

    } catch (\Exception $e) {
      $reply = 'מצטער/ת, הייתה תקלה. נסו שוב בעוד רגע.';
      $this->log_event('allowed_error', $user_key, $ip, $this->safe_excerpt($message, 1200), $this->safe_excerpt($reply, 1200), null, [
        'error' => $e->getMessage(),
      ]);
      $this->send_error_notification_emails($message, $user_key, $ip, $ua, $e);
      return new \WP_REST_Response(['reply' => $reply], 200);
    }
  }

  // -------------------------
  // Prompts + clinic strings
  // -------------------------
  /**
   * Returns the system prompt used for the triage OpenAI call.
   *
   * The model must return JSON with fixed keys so the plugin can reliably parse it.
   */
  private function triage_prompt() {
    return <<<PROMPT
You are a sexual-health clinic navigator for educational triage only.
You do NOT diagnose and do NOT recommend medications or specific treatments.

Return ONLY valid JSON with EXACT keys:
- assistant_answer (string, in Hebrew)
- is_sexual_health_related (boolean)
- is_symptoms_present (boolean)
- is_exposure_risk_present (boolean)
- should_seek_care (boolean)
- care_urgency (string enum: EMERGENCY_NOW, URGENT_CLINIC_24_48H, CLINIC_SOON_3_7D, ROUTINE_TESTING, DONT_NEED_TO_COME_AT_ALL, SELF_CARE_INFO_ONLY, UNCLEAR_NEED_MORE_INFO)
- is_sensitive_case (boolean)
- sensitive_reasons (array of strings from: sexual_assault_or_coercion, minor_under_18, trafficking_or_exploitation, domestic_violence_threat, suicidal_ideation)
- safety_red_flags (array of strings from: high_fever, severe_pelvic_pain, severe_testicular_pain, fainting, pregnancy_with_symptoms, sexual_assault, suicidal_ideation, severe_allergic_reaction, uncontrolled_bleeding)
- confidence (number 0.0-1.0)
- user_gender (string enum: male, female, neutral, unknown)

The text you receive may contain sensitive content. Be empathetic and non-judgmental in your assistant_answer.

Rules:
1) If safety_red_flags is non-empty => care_urgency=EMERGENCY_NOW and should_seek_care=true.
2) If not sexual-health related => care_urgency=SELF_CARE_INFO_ONLY and should_seek_care=false.
3) is_sensitive_case MUST be true ONLY if the text clearly indicates at least one sensitive_reasons item.
   If uncertain, set is_sensitive_case=false and sensitive_reasons=[].
4) If is_sensitive_case=true then sensitive_reasons must be non-empty. Otherwise must be [].

assistant_answer rules:
- Write in Hebrew, friendly and non-judgmental.
- If is_sexual_health_related is false, assistant_answer MUST be an empty string "" (exactly).
- Give general explanation and safe next steps, but do NOT diagnose and do NOT recommend medications.
- BE EMPATHETIC and REASSURING.
- Keep it short (6-10 sentences).
- Do NOT include the care_urgency labels inside assistant_answer.
- End with one short disclaimer sentence: "המידע כאן כללי ואינו תחליף לייעוץ רפואי."

assistant_answer rules (additional):
- Use gendered Hebrew language that matches user_gender:
  * female → את / אותך / שלך
  * male → אתה / אותך / שלך
  * neutral or unknown → פנייה ניטרלית (אפשר "אפשר לשקול", בלי פנייה ישירה)

Gender rules:
- If the user explicitly refers to themselves in feminine form → user_gender=female
- If the user explicitly refers to themselves in masculine form → user_gender=male
- If unclear or mixed → user_gender=neutral
- NEVER guess gender from symptoms alone

Output JSON only; no extra keys, no extra text.
PROMPT;
  }

  /**
   * Returns the system prompt used for the optional merge OpenAI call.
   *
   * This call turns two texts (assistant_answer + clinic guidance) into one natural
   * Hebrew response.
   */
  private function empathy_merge_prompt() {
    return <<<PROMPT
You are an empathetic Hebrew-speaking health assistant.

You will receive:
1) A clinical educational explanation (assistant_answer)
2) A short guidance message from the clinic (custom_answer)
3) user_gender (male/female/neutral/unknown)

Your task:
- Merge them into ONE single empathetic, natural Hebrew response.
- Keep it supportive, empathetic, calm, non-judgmental.
- Do NOT add medical diagnoses or medications.
- Do NOT contradict the guidance.
- If assistant_answer is empty, gently rephrase only the custom_answer.
- Do NOT restate the classification (e.g. sexual health related).
- Start directly with neutral factual context.
- Length: 6-8 sentences.
- End with exactly this sentence:
"המידע כאן כללי ואינו תחליף לייעוץ רפואי."

Rules:
- Match Hebrew gender to user_gender
- If neutral/unknown, avoid gendered verbs and pronouns

Return ONLY plain text in Hebrew. No JSON.
PROMPT;
  }

  /**
   * Returns the clinic guidance messages by category.
   *
   * These are used as a deterministic layer on top of the model output.
   */
  private function custom_answers() {
    return [
      "SENSITIVE" => "אני מצטער/ת שאת/ה מתמודד/ת עם זה. אם מדובר בכפייה/תקיפה או אם את/ה לא מרגיש/ה בטוח/ה, מומלץ לפנות לשירותי החירום או לארגון תמיכה אמין. אם יש סכנה מיידית - התקשר/י עכשיו למספר החירום.",
      "EMERGENCY_NOW" => "מומלץ לפנות עכשיו לקבלת טיפול רפואי דחוף (מוקד חירום/מיון). אם התסמינים חמורים או שיש סכנה מיידית - התקשר/י למספר החירום.",
      "URGENT_CLINIC_24_48H" => "הכי בטוח להיבדק במרפאה בתוך 24-48 שעות, ניתן לקבוע תור באתר. אם מתפתח חום, כאב חזק, עילפון או החמרה מהירה - פנה/י לטיפול דחוף.",
      "CLINIC_SOON_3_7D" => "מומלץ לשקול להגיע למרפאה בימים הקרובים, ניתן לקבוע תור באתר. אם התסמינים מחמירים או מתפתח כאב חזק או חום - פנה/י לטיפול דחוף.",
      "ROUTINE_TESTING" => "גם אם אין תסמינים, ההמלצה היא לבצע בדיקות שגרתיות למחלות מין לאחר חשיפות מסוימות. אפשר לקבוע בדיקה דרך האתר. אם מופיעים תסמינים - פנה/י מוקדם יותר.",
      "DONT_NEED_TO_COME_AT_ALL" => "לפי מה שנכתב, לא נראה שיש צורך להגיע למרפאה כרגע. אם יש דאגה או אם מופיעים תסמינים חדשים - אפשר לשקול בדיקות שגרתיות או לפנות לבדיקה.",
      "SELF_CARE_INFO_ONLY" => "לפי מה שנכתב זה לא נשמע דחוף. אם עדיין יש דאגה, אפשר לעקוב אחרי התסמינים ולשקול בדיקות שגרתיות. אם מופיעים תסמינים או שיש החמרה - מומלץ לפנות לבדיקה.",
      "UNCLEAR_NEED_MORE_INFO" => "כדי לעזור בצורה טובה יותר, צריך עוד פרטים: (1) האם יש תסמינים כרגע? (2) מתי הייתה החשיפה? (3) האם יש חום או כאב חזק?",
      "NOT_SEXUAL_HEALTH" => "ממה שתואר, זה לא נשמע קשור לבריאות מינית. אם התכוונת לנושא של בריאות מינית, אפשר לפרט תסמינים או חשיפה אפשרית."
    ];
  }

  /**
   * Returns the allowlist for sensitive reasons.
   *
   * The model output is filtered through this list to avoid unexpected values.
   */
  private function allowed_sensitive_reasons() {
    return [
      "sexual_assault_or_coercion",
      "minor_under_18",
      "trafficking_or_exploitation",
      "domestic_violence_threat",
      "suicidal_ideation",
    ];
  }

  // -------------------------
  // OpenAI calls (PHP)
  // -------------------------
  /**
   * Resolves the OpenAI API key.
   *
   * Priority:
   * 1) Constant defined in wp-config.php (LEVINSKY_OPENAI_API_KEY)
   * 2) Saved setting in the plugin settings page
   */
  private function get_openai_key($cfg) {
    if (defined('LEVINSKY_OPENAI_API_KEY') && LEVINSKY_OPENAI_API_KEY) {
      return (string)LEVINSKY_OPENAI_API_KEY;
    }
    return (string)$cfg['openai_api_key'];
  }

  /**
   * Performs the OpenAI triage request.
   *
   * - Uses the Chat Completions endpoint.
   * - Forces JSON output via response_format.
   * - Throws exceptions on HTTP errors or invalid JSON.
   */
  private function openai_triage($text, $cfg) {
    $api_key = $this->get_openai_key($cfg);
    if (!$api_key) {
      throw new \Exception('Missing OpenAI API key');
    }

    $body = [
      'model' => (string)$cfg['openai_model_triage'],
      'messages' => [
        ['role' => 'system', 'content' => $this->triage_prompt()],
        ['role' => 'user', 'content' => (string)$text],
      ],
      'temperature' => (float)$cfg['temperature_triage'],
      'response_format' => ['type' => 'json_object'],
    ];

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 30,
    ]);

    if (is_wp_error($res)) {
      throw new \Exception($res->get_error_message());
    }

    $code = (int)wp_remote_retrieve_response_code($res);
    $raw  = (string)wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
      throw new \Exception("OpenAI HTTP $code: " . $this->safe_excerpt($raw, 300));
    }

    $data = json_decode($raw, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!$content) throw new \Exception('Empty model response');

    $triage = json_decode($content, true);
    if (!is_array($triage)) throw new \Exception('Invalid JSON from model');
    return $triage;
  }

  /**
   * Performs the optional OpenAI merge request.
   *
   * - Uses the Chat Completions endpoint.
   * - Returns plain Hebrew text.
   * - Throws exceptions on HTTP errors or empty outputs.
   */
  private function openai_merge($assistant_answer, $custom_answer, $user_gender, $cfg) {
    $api_key = $this->get_openai_key($cfg);
    if (!$api_key) {
      throw new \Exception('Missing OpenAI API key');
    }

    $content = trim(
      "הסבר חינוכי:\n" . ($assistant_answer ? $assistant_answer : "[אין]") .
      "\n\nהנחיה מהמרפאה:\n" . $custom_answer .
      "\n\nמגדר משתמש:\n" . ($user_gender ? $user_gender : "neutral")
    );

    $body = [
      'model' => (string)$cfg['openai_model_merge'],
      'messages' => [
        ['role' => 'system', 'content' => $this->empathy_merge_prompt()],
        ['role' => 'user', 'content' => $content],
      ],
      'temperature' => (float)$cfg['temperature_merge'],
    ];

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 30,
    ]);

    if (is_wp_error($res)) {
      throw new \Exception($res->get_error_message());
    }

    $code = (int)wp_remote_retrieve_response_code($res);
    $raw  = (string)wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
      throw new \Exception("OpenAI merge HTTP $code: " . $this->safe_excerpt($raw, 300));
    }

    $data = json_decode($raw, true);
    $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    if (!$text) throw new \Exception('Empty merged answer');
    return $text;
  }

  // -------------------------
  // Triage composition logic
  // -------------------------
  /**
   * Normalizes sensitive fields in the triage JSON.
   *
   * - Filters sensitive_reasons to the allowlist.
   * - Sets is_sensitive_case based on filtered reasons.
   */
  private function enforce_sensitive_gate(&$triage) {
    $allowed = $this->allowed_sensitive_reasons();
    $reasons = $triage['sensitive_reasons'] ?? [];
    if (!is_array($reasons)) $reasons = [];
    $valid = [];
    foreach ($reasons as $r) {
      if (in_array($r, $allowed, true)) $valid[] = $r;
    }
    $triage['sensitive_reasons'] = $valid;
    $triage['is_sensitive_case'] = (count($valid) > 0);
  }

  /**
   * Maps triage data to a deterministic clinic guidance message.
   *
   * Order of precedence:
   * 1) Sensitive case
   * 2) Safety red flags
   * 3) Not sexual-health related
   * 4) care_urgency mapping
   * 5) Fallback to UNCLEAR_NEED_MORE_INFO
   */
  private function build_custom_response($triage) {
    $ca = $this->custom_answers();

    if (!empty($triage['is_sensitive_case']) && !empty($triage['sensitive_reasons']) && is_array($triage['sensitive_reasons'])) {
      return $ca['SENSITIVE'];
    }

    if (!empty($triage['safety_red_flags']) && is_array($triage['safety_red_flags']) && count($triage['safety_red_flags']) > 0) {
      return $ca['EMERGENCY_NOW'];
    }

    if (array_key_exists('is_sexual_health_related', $triage) && $triage['is_sexual_health_related'] === false) {
      return $ca['NOT_SEXUAL_HEALTH'];
    }

    $urgency = $triage['care_urgency'] ?? 'UNCLEAR_NEED_MORE_INFO';
    return $ca[$urgency] ?? $ca['UNCLEAR_NEED_MORE_INFO'];
  }

  /**
   * Builds the final reply text shown to the user.
   *
   * - Extracts assistant_answer from the model JSON.
   * - Chooses a custom clinic guidance message.
   * - Optionally calls merge model to combine them.
   * - Returns: (final_reply, reply_for_history, triage_without_assistant_answer).
   */
  private function compose_final_answer($model_json, $cfg) {
    $assistant_answer = trim((string)($model_json['assistant_answer'] ?? ''));

    $triage = $model_json;
    unset($triage['assistant_answer']);

    $this->enforce_sensitive_gate($triage);
    $custom = $this->build_custom_response($triage);

    if (array_key_exists('is_sexual_health_related', $triage) && $triage['is_sexual_health_related'] === false) {
      $assistant_answer = '';
    }

    $user_gender = $triage['user_gender'] ?? 'neutral';
    if (!$user_gender) $user_gender = 'neutral';

    // merged or fallback
    $final = '';
    if (!empty($cfg['enable_merge_call'])) {
      try {
        $final = $this->openai_merge($assistant_answer, $custom, $user_gender, $cfg);
      } catch (\Exception $e) {
        $final = '';
      }
    }

    if (!$final) {
      if ($assistant_answer) $final = $assistant_answer . "\n\n" . "הכוונה מהירה מהמרפאה: " . $custom;
      else $final = "הכוונה מהירה מהמרפאה: " . $custom;
    }

    // What we store in history (keep it short but useful)
    $assistant_answer_for_history = $final;

    return [$final, $assistant_answer_for_history, $triage];
  }

  /**
   * Builds the contextual text that is sent to the triage model.
   *
   * Only the last N Q/A pairs (configured) are included to limit tokens and PII.
   */
  private function build_context_user_text($current_question, $history) {
    if (!is_array($history) || count($history) === 0) return trim((string)$current_question);

    $parts = ["הקשר לשיחה (שאלות קודמות ותשובות):"];
    $idx = 1;
    foreach ($history as $item) {
      $q = trim((string)($item['q'] ?? ''));
      $a = trim((string)($item['a'] ?? ''));
      if ($q !== '') {
        $parts[] = "שאלה {$idx}: {$q}";
        $parts[] = "תשובה {$idx}: " . ($a !== '' ? $a : "[לא ניתנה תשובה כי לא קשור לבריאות מינית]");
        $idx++;
      }
    }
    $parts[] = "השאלה הנוכחית:";
    $parts[] = trim((string)$current_question);
    return implode("\n", $parts);
  }

  // -------------------------
  // Usage + history in DB
  // -------------------------
  /**
   * Builds a stable (best-effort) user identity key.
   *
   * - Uses REMOTE_ADDR + User-Agent by default.
   * - Can trust X-Forwarded-For if enabled and the environment is controlled.
   * - Returns (user_key, ip, user_agent).
   *
   * Security note:
   * - user_key is a hash prefix, not the raw IP.
   */
  private function get_user_identity() {
    $cfg = $this->get_settings();

    $ip = '';
    if (!empty($cfg['trust_x_forwarded_for'])) {
      $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string)$_SERVER['HTTP_X_FORWARDED_FOR']) : '';
      if ($xff) {
        $ip = trim(explode(',', $xff)[0]);
      }
    }
    if (!$ip) {
      $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : 'unknown';
    if (!$ua) $ua = 'unknown';

    $raw = $ip . '|' . $ua;
    $user_key = substr(hash('sha256', $raw), 0, 24);

    return [$user_key, $ip, substr($ua, 0, 255)];
  }

  /**
   * Reads per-user usage state and evaluates if the user is allowed.
   *
   * This function does not increment counters.
   * The rolling window is based on window_start + window_seconds.
   */
  private function get_user_usage($user_key, $check_only) {
    global $wpdb;
    $cfg = $this->get_settings();
    $limit = (int)$cfg['user_limit_24h'];
    $window = (int)$cfg['window_seconds'];
    $now = time();

    $row = $wpdb->get_row($wpdb->prepare("SELECT window_start, count FROM {$this->t_user_usage} WHERE user_key=%s", $user_key), ARRAY_A);

    if (!$row) {
      // not created yet, treat as 0
      return [
        'allowed' => (0 < $limit || $limit === 0 ? true : true),
        'count' => 0,
        'remaining' => max(0, $limit - 0),
        'window_start' => $now
      ];
    }

    $ws = (int)$row['window_start'];
    $count = (int)$row['count'];

    // reset if expired
    if (($now - $ws) >= $window) {
      $ws = $now;
      $count = 0;
      // do not write yet in check_only
      if (!$check_only) {
        $wpdb->update($this->t_user_usage, ['window_start' => $ws, 'count' => $count], ['user_key' => $user_key], ['%d','%d'], ['%s']);
      }
    }

    $allowed = ($count < $limit);
    return [
      'allowed' => $allowed,
      'count' => $count,
      'remaining' => max(0, $limit - $count),
      'window_start' => $ws
    ];
  }

  /**
   * Increments per-user usage counter after a successful OpenAI completion.
   *
   * If the rolling window has expired, it resets the window start and count first.
   */
  private function increment_user_usage($user_key, $cfg) {
    global $wpdb;
    $limit = (int)$cfg['user_limit_24h'];
    $window = (int)$cfg['window_seconds'];
    $now = time();

    // If limit is 0, effectively block all; but we shouldn't be here.
    $row = $wpdb->get_row($wpdb->prepare("SELECT window_start, count FROM {$this->t_user_usage} WHERE user_key=%s", $user_key), ARRAY_A);

    if (!$row) {
      $wpdb->insert($this->t_user_usage, [
        'user_key' => $user_key,
        'window_start' => $now,
        'count' => 1,
      ], ['%s','%d','%d']);
      return;
    }

    $ws = (int)$row['window_start'];
    $count = (int)$row['count'];

    if (($now - $ws) >= $window) {
      $ws = $now;
      $count = 0;
    }

    $count = $count + 1;
    $wpdb->update($this->t_user_usage, [
      'window_start' => $ws,
      'count' => $count
    ], ['user_key' => $user_key], ['%d','%d'], ['%s']);
  }

  /**
   * Reads global usage state and evaluates if requests are allowed.
   *
   * This function does not increment counters.
   * The global row is stored as a single row with id=1.
   */
  private function get_global_usage($check_only) {
    global $wpdb;
    $cfg = $this->get_settings();
    $limit = (int)$cfg['global_limit_24h'];
    $window = (int)$cfg['window_seconds'];
    $now = time();

    $row = $wpdb->get_row($wpdb->prepare("SELECT window_start, count FROM {$this->t_global_usage} WHERE id=%d", 1), ARRAY_A);
    if (!$row) {
      // create fallback row
      $wpdb->insert($this->t_global_usage, ['id'=>1,'window_start'=>$now,'count'=>0], ['%d','%d','%d']);
      $row = ['window_start'=>$now, 'count'=>0];
    }

    $ws = (int)$row['window_start'];
    $count = (int)$row['count'];

    if (($now - $ws) >= $window) {
      $ws = $now;
      $count = 0;
      if (!$check_only) {
        $wpdb->update($this->t_global_usage, ['window_start'=>$ws,'count'=>$count], ['id'=>1], ['%d','%d'], ['%d']);
      }
    }

    $allowed = ($count < $limit);
    return [
      'allowed' => $allowed,
      'count' => $count,
      'remaining' => max(0, $limit - $count),
      'window_start' => $ws
    ];
  }

  /**
   * Increments the global usage counter after a successful OpenAI completion.
   *
   * If the rolling window has expired, it resets the window start and count first.
   */
  private function increment_global_usage($cfg) {
    global $wpdb;
    $limit = (int)$cfg['global_limit_24h'];
    $window = (int)$cfg['window_seconds'];
    $now = time();

    $row = $wpdb->get_row($wpdb->prepare("SELECT window_start, count FROM {$this->t_global_usage} WHERE id=%d", 1), ARRAY_A);
    if (!$row) {
      $wpdb->insert($this->t_global_usage, ['id'=>1,'window_start'=>$now,'count'=>1], ['%d','%d','%d']);
      return;
    }

    $ws = (int)$row['window_start'];
    $count = (int)$row['count'];

    if (($now - $ws) >= $window) {
      $ws = $now;
      $count = 0;
    }

    $count = $count + 1;
    $wpdb->update($this->t_global_usage, ['window_start'=>$ws,'count'=>$count], ['id'=>1], ['%d','%d'], ['%d']);
  }

  /**
   * Loads server-side chat history for a user.
   *
   * - Stored as JSON in the history table.
   * - Only the last N pairs are returned and sanitized.
   */
  private function load_history($user_key, $max_pairs) {
    global $wpdb;

    if ($max_pairs <= 0) return [];

    $row = $wpdb->get_var($wpdb->prepare("SELECT history_json FROM {$this->t_history} WHERE user_key=%s", $user_key));
    if (!$row) return [];

    $data = json_decode((string)$row, true);
    if (!is_array($data)) return [];

    // keep only last max_pairs
    $data = array_slice($data, -$max_pairs);
    // enforce shape
    $out = [];
    foreach ($data as $item) {
      if (is_array($item)) {
        $out[] = ['q' => (string)($item['q'] ?? ''), 'a' => (string)($item['a'] ?? '')];
      }
    }
    return $out;
  }

  /**
   * Saves server-side chat history for a user.
   *
   * History is stored as a JSON array of objects: [{q:..., a:...}, ...].
   */
  private function save_history($user_key, $history, $max_pairs) {
    global $wpdb;
    if ($max_pairs <= 0) return;

    if (!is_array($history)) $history = [];
    $trimmed = array_slice($history, -$max_pairs);

    $payload = wp_json_encode($trimmed, JSON_UNESCAPED_UNICODE);
    $now = time();

    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->t_history} WHERE user_key=%s", $user_key));
    if ((int)$exists === 0) {
      $wpdb->insert($this->t_history, [
        'user_key' => $user_key,
        'history_json' => $payload,
        'updated_at' => $now,
      ], ['%s','%s','%d']);
    } else {
      $wpdb->update($this->t_history, [
        'history_json' => $payload,
        'updated_at' => $now,
      ], ['user_key' => $user_key], ['%s','%d'], ['%s']);
    }
  }

  // -------------------------
  // Logging (DB)
  // -------------------------
  /**
   * Writes a structured event record into the events table.
   *
   * Events include:
   * - allowed_success / allowed_error
   * - blocked_* (limits, origin)
   * - first_message_too_short
   *
   * PII note:
   * - Messages are stored; consider retention policy and access controls.
   */
  private function log_event($event_type, $user_key, $ip, $message, $reply_preview, $triage_array, $meta_array) {
    $cfg = $this->get_settings();
    if (empty($cfg['log_enabled'])) return;

    global $wpdb;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $triage_json = $triage_array ? wp_json_encode($triage_array, JSON_UNESCAPED_UNICODE) : null;
    $meta_json = $meta_array ? wp_json_encode($meta_array, JSON_UNESCAPED_UNICODE) : null;

    $wpdb->insert($this->t_events, [
      'created_at' => current_time('mysql', 1),
      'event_type' => (string)$event_type,
      'user_key' => (string)($user_key ?: ''),
      'ip' => (string)($ip ?: ''),
      'user_agent' => $this->safe_excerpt($ua, 255),
      'message' => $message,
      'reply_preview' => $reply_preview,
      'triage_json' => $triage_json,
      'meta_json' => $meta_json,
    ], [
      '%s','%s','%s','%s','%s','%s','%s','%s','%s'
    ]);
  }

  /**
   * Sends alert emails when the chatbot hits the generic runtime failure path.
   *
   * The notifications are best-effort and must never interrupt the user-facing
   * response if email delivery fails.
   */
  private function send_error_notification_emails($message, $user_key, $ip, $ua, \Exception $e) {
    $cfg = $this->get_settings();
    $recipients = isset($cfg['error_notification_gmails']) && is_array($cfg['error_notification_gmails'])
      ? $cfg['error_notification_gmails']
      : [];

    if (empty($recipients)) return;

    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = sprintf('[%s] Levinsky Chatbot error', $site_name ?: 'WordPress');
    $from_email = $this->get_notification_from_email();
    $headers = [
      'Content-Type: text/plain; charset=UTF-8',
      sprintf('From: %s <%s>', $site_name ?: 'Levinsky Chatbot', $from_email),
    ];
    $body = implode("\n\n", [
      'The chatbot returned the generic error message to a user.',
      'Time (UTC): ' . gmdate('Y-m-d H:i:s'),
      'Site: ' . home_url('/'),
      'User key: ' . (string)$user_key,
      'IP: ' . (string)$ip,
      'User agent: ' . (string)$ua,
      'Error: ' . $e->getMessage(),
      'Message excerpt: ' . $this->safe_excerpt((string)$message, 1000),
    ]);

    $mail_error = null;
    $mail_failed_handler = null;

    try {
      $mail_failed_handler = function($wp_error) use (&$mail_error) {
        if (is_wp_error($wp_error)) {
          $mail_error = $wp_error->get_error_message();
        }
      };

      add_action('wp_mail_failed', $mail_failed_handler);
      $sent = wp_mail($recipients, $subject, $body, $headers);
      remove_action('wp_mail_failed', $mail_failed_handler);

      $this->log_event($sent ? 'error_notification_sent' : 'error_notification_failed', $user_key, $ip, null, null, null, [
        'recipients' => $recipients,
        'subject' => $subject,
        'from_email' => $from_email,
        'mail_error' => $mail_error,
      ]);
    } catch (\Exception $mail_exception) {
      if ($mail_failed_handler) {
        remove_action('wp_mail_failed', $mail_failed_handler);
      }
      $this->log_event('error_notification_failed', $user_key, $ip, null, null, null, [
        'recipients' => $recipients,
        'subject' => $subject,
        'from_email' => $from_email,
        'mail_error' => $mail_exception->getMessage(),
      ]);
      // Ignore mail transport failures so the API response remains stable.
    }
  }

  /**
   * Returns a syntactically valid sender address for notification emails.
   *
   * Local WordPress installs often default to wordpress@localhost, which many
   * mailers reject before delivery is attempted.
   */
  private function get_notification_from_email() {
    $admin_email = sanitize_email((string)get_option('admin_email'));
    if ($admin_email && is_email($admin_email)) {
      return $admin_email;
    }

    return 'wordpress@example.com';
  }

  /**
   * Probabilistic cleanup of old log rows.
   *
   * To avoid doing cleanup work on every request, it runs randomly (about 1/25).
   * Deletes rows older than log_retention_days.
   */
  private function cleanup_logs_if_needed() {
    $cfg = $this->get_settings();
    if (empty($cfg['log_enabled'])) return;

    $days = (int)$cfg['log_retention_days'];
    if ($days <= 0) return;

    if (mt_rand(1, 25) !== 1) return;

    global $wpdb;
    $cut = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    $wpdb->query($wpdb->prepare("DELETE FROM {$this->t_events} WHERE created_at < %s", $cut));
  }

  // -------------------------
  // Security helpers
  // -------------------------
  /**
   * Best-effort origin check for public REST endpoint.
   *
   * If enabled, requests must match the site host in Origin or Referer.
   * If both headers are missing, it allows the request (common on some setups).
   */
  private function is_same_origin_request() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    if (!$host) return true; // can't check

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? strtolower((string)$_SERVER['HTTP_ORIGIN']) : '';
    $referer = isset($_SERVER['HTTP_REFERER']) ? strtolower((string)$_SERVER['HTTP_REFERER']) : '';

    // allow if either header matches host
    if ($origin && strpos($origin, $host) !== false) return true;
    if ($referer && strpos($referer, $host) !== false) return true;

    // if both missing, allow (many environments omit)
    if (!$origin && !$referer) return true;

    return false;
  }

  // -------------------------
  // Utilities
  // -------------------------
  /**
   * Truncates a string safely for storage/log previews.
   *
   * Uses mb_* when available to avoid breaking UTF-8 characters.
   */
  private function safe_excerpt($text, $max) {
    $text = (string)$text;
    if ($max <= 0) return '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
      return mb_substr($text, 0, $max, 'UTF-8');
    }
    if (strlen($text) > $max) {
      return substr($text, 0, $max);
    }
    return $text;
  }
  // =========================
  // Admin: Logs + Usage pages
  // =========================

  /**
   * Admin UI: paginated log viewer.
   *
   * Features:
   * - Filters (event type, user key, IP, date range)
   * - Pagination (Load more)
   * - CSV/JSONL export (admin-post action)
   */
  public function admin_logs_page() {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }

    global $wpdb;

    $t = $this->t_events;
    $limit = 100;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    $event_type = isset($_GET['event_type']) ? sanitize_text_field(wp_unslash($_GET['event_type'])) : '';
    $user_key   = isset($_GET['user_key']) ? sanitize_text_field(wp_unslash($_GET['user_key'])) : '';
    $ip         = isset($_GET['ip']) ? sanitize_text_field(wp_unslash($_GET['ip'])) : '';
    $from       = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $to         = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

    $where_parts = ['1=1'];
    $params = [];

    if ($event_type !== '') {
      $where_parts[] = 'event_type = %s';
      $params[] = $event_type;
    }
    if ($user_key !== '') {
      $where_parts[] = 'user_key LIKE %s';
      $params[] = '%' . $wpdb->esc_like($user_key) . '%';
    }
    if ($ip !== '') {
      $where_parts[] = 'ip LIKE %s';
      $params[] = '%' . $wpdb->esc_like($ip) . '%';
    }
    if ($from !== '') {
      $where_parts[] = 'created_at >= %s';
      $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
      $where_parts[] = 'created_at <= %s';
      $params[] = $to . ' 23:59:59';
    }

    $where_sql = implode(' AND ', $where_parts);

    // Count
    $count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";
    $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

    // Rows
    $rows_sql = "SELECT id, created_at, event_type, user_key, ip, message, reply_preview, triage_json, meta_json
                 FROM {$t}
                 WHERE {$where_sql}
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d";

    $params2 = $params;
    $params2[] = $limit;
    $params2[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params2), ARRAY_A);

    // Export link
    $export_url = add_query_arg(
      array_filter([
        'action' => 'levinsky_chatbot_export_logs',
        'format' => 'csv',
        'event_type' => $event_type,
        'user_key' => $user_key,
        'ip' => $ip,
        'from' => $from,
        'to' => $to,
        'limit' => 2000,
      ]),
      admin_url('admin-post.php')
    );
    $export_url = wp_nonce_url($export_url, 'levinsky_chatbot_export_logs');

    echo '<div class="wrap">';
    echo '<h1>Levinsky Chatbot Logs</h1>';

    echo '<form method="get" style="margin: 12px 0;">';
    echo '<input type="hidden" name="page" value="levinsky-chatbot-logs">';

    echo '<label style="margin-right:10px;">Event ';
    echo '<input type="text" name="event_type" value="' . esc_attr($event_type) . '" placeholder="allowed_success">';
    echo '</label>';

    echo '<label style="margin-right:10px;">User key ';
    echo '<input type="text" name="user_key" value="' . esc_attr($user_key) . '" placeholder="hash prefix">';
    echo '</label>';

    echo '<label style="margin-right:10px;">IP ';
    echo '<input type="text" name="ip" value="' . esc_attr($ip) . '" placeholder="127.0.0.1">';
    echo '</label>';

    echo '<label style="margin-right:10px;">From ';
    echo '<input type="date" name="from" value="' . esc_attr($from) . '">';
    echo '</label>';

    echo '<label style="margin-right:10px;">To ';
    echo '<input type="date" name="to" value="' . esc_attr($to) . '">';
    echo '</label>';

    submit_button('Filter', 'secondary', '', false);
    echo ' <a class="button button-secondary" href="' . esc_url($export_url) . '">Export CSV</a>';
    echo '</form>';

    echo '<p style="margin: 6px 0; color:#555;">Showing ' . esc_html(count($rows)) . ' of ' . esc_html($total) . ' events.</p>';

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:160px;">Time (UTC)</th>';
    echo '<th style="width:140px;">Event</th>';
    echo '<th style="width:120px;">IP</th>';
    echo '<th style="width:140px;">User key</th>';
    echo '<th>Message</th>';
    echo '<th>Reply preview</th>';
    echo '<th style="width:220px;">Details</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="7">No events found.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $msg = $this->safe_excerpt((string) ($r['message'] ?? ''), 160);
        $rep = $this->safe_excerpt((string) ($r['reply_preview'] ?? ''), 160);

        $triage = (string) ($r['triage_json'] ?? '');
        $meta   = (string) ($r['meta_json'] ?? '');

        echo '<tr>';
        echo '<td>' . esc_html($r['created_at']) . '</td>';
        echo '<td>' . esc_html($r['event_type']) . '</td>';
        echo '<td>' . esc_html($r['ip']) . '</td>';
        echo '<td><code>' . esc_html($r['user_key']) . '</code></td>';
        echo '<td>' . esc_html($msg) . '</td>';
        echo '<td>' . esc_html($rep) . '</td>';
        echo '<td>';
        echo '<details><summary>View</summary>';
        if ($triage !== '') {
          echo '<div style="margin-top:8px;"><strong>Triage:</strong><pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">' . esc_html($triage) . '</pre></div>';
        }
        if ($meta !== '') {
          echo '<div style="margin-top:8px;"><strong>Meta:</strong><pre style="white-space:pre-wrap;max-height:160px;overflow:auto;">' . esc_html($meta) . '</pre></div>';
        }
        echo '</details>';
        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
// Pagination
// Load more (avoid rendering huge tables in the browser)
$base_args = array_filter([
  'page' => 'levinsky-chatbot-logs',
  'event_type' => $q_event_type,
  'user_key' => $q_user_key,
  'since' => $q_since,
  'until' => $q_until,
]);

if (($offset + $limit) < $total) {
  $more_url = add_query_arg(array_merge($base_args, ['offset' => $offset + $limit]), admin_url('options-general.php'));
  echo '<div style="margin-top:12px;">';
  echo '<a class="button button-secondary" href="' . esc_url($more_url) . '">Load more</a>';
  echo '</div>';
} elseif ($offset > 0) {
  $newest_url = add_query_arg(array_merge($base_args, ['offset' => 0]), admin_url('options-general.php'));
  echo '<div style="margin-top:12px;">';
  echo '<a class="button" href="' . esc_url($newest_url) . '">Back to newest</a>';
  echo '</div>';
}
    echo '</div>';
  }

  /**
   * Admin UI: usage viewer.
   *
   * Shows:
   * - Global counter (count, remaining, reset estimate)
   * - Per-user counters (count, remaining, reset estimate)
   * - Filter by user key prefix
   * - Reset user action (admin-post)
   */
  public function admin_usage_page() {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }

    global $wpdb;

    $t_user = $this->t_user_usage;
    $t_global = $this->t_global_usage;

    $global = $wpdb->get_row("SELECT window_start, count FROM {$t_global} WHERE window_start IS NOT NULL ORDER BY window_start DESC LIMIT 1", ARRAY_A);
    $now = time();

    $global_window_start = $global ? (int) $global['window_start'] : 0;
    $global_count = $global ? (int) $global['count'] : 0;
    $global_remaining = max(0, $this->get_settings()['global_limit_24h'] - $global_count);

    $global_resets_in = $global_window_start ? max(0, ($global_window_start + 86400) - $now) : 0;

    $limit = 100;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    $q_user = isset($_GET['user_key']) ? sanitize_text_field(wp_unslash($_GET['user_key'])) : '';
    $where = '1=1';
    $params = [];

    if ($q_user !== '') {
      $where = 'user_key LIKE %s';
      $params[] = '%' . $wpdb->esc_like($q_user) . '%';
    }

    $count_sql = "SELECT COUNT(*) FROM {$t_user} WHERE {$where}";
    $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

    $rows_sql = "SELECT user_key, window_start, count FROM {$t_user} WHERE {$where} ORDER BY window_start DESC LIMIT %d OFFSET %d";
    $params2 = $params;
    $params2[] = $limit;
    $params2[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params2), ARRAY_A);

    $export_url = add_query_arg(
      array_filter([
        'action' => 'levinsky_chatbot_export_usage',
        'format' => 'csv',
      ]),
      admin_url('admin-post.php')
    );
    $export_url = wp_nonce_url($export_url, 'levinsky_chatbot_export_usage');

    echo '<div class="wrap">';
    echo '<h1>Levinsky Chatbot Usage</h1>';

    echo '<div style="margin: 10px 0; padding: 12px; background:#fff; border:1px solid #ccd0d4; border-radius:6px;">';
    echo '<strong>Global limit:</strong> ' . esc_html($global_count) . ' used, ' . esc_html($global_remaining) . ' remaining.';
    if ($global_window_start) {
      echo ' Window started at <code>' . esc_html(gmdate('Y-m-d H:i:s', $global_window_start)) . '</code> (UTC).';
      echo ' Resets in about <code>' . esc_html((int) round($global_resets_in / 60)) . '</code> minutes.';
    } else {
      echo ' No global window started yet.';
    }
    echo ' <a class="button button-secondary" style="margin-left:10px;" href="' . esc_url($export_url) . '">Export CSV</a>';
    echo '</div>';

    echo '<form method="get" style="margin: 12px 0;">';
    echo '<input type="hidden" name="page" value="levinsky-chatbot-usage">';
    echo '<label style="margin-right:10px;">User key ';
    echo '<input type="text" name="user_key" value="' . esc_attr($q_user) . '" placeholder="hash prefix">';
    echo '</label>';
    submit_button('Filter', 'secondary', '', false);
    echo '</form>';

    echo '<p style="margin: 6px 0; color:#555;">Showing ' . esc_html(count($rows)) . ' of ' . esc_html($total) . ' users with activity.</p>';

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:220px;">User key</th>';
    echo '<th style="width:170px;">Window start (UTC)</th>';
    echo '<th style="width:80px;">Count</th>';
    echo '<th style="width:120px;">Resets in</th>';
    echo '<th style="width:140px;">Actions</th>';
    echo '</tr></thead><tbody>';

    $cfg = $this->get_settings();
    $user_limit = (int) $cfg['user_limit_24h'];

    if (!$rows) {
      echo '<tr><td colspan="5">No users found.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $uk = (string) ($r['user_key'] ?? '');
        $ws = (int) ($r['window_start'] ?? 0);
        $cnt = (int) ($r['count'] ?? 0);
        $reset_in = $ws ? max(0, ($ws + 86400) - $now) : 0;
        $remaining = max(0, $user_limit - $cnt);

        $reset_url = add_query_arg(
          ['action' => 'levinsky_chatbot_reset_user', 'user_key' => $uk],
          admin_url('admin-post.php')
        );
        $reset_url = wp_nonce_url($reset_url, 'levinsky_chatbot_reset_user');

        echo '<tr>';
        echo '<td><code>' . esc_html($uk) . '</code></td>';
        echo '<td>' . esc_html($ws ? gmdate('Y-m-d H:i:s', $ws) : '-') . '</td>';
        echo '<td>' . esc_html($cnt) . ' / ' . esc_html($user_limit) . ' (rem ' . esc_html($remaining) . ')</td>';
        echo '<td>' . esc_html((int) round($reset_in / 60)) . ' min</td>';
        echo '<td><a class="button button-secondary" href="' . esc_url($reset_url) . '">Reset user</a></td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    // Load more (avoid rendering huge tables in the browser)
    $base_args = array_filter([
      'page' => 'levinsky-chatbot-usage',
      'user_key' => $q_user,
    ]);

    if (($offset + $limit) < $total) {
      $more_url = add_query_arg(array_merge($base_args, ['offset' => $offset + $limit]), admin_url('options-general.php'));
      echo '<div style="margin-top:12px;">';
      echo '<a class="button button-secondary" href="' . esc_url($more_url) . '">Load more</a>';
      echo '</div>';
    } elseif ($offset > 0) {
      $newest_url = add_query_arg(array_merge($base_args, ['offset' => 0]), admin_url('options-general.php'));
      echo '<div style="margin-top:12px;">';
      echo '<a class="button" href="' . esc_url($newest_url) . '">Back to newest</a>';
      echo '</div>';
    }


    echo '</div>';
  }

  /**
   * Admin action handler: export log rows.
   *
   * Outputs CSV (default) or JSONL.
   * Protected by nonce and manage_options capability.
   */
  public function handle_export_logs() {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }
    check_admin_referer('levinsky_chatbot_export_logs');

    global $wpdb;
    $t = $this->t_events;

    $format = isset($_GET['format']) ? sanitize_text_field(wp_unslash($_GET['format'])) : 'csv';
    $limit  = isset($_GET['limit']) ? max(1, min(5000, (int) $_GET['limit'])) : 2000;

    $event_type = isset($_GET['event_type']) ? sanitize_text_field(wp_unslash($_GET['event_type'])) : '';
    $user_key   = isset($_GET['user_key']) ? sanitize_text_field(wp_unslash($_GET['user_key'])) : '';
    $ip         = isset($_GET['ip']) ? sanitize_text_field(wp_unslash($_GET['ip'])) : '';
    $from       = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $to         = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

    $where_parts = ['1=1'];
    $params = [];

    if ($event_type !== '') { $where_parts[] = 'event_type = %s'; $params[] = $event_type; }
    if ($user_key !== '')   { $where_parts[] = 'user_key LIKE %s'; $params[] = '%' . $wpdb->esc_like($user_key) . '%'; }
    if ($ip !== '')         { $where_parts[] = 'ip LIKE %s'; $params[] = '%' . $wpdb->esc_like($ip) . '%'; }
    if ($from !== '')       { $where_parts[] = 'created_at >= %s'; $params[] = $from . ' 00:00:00'; }
    if ($to !== '')         { $where_parts[] = 'created_at <= %s'; $params[] = $to . ' 23:59:59'; }

    $where_sql = implode(' AND ', $where_parts);

    $sql = "SELECT id, created_at, event_type, user_key, ip, message, reply_preview, triage_json, meta_json
            FROM {$t}
            WHERE {$where_sql}
            ORDER BY id DESC
            LIMIT %d";

    $params2 = $params;
    $params2[] = $limit;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params2), ARRAY_A);

    $filename = 'levinsky-chat-logs-' . gmdate('Ymd-His') . '.' . ($format === 'jsonl' ? 'jsonl' : 'csv');

    nocache_headers();
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($format === 'jsonl') {
      header('Content-Type: application/x-ndjson; charset=utf-8');
      foreach ($rows as $r) {
        echo wp_json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
      }
      exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','created_at_utc','event_type','user_key','ip','message','reply_preview','triage_json','meta_json']);
    foreach ($rows as $r) {
      fputcsv($out, [
        $r['id'] ?? '',
        $r['created_at'] ?? '',
        $r['event_type'] ?? '',
        $r['user_key'] ?? '',
        $r['ip'] ?? '',
        $r['message'] ?? '',
        $r['reply_preview'] ?? '',
        $r['triage_json'] ?? '',
        $r['meta_json'] ?? '',
      ]);
    }
    fclose($out);
    exit;
  }

  /**
   * Admin action handler: export usage state.
   *
   * Outputs CSV containing both the global row and all user rows.
   * Protected by nonce and manage_options capability.
   */
  public function handle_export_usage() {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }
    check_admin_referer('levinsky_chatbot_export_usage');

    global $wpdb;

    $t_user = $this->t_user_usage;
    $t_global = $this->t_global_usage;

    $cfg = $this->get_settings();
    $user_limit = (int) $cfg['user_limit_24h'];
    $global_limit = (int) $cfg['global_limit_24h'];

    $global = $wpdb->get_row("SELECT window_start, count FROM {$t_global} WHERE window_start IS NOT NULL ORDER BY window_start DESC LIMIT 1", ARRAY_A);
    $global_window_start = $global ? (int) $global['window_start'] : 0;
    $global_count = $global ? (int) $global['count'] : 0;

    $users = $wpdb->get_results("SELECT user_key, window_start, count FROM {$t_user} ORDER BY window_start DESC", ARRAY_A);

    $filename = 'levinsky-chat-usage-' . gmdate('Ymd-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['section','key','window_start_utc','count','limit','remaining']);

    // Global section
    $global_remaining = max(0, $global_limit - $global_count);
    fputcsv($out, ['global','all_users', $global_window_start ? gmdate('Y-m-d H:i:s', $global_window_start) : '', $global_count, $global_limit, $global_remaining]);

    // Per-user section
    foreach ($users as $u) {
      $cnt = (int) ($u['count'] ?? 0);
      $rem = max(0, $user_limit - $cnt);
      $ws = (int) ($u['window_start'] ?? 0);
      fputcsv($out, ['user', $u['user_key'] ?? '', $ws ? gmdate('Y-m-d H:i:s', $ws) : '', $cnt, $user_limit, $rem]);
    }

    fclose($out);
    exit;
  }

  /**
   * Admin action handler: reset one user.
   *
   * Deletes the user's usage row and history row.
   * Protected by nonce and manage_options capability.
   */
  public function handle_reset_user() {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }
    check_admin_referer('levinsky_chatbot_reset_user');

    if (!isset($_GET['user_key'])) {
      wp_safe_redirect(admin_url('options-general.php?page=levinsky-chatbot-usage'));
      exit;
    }

    $user_key = sanitize_text_field(wp_unslash($_GET['user_key']));
    if ($user_key === '') {
      wp_safe_redirect(admin_url('options-general.php?page=levinsky-chatbot-usage'));
      exit;
    }

    global $wpdb;
    $wpdb->delete($this->t_user_usage, ['user_key' => $user_key], ['%s']);
    $wpdb->delete($this->t_history, ['user_key' => $user_key], ['%s']);

    wp_safe_redirect(admin_url('options-general.php?page=levinsky-chatbot-usage&reset=1'));
    exit;
  }

}

new LevinskyChatbotAllInOne();
