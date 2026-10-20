<?php
/*
Plugin Name: Stunnz Dynamic Tracker
Description: Customized live tracking for WPCargo shipments. Classic Google Roadmap view, smooth truck animation, rotation, checkpoint caching, strict resume & smart looping with real-ETA looping.
Version: 1.3.6
Author: Big Stunnz
*/

if (!defined('ABSPATH')) exit;
if (!defined('STUNNZ_TRACKER_VERSION')) define('STUNNZ_TRACKER_VERSION', '1.4.1');


// ===== Global flags to print assets in footer only when shortcode is used
$GLOBALS['STUNNZ_TRACKER_NEEDS_ASSETS'] = false;
$GLOBALS['STUNNZ_TRACKER_ATTS'] = [];

// CONFIG
if (!defined('STUNNZ_TRACKER_SECRET')) define('STUNNZ_TRACKER_SECRET', 'G8vM4xP2qN7rW1tZ9sB6jF0hL3yD5uK8eR4mA2cQ7oV5nX1p');
if (!defined('STUNNZ_TRACKER_POSTTYPE')) define('STUNNZ_TRACKER_POSTTYPE', 'wpcargo_shipment');
if (!defined('STUNNZ_TRACKER_METAKEY')) define('STUNNZ_TRACKER_METAKEY', 'original_post_title');

if (!defined('STUNNZ_GOOGLE_API_KEY')) {
    define('STUNNZ_GOOGLE_API_KEY', 'AIzaSyA3NnNe8ks7CUxWcoDe_JEl-Ky_xB6ZweM');
}

/* ----------------------------------------------------------------
   AUTO-INDEX + FALLBACK LOOKUP (NEW)
   ---------------------------------------------------------------- */

/** Normalize a tracking code (trim; keep user formatting) */
function stunnz_norm_code($code){
    $code = is_scalar($code) ? trim((string)$code) : '';
    return $code;
}

/** Ensure our primary index meta exists and is useful */
function stunnz_index_shipment($post_id, $force=false){
    if (get_post_type($post_id) !== STUNNZ_TRACKER_POSTTYPE) return;
    $current = get_post_meta($post_id, STUNNZ_TRACKER_METAKEY, true);
    if (!$force && !empty($current)) return;

    $p = get_post($post_id);
    if (!$p) return;

    // Choose the best available "tracking code":
    // 1) existing meta (if present)
    // 2) carrier ref number (common code field)
    // 3) post_name (slug)
    // 4) post_title (as a last resort)
    $carrier = get_post_meta($post_id, 'wpcargo_carrier_ref_number', true);
    $candidate = $current;
    if (!$candidate && !empty($carrier)) $candidate = $carrier;
    if (!$candidate && !empty($p->post_name)) $candidate = $p->post_name;
    if (!$candidate && !empty($p->post_title)) $candidate = $p->post_title;

    $candidate = stunnz_norm_code($candidate);
    if (!empty($candidate)){
        update_post_meta($post_id, STUNNZ_TRACKER_METAKEY, $candidate);
    }
}

/** Hook: index automatically on save/update */
add_action('save_post', function($post_id, $post){
    if ($post && $post->post_type === STUNNZ_TRACKER_POSTTYPE){
        // Index after the post exists and has a slug
        stunnz_index_shipment($post_id, false);
    }
}, 20, 2);

/** Daily cron: reindex all shipments (heals any missing codes) */
add_action('init', function(){
    if (!wp_next_scheduled('stunnz_tracker_daily_reindex')){
        // schedule to run once a day
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'stunnz_tracker_daily_reindex');
    }
});
add_action('stunnz_tracker_daily_reindex', function(){
    $paged = 1;
    do {
        $q = new WP_Query(array(
            'post_type'      => STUNNZ_TRACKER_POSTTYPE,
            'posts_per_page' => 200,
            'paged'          => $paged,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));
        if (!$q->have_posts()) break;
        foreach ($q->posts as $pid){
            stunnz_index_shipment($pid, false);
        }
        $paged++;
        wp_reset_postdata();
    } while (true);
});

/** Fallback lookup helper: try multiple keys to find a shipment by code */
function stunnz_find_shipment_by_code($code){
    $code = stunnz_norm_code($code);
    if ($code === '') return 0;

    // 1) Primary: original_post_title exact match
    $q = new WP_Query(array(
        'post_type'      => STUNNZ_TRACKER_POSTTYPE,
        'posts_per_page' => 1,
        'meta_query'     => array(array(
            'key'     => STUNNZ_TRACKER_METAKEY,
            'value'   => $code,
            'compare' => '='
        ))
    ));
    if ($q->have_posts()) return $q->posts[0]->ID;

    // 2) Slug match (post_name)
    $q = new WP_Query(array(
        'post_type'      => STUNNZ_TRACKER_POSTTYPE,
        'posts_per_page' => 1,
        'name'           => sanitize_title($code)
    ));
    if ($q->have_posts()) return $q->posts[0]->ID;

    // 3) Carrier ref number
    $q = new WP_Query(array(
        'post_type'      => STUNNZ_TRACKER_POSTTYPE,
        'posts_per_page' => 1,
        'meta_query'     => array(array(
            'key'     => 'wpcargo_carrier_ref_number',
            'value'   => $code,
            'compare' => '='
        ))
    ));
    if ($q->have_posts()) return $q->posts[0]->ID;

    return 0;
}

/* ----------------------------------------------------------------
   META BOX / SAVE (unchanged from your working version)
   ---------------------------------------------------------------- */

add_action('save_post', function($post_id, $post){
    if ($post->post_type !== STUNNZ_TRACKER_POSTTYPE) return;
    if (!isset($_POST['stunnz_checkpoints_nonce_field']) || !wp_verify_nonce($_POST['stunnz_checkpoints_nonce_field'], 'stunnz_checkpoints_nonce')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $clean = array();
    if (isset($_POST['stunnz_checkpoints']) && is_array($_POST['stunnz_checkpoints'])) {
        // Enforce first checkpoint note = Departure (can't be changed by UI)
        if (!empty($_POST['stunnz_checkpoints'][0])){
            $_POST['stunnz_checkpoints'][0]['note'] = 'Departure';
        }
        foreach ($_POST['stunnz_checkpoints'] as $entry) {
            $date = sanitize_text_field(isset($entry['date']) ? $entry['date'] : '');
            $place = sanitize_text_field(isset($entry['place']) ? $entry['place'] : '');
            $note  = sanitize_text_field(isset($entry['note']) ? $entry['note'] : ''); // carries Shipment Status
            $status = sanitize_text_field(isset($entry['status']) ? $entry['status'] : '');
            if ($place) $clean[] = array('date'=>$date,'place'=>$place,'note'=>$note,'status'=>$status);
        }
    }
    update_post_meta($post_id, 'stunnz_checkpoints', $clean);
    delete_post_meta($post_id, 'stunnz_geocoded_cache');
    delete_transient('stunnz_eta_'.$post_id);
}, 10, 2);

/* ----------------------------------------------------------------
   REST + helpers (kept intact; now uses fallback finder)
   ---------------------------------------------------------------- */
add_action('rest_api_init', function(){
    register_rest_route('stunnz/v1', '/track', array(
    'methods'  => 'GET',
    'callback' => function( WP_REST_Request $req ) {
        // Accept ?tracking_code=..., ?id=..., or ?code=...
        $tracking_id = sanitize_text_field( $req->get_param('tracking_code') ?: $req->get_param('id') ?: $req->get_param('code') );
        return rest_ensure_response( stunnz_rest_get( $tracking_id ) );
    },
    'permission_callback' => '__return_true'
));
    register_rest_route('stunnz/v1','/save_geocode',array('methods'=>'POST','callback'=>'stunnz_save_geocode','permission_callback'=>'__return_true'));
});

function stunnz_build_meta_clean($post_id){
    $raw = get_post_meta($post_id);
    $clean = array();
    $map = array(
        'wpcargo_shipper_name'=>'shipper_name','wpcargo_shipper_phone'=>'shipper_phone','wpcargo_shipper_address'=>'shipper_address','wpcargo_shipper_email'=>'shipper_email',
        'wpcargo_receiver_name'=>'receiver_name','wpcargo_receiver_phone'=>'receiver_phone','wpcargo_receiver_address'=>'receiver_address','wpcargo_receiver_email'=>'receiver_email',
        'wpcargo_type_of_shipment'=>'shipment_type','wpcargo_weight'=>'weight','wpcargo_courier'=>'courier','wpcargo_packages'=>'packages','wpcargo_mode_field'=>'mode',
        'wpcargo_product'=>'product','wpcargo_qty'=>'quantity','payment_wpcargo_mode_field'=>'payment_mode','wpcargo_total_freight'=>'total_freight',
        'wpcargo_carrier_field'=>'carrier','wpcargo_carrier_ref_number'=>'carrier_reference_no','wpcargo_departure_time_picker'=>'departure_time',
        'wpcargo_origin_field'=>'origin','wpcargo_destination'=>'destination','wpcargo_pickup_date_picker'=>'pickup_date','wpcargo_pickup_time_picker'=>'pickup_time',
        'wpcargo_expected_delivery_date_picker'=>'expected_delivery_date','wpcargo_comments'=>'comments'
    );
    foreach ($map as $k=>$t){
        if (!empty($raw[$k]) && isset($raw[$k][0])) {
            $val = maybe_unserialize($raw[$k][0]);
            if (is_scalar($val) && trim((string)$val) !== '') $clean[$t]=$val;
        }
    }
    $status = get_post_meta($post_id,'wpcargo_status',true);
    if (!empty($status)) $clean['status']=$status;

    $checkpoints = get_post_meta($post_id,'stunnz_checkpoints',true);
    if (!empty($checkpoints)) $clean['checkpoints']=$checkpoints;

    $packages = get_post_meta($post_id,'wpc-multiple-package',true);
    if (!empty($packages) && is_array($packages)){
        $valid=array();
        foreach($packages as $pkg){
            if (!empty($pkg['wpc-pm-qty'])||!empty($pkg['wpc-pm-piece-type'])||!empty($pkg['wpc-pm-description'])||!empty($pkg['wpc-pm-length'])||!empty($pkg['wpc-pm-width'])||!empty($pkg['wpc-pm-height'])||!empty($pkg['wpc-pm-weight'])){
                $valid[]=array(
                    'qty'=>isset($pkg['wpc-pm-qty'])?$pkg['wpc-pm-qty']:'','piece_type'=>isset($pkg['wpc-pm-piece-type'])?$pkg['wpc-pm-piece-type']:'','description'=>isset($pkg['wpc-pm-description'])?$pkg['wpc-pm-description']:'',
                    'length'=>isset($pkg['wpc-pm-length'])?$pkg['wpc-pm-length']:'','width'=>isset($pkg['wpc-pm-width'])?$pkg['wpc-pm-width']:'','height'=>isset($pkg['wpc-pm-height'])?$pkg['wpc-pm-height']:'','weight'=>isset($pkg['wpc-pm-weight'])?$pkg['wpc-pm-weight']:'',
                );
            }
        }
        if ($valid) $clean['packages_data']=$valid;
    }
    return $clean;
}

function stunnz_haversine_km($lat1,$lon1,$lat2,$lon2){
    $R=6371;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lat2-$lon1);
    $a=sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $R*(2*atan2(sqrt($a),sqrt(1-$a)));
}

function stunnz_simulated_eta_seconds($mode,$km){
    $speed=80;$m=strtolower((string)$mode);
    if (strpos($m,'air')!==false) $speed=900;
    elseif (strpos($m,'sea')!==false||strpos($m,'ocean')!==false) $speed=45;
    elseif (strpos($m,'land')!==false||strpos($m,'road')!==false) $speed=80;
    $hours = ($km>0 && $speed>0) ? ($km/$speed) : 0.5;
    return (int)round($hours*3600);
}

function stunnz_distance_matrix_eta($lat1,$lng1,$lat2,$lng2,$apiKey){
    $url='https://maps.googleapis.com/maps/api/distancematrix/json?units=metric'
        .'&origins='.rawurlencode($lat1.','.$lng1)
        .'&destinations='.rawurlencode($lat2.','.$lng2)
        .'&mode=driving&key='.rawurlencode($apiKey);
    $resp = wp_remote_get($url, array('timeout'=>10));
    if (is_wp_error($resp)) return false;
    if (wp_remote_retrieve_response_code($resp)!==200) return false;
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($body['rows'][0]['elements'][0]) || (isset($body['rows'][0]['elements'][0]['status'])?$body['rows'][0]['elements'][0]['status']: '')!=='OK') return false;
    $el=$body['rows'][0]['elements'][0];
    return array(
        'distance_text'=>isset($el['distance']['text'])?$el['distance']['text']:'',
        'eta_text'=>isset($el['duration']['text'])?$el['duration']['text']:'',
        'eta_value'=>(int)(isset($el['duration']['value'])?$el['duration']['value']:0), // seconds
        'mode_used'=>'driving'
    );
}

function stunnz_build_segments($post_id, $coords){
    $segments=array();
    if (count($coords)<2) return $segments;

    $cache_key='stunnz_eta_best_'.$post_id;
    $cached=get_transient($cache_key);
    if (is_array($cached)) return $cached;

    for($i=1;$i<count($coords);$i++){
        $a=$coords[$i-1]; $b=$coords[$i];
        $km = stunnz_haversine_km($a['lat'],$a['lng'],$b['lat'],$b['lng']);

        $landRow = stunnz_distance_matrix_eta($a['lat'],$a['lng'],$b['lat'],$b['lng'], STUNNZ_GOOGLE_API_KEY);
        if ($landRow===false){
            $etaLand = stunnz_simulated_eta_seconds('land',$km);
            $landRow = array('distance_text'=>sprintf('%s km', number_format_i18n($km,0)),'eta_value'=>$etaLand,'mode_used'=>'sim_land');
        }
        $etaAir = stunnz_simulated_eta_seconds('air',$km);
        $etaSea = stunnz_simulated_eta_seconds('sea',$km);

        $bestMode='land'; $bestEta=$landRow['eta_value']; $bestDistance = $landRow['distance_text'];
        if ($etaAir>0 && $etaAir < $bestEta){ $bestMode='air'; $bestEta=$etaAir; $bestDistance=sprintf('%s km', number_format_i18n($km,0)); }
        if ($etaSea>0 && $etaSea < $bestEta){ $bestMode='sea'; $bestEta=$etaSea; $bestDistance=sprintf('%s km', number_format_i18n($km,0)); }

        $modeLabel = ($bestMode==='air' ? 'Air Shipping' : ($bestMode==='sea' ? 'Sea Freight' : 'Land Shipping'));

        $segments[] = array(
            'from_index'   => $i-1,
            'to_index'     => $i,
            'eta_value'    => (int)$bestEta,
            'distance_text'=> $bestDistance,
            'mode_label'   => $modeLabel,
            'best_mode'    => $bestMode,
        );
    }

    set_transient($cache_key,$segments,6*HOUR_IN_SECONDS);
    return $segments;
}

/* -------------- REPLACED FUNCTION: broader fallbacks for lookup -------------- */
function stunnz_rest_get($tracking_id) {
    if (empty($tracking_id)) {
        return ['found' => false, 'error' => 'Empty tracking ID'];
    }

    // Try to locate the shipment
    $shipment = get_page_by_title($tracking_id, OBJECT, 'wpcargo_shipment');
    if (!$shipment) {
        $query = new WP_Query([
            'post_type'      => 'wpcargo_shipment',
            'meta_query'     => [[
                'key'     => '_wpcargo_tracking_number',
                'value'   => $tracking_id,
                'compare' => '='
            ]],
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ]);
        if ($query->have_posts()) $shipment = $query->posts[0];
    }

    if (!$shipment) {
        return ['found' => false, 'error' => 'Shipment not found in title or meta'];
    }

    $shipment_id = $shipment->ID;

    // Core response skeleton
    $response = [
        'found'       => true,
        'post_id'     => $shipment_id,
        'title'       => get_the_title($shipment_id),
        'status'      => get_post_status($shipment_id),
        'checkpoints' => [],
        'meta'        => [],
    ];

    // --- Shipper / Receiver ---
    $meta_keys = [
        'wpcargo_shipper_name'    => 'shipper_name',
        'wpcargo_shipper_phone'   => 'shipper_phone',
        'wpcargo_shipper_address' => 'shipper_address',
        'wpcargo_shipper_email'   => 'shipper_email',
        'wpcargo_receiver_name'   => 'receiver_name',
        'wpcargo_receiver_phone'  => 'receiver_phone',
        'wpcargo_receiver_address'=> 'receiver_address',
        'wpcargo_receiver_email'  => 'receiver_email',
    ];
    foreach ($meta_keys as $wp_key => $out_key) {
        $response['meta'][$out_key] = get_post_meta($shipment_id, $wp_key, true);
    }

    // --- Shipment info & packages ---
    if (function_exists('stunnz_build_meta_clean')) {
        $extra = stunnz_build_meta_clean($shipment_id);
        $response['meta'] = array_merge($response['meta'], $extra);
    }

    // --- Checkpoints ---
    $checkpoints = get_post_meta($shipment_id, 'stunnz_checkpoints', true);
    if (!empty($checkpoints) && is_array($checkpoints)) {
        foreach ($checkpoints as $cp) {
            $response['checkpoints'][] = [
                'date'   => $cp['date'] ?? '',
                'place'  => $cp['place'] ?? '',
                'note'   => $cp['note'] ?? '',
                'status' => $cp['status'] ?? ''
            ];
        }
    }

    return $response;
}
/* --------------------------- END REPLACED FUNCTION --------------------------- */

function stunnz_save_geocode($request){
    $body = $request->get_json_params();
    if (empty($body)) return array('saved'=>false);
    $post_id = intval($body['post_id']);
    $cache = get_post_meta($post_id,'stunnz_geocoded_cache',true) ?: array();
    $cache[intval($body['index'])] = array(
        'place'=>sanitize_text_field($body['place']),
        'lat'=>floatval($body['lat']),
        'lng'=>floatval($body['lng'])
    );
    update_post_meta($post_id,'stunnz_geocoded_cache',$cache);
    delete_transient('stunnz_eta_'.$post_id);
    delete_transient('stunnz_eta_best_'.$post_id);

    // Keep index healthy
    stunnz_index_shipment($post_id, false);

    return array('saved'=>true);
}

/* ----------------------------------------------------------------
   Shortcode renders ONLY the UI, and flags that assets must print in footer
   ---------------------------------------------------------------- */
add_shortcode('stunnz_tracking', function($atts){
    $atts = shortcode_atts(array('google_api_key' => ''), $atts, 'stunnz_tracking');
    $GLOBALS['STUNNZ_TRACKER_NEEDS_ASSETS'] = true;
    $GLOBALS['STUNNZ_TRACKER_ATTS'] = $atts;
    include plugin_dir_path(__FILE__).'frontend-v120.php';
});

/* ----------------------------------------------------------------
   Print CSS + JS in wp_footer (outside Elementor’s widget sanitization)
   ---------------------------------------------------------------- */
add_action('wp_footer', function(){
    if (empty($GLOBALS['STUNNZ_TRACKER_NEEDS_ASSETS'])) return;
    $atts = $GLOBALS['STUNNZ_TRACKER_ATTS'];
    $base = plugin_dir_path(__FILE__) . 'includes/';
    if (file_exists($base.'frontend-styles.php'))  include $base.'frontend-styles.php';
    if (file_exists($base.'frontend-scripts.php')) include $base.'frontend-scripts.php';
}, 99);

/* =================================================================
   Stunnz Dynamic Tracker — v1.4-LITE (PHP 7.4 → 8.x compatible)
   - No settings page
   - Lock first checkpoint status to 'Departure' (UI + server-side)
   - Require ≥ 2 checkpoint addresses (block publish + admin notice)
   - REST hardener to avoid false "Tracking code not found"
   ================================================================= */

// Hardcoded status keywords (kept global for future use if needed)
if (!isset($stunnz_pause_statuses)) {
    $stunnz_pause_statuses = array('On Hold','Pending Insurance','Cancelled');
}
if (!isset($stunnz_arrival_statuses)) {
    $stunnz_arrival_statuses = array('Delivered','Arrived','Arrival');
}

// Admin UI: disable/lock first checkpoint "Shipment Status" (note field) to 'Departure'
add_action('admin_enqueue_scripts', function($hook){
    if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
    global $post;
    $ptype = defined('STUNNZ_TRACKER_POSTTYPE') ? STUNNZ_TRACKER_POSTTYPE : 'wpcargo_shipment';
    if (!$post || $post->post_type !== $ptype) return;
    wp_add_inline_script('jquery-core', "(function(jQuery){jQuery(function(){ try{ var wrap=jQuery('#stunnz-checkpoints-wrapper'); if(!wrap.length) return; var first=wrap.find('.stunnz-cp-row').first(); var inp=first.find(\"input[name^='stunnz_checkpoints[0][note]']\"); if(!inp.length) return; if(!inp.val()) inp.val('Departure'); inp.prop('readonly',true).prop('disabled',true).css({opacity:.75}); }catch(e){} });})(jQuery);");
});

// Server-side: enforce first checkpoint Shipment Status (note) = 'Departure' before meta save
add_action('save_post', function($post_id, $post){
    $ptype = defined('STUNNZ_TRACKER_POSTTYPE') ? STUNNZ_TRACKER_POSTTYPE : 'wpcargo_shipment';
    if (!$post || $post->post_type !== $ptype) return;
    if (!isset($_POST['stunnz_checkpoints']) || !is_array($_POST['stunnz_checkpoints'])) return;
    if (!empty($_POST['stunnz_checkpoints'][0])){
        $_POST['stunnz_checkpoints'][0]['note'] = 'Departure';
    }
}, 5, 2);

// Validation: require at least two checkpoint locations to publish
add_filter('wp_insert_post_data', function($data, $postarr){
    $ptype = defined('STUNNZ_TRACKER_POSTTYPE') ? STUNNZ_TRACKER_POSTTYPE : 'wpcargo_shipment';
    if (!isset($postarr['post_type']) || $postarr['post_type'] !== $ptype) return $data;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $data;

    $count = 0;
    if (isset($_POST['stunnz_checkpoints']) && is_array($_POST['stunnz_checkpoints'])){
        foreach ($_POST['stunnz_checkpoints'] as $entry){
            $place = isset($entry['place']) ? trim($entry['place']) : '';
            if ($place !== '') $count++;
        }
    }
    if ($count < 2){
        if (isset($data['post_status']) && $data['post_status'] === 'publish'){
            $data['post_status'] = 'draft';
        }
        $uid = get_current_user_id();
        if ($uid) update_user_meta($uid, '_stunnz_cp_notice', 1);
    }
    return $data;
}, 9, 2);

// One-time admin notice for missing checkpoints (red, visible, white text)
add_action('admin_notices', function(){
    $uid = get_current_user_id();
    if (!$uid) return;
    $flag = get_user_meta($uid, '_stunnz_cp_notice', true);
    if ($flag){
        delete_user_meta($uid, '_stunnz_cp_notice');
        echo '<div class="notice notice-error is-dismissible" style="background:#cc0000;color:#fff;padding:12px 15px;border-left:6px solid #ff5c5c;">
                <p style="font-size:15px;font-weight:600;">
                    <strong>Stunnz Dynamic Tracker:</strong> Please add <u>at least two checkpoint addresses</u> before publishing this shipment.
                </p>
              </div>';
    }
});

// REST hardener: normalize /stunnz/v1/track response to avoid false "not found"
add_filter('rest_pre_echo_response', function($response, $server, $request){
    $route = method_exists($request,'get_route') ? $request->get_route() : '';
    if (strpos($route, '/stunnz/v1/track') === false) return $response;

    if (is_array($response)){
        // Lift meta.checkpoints if main 'checkpoints' missing
        if (!isset($response['checkpoints']) && isset($response['meta']) && isset($response['meta']['checkpoints']) && is_array($response['meta']['checkpoints'])){
            $response['checkpoints'] = $response['meta']['checkpoints'];
        }
        // If checkpoints present but found is missing/false, mark found true
        if (!isset($response['found']) && !empty($response['checkpoints'])){
            $response['found'] = true;
        }
        if (isset($response['found']) && $response['found'] === false && !empty($response['checkpoints'])){
            $response['found'] = true;
        }
        // Ensure status always present
        if (!isset($response['status'])){
            $pid = isset($response['post_id']) ? intval($response['post_id']) : 0;
            if ($pid) $response['status'] = get_post_meta($pid,'wpcargo_status',true);
            if (!isset($response['status']) || $response['status'] === null) $response['status'] = '';
        }
    }
    return $response;
}, 10, 3);

// Version hint for cache-busting (works alongside page caches/CDN)
add_action('wp_footer', function(){
    if (empty($GLOBALS['STUNNZ_TRACKER_NEEDS_ASSETS'])) return;
    $ver = defined('STUNNZ_TRACKER_VERSION') ? STUNNZ_TRACKER_VERSION : '1.0.0';
    echo '<script>(function(){try{window.STUNNZ_VERSION=\"' . esc_js($ver) . '\";}catch(e){}})();</script>';
}, 97);
// ==============================
// FRONTEND ASSET ENQUEUE (corrected, no UI here)
// ==============================
add_action('wp_enqueue_scripts', function() {
    // Styles
    wp_enqueue_style(
        'stunnz-frontend-styles',
        plugins_url('includes/frontend-styles.php', __FILE__),
        [],
        STUNNZ_TRACKER_VERSION
    );

    // Scripts
    wp_enqueue_script(
        'stunnz-frontend-scripts',
        plugins_url('includes/frontend-scripts.php', __FILE__),
        [],
        time(), // cache-buster
        true
    );
});

// ===============================
// ADMIN VALIDATION & UI LOGIC
// ===============================
add_action('add_meta_boxes', function() {
    add_meta_box(
        'stunnz_checkpoints_box',
        'Tracking Checkpoints',
        'stunnz_render_checkpoints_box_backend',
        STUNNZ_TRACKER_POSTTYPE, // ✅ Correct post type
        'normal',
        'default'
    );
});

if (!function_exists('stunnz_render_checkpoints_box_backend')) {
function stunnz_render_checkpoints_box_backend($post) {
    // ✅ Use the correct meta key
    $checkpoints = get_post_meta($post->ID, 'stunnz_checkpoints', true);
    if (!is_array($checkpoints)) $checkpoints = [];

    $statuses = [
        'Departure',
        'In Transit',
        'Enroute',
        'On Hold',
        'Delayed',
        'Destination',
        'Delivered'
    ];

    echo '<div id="stunnz-checkpoints-wrapper" style="margin-top:8px;">';

    foreach ($checkpoints as $i => $cp) {
        $address = esc_attr($cp['place'] ?? '');
        $status  = esc_attr($cp['status'] ?? '');
        echo '<div class="stunnz-cp-row" style="padding:8px;border:1px solid #ddd;background:#fafafa;margin-bottom:10px;">';
        echo "<p><label>Address / Location:<br><input type='text' name='stunnz_checkpoints[$i][place]' value='$address' class='widefat' required></label></p>";
        echo "<p><label>Shipment Status:<br><select name='stunnz_checkpoints[$i][status]' class='widefat' ".($i==0?'disabled':'required').">";
        echo "<option value=''>-- Select Status --</option>";
        foreach ($statuses as $st) {
            $sel = ($status === $st || ($i==0 && $st=='Departure')) ? 'selected' : '';
            echo "<option value='$st' $sel>$st</option>";
        }
        echo "</select></label></p>";
        echo '<p><button type="button" class="button stunnz-remove-row">Remove</button></p>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p><button type="button" class="button" id="stunnz-add-checkpoint">+ Add Checkpoint</button></p>';
    echo '<p><em>The first address is automatically marked as <strong>Departure</strong>.</em></p>';
    ?>
    <script>
    (function(){
      const statuses = ["Departure","In Transit","Enroute","On Hold","Delayed","Destination","Delivered"];
      document.addEventListener('click', function(e){
        if(e.target && e.target.id === 'stunnz-add-checkpoint'){
          const w = document.getElementById('stunnz-checkpoints-wrapper');
          const i = w.children.length;
          let opts = '<option value="">-- Select Status --</option>';
          statuses.forEach(st => opts += `<option value="${st}">${st}</option>`);
          const block = `
            <div class="stunnz-cp-row" style="padding:8px;border:1px solid #ddd;background:#fafafa;margin-bottom:10px;">
              <p><label>Address / Location:<br>
              <input type="text" name="stunnz_checkpoints[${i}][place]" class="widefat" required></label></p>
              <p><label>Shipment Status:<br>
              <select name="stunnz_checkpoints[${i}][status]" class="widefat" required>${opts}</select></label></p>
              <p><button type="button" class="button stunnz-remove-row">Remove</button></p>
            </div>`;
          w.insertAdjacentHTML('beforeend', block);
        }
        if(e.target && e.target.classList.contains('stunnz-remove-row')){
          e.target.closest('.stunnz-cp-row').remove();
        }
      });
    })();
    </script>
    <?php
}}
// Safe wrapper to prevent duplicate function name collision
if (!function_exists('stunnz_render_checkpoints_box_backend')) {
function stunnz_render_checkpoints_box_backend($post) {
    $checkpoints = get_post_meta($post->ID, '_stunnz_checkpoints', true);
    if (!is_array($checkpoints)) $checkpoints = [];

    $statuses = [
        'Departure',
        'In Transit',
        'Enroute',
        'On Hold',
        'Delayed',
        'Destination',
        'Delivered'
    ];

    echo '<div id="stunnz-checkpoints-wrapper" style="margin-top:8px;">';

    // Existing rows
    foreach ($checkpoints as $i => $cp) {
        $address = esc_attr($cp['place'] ?? '');
        $status  = esc_attr($cp['status'] ?? '');
        echo '<div class="stunnz-cp-row" style="padding:8px;border:1px solid #ddd;background:#fafafa;margin-bottom:10px;">';
        echo "<p><label>Address / Location:<br><input type='text' name='stunnz_checkpoints[$i][place]' value='$address' class='widefat' required></label></p>";
        echo "<p><label>Shipment Status:<br><select name='stunnz_checkpoints[$i][status]' class='widefat' ".($i==0?'disabled':'required').">";
        echo "<option value=''>-- Select Status --</option>";
        foreach ($statuses as $st) {
            $sel = ($status === $st || ($i==0 && $st=='Departure')) ? 'selected' : '';
            echo "<option value='$st' $sel>$st</option>";
        }
        echo "</select></label></p>";
        echo '<p><button type="button" class="button stunnz-remove-row">Remove</button></p>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p><button type="button" class="button" id="stunnz-add-checkpoint">+ Add Checkpoint</button></p>';
    echo '<p><em>The first address is automatically marked as <strong>Departure</strong>.</em></p>';

    // JS: add/remove rows
    ?>
    <script>
    (function(){
      const statuses = ["Departure","In Transit","Enroute","On Hold","Delayed","Destination","Delivered"];
      document.addEventListener('click', function(e){
        if(e.target && e.target.id === 'stunnz-add-checkpoint'){
          const w = document.getElementById('stunnz-checkpoints-wrapper');
          const i = w.children.length;
          let opts = '<option value="">-- Select Status --</option>';
          statuses.forEach(st => opts += `<option value="${st}">${st}</option>`);
          const block = `
            <div class="stunnz-cp-row" style="padding:8px;border:1px solid #ddd;background:#fafafa;margin-bottom:10px;">
              <p><label>Address / Location:<br>
              <input type="text" name="stunnz_checkpoints[${i}][place]" class="widefat" required></label></p>
              <p><label>Shipment Status:<br>
              <select name="stunnz_checkpoints[${i}][status]" class="widefat" required>${opts}</select></label></p>
              <p><button type="button" class="button stunnz-remove-row">Remove</button></p>
            </div>`;
          w.insertAdjacentHTML('beforeend', block);
        }
        if(e.target && e.target.classList.contains('stunnz-remove-row')){
          e.target.closest('.stunnz-cp-row').remove();
        }
      });
    })();
    </script>
    <?php
}}


// Validate before publish
add_action('save_post_shipment', function($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if ($post->post_type !== 'shipment') return;

    if (isset($_POST['stunnz_checkpoints'])) {
        $checkpoints = array_values(array_filter($_POST['stunnz_checkpoints'], function($c) {
            return !empty($c['place']);
        }));

        // Store checkpoints
        update_post_meta($post_id, '_stunnz_checkpoints', $checkpoints);

        // Validation logic
        $error = '';
        if (count($checkpoints) < 2) {
            $error = '❌ You must add at least two location checkpoints before publishing.';
        } else {
            foreach ($checkpoints as $i => $cp) {
                if ($i > 0 && empty($cp['status'])) {
                    $error = '❌ Every checkpoint (except the first) must have a shipment status selected.';
                    break;
                }
            }
        }

        if ($error) {
            // Prevent publish
            remove_action('save_post_shipment', __FUNCTION__, 10);
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            add_action('admin_notices', function() use ($error) {
                echo '<div class="notice notice-error" style="background:#b91c1c;color:#fff;font-weight:bold;padding:12px 16px;font-size:15px;text-align:center;border:none;">'.$error.'</div>';
            });
        }
    }
}, 10, 3);
/**
 * ===========================================================
 *  STUNNZ SERVER-SIDE PERSISTENCE (SAVE / LOAD / CLEAR STATE)
 * ===========================================================
 */
add_action('rest_api_init', function () {

    // --- Save tracking progress ---
    register_rest_route('stunnz/v1', '/save_state', [
        'methods'             => 'POST',
        'callback'            => 'stunnz_save_state',
        'permission_callback' => '__return_true',
    ]);

    // --- Load tracking progress ---
    register_rest_route('stunnz/v1', '/load_state', [
        'methods'             => 'GET',
        'callback'            => 'stunnz_load_state',
        'permission_callback' => '__return_true',
    ]);

    // --- Clear saved progress ---
    register_rest_route('stunnz/v1', '/clear_state', [
        'methods'             => 'POST',
        'callback'            => 'stunnz_clear_state',
        'permission_callback' => '__return_true',
    ]);
});


// -----------------------------------------------------------
// Function: SAVE STATE
// -----------------------------------------------------------
function stunnz_save_state(WP_REST_Request $req) {
    $params = $req->get_json_params();
    $code   = sanitize_text_field($params['tracking_code'] ?? '');
    if (!$code) {
        return new WP_Error('no_code', 'Tracking code missing', ['status' => 400]);
    }

    $data = wp_json_encode($params);
    update_option('stunnz_state_' . $code, $data, false);
    return ['saved' => true];
}


// -----------------------------------------------------------
// Function: LOAD STATE
// -----------------------------------------------------------
function stunnz_load_state(WP_REST_Request $req) {
    $code = sanitize_text_field($req->get_param('tracking_code') ?? '');
    if (!$code) {
        return new WP_Error('no_code', 'Tracking code missing', ['status' => 400]);
    }

    $data = get_option('stunnz_state_' . $code, '');
    return $data ? json_decode($data, true) : [];
}


// -----------------------------------------------------------
// Function: CLEAR STATE
// -----------------------------------------------------------
function stunnz_clear_state(WP_REST_Request $req) {
    $code = sanitize_text_field($req->get_param('tracking_code') ?? '');
    if ($code) {
        delete_option('stunnz_state_' . $code);
    }
    return ['cleared' => true];
}

/* ============================================================
 * STUNNZ OFFLINE LICENSE VALIDATION (Local Protection)
 * Version: 1.0 (for StunnzDynamicTracker)
 * ============================================================ */

if (!defined('ABSPATH')) exit;

// === Your Private Master Key (you must give this only to authorized installs) ===
define('STUNNZ_LICENSE_MASTER_KEY', 'STUNNZ-PRO-V4-97X8-ALPHA-2584');
define('STUNNZ_LICENSE_SALT', 'stz_98hfa0s7p2zq'); // you can change this random salt anytime

// === Internal option names (do not change) ===
define('STUNNZ_LICENSE_OPTION_KEY',   'stunnz_license_key');
define('STUNNZ_LICENSE_VALID_OPTION', 'stunnz_license_valid');

/* ------------------------------------------------------------
   Helper functions
------------------------------------------------------------ */
function stunnz_norm_key($key){
    $key = strtoupper(trim((string)$key));
    return preg_replace('/[^A-Z0-9]/', '', $key);
}

function stunnz_hash_key($key){
    return hash_hmac('sha256', stunnz_norm_key($key), STUNNZ_LICENSE_SALT);
}

function stunnz_timing_safe_equals($a, $b){
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i=0; $i<strlen($a); $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
    return $res === 0;
}

function stunnz_validate_license_key($key){
    $norm   = stunnz_norm_key($key);
    $master = stunnz_norm_key(STUNNZ_LICENSE_MASTER_KEY);
    if (stunnz_timing_safe_equals($norm, $master)) return true;
    return stunnz_timing_safe_equals(stunnz_hash_key($norm), stunnz_hash_key($master));
}

function stunnz_license_is_valid(){
    $cached = get_option(STUNNZ_LICENSE_VALID_OPTION, 0);
    if ($cached) return true;
    $key = get_option(STUNNZ_LICENSE_OPTION_KEY, '');
    if ($key && stunnz_validate_license_key($key)){
        update_option(STUNNZ_LICENSE_VALID_OPTION, 1, false);
        return true;
    }
    return false;
}

/* ------------------------------------------------------------
   Activation redirect
------------------------------------------------------------ */
register_activation_hook(__FILE__, function(){
    update_option('stunnz_license_activation_redirect', 1, false);
});

add_action('admin_init', function(){
    if (get_option('stunnz_license_activation_redirect')){
        delete_option('stunnz_license_activation_redirect');
        if (!stunnz_license_is_valid()){
            wp_safe_redirect(admin_url('options-general.php?page=stunnz-license'));
            exit;
        }
    }
});

/* ------------------------------------------------------------
   Admin page: License entry
------------------------------------------------------------ */
add_action('admin_menu', function(){
    add_options_page('Stunnz License', 'Stunnz License', 'manage_options', 'stunnz-license', 'stunnz_license_render_page');
});

function stunnz_license_render_page(){
    if (!current_user_can('manage_options')) return;
    $msg = '';
    if (isset($_POST['stunnz_license_nonce']) && wp_verify_nonce($_POST['stunnz_license_nonce'], 'stunnz_save_license')){
        $key = sanitize_text_field($_POST['stunnz_license_key'] ?? '');
        if ($key && stunnz_validate_license_key($key)){
            update_option(STUNNZ_LICENSE_OPTION_KEY, $key, false);
            update_option(STUNNZ_LICENSE_VALID_OPTION, 1, false);
            $msg = '<div class="updated"><p><strong>License Activated.</strong></p></div>';
        } else {
            update_option(STUNNZ_LICENSE_VALID_OPTION, 0, false);
            $msg = '<div class="error"><p><strong>Invalid License Key.</strong></p></div>';
        }
    }
    $valid = stunnz_license_is_valid();
    ?>
    <div class="wrap">
        <h1>Stunnz Dynamic Tracker — License</h1>
        <?php echo $msg; ?>
        <form method="post">
            <?php wp_nonce_field('stunnz_save_license', 'stunnz_license_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="stunnz_license_key">Product Key</label></th>
                    <td>
                        <input type="text" name="stunnz_license_key" id="stunnz_license_key" class="regular-text" value="<?php echo esc_attr(get_option(STUNNZ_LICENSE_OPTION_KEY,'')); ?>">
                        <p class="description">
                            Status: <?php echo $valid ? '<strong style="color:green;">Active ✓</strong>' : '<strong style="color:red;">Inactive</strong>'; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button($valid ? 'Update Key' : 'Activate'); ?>
        </form>
    </div>
    <?php
}

/* ------------------------------------------------------------
   Frontend & backend restrictions
------------------------------------------------------------ */
add_action('init', function(){
    if (is_admin()) return;
    if (stunnz_license_is_valid()) return;

    // Disable shortcode output
    if (shortcode_exists('stunnz_tracking')) remove_shortcode('stunnz_tracking');
    add_shortcode('stunnz_tracking', function(){
        if (current_user_can('manage_options')){
            return '<div style="padding:10px;background:#ffefef;border:1px solid #cc0000;">Stunnz Dynamic Tracker is not activated. <a href="'.esc_url(admin_url('options-general.php?page=stunnz-license')).'">Enter your product key</a>.</div>';
        }
        return '';
    });
}, 1);

add_action('wp_enqueue_scripts', function(){
    if (stunnz_license_is_valid()) return;
    wp_dequeue_script('stunnz-frontend-scripts');
    wp_dequeue_style('stunnz-frontend-styles');
}, 999);

add_action('admin_notices', function(){
    if (stunnz_license_is_valid() || !current_user_can('manage_options')) return;
    $url = admin_url('options-general.php?page=stunnz-license');
    echo '<div class="notice notice-error"><p><strong>Stunnz Dynamic Tracker</strong> requires activation. Please enter your product key on the <a href="'.esc_url($url).'">License page</a>.</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $url = admin_url('options-general.php?page=stunnz-license');
    array_unshift($links, '<a href="'.esc_url($url).'">'.(stunnz_license_is_valid() ? 'License ✓' : 'Activate License').'</a>');
    return $links;
});