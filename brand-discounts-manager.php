<?php
/**
 * Plugin Name: Brand Discounts Manager
 * Description: Gestione degli sconti per marca per WooCommerce Brands
 * Version: 1.20
 * Author: Il Tuo Negozio
 */

if (!defined('ABSPATH')) exit;

define('BDM_CRON_HOOK', 'bdm_daily_recalculate');

function bdm_schedule_cron() {
    $hour = absint(get_option('bdm_cron_hour', 3));
    $now  = current_time('timestamp');
    $time = strtotime(date("Y-m-d $hour:00:00", $now));

    if ($time <= $now) {
        $time = strtotime('+1 day', $time);
    }

    wp_clear_scheduled_hook(BDM_CRON_HOOK);
    wp_schedule_event($time, 'daily', BDM_CRON_HOOK);
}

register_activation_hook(__FILE__, function () {
    add_option('bdm_cron_hour', 3);
    bdm_schedule_cron();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(BDM_CRON_HOOK);
});

add_action('wp_ajax_bdm_set_cron_hour', function () {
    check_ajax_referer('bdm_nonce', 'nonce');
    $hour = absint($_POST['hour']);
    if ($hour < 0 || $hour > 23) {
        wp_send_json_error('Ora non valida.');
    }
    update_option('bdm_cron_hour', $hour);
    bdm_schedule_cron();
    wp_send_json_success([
        'message' => sprintf('✓ Cron programmato alle %02d:00.', $hour),
    ]);
});

add_action(BDM_CRON_HOOK, 'bdm_recalculate_all_discounts');

function bdm_recalculate_all_discounts() {
    $saved = get_option('bdm_discounts', []);
    $total = 0;

    foreach ($saved as $brand_id => $data) {
        if (empty($data['active']) || empty($data['discount'])) continue;

        $discount = floatval($data['discount']) / 100;

        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'tax_query'      => [
                ['taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $brand_id],
            ],
        ]);

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $items = [];
            if ($product->is_type('simple'))   $items[] = $product;
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if ($v) $items[] = $v;
                }
            }

            foreach ($items as $item) {
                $rp = (float) $item->get_regular_price();
                if ($rp > 0) {
                    $item->set_sale_price(round($rp * (1 - $discount), 2));
                }
                $item->set_date_on_sale_from('');
                $item->set_date_on_sale_to('');
                $item->save();
                $total++;
            }
        }
    }

    wc_delete_product_transients();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BDM: Recalculated prices for $total products.");
    }

    return $total;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Sconti per Marchio',
        'Sconti per Marchio',
        'manage_woocommerce',
        'brand-discounts-manager',
        'bdm_render_page'
    );
});

add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'brand-discounts-manager') === false) return;
    ?>
    <style>
        #bdm-wrap { max-width: 100%; margin: 30px 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        #bdm-wrap h1 { font-size: 22px; font-weight: 600; color: #1d2327; margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        #bdm-wrap .bdm-subtitle { color: #646970; margin-bottom: 28px; font-size: 13px; }

        /* NOTICE */
        .bdm-notice { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; display: none; border-left: 3px solid; }
        .bdm-notice-success { background: #f0fdf4; color: #166534; border-color: #16a34a; }
        .bdm-notice-error   { background: #fef2f2; color: #991b1b; border-color: #dc2626; }

        /* SUMMARY CARDS */
        .bdm-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .bdm-stat { background: #f9fafb; border-radius: 8px; padding: 14px 18px; }
        .bdm-stat-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; margin-bottom: 4px; }
        .bdm-stat-value { font-size: 24px; font-weight: 600; color: #1d2327; }

        /* FILTERS */
        .bdm-toolbar { display: flex; gap: 10px; margin-bottom: 16px; align-items: center; }
        .bdm-search { flex: 1; padding: 7px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; max-width:40% }
        .bdm-search:focus { border-color: #7c3aed; outline: none; box-shadow: 0 0 0 2px rgba(124,58,237,.12); }
        .bdm-filter-btn { padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid #ddd; background: #fff; color: #374151; transition: all .15s; }
        .bdm-filter-btn.active { background: #7c3aed; color: #fff; border-color: #7c3aed; }
        .bdm-filter-btn:hover:not(.active) { background: #f3f4f6; }

        /* TABLE */
        .bdm-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; margin-bottom: 20px; }
        .bdm-table { width: 100%; border-collapse: collapse; }
        .bdm-table thead th { font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; padding: 10px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .bdm-table thead th.col-action { text-align: center; }
        .bdm-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
        .bdm-table tbody tr:last-child { border-bottom: none; }
        .bdm-table tbody tr:hover { background: #fafafa; }
        .bdm-table td { padding: 11px 16px; vertical-align: middle; }

        .bdm-brand-name { font-size: 14px; font-weight: 500; color: #1d2327; }
        .bdm-brand-meta { font-size: 11px; color: #9ca3af; margin-top: 1px; }

        .bdm-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .bdm-badge-active   { background: #dcfce7; color: #166534; }
        .bdm-badge-inactive { background: #f3f4f6; color: #6b7280; }
        .bdm-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .bdm-input-wrap { display: flex; align-items: center; gap: 6px; }
        .bdm-discount-input { width: 70px; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; text-align: center; transition: border-color .15s; }
        .bdm-discount-input:focus { border-color: #7c3aed; outline: none; box-shadow: 0 0 0 2px rgba(124,58,237,.12); }
        .bdm-pct { font-size: 13px; color: #6b7280; }

        .bdm-actions-cell { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
        .bdm-btn { padding: 6px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; transition: all .15s; white-space: nowrap; }
        .bdm-btn-apply  { background: #7c3aed; color: #fff; }
        .bdm-btn-apply:hover  { background: #6d28d9; }
        .bdm-btn-revert { background: #fff; color: #dc2626; border: 1px solid #fca5a5; }
        .bdm-btn-revert:hover { background: #fef2f2; }
        .bdm-btn:disabled { opacity: .45; cursor: not-allowed; }

        .bdm-spinner { display: none; width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: #7c3aed; border-radius: 50%; animation: bdm-spin .6s linear infinite; flex-shrink: 0; }
        @keyframes bdm-spin { to { transform: rotate(360deg); } }

        .bdm-empty { text-align: center; padding: 48px; color: #9ca3af; font-size: 13px; }

        .bdm-hidden { display: none !important; }
    </style>
    <?php
});

add_action('wp_ajax_bdm_get_brands', function () {
    check_ajax_referer('bdm_nonce', 'nonce');
    $terms = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
    if (is_wp_error($terms)) { wp_send_json_error('Impossibile ottenere i marchi.'); }
    $saved = get_option('bdm_discounts', []);
    $brands = [];
    foreach ($terms as $term) {
        $brands[] = [
            'id'       => $term->term_id,
            'name'     => $term->name,
            'count'    => $term->count,
            'discount' => isset($saved[$term->term_id]) ? $saved[$term->term_id]['discount'] : 0,
            'active'   => isset($saved[$term->term_id]) ? $saved[$term->term_id]['active'] : false,
        ];
    }
    wp_send_json_success($brands);
});

add_action('wp_ajax_bdm_recalculate_all', function () {
    check_ajax_referer('bdm_nonce', 'nonce');
    $count = bdm_recalculate_all_discounts();
    wp_send_json_success([
        'message' => "✓ Prezzi ricalcolati per $count prodotti.",
        'count'   => $count,
    ]);
});

add_action('wp_ajax_bdm_apply_discounts', function () {
    check_ajax_referer('bdm_nonce', 'nonce');
    $brand_id = intval($_POST['brand_id']);
    $discount = floatval($_POST['discount']) / 100;
    $mode     = sanitize_text_field($_POST['mode']);

    $products = get_posts([
        'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish',
        'tax_query' => [[ 'taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $brand_id ]],
    ]);
    $count = 0;

    foreach ($products as $post) {
        $product = wc_get_product($post->ID);
        if (!$product) continue;
        $items = [];
        if ($product->is_type('simple'))   $items[] = $product;
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $vid) { $v = wc_get_product($vid); if ($v) $items[] = $v; }
        }
        foreach ($items as $item) {
            if ($mode === 'apply') {
                $rp = (float) $item->get_regular_price();
                if ($rp > 0) $item->set_sale_price(round($rp * (1 - $discount), 2));
            } else {
                $item->set_sale_price('');
            }
            $item->set_date_on_sale_from(''); $item->set_date_on_sale_to(''); $item->save(); $count++;
        }
    }

    $saved = get_option('bdm_discounts', []);
    $saved[$brand_id] = $mode === 'apply'
        ? ['discount' => floatval($_POST['discount']), 'active' => true,  'updated' => current_time('mysql')]
        : ['discount' => 0,                            'active' => false, 'updated' => current_time('mysql')];
    update_option('bdm_discounts', $saved);
    wc_delete_product_transients();

    wp_send_json_success([
        'count'   => $count,
        'message' => $mode === 'apply'
            ? "✓ Sconto del {$_POST['discount']}% applicato a $count prodotti."
            : "✓ Sconto rimosso da $count prodotti.",
    ]);
});

function bdm_render_page() { ?>
<div id="bdm-wrap">
    <h1>🏷️ Sconti per Marchio</h1>
    <p class="bdm-subtitle">Applica o rimuovi sconti su tutti i prodotti di un marchio con un solo clic.
      <?php $next = wp_next_scheduled(BDM_CRON_HOOK); if ($next): ?>
        <span style="display:block;margin-top:4px;font-size:12px;color:#7c3aed;">
          ⏱ Prossimo ricalcolo automatico: <?php echo wp_date('d/m/Y H:i', $next); ?>
        </span>
      <?php endif; ?>
    </p>

    <div id="bdm-notice" class="bdm-notice"></div>

    <div class="bdm-summary">
        <div class="bdm-stat">
            <div class="bdm-stat-label">Marchi totali</div>
            <div class="bdm-stat-value" id="stat-total">—</div>
        </div>
        <div class="bdm-stat">
            <div class="bdm-stat-label">Con sconto attivo</div>
            <div class="bdm-stat-value" id="stat-active" style="color:#166534;">—</div>
        </div>
        <div class="bdm-stat">
            <div class="bdm-stat-label">Prodotti in promozione</div>
            <div class="bdm-stat-value" id="stat-products" style="color:#7c3aed;">—</div>
        </div>
    </div>

    <div class="bdm-toolbar">
        <input type="text" class="bdm-search" id="bdm-search" placeholder="Cerca marchio...">
        <button class="bdm-filter-btn active" data-filter="all">Tutti</button>
        <button class="bdm-filter-btn" data-filter="active">Con sconto</button>
        <button class="bdm-filter-btn" data-filter="inactive">Senza sconto</button>
        <button class="bdm-btn bdm-btn-apply" id="bdm-recalc-all" style="margin-left:auto;">⟳ Ricalcola tutti</button>
        <label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:4px; width:17%; justify-content:flex-end;">
          Cron alle
          <select id="bdm-cron-hour" style="padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:12px;width:120px;">
            <?php for ($h = 0; $h < 24; $h++): ?>
              <option value="<?php echo $h; ?>" <?php selected($h, get_option('bdm_cron_hour', 3)); ?>>
                <?php echo sprintf('%02d:00', $h); ?>
              </option>
            <?php endfor; ?>
          </select>
        </label>
    </div>

    <div class="bdm-card">
        <table class="bdm-table">
            <thead>
                <tr>
                    <th>Marchio</th>
                    <th>Stato</th>
                    <th>Sconto %</th>
                    <th class="col-action">Azioni</th>
                </tr>
            </thead>
            <tbody id="bdm-tbody">
                <tr><td colspan="4"><div class="bdm-empty">Caricamento in corso...</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function($){
    const nonce = '<?php echo wp_create_nonce('bdm_nonce'); ?>';
    let allBrands = [];
    let currentFilter = 'all';
    let currentSearch = '';

    function showNotice(msg, type) {
        $('#bdm-notice').attr('class', 'bdm-notice bdm-notice-' + type).html(msg).show();
        setTimeout(() => $('#bdm-notice').fadeOut(), 5000);
    }

    function updateStats(data) {
        const active   = data.filter(b => b.active);
        const products = active.reduce((s, b) => s + b.count, 0);
        $('#stat-total').text(data.length);
        $('#stat-active').text(active.length);
        $('#stat-products').text(products);
    }

    function renderTable(data) {
        if (!data.length) {
            $('#bdm-tbody').html('<tr><td colspan="4"><div class="bdm-empty">Nessun marchio trovato.</div></td></tr>');
            return;
        }
        let html = '';
        data.forEach(b => {
            const badgeClass = b.active ? 'bdm-badge-active' : 'bdm-badge-inactive';
            const badgeText  = b.active ? `${b.discount}% attivo` : 'Nessuno sconto';
            html += `
            <tr data-id="${b.id}" data-active="${b.active}" data-name="${b.name.toLowerCase()}">
                <td>
                    <div class="bdm-brand-name">${b.name}</div>
                    <div class="bdm-brand-meta">${b.count} prodotti &middot; ID ${b.id}</div>
                </td>
                <td><span class="bdm-badge ${badgeClass} js-badge"><span class="bdm-badge-dot"></span>${badgeText}</span></td>
                <td>
                    <div class="bdm-input-wrap">
                        <input type="number" class="bdm-discount-input js-discount" value="${b.discount}" min="1" max="90" step="1" placeholder="0">
                        <span class="bdm-pct">%</span>
                    </div>
                </td>
                <td>
                    <div class="bdm-actions-cell">
                        <div class="bdm-spinner js-spinner"></div>
                        <button class="bdm-btn bdm-btn-apply js-apply">Applica</button>
                        <button class="bdm-btn bdm-btn-revert js-revert">Rimuovi</button>
                    </div>
                </td>
            </tr>`;
        });
        $('#bdm-tbody').html(html);
        applyFilters();
    }

    function applyFilters() {
        $('#bdm-tbody tr').each(function() {
            const $r      = $(this);
            const name    = $r.data('name') || '';
            const active  = $r.data('active');
            const matchS  = name.includes(currentSearch);
            const matchF  = currentFilter === 'all'
                          || (currentFilter === 'active'   && active)
                          || (currentFilter === 'inactive' && !active);
            $r.toggleClass('bdm-hidden', !(matchS && matchF));
        });
    }

    function loadBrands() {
        $.post(ajaxurl, { action: 'bdm_get_brands', nonce }, function(res) {
            if (res.success) {
                allBrands = res.data;
                updateStats(allBrands);
                renderTable(allBrands);
            } else {
                showNotice('Errore durante il caricamento dei marchi.', 'error');
            }
        });
    }

    function applyDiscount($row, mode) {
        const id       = $row.data('id');
        const discount = $row.find('.js-discount').val();
        const $spinner = $row.find('.js-spinner');
        const $btnA    = $row.find('.js-apply');
        const $btnR    = $row.find('.js-revert');

        if (mode === 'apply' && (discount <= 0 || discount > 90)) {
            showNotice('Inserisci uno sconto tra 1% e 90%.', 'error');
            return;
        }

        $spinner.show(); $btnA.prop('disabled', true); $btnR.prop('disabled', true);

        $.post(ajaxurl, { action: 'bdm_apply_discounts', nonce, brand_id: id, discount, mode }, function(res) {
            $spinner.hide(); $btnA.prop('disabled', false); $btnR.prop('disabled', false);
            if (res.success) {
                showNotice(res.data.message, 'success');
                loadBrands();
            } else {
                showNotice("Errore durante l'elaborazione. Riprova.", 'error');
            }
        });
    }

    $(document).on('click', '.js-apply',  function() { applyDiscount($(this).closest('tr'), 'apply'); });
    $(document).on('click', '.js-revert', function() { applyDiscount($(this).closest('tr'), 'revert'); });

    $('#bdm-cron-hour').on('change', function() {
        const $sel = $(this);
        $.post(ajaxurl, { action: 'bdm_set_cron_hour', nonce, hour: $sel.val() }, function(res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
            } else {
                showNotice('Errore impostando l\'ora del cron.', 'error');
            }
        });
    });

    $('#bdm-recalc-all').on('click', function() {
        const $btn = $(this).prop('disabled', true).text('Ricalcolo...');
        $.post(ajaxurl, { action: 'bdm_recalculate_all', nonce }, function(res) {
            $btn.prop('disabled', false).text('⟳ Ricalcola tutti');
            if (res.success) {
                showNotice(res.data.message, 'success');
                loadBrands();
            } else {
                showNotice('Errore durante il ricalcolo.', 'error');
            }
        });
    });

    $('#bdm-search').on('input', function() {
        currentSearch = $(this).val().toLowerCase();
        applyFilters();
    });

    $(document).on('click', '.bdm-filter-btn', function() {
        $('.bdm-filter-btn').removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('filter');
        applyFilters();
    });

    loadBrands();
})(jQuery);
</script>
<?php }
