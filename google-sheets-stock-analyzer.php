<?php
/**
 * Plugin Name:       Google Sheets Stock Analyzer
 * Plugin URI:        https://oxigen.team
 * Description:       Reads stock symbols from a Google Sheet, fetches historical data from Yahoo Finance, calculates statistics, and writes the results back to the sheet.
 * Version:           1.9.4
 * Author:            Tevfik Gülep
 * Author URI:        https://oxigen.team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gssa
 */

if (!defined('WPINC')) {
    die;
}

define('GSSA_BATCH_SIZE', 500); // Her cron çalıştığında işlenecek hisse sayısı

// === 1. PLUGIN ADMIN MENU & SETTINGS ===

add_action('admin_menu', 'gssa_add_admin_menu');
function gssa_add_admin_menu() {
    add_menu_page(
        'Stock Analyzer',
        'Stock Analyzer',
        'manage_options',
        'google_sheets_stock_analyzer',
        'gssa_admin_page_html',
        'dashicons-chart-area',
        20
    );
}

add_action('admin_init', 'gssa_settings_init');
function gssa_settings_init() {
    register_setting('gssa_settings_group', 'gssa_settings');
}

function gssa_admin_page_html() {
    $vendor_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
    if (!file_exists($vendor_autoload)) {
        echo '<div class="notice notice-error"><p><strong>HATA:</strong> Google API Client kütüphanesi bulunamadı. Lütfen eklenti klasöründe <code>composer install</code> komutunu çalıştırın. Detaylar için kurulum talimatlarına bakın.</p></div>';
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p>Bu araç, Google E-Tablonuzdaki hisse senetlerini okur, Yahoo Finance'ten veri çeker, analiz eder ve sonuçları E-Tablonuza geri yazar.</p>

        <div style="display: flex; gap: 20px;">
            <div style="flex: 1;">
                <h2>Ayarlar</h2>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('gssa_settings_group');
                    $options = get_option('gssa_settings');
                    ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Google Sheet ID</th>
                            <td><input type="text" name="gssa_settings[sheet_id]" value="<?php echo esc_attr($options['sheet_id'] ?? ''); ?>" size="50" placeholder="E-tablo URL'sindeki uzun karakter dizisi"/></td>
                        </tr>
                        
                        <tr valign="top" style="border-top: 1px solid #ccc;">
                            <th scope="row" colspan="2" style="padding-left:0;"><h3>Pre-Market Analizi Ayarları</h3></th>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Tab Adı</th>
                            <td><input type="text" name="gssa_settings[pre_market_read_sheet_name]" value="<?php echo esc_attr($options['pre_market_read_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: PreMarketHisseler"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Aralığı</th>
                            <td><input type="text" name="gssa_settings[pre_market_symbol_range]" value="<?php echo esc_attr($options['pre_market_symbol_range'] ?? 'A2:A'); ?>" size="20" placeholder="Örn: A2:A"/></td>
                        </tr>
                         <tr valign="top">
                            <th scope="row">Sonuç Tab Adı</th>
                            <td><input type="text" name="gssa_settings[write_sheet_name]" value="<?php echo esc_attr($options['write_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: PreMarketSonuclar"/></td>
                        </tr>

                        <tr valign="top" style="border-top: 1px solid #ccc;">
                            <th scope="row" colspan="2" style="padding-left:0;"><h3>Post-Market Analizi Ayarları</h3></th>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Tab Adı</th>
                            <td><input type="text" name="gssa_settings[post_market_read_sheet_name]" value="<?php echo esc_attr($options['post_market_read_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: PostMarketHisseler"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Aralığı</th>
                            <td><input type="text" name="gssa_settings[post_market_symbol_range]" value="<?php echo esc_attr($options['post_market_symbol_range'] ?? 'A2:A'); ?>" size="20" placeholder="Örn: A2:A"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Sonuç Tab Adı</th>
                            <td><input type="text" name="gssa_settings[post_market_write_sheet_name]" value="<?php echo esc_attr($options['post_market_write_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: PostMarketSonuclar"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Yüzde Eşiği (%)</th>
                            <td>
                                <input type="number" step="0.1" name="gssa_settings[post_market_percentage]" value="<?php echo esc_attr($options['post_market_percentage'] ?? '2'); ?>" />
                                <p class="description">Post-market analizinde kullanılacak yüzde eşiği. Örneğin: 1.5</p>
                            </td>
                        </tr>

                        <tr valign="top" style="border-top: 1px solid #ccc; border-bottom: 1px solid #ccc;">
                            <th scope="row" colspan="2" style="padding-left:0;"><h3>Açılış Fiyatı Analizi Ayarları</h3></th>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Tab Adı</th>
                            <td><input type="text" name="gssa_settings[opening_price_read_sheet_name]" value="<?php echo esc_attr($options['opening_price_read_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: AcilisFiyatiHisseler"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Kaynak Aralığı</th>
                            <td><input type="text" name="gssa_settings[opening_price_symbol_range]" value="<?php echo esc_attr($options['opening_price_symbol_range'] ?? 'A2:A'); ?>" size="20" placeholder="Örn: A2:A"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Sonuç Tab Adı</th>
                            <td><input type="text" name="gssa_settings[opening_price_write_sheet_name]" value="<?php echo esc_attr($options['opening_price_write_sheet_name'] ?? ''); ?>" size="50" placeholder="Örn: AcilisFiyatiSonuclar"/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Yüzde Eşiği (%)</th>
                            <td>
                                <input type="number" step="0.1" name="gssa_settings[opening_price_percentage]" value="<?php echo esc_attr($options['opening_price_percentage'] ?? '1.2'); ?>" />
                                <p class="description">Açılış fiyatı analizinde kullanılacak yüzde eşiği. Varsayılan: 1.2</p>
                            </td>
                        </tr>

                        <tr valign="top" style="border-top: 1px solid #ccc;">
                            <th scope="row" colspan="2" style="padding-left:0;"><h3>Genel Ayarlar</h3></th>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Analiz Bitiş Tarihi</th>
                            <td>
                                <input type="text" id="gssa-end-date" name="gssa_settings[end_date]" value="<?php echo esc_attr($options['end_date'] ?? ''); ?>" placeholder="YYYY-MM-DD formatında"/>
                                <p class="description">Boş bırakılırsa bugünün tarihi kullanılır. Geçmişe dönük tarama bu tarihten 365 gün öncesine kadar yapılır.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Google Service Account JSON</th>
                            <td>
                                <textarea name="gssa_settings[service_account_json]" rows="10" cols="50" class="large-text" placeholder="Google Cloud'dan indirdiğiniz JSON dosyasının içeriğini buraya yapıştırın."><?php echo esc_textarea($options['service_account_json'] ?? ''); ?></textarea>
                                <p class="description">Bu bilgi veritabanında saklanır. Güvenliğiniz için sitenizin veritabanı erişimini kısıtladığınızdan emin olun.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Ayarları Kaydet'); ?>
                </form>
            </div>

            <div style="flex: 1;">
                <h2>Analiz Kontrol Paneli</h2>
                <div id="gssa-control-panel">
                    <div id="gssa-start-controls">
                        <button id="gssa-start-pre-analysis" class="button button-primary" style="margin-top: 5px;">Sadece Pre-Market Analizi</button>
                        <button id="gssa-start-post-analysis" class="button button-primary" style="margin-top: 5px;">Sadece Post-Market Analizi</button>
                        <button id="gssa-start-opening-price-analysis" class="button button-primary" style="margin-top: 5px;">Sadece Açılış Fiyatı Analizi</button>
                        <button id="gssa-start-both-analysis" class="button button-secondary" style="margin-top: 5px;">Tüm Analizleri Başlat</button>
                    </div>
                    <div id="gssa-resume-controls" style="display:none;margin-top: 5px;">
                        <button id="gssa-resume-analysis" class="button button-primary">Kaldığı Yerden Devam Et</button>
                        <button id="gssa-start-fresh-analysis" class="button button-secondary">Sıfırdan Başlat (Tümü)</button>
                    </div>
                    <button id="gssa-stop-analysis" class="button button-secondary" style="display:none;">İşlemi Durdur</button>
                    <div id="gssa-status" style="margin-top: 20px; padding: 10px; background-color: #f7f7f7; border: 1px solid #ccc; border-radius: 4px; min-height: 200px; max-height: 400px; overflow-y: auto; font-family: monospace; white-space: pre-wrap;">
                        İşlem logu burada görünecek...
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// === 2. JAVASCRIPT & AJAX HANDLING ===

add_action('admin_enqueue_scripts', 'gssa_enqueue_admin_scripts');
function gssa_enqueue_admin_scripts($hook) {
    if ($hook != 'toplevel_page_google_sheets_stock_analyzer') {
        return;
    }
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css', true);

    wp_enqueue_script(
        'gssa-admin-js',
        plugin_dir_url(__FILE__) . 'admin.js',
        ['jquery', 'jquery-ui-datepicker'],
        '1.9.4', 
        true
    );
    wp_localize_script('gssa-admin-js', 'gssa_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('gssa_ajax_nonce'),
    ]);
}

// === 3. BACKGROUND PROCESS (WP-CRON) ===

add_action('wp_ajax_gssa_start_background_process', 'gssa_start_background_process_callback');
function gssa_start_background_process_callback() {
    try {
        check_ajax_referer('gssa_ajax_nonce', 'nonce');
        
        gssa_clear_all_process_data();
        
        gssa_add_log_entry("Hisse listeleri alınmaya çalışılıyor...");
        $client = gssa_get_google_client();
        $service = new Google_Service_Sheets($client);
        $options = get_option('gssa_settings');
        $analysis_mode = sanitize_text_field($_POST['analysis_mode'] ?? 'both');

        $spreadsheetId = $options['sheet_id'];
        $pre_symbols = [];
        $post_symbols = [];
        $opening_price_symbols = []; 

        if ($analysis_mode === 'pre' || $analysis_mode === 'both') {
            if (empty($options['pre_market_read_sheet_name']) || empty($options['pre_market_symbol_range'])) throw new Exception("Pre-Market kaynak ayarları eksik.");
            $pre_range = $options['pre_market_read_sheet_name'] . '!' . $options['pre_market_symbol_range'];
            $pre_symbols = gssa_get_symbols_from_sheet($service, $spreadsheetId, $pre_range);
            gssa_add_log_entry(count($pre_symbols) . " adet pre-market hissesi bulundu.");
        }
        if ($analysis_mode === 'post' || $analysis_mode === 'both') {
            if (empty($options['post_market_read_sheet_name']) || empty($options['post_market_symbol_range'])) throw new Exception("Post-Market kaynak ayarları eksik.");
            $post_range = $options['post_market_read_sheet_name'] . '!' . $options['post_market_symbol_range'];
            $post_symbols = gssa_get_symbols_from_sheet($service, $spreadsheetId, $post_range);
            gssa_add_log_entry(count($post_symbols) . " adet post-market hissesi bulundu.");
        }
        if ($analysis_mode === 'opening_price' || $analysis_mode === 'both') {
             if (empty($options['opening_price_read_sheet_name']) || empty($options['opening_price_symbol_range'])) throw new Exception("Açılış Fiyatı Analizi kaynak ayarları eksik.");
            $opening_range = $options['opening_price_read_sheet_name'] . '!' . $options['opening_price_symbol_range'];
            $opening_price_symbols = gssa_get_symbols_from_sheet($service, $spreadsheetId, $opening_range);
            gssa_add_log_entry(count($opening_price_symbols) . " adet açılış fiyatı hissesi bulundu.");
        }
        
        if (empty($pre_symbols) && empty($post_symbols) && empty($opening_price_symbols)) {
            throw new Exception('Belirtilen kaynak tablolarda hiç hisse senedi sembolü bulunamadı.');
        }

        $symbol_map = [];
        if ($analysis_mode === 'pre') {
            foreach ($pre_symbols as $symbol) $symbol_map[$symbol] = 'pre';
        } elseif ($analysis_mode === 'post') {
            foreach ($post_symbols as $symbol) $symbol_map[$symbol] = 'post';
        } elseif ($analysis_mode === 'opening_price') { 
            foreach ($opening_price_symbols as $symbol) $symbol_map[$symbol] = 'opening_price';
        } else { // 'both'
            foreach ($pre_symbols as $symbol) $symbol_map[$symbol] = isset($symbol_map[$symbol]) ? $symbol_map[$symbol] . ',pre' : 'pre';
            foreach ($post_symbols as $symbol) $symbol_map[$symbol] = isset($symbol_map[$symbol]) ? $symbol_map[$symbol] . ',post' : 'post';
            foreach ($opening_price_symbols as $symbol) $symbol_map[$symbol] = isset($symbol_map[$symbol]) ? $symbol_map[$symbol] . ',opening_price' : 'opening_price';
        }
        
        $queue = [];
        foreach ($symbol_map as $symbol => $type) {
            $queue[] = [$symbol, $type];
        }

        update_option('gssa_stock_queue', $queue);
        update_option('gssa_current_index', 0);
        update_option('gssa_process_end_date', sanitize_text_field($options['end_date'] ?? ''));
        update_option('gssa_pre_market_sheet_cleared', false);
        update_option('gssa_post_market_sheet_cleared', false);
        update_option('gssa_opening_price_sheet_cleared', false); 
        update_option('gssa_process_status', 'running');
        gssa_add_log_entry("İşlem başlatıldı. Toplam " . count($queue) . " benzersiz hisse işlenecek.");

        wp_schedule_single_event(time(), 'gssa_run_analysis_cron');
        gssa_spawn_cron();
        
        wp_send_json_success(['message' => 'Arka plan işlemi başarıyla başlatıldı.']);

    } catch (Throwable $e) {
        // Herhangi bir kritik hata veya istisnayı yakala ve logla
        $error_message = sprintf(
            'Kritik Hata: "%s" Dosya: %s Satır: %s',
            $e->getMessage(),
            basename($e->getFile()), // Sadece dosya adını göster
            $e->getLine()
        );
        gssa_add_log_entry("HATA: " . $error_message);
        update_option('gssa_process_status', 'stopped');
        wp_send_json_error(['message' => $error_message]);
    }
}

function gssa_get_symbols_from_sheet($service, $spreadsheetId, $range) {
    try {
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        if (empty($values)) return [];
        $symbols = array_map(function($row) { return $row[0] ?? null; }, $values);
        return array_values(array_filter(array_map('trim', $symbols)));
    } catch (Exception $e) {
        throw new Exception("Google Sheet okuma hatası ($range): " . $e->getMessage());
    }
}

add_action('wp_ajax_gssa_resume_background_process', 'gssa_resume_background_process_callback');
function gssa_resume_background_process_callback() {
    try {
        check_ajax_referer('gssa_ajax_nonce', 'nonce');
        $index = get_option('gssa_current_index', false);
        if ($index === false) {
            wp_send_json_error(['message' => 'Devam edilecek bir işlem bulunamadı. Lütfen sıfırdan başlatın.']);
            return;
        }
        gssa_add_log_entry("İşleme index {$index}'dan devam ediliyor...");
        update_option('gssa_process_status', 'running');
        wp_clear_scheduled_hook('gssa_run_analysis_cron');
        wp_schedule_single_event(time(), 'gssa_run_analysis_cron');
        gssa_spawn_cron();
        wp_send_json_success(['message' => 'İşleme kaldığı yerden devam ediliyor.']);
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'İşlem devam ettirilemedi: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_gssa_stop_background_process', 'gssa_stop_background_process_callback');
function gssa_stop_background_process_callback() {
    try {
        check_ajax_referer('gssa_ajax_nonce', 'nonce');
        wp_clear_scheduled_hook('gssa_run_analysis_cron');
        update_option('gssa_process_status', 'stopped');
        gssa_add_log_entry("İşlem kullanıcı tarafından duraklatıldı.");
        wp_send_json_success(['message' => 'Arka plan işlemi duraklatıldı.']);
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'İşlem durdurulamadı: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_gssa_get_status_log', 'gssa_get_status_log_callback');
function gssa_get_status_log_callback() {
    try {
        check_ajax_referer('gssa_ajax_nonce', 'nonce');
        
        $log = get_option('gssa_process_log', []);
        $status = get_option('gssa_process_status', 'stopped');
        $current_index = get_option('gssa_current_index', 0);
        $queue = get_option('gssa_stock_queue', []);

        if (!is_array($queue)) $queue = [];
        if (!is_array($log)) $log = [];
        
        wp_send_json_success([
            'log' => $log, 
            'status' => $status, 
            'currentIndex' => (int) $current_index, 
            'totalSymbols' => count($queue)
        ]);
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'Durum bilgisi alınırken sunucu hatası oluştu: ' . $e->getMessage()]);
    }
}

add_action('gssa_run_analysis_cron', 'gssa_run_analysis_cron_callback');
function gssa_run_analysis_cron_callback() {
    if (get_option('gssa_process_status') !== 'running') {
        gssa_add_log_entry("İşlem durdurulmuş olarak algılandı, sonlandırılıyor.");
        return;
    }

    $queue = get_option('gssa_stock_queue');
    $start_index = (int) get_option('gssa_current_index');

    if ($queue === false || $start_index === false) {
        update_option('gssa_process_status', 'stopped');
        return;
    }

    $total_symbols = count($queue);
    if ($start_index >= $total_symbols) {
        gssa_add_log_entry("Tüm hisseler başarıyla işlendi.");
        gssa_clear_all_process_data();
        return;
    }
    
    gssa_add_log_entry("Toplu işlem başlıyor: Index {$start_index} -> " . min($start_index + GSSA_BATCH_SIZE - 1, $total_symbols - 1));

    $i = 0;
    for ($i = 0; $i < GSSA_BATCH_SIZE; $i++) {
        if (get_option('gssa_process_status') !== 'running') {
            gssa_add_log_entry("Durdurma sinyali alındı, mevcut toplu işlem durduruluyor.");
            break; 
        }

        $current_index = $start_index + $i;
        if ($current_index >= $total_symbols) break;

        $item = $queue[$current_index];
        $symbol = $item[0];
        $analysis_types = explode(',', $item[1]);

        gssa_add_log_entry("İşleniyor: {$symbol} (" . implode(', ', $analysis_types) . ") (" . ($current_index + 1) . "/" . $total_symbols . ")");

        try {
            gssa_process_single_stock($symbol, $analysis_types);
            gssa_add_log_entry("Başarılı: {$symbol} işlendi ve yazıldı.");
        } catch (Exception $e) {
            gssa_add_log_entry("HATA ({$symbol}): İşlem sırasında hata oluştuğu için ATLANDI. Hata: " . $e->getMessage());
            try {
                if (in_array('pre', $analysis_types)) {
                    gssa_write_skipped_stock_to_sheet($symbol, 'pre_market');
                }
                if (in_array('post', $analysis_types)) {
                    gssa_write_skipped_stock_to_sheet($symbol, 'post_market');
                }
                if (in_array('opening_price', $analysis_types)) { 
                    gssa_write_skipped_stock_to_sheet($symbol, 'opening_price');
                }
                gssa_add_log_entry("Bilgi: {$symbol} için ilgili E-Tablo(lar)a atlama kaydı düşüldü.");
            } catch (Exception $write_e) {
                gssa_add_log_entry("UYARI: {$symbol} için atlama kaydı E-Tablo'ya yazılamadı. Hata: " . $write_e->getMessage());
            }
        }
    }

    $next_index = $start_index + $i;
    update_option('gssa_current_index', $next_index);

    if (get_option('gssa_process_status') === 'running' && $next_index < $total_symbols) {
        wp_schedule_single_event(time() + 1, 'gssa_run_analysis_cron');
        gssa_spawn_cron();
    } else if (get_option('gssa_process_status') !== 'running') {
        gssa_add_log_entry("İşlem duraklatıldı. Bir sonraki cron görevi planlanmadı.");
    } else {
        gssa_add_log_entry("Tüm hisseler başarıyla işlendi.");
        gssa_clear_all_process_data();
    }
}

function gssa_add_log_entry($message) {
    $log = get_option('gssa_process_log', []);
    array_unshift($log, wp_date('Y-m-d H:i:s') . ' - ' . $message);
    if (count($log) > 200) $log = array_slice($log, 0, 200);
    update_option('gssa_process_log', $log);
}

function gssa_clear_all_process_data() {
    wp_clear_scheduled_hook('gssa_run_analysis_cron');
    delete_option('gssa_stock_queue');
    delete_option('gssa_current_index');
    delete_option('gssa_error_counts');
    delete_option('gssa_process_end_date');
    delete_option('gssa_pre_market_sheet_cleared');
    delete_option('gssa_post_market_sheet_cleared');
    delete_option('gssa_opening_price_sheet_cleared'); 
    update_option('gssa_process_status', 'stopped');
}

function gssa_spawn_cron() {
    $cron_url = site_url('/wp-cron.php?doing_wp_cron');
    wp_remote_post($cron_url, [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
    ]);
}

function gssa_process_single_stock($symbol, $analysis_types) {
    $timezone = new DateTimeZone('America/New_York');
    $options = get_option('gssa_settings');
    $end_date_setting = get_option('gssa_process_end_date');

    $end_date = !empty($end_date_setting) ? (new DateTime($end_date_setting . ' 23:59:59', $timezone))->getTimestamp() : time();
    $start_date = strtotime('-365 days', $end_date);
    
    $needs_hourly = in_array('pre', $analysis_types) || in_array('post', $analysis_types);
    $needs_daily = in_array('opening_price', $analysis_types) || $needs_hourly;
    $needs_options_check = in_array('opening_price', $analysis_types);

    $daily_data_api = null;
    $split_info = 'Yok';
    $has_options = 'Bilinmiyor';
    
    if($needs_daily) {
        $daily_url = sprintf('https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d&events=splits', $symbol, $start_date, $end_date);
        $daily_response = wp_remote_get($daily_url, ['timeout' => 30]);
        if (is_wp_error($daily_response) || wp_remote_retrieve_response_code($daily_response) != 200) throw new Exception("Günlük veri çekilemedi: " . wp_remote_retrieve_response_code($daily_response));
        $daily_data_api = json_decode(wp_remote_retrieve_body($daily_response), true);
        if (empty($daily_data_api['chart']['result'][0]['timestamp'])) throw new Exception("Günlük veri bulunamadı.");

        if (isset($daily_data_api['chart']['result'][0]['events']['splits'])) {
            $latest_split = end($daily_data_api['chart']['result'][0]['events']['splits']);
            if ($latest_split && $latest_split['date'] >= $start_date) {
                $split_date = new DateTime('@' . $latest_split['date']);
                $split_info = sprintf('%s (%s)', $latest_split['splitRatio'], $split_date->format('Y-m-d'));
            }
        }
    }

    if ($needs_options_check) {
        // Yahoo Finance Opsiyon API'si rate limit sorunları nedeniyle geçici olarak devre dışı bırakıldı.
        $has_options = 'Kontrol Devre Dışı';
    }


    if ($needs_hourly) {
        $hourly_url = sprintf('https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1h&includePrePost=true', $symbol, $start_date, $end_date);
        $hourly_response = wp_remote_get($hourly_url, ['timeout' => 30]);
        if (is_wp_error($hourly_response) || wp_remote_retrieve_response_code($hourly_response) != 200) throw new Exception("Saatlik veri çekilemedi: " . wp_remote_retrieve_response_code($hourly_response));
        $hourly_data_api = json_decode(wp_remote_retrieve_body($hourly_response), true);
        if (empty($hourly_data_api['chart']['result'][0]['timestamp'])) throw new Exception("Saatlik veri bulunamadı.");

        $official_closes = [];
        $daily_timestamps = $daily_data_api['chart']['result'][0]['timestamp'];
        $daily_quotes = $daily_data_api['chart']['result'][0]['indicators']['quote'][0];
        foreach ($daily_timestamps as $i => $ts) {
            $dt = new DateTime('@' . $ts); $dt->setTimezone($timezone);
            $date_key = $dt->format('Y-m-d');
            if (isset($daily_quotes['close'][$i])) $official_closes[$date_key] = $daily_quotes['close'][$i];
        }

        $hourly_data_grouped = [];
        $hourly_timestamps = $hourly_data_api['chart']['result'][0]['timestamp'];
        $hourly_quotes = $hourly_data_api['chart']['result'][0]['indicators']['quote'][0];
        foreach ($hourly_timestamps as $i => $ts) {
            $dt = new DateTime('@' . $ts); $dt->setTimezone($timezone);
            $date_key = $dt->format('Y-m-d');
            if ($dt->format('N') >= 6) continue;
            
            if (!isset($hourly_data_grouped[$date_key])) {
                $hourly_data_grouped[$date_key] = [
                    'pre_market_highs' => [], 'market_highs' => [], 'post_market_highs' => [],
                    'pre_market_opens' => [], 'pre_market_activity' => false, 'post_market_activity' => false
                ];
            }
            
            $high_price = $hourly_quotes['high'][$i] ?? null; $low_price = $hourly_quotes['low'][$i] ?? null; $open_price = $hourly_quotes['open'][$i] ?? null;
            if ($high_price === null) continue;
            
            $time = (int)$dt->format('Hi');
            if ($time >= 400 && $time <= 929) { // Pre-market
                $hourly_data_grouped[$date_key]['pre_market_highs'][] = $high_price;
                if ($open_price !== null) $hourly_data_grouped[$date_key]['pre_market_opens'][] = $open_price;
                if ($high_price > $low_price) $hourly_data_grouped[$date_key]['pre_market_activity'] = true;
            } elseif ($time >= 930 && $time <= 1559) { // Market hours
                $hourly_data_grouped[$date_key]['market_highs'][] = $high_price;
            } elseif ($time >= 1600 && $time <= 1659) { // Post-market (first hour)
                $hourly_data_grouped[$date_key]['post_market_highs'][] = $high_price;
                if ($high_price > $low_price) $hourly_data_grouped[$date_key]['post_market_activity'] = true;
            }
        }

        $processed_data = []; $dates = array_keys($official_closes); sort($dates);
        $previous_day_close = null;
        foreach ($dates as $date) {
            $day_hourly = $hourly_data_grouped[$date] ?? [
                'pre_market_highs' => [], 'market_highs' => [], 'post_market_highs' => [],
                'pre_market_opens' => [], 'pre_market_activity' => false, 'post_market_activity' => false
            ];
            
            $metrics = [
                'close' => $official_closes[$date] ?? null, 
                'pre_market_high' => !empty($day_hourly['pre_market_highs']) ? max($day_hourly['pre_market_highs']) : null, 
                'intraday_high' => !empty($day_hourly['market_highs']) ? max($day_hourly['market_highs']) : null,
                'post_market_high' => !empty($day_hourly['post_market_highs']) ? max($day_hourly['post_market_highs']) : null,
                'pre_market_percent_diff' => null, 'post_market_percent_diff' => null, 'pre_market_open_percent' => null,
                'pre_market_activity' => $day_hourly['pre_market_activity'], 'post_market_activity' => $day_hourly['post_market_activity']
            ];

            if ($previous_day_close !== null && $metrics['pre_market_high'] !== null) $metrics['pre_market_percent_diff'] = (($metrics['pre_market_high'] / $previous_day_close) - 1) * 100;
            if ($previous_day_close !== null && !empty($day_hourly['pre_market_opens'])) $metrics['pre_market_open_percent'] = (($day_hourly['pre_market_opens'][0] / $previous_day_close) - 1) * 100;
            if ($metrics['close'] !== null && $metrics['post_market_high'] !== null) $metrics['post_market_percent_diff'] = (($metrics['post_market_high'] / $metrics['close']) - 1) * 100;

            $processed_data[$date] = $metrics;
            if ($metrics['close'] !== null) $previous_day_close = $metrics['close'];
        }
    
        if (in_array('pre', $analysis_types)) {
            $pre_market_summary = gssa_calculate_summary($processed_data, $dates, 'pre_market');
            gssa_write_summary_to_sheet($symbol, $pre_market_summary, $split_info, 'pre_market', 2.0, null);
        }
        if (in_array('post', $analysis_types)) {
            $post_market_percentage = (float) ($options['post_market_percentage'] ?? 2.0);
            $post_market_summary = gssa_calculate_summary($processed_data, $dates, 'post_market', $post_market_percentage);
            gssa_write_summary_to_sheet($symbol, $post_market_summary, $split_info, 'post_market', $post_market_percentage, null);
        }
    }
    
    if (in_array('opening_price', $analysis_types)) {
        $opening_price_percentage = (float) ($options['opening_price_percentage'] ?? 1.2);
        $opening_price_summary = gssa_calculate_opening_price_summary($daily_data_api, $timezone, $opening_price_percentage);
        gssa_write_summary_to_sheet($symbol, $opening_price_summary, $split_info, 'opening_price', $opening_price_percentage, $has_options);
    }
}

function gssa_calculate_opening_price_summary($daily_data_api, $timezone, $percentage_threshold = 1.2) {
    $summary_template = ['count' => 0, 'intraday_recovery_count' => 0, 'total_trading_days' => 0];
    $summary = ['d365' => $summary_template, 'd90' => $summary_template, 'd60' => $summary_template, 'd30' => $summary_template];
    $today = new DateTime('now', $timezone);

    $timestamps = $daily_data_api['chart']['result'][0]['timestamp'];
    $quotes = $daily_data_api['chart']['result'][0]['indicators']['quote'][0];
    
    for ($i = 1; $i < count($timestamps); $i++) {
        $current_open = $quotes['open'][$i] ?? null;
        $current_high = $quotes['high'][$i] ?? null;
        $previous_close = $quotes['close'][$i - 1] ?? null;

        if ($current_open === null || $previous_close === null || $previous_close == 0 || $current_high === null) continue;
        
        $current_date = new DateTime('@' . $timestamps[$i]);
        $current_date->setTimezone($timezone);
        $interval_days = $today->diff($current_date)->days;
        
        $periods_to_update = [];
        if ($interval_days <= 365) $periods_to_update[] = 'd365';
        if ($interval_days <= 90) $periods_to_update[] = 'd90';
        if ($interval_days <= 60) $periods_to_update[] = 'd60';
        if ($interval_days <= 30) $periods_to_update[] = 'd30';

        foreach($periods_to_update as $period) {
            $summary[$period]['total_trading_days']++;
        }

        $opening_percentage_diff = (($current_open / $previous_close) - 1) * 100;
        
        if ($opening_percentage_diff >= $percentage_threshold) {
            foreach($periods_to_update as $period) {
                $summary[$period]['count']++;
            }
        } else {
            // Açılışta geçemedi, gün içi en yükseği kontrol et
            $intraday_percentage_diff = (($current_high / $previous_close) - 1) * 100;
            if ($intraday_percentage_diff >= $percentage_threshold) {
                foreach($periods_to_update as $period) {
                    $summary[$period]['intraday_recovery_count']++;
                }
            }
        }
    }
    return $summary;
}


function gssa_calculate_summary($processed_data, $dates, $type = 'pre_market', $percentage_threshold = 2.0) {
    $summary_template = ['total_over_threshold' => 0, 'over_threshold' => 0, 'under_threshold' => 0, 'special_case' => 0, 'intraday_over_2_percent' => 0, 'intraday_recovery' => 0, 'weak_day' => 0, 'active_days' => 0, 'total_trading_days' => 0];
    $summary = ['d365' => $summary_template, 'd90' => $summary_template, 'd60' => $summary_template, 'd30' => $summary_template];
    $timezone = new DateTimeZone('America/New_York');
    $today = new DateTime('now', $timezone);

    foreach($dates as $date) {
        if (!isset($processed_data[$date])) continue;
        $current_day_metrics = $processed_data[$date];
        
        $interval_days = $today->diff(new DateTime($date, $timezone))->days;

        $periods_to_update = [];
        if ($interval_days <= 365) $periods_to_update[] = 'd365';
        if ($interval_days <= 90) $periods_to_update[] = 'd90';
        if ($interval_days <= 60) $periods_to_update[] = 'd60';
        if ($interval_days <= 30) $periods_to_update[] = 'd30';
        
        if (empty($periods_to_update)) continue;

        if ($type === 'pre_market') {
            $percent_diff = $current_day_metrics['pre_market_percent_diff'];
            $active_flag = $current_day_metrics['pre_market_activity'];
            $prev_date_index = array_search($date, $dates) - 1;
            if ($prev_date_index < 0) continue;
            $reference_close = $processed_data[$dates[$prev_date_index]]['close'] ?? null;
            if ($reference_close === null) continue;
        } else { // post_market
            $percent_diff = $current_day_metrics['post_market_percent_diff'];
            $active_flag = $current_day_metrics['post_market_activity'];
            $reference_close = $current_day_metrics['close'];
            if ($reference_close === null) continue;
        }

        foreach ($periods_to_update as $period) {
            $summary[$period]['total_trading_days']++;
            if ($active_flag) $summary[$period]['active_days']++;
            
            $is_intraday_over_2_percent = false;

            if ($type === 'pre_market') {
                $high_price = $current_day_metrics['pre_market_high'];
                $intraday_high = $current_day_metrics['intraday_high'];
                if ($high_price !== null && $reference_close > 0) {
                    $high_passed_threshold = (($high_price / $reference_close) - 1) * 100 >= $percentage_threshold;
                    if (!$high_passed_threshold) {
                        if ($intraday_high !== null) {
                            if ($intraday_high > $reference_close) {
                                $intraday_percentage_gain = (($intraday_high / $reference_close) - 1) * 100;
                                if ($intraday_percentage_gain >= $percentage_threshold) {
                                    $summary[$period]['intraday_over_2_percent']++;
                                    $summary[$period]['total_over_threshold']++;
                                    $is_intraday_over_2_percent = true;
                                } else {
                                    $summary[$period]['intraday_recovery']++;
                                }
                            } else { 
                                $summary[$period]['weak_day']++;
                            }
                        }
                    }
                }
            }
            
            if ($percent_diff !== null) {
                if ($percent_diff >= $percentage_threshold) {
                    $summary[$period]['over_threshold']++;
                     if ($type === 'pre_market') {
                        $summary[$period]['total_over_threshold']++;
                    }
                } else {
                    if (!$is_intraday_over_2_percent) {
                        $summary[$period]['under_threshold']++;
                    }
                }
                if ($type === 'pre_market') {
                    $pre_market_open_percent = $current_day_metrics['pre_market_open_percent'];
                    if ($pre_market_open_percent !== null && $pre_market_open_percent < 0.5 && $percent_diff >= $percentage_threshold) {
                        $summary[$period]['special_case']++;
                    }
                }
            }
        }
    }
    return $summary;
}

function gssa_write_summary_to_sheet($symbol, $summary, $split_info, $type = 'pre_market', $percentage_threshold = 2.0, $has_options = null) {
    $client = gssa_get_google_client();
    $service = new Google_Service_Sheets($client);
    $options = get_option('gssa_settings');
    $spreadsheetId = $options['sheet_id'];
    
    if ($type === 'pre_market') {
        $writeSheetName = $options['write_sheet_name'];
    } elseif ($type === 'post_market') {
        $writeSheetName = $options['post_market_write_sheet_name'];
    } else { // opening_price
        $writeSheetName = $options['opening_price_write_sheet_name'];
    }

    $is_cleared_option_name = "gssa_{$type}_sheet_cleared";
    $is_cleared = get_option($is_cleared_option_name, false);

    $periods = ['d365' => '(365g)', 'd90' => '(90g)', 'd60' => '(60g)', 'd30' => '(30g)'];
    
    if ($type === 'pre_market') {
        $stat_headers_base = ['Toplam +%' . $percentage_threshold, 'PM +%' . $percentage_threshold . ' ve Üzeri', 'Gün içi +%' . $percentage_threshold, 'Yüzde ' . $percentage_threshold . ' Altı', 'PM Sakin Açılış +%2', 'Gün İçi Telafi', 'Zayıf Gün', 'PM Aktif Gün', 'Toplam Aktif Gün'];
        $stat_keys_base = ['total_over_threshold', 'over_threshold', 'intraday_over_2_percent', 'under_threshold', 'special_case', 'intraday_recovery', 'weak_day', 'active_days', 'total_trading_days'];
    } elseif ($type === 'post_market') {
        $stat_headers_base = ['Pozitif %' . $percentage_threshold . ' ve Üzeri', 'Yüzde ' . $percentage_threshold . ' Altı', 'Post-M Aktif Gün', 'Toplam Aktif Gün'];
        $stat_keys_base = ['over_threshold', 'under_threshold', 'active_days', 'total_trading_days'];
    } else { // opening_price
        $stat_headers_base = ['Toplam', 'Açılış >= +%' . $percentage_threshold, 'Gün İçi Telafi', 'Toplam Aktif Gün'];
        $stat_keys_base = ['total_count', 'count', 'intraday_recovery_count', 'total_trading_days'];
    }

    $header_row = ['Hisse Senedi'];
    foreach ($periods as $period_label) {
        foreach($stat_headers_base as $stat_header) $header_row[] = $stat_header . ' ' . $period_label;
    }
    $header_row[] = 'Hisse Bölünmesi';
    if ($type === 'opening_price') {
        $header_row[] = 'Opsiyon Durumu';
    }

    $data_row = [$symbol];
    foreach ($periods as $period_key => $period_label) {
        foreach($stat_keys_base as $stat_key) {
            if ($stat_key === 'total_count') {
                $total = ($summary[$period_key]['count'] ?? 0) + ($summary[$period_key]['intraday_recovery_count'] ?? 0);
                $data_row[] = $total;
            } else {
                $data_row[] = $summary[$period_key][$stat_key] ?? 0;
            }
        }
    }
    $data_row[] = $split_info;
    if ($type === 'opening_price') {
        $data_row[] = $has_options;
    }

    if (!$is_cleared) {
        gssa_add_log_entry("İlk hisse ($type), '$writeSheetName' sayfası temizleniyor...");
        $clear_request = new Google_Service_Sheets_ClearValuesRequest();
        $service->spreadsheets_values->clear($spreadsheetId, $writeSheetName, $clear_request);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
        
        $values_to_write = [$header_row, $data_row];
        $update_body = new Google_Service_Sheets_ValueRange(['values' => $values_to_write]);
        $service->spreadsheets_values->update($spreadsheetId, $writeSheetName . '!A1', $update_body, ['valueInputOption' => 'USER_ENTERED']);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
        update_option($is_cleared_option_name, true);
    } else {
        $values_to_write = [$data_row];
        $append_body = new Google_Service_Sheets_ValueRange(['values' => $values_to_write]);
        $service->spreadsheets_values->append($spreadsheetId, $writeSheetName, $append_body, ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS']);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
    }
}


function gssa_write_skipped_stock_to_sheet($symbol, $type = 'pre_market') {
    $client = gssa_get_google_client();
    $service = new Google_Service_Sheets($client);
    $options = get_option('gssa_settings');
    $spreadsheetId = $options['sheet_id']; 
    
    if ($type === 'pre_market') {
        $writeSheetName = $options['write_sheet_name'];
        $percentage_threshold = 2.0;
    } elseif ($type === 'post_market') {
        $writeSheetName = $options['post_market_write_sheet_name'];
        $percentage_threshold = (float) ($options['post_market_percentage'] ?? 2.0);
    } else { // opening_price
        $writeSheetName = $options['opening_price_write_sheet_name'];
        $percentage_threshold = (float) ($options['opening_price_percentage'] ?? 1.2);
    }
    
    $is_cleared_option_name = "gssa_{$type}_sheet_cleared";
    $is_cleared = get_option($is_cleared_option_name, false);
    
    $values_to_write = [[$symbol . ' - HATA NEDENİYLE ATLANDI']];

    if (!$is_cleared) {
        if ($type === 'pre_market') {
            $stat_headers_base = ['Toplam +%' . $percentage_threshold, 'PM +%' . $percentage_threshold . ' ve Üzeri', 'Gün içi +%' . $percentage_threshold, 'Yüzde ' . $percentage_threshold . ' Altı', 'PM Sakin Açılış +%2', 'Gün İçi Telafi', 'Zayıf Gün', 'PM Aktif Gün', 'Toplam Aktif Gün'];
        } elseif ($type === 'post_market') {
            $stat_headers_base = ['Pozitif %' . $percentage_threshold . ' ve Üzeri', 'Yüzde ' . $percentage_threshold . ' Altı', 'Post-M Aktif Gün', 'Toplam Aktif Gün'];
        } else { // opening_price
             $stat_headers_base = ['Toplam', 'Açılış >= +%' . $percentage_threshold, 'Gün İçi Telafi', 'Toplam Aktif Gün'];
        }
        
        $header_row = ['Hisse Senedi'];
        $periods = ['d365' => '(365g)', 'd90' => '(90g)', 'd60' => '(60g)', 'd30' => '(30g)'];
        foreach ($periods as $period_label) {
            foreach($stat_headers_base as $stat_header) $header_row[] = $stat_header . ' ' . $period_label;
        }
        $header_row[] = 'Hisse Bölünmesi';
        if ($type === 'opening_price') {
            $header_row[] = 'Opsiyon Durumu';
        }
        
        $clear_request = new Google_Service_Sheets_ClearValuesRequest();
        $service->spreadsheets_values->clear($spreadsheetId, $writeSheetName, $clear_request);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
        
        $initial_data = [$header_row, $values_to_write[0]];
        $update_body = new Google_Service_Sheets_ValueRange(['values' => $initial_data]);
        $service->spreadsheets_values->update($spreadsheetId, $writeSheetName . '!A1', $update_body, ['valueInputOption' => 'USER_ENTERED']);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
        update_option($is_cleared_option_name, true);
    } else {
        $append_body = new Google_Service_Sheets_ValueRange(['values' => $values_to_write]);
        $service->spreadsheets_values->append($spreadsheetId, $writeSheetName, $append_body, ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS']);
        usleep(500000); // API limitini aşmamak için 0.3 saniye bekle
    }
}


function gssa_get_google_client() {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
    $options = get_option('gssa_settings');
    $json_credentials = $options['service_account_json'] ?? '';
    if (empty(trim($json_credentials))) {
        throw new Exception("Google Service Account JSON bilgisi ayarlarda eksik.");
    }
    $credentials_array = json_decode($json_credentials, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($credentials_array) || empty($credentials_array)) {
        throw new Exception("Google Service Account JSON formatı geçersiz veya boş.");
    }
    $client = new Google_Client();
    $client->setApplicationName("WordPress Stock Analyzer");
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
    $client->setAccessType('offline');
    $client->setAuthConfig($credentials_array);
    return $client;
}
?>
