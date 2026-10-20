<?php /* UI */ ?>
<div class="stunnz-wrap">
  <form class="stunnz-form" onsubmit="return false;">
    <input id="stunnz-code" type="text" placeholder="Enter tracking code (e.g. TRK12345)" />
    <button id="stunnz-btn" type="button">Track</button>
  </form>

  <!-- ETA Banner -->
  <div id="stunnz-edt" style="display:none;">
    <div class="pill">
      <div class="left">
        <span class="dot"></span>
        <span class="title">Estimated Drive Time:</span>
        <span class="time" id="stunnz-edt-text">—</span>
      </div>
      <div class="right meta">
        <span id="stunnz-edt-dist">—</span>
        <span class="sep">|</span>
        <span class="mode" id="stunnz-edt-mode">—</span>
      </div>
    </div>
  </div>

  <!-- Progress -->
  <div id="stunnz-progress" style="display:none;">
    <div class="stz-progress-wrap" id="stz-progress-wrap">
      <div class="stz-progress-bar" id="stz-progress-bar"></div>
      <div class="stz-progress-label" id="stz-progress-label">0% Completed</div>
    </div>
  </div>

  <div id="stunnz-msg" class="stunnz-msg"></div>

  <!-- delayed spinner -->
  <div id="stunnz-spinner" style="display:none;">
    <div style="display:flex;align-items:center;">
      <div class="stz-loader"></div>
      <span>Loading Google Maps…</span>
    </div>
  </div>

  <div id="stunnz-map" style="display:none;"></div>

  <!-- Info Panels -->
  <div id="stunnz-panels" class="stunnz-panels" style="display:none;">
    <div id="panel-shipper" class="stunnz-panel stunnz-half">
      <h3>Shipper Details</h3><div class="body"></div>
    </div>
    <div id="panel-receiver" class="stunnz-panel stunnz-half">
      <h3>Receiver Details</h3><div class="body"></div>
    </div>
    <div id="panel-shipment" class="stunnz-panel stunnz-full">
      <h3>Shipment Information</h3><div class="body"></div>
    </div>
    <div id="panel-packages" class="stunnz-panel stunnz-full" style="display:none;">
      <h3>Packages</h3><div class="body"></div>
    </div>
  </div>

  <div id="stunnz-timeline" class="stunnz-timeline"></div>

  <!-- Soft chime -->
  <audio id="stunnz-chime" preload="auto">
    <source src="data:audio/mp3;base64,//uQZAAAAAAAAAAAAAAAAAAAA..." type="audio/mpeg">
  </audio>

  <!-- Version Badge -->
  <div id="stunnz-version-badge" style="
    position: fixed; right: 14px; bottom: 12px;
    background: rgba(14,165,233,0.1);
    border: 1px solid rgba(14,165,233,0.3);
    color: #0369a1; font-size: 12px; font-weight: 600;
    font-family: 'Segoe UI', Roboto, sans-serif;
    padding: 6px 10px; border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    z-index: 9999; pointer-events: none; backdrop-filter: blur(4px);
    opacity: 0; transform: translateY(10px);
    animation: fadeInBadge 1.2s ease-out 0.5s forwards;">
    <span style="opacity:0.8;">StunnzDynamicTracker</span>
    <span style="opacity:0.6;">
      v<?php echo esc_html(defined('STUNNZ_PLUGIN_VERSION') ? STUNNZ_PLUGIN_VERSION : '1.0.0'); ?>
    </span>
  </div>
</div>