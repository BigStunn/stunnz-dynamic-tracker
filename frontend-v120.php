<?php
/* Frontend view for StunnzDynamicTracker v1.3.5
   - Defines stz_safe_svg helper
   - Outputs only the UI (scripts/styles are printed from wp_footer)
*/

if (!function_exists('stz_safe_svg')) {
  function stz_safe_svg($svg) {
    $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg);
    $svg = preg_replace('/<\?(?!xml)/i', '', $svg);
    $svg = preg_replace('/\s+/', ' ', trim($svg));
    return $svg;
  }
}

$base = plugin_dir_path(__FILE__) . 'includes/';
include $base . 'frontend-ui.php';