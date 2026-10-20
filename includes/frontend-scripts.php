<?php
/* Scripts â€” multi-leg routes + pause countdown + persistence (safe) */
// Resolve assets from plugin root safely after the folder rename
$__stz_main_file = dirname(__DIR__) . '/stunnz-dynamic-tracker.php'; // main plugin file
$__stz_assets_base = plugins_url('assets/', $__stz_main_file);
?>

<script>

(function(){
/* =========================
   Safe boot + Elementor hooks
   ========================= */
let _bound = false;
function bindOnce() {
  if (_bound) return false;
  const btn = document.getElementById('stunnz-btn');
  const codeInput = document.getElementById('stunnz-code');
  const map = document.getElementById('stunnz-map');
  if (!btn || !codeInput || !map) {
    // try again shortly if UI loads later (like via wp_footer)
    setTimeout(bindOnce, 300);
    return false;
  }
  _bound = true;
  console.log('✅ initStunnzTracker() has started');
  initStunnzTracker();
  return true;
}
if (document.readyState !== 'loading') bindOnce();
else document.addEventListener('DOMContentLoaded', bindOnce);
if (window.jQuery && window.elementorFrontend) {
  jQuery(window).on('elementor/frontend/init', function(){
    elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function($scope){
      if ($scope && $scope.find && $scope.find('#stunnz-btn').length) bindOnce();
    });
  });
}
const mo = new MutationObserver(() => { bindOnce(); });
mo.observe(document.documentElement, {childList:true, subtree:true});

/* =========================
   Main tracker boot function
   ========================= */
function initStunnzTracker(){
  console.log('âœ… initStunnzTracker() has started');
  const REST_ENDPOINT='<?php echo rest_url("stunnz/v1/track"); ?>';
  const SAVE_ENDPOINT='<?php echo rest_url("stunnz/v1/save_geocode"); ?>';
  const API_KEY='<?php echo esc_js(isset($atts["google_api_key"]) ? $atts["google_api_key"] : (defined("STUNNZ_GOOGLE_API_KEY") ? STUNNZ_GOOGLE_API_KEY : "")); ?>';

  // Timings / behavior
  const POLL_INTERVAL = 10000;
  const VISUAL_SCALE  = 1;
  const WAIT_BETWEEN_LEGS_MS = 5 * 60 * 1000; // 5 minutes

  // Fallback speeds (km/h)
  const SPEEDS = { land: 80, air: 850, sea: 35 };

  // Colors
  const ROUTE_COLORS = {
    land: '#ef4444',     // red
    air : '#f59e0b',     // golden yellow (darker)
    sea : '#facc15'      // golden yellow (lighter)
  };

  // State
  let map, geocoder, markers=[], legPolylines=[], animating=false, pollTimer, pendingNewData;
  let trailLine = null;
  let glowLine = null;
  let directionsService=null;
  let mover = null; // overlay mover
  let countdownTimer = null;

  // UI refs
  let edtWrap=null, edtText=null, edtDist=null, edtMode=null, edtTimer=null, edtEndAt=null;
  let pWrap=null, pBar=null, pLabel=null;
  const spinner = document.getElementById('stunnz-spinner');
  let spinnerTimer = null;

  // Static marker icons (from your /assets)
 const ICONS = {
  warehouse  :'<?php echo plugins_url("assets/warehouse.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  checkpoint :'<?php echo plugins_url("assets/checkpoint.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  hand       :'<?php echo plugins_url("assets/hand.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  destination:'<?php echo plugins_url("assets/destination.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  plane      :'<?php echo plugins_url("assets/plane.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  ship       :'<?php echo plugins_url("assets/ship.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>',
  truck      :'<?php echo plugins_url("assets/truck.svg", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>'
};

  /* ---------------- Google loader ---------------- */
  function loadGoogle(cb){
    if(window.google && window.google.maps){ cb(); return; }
    if(!window._stzGoogleCallbacks){ window._stzGoogleCallbacks=[]; }
    window._stzGoogleCallbacks.push(cb);
    if(window._stzGoogleLoading) return;
    window._stzGoogleLoading = true;

    spinnerTimer = setTimeout(()=>{ spinner && (spinner.style.display='flex'); }, 800);

    function finish(){
      clearTimeout(spinnerTimer);
      if (spinner){
        spinner.style.opacity='0';
        setTimeout(()=>{ spinner.style.display='none'; spinner.style.opacity='1'; }, 250);
      }
      const q = window._stzGoogleCallbacks || [];
      window._stzGoogleCallbacks = [];
      q.forEach(f=>{ try{ f(); }catch(e){} });
    }

    const s=document.createElement('script');
    const key = encodeURIComponent(API_KEY || '<?php echo STUNNZ_GOOGLE_API_KEY; ?>');
    s.src='https://maps.googleapis.com/maps/api/js?key='+ key +'&libraries=geometry,places';
    s.async=true; s.defer=true;
    s.onload=()=>{ const iv=setInterval(()=>{ if(window.google && window.google.maps){ clearInterval(iv); finish(); } }, 80); };
    s.onerror=()=>{ clearTimeout(spinnerTimer); if(spinner){ spinner.style.display='flex'; spinner.innerHTML = '<div style="padding:16px 20px;color:#d00;font-weight:700;background:#fff;border:1px solid #f3bcbc;border-radius:10px;">Google Maps failed to load. Please refresh.</div>'; } };
    document.head.appendChild(s);
  }

  /* ---------------- Mover overlay (slight forward lean; no tilt animation) ---------------- */
  function createMoverOverlay(map){
    class MoverOverlay extends google.maps.OverlayView{
      constructor(map){
        super(); this._map=map; this.position=null; this.rotation=0; this.scale=1; this.mode='land'; this.isMoving = false; // new flag for lean control
        this.div=null; this.img=null; this.badge=null; this.setMap(map);
      }
      onAdd(){
        this.div=document.createElement('div');
        this.div.className='stz-mover land glow';
        this.div.style.position='absolute';
        // vehicle
        this.img=new Image();
        this.img.src = ICONS.truck;
        this.img.alt='vehicle';
        this.img.style.width='40px';
        this.img.style.height='auto';
        this.img.draggable=false;
        // countdown badge
        this.badge=document.createElement('div');
        this.badge.style.position='absolute';
        this.badge.style.left='50%';
        this.badge.style.bottom='42px';
        this.badge.style.transform='translateX(-50%)';
        this.badge.style.padding='2px 6px';
        this.badge.style.font='600 11px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
        this.badge.style.background='rgba(17,24,39,0.8)';
        this.badge.style.color='#fff';
        this.badge.style.borderRadius='6px';
        this.badge.style.boxShadow='0 2px 8px rgba(0,0,0,0.25)';
        this.badge.style.display='none';

        this.div.appendChild(this.img);
        this.div.appendChild(this.badge);
        this.getPanes().overlayMouseTarget.appendChild(this.div);
      }
      draw(){
        if(!this.position||!this.div) return;
        const proj=this.getProjection();
        const p=proj.fromLatLngToDivPixel(this.position);
        this.div.style.left=p.x+'px';
        this.div.style.top=p.y+'px';
		
		// stay upright but lean slightly forward
		const lean = this.isMoving ? 7 : 0; // slight tilt
		this.div.style.transformOrigin = '50% 100%'; // pivot from bottom
		this.div.style.transform = `translate(-50%, -100%) rotate(${this.rotation + lean}deg) scale(${this.scale})`;
		this.div.style.transition = 'transform 0.35s ease-out';


      }
      onRemove(){ if(this.div&&this.div.parentNode) this.div.parentNode.removeChild(this.div); this.div=null; this.img=null; this.badge=null; }
      setPosition(latLng){ this.position=latLng; this.draw(); }
      setRotation(deg){ this.rotation=deg; this.draw(); }
      setScale(s){ this.scale=s; this.draw(); }
      setMode(modeKey){
        this.mode = (modeKey||'land').toLowerCase();
        if(!this.div) return;
        this.div.classList.remove('land','air','sea'); this.div.classList.add(this.mode);
        let src=ICONS.truck;
        if(this.mode==='air') src=ICONS.plane;
        else if(this.mode==='sea') src=ICONS.ship;
        this.img.src = src;
        this.setScale(this.mode==='land' ? 1.0 : 1.1);
      }
      showCountdown(txt){ if(!this.badge) return; this.badge.textContent = txt; this.badge.style.display='block'; }
      hideCountdown(){ if(!this.badge) return; this.badge.style.display='none'; }
    }
    return new MoverOverlay(map);
  }

  /* ---------------- Helpers ---------------- */
  function norm(s){ return (s||'').toString().trim().toLowerCase(); }
  function statusIsHold(s){ s=norm(s); return /on hold|pending insurance|cancelled/.test(s); }
  function statusIsArrived(s){ s=norm(s); return /delivered|arrival|arrived|destination/.test(s); }

  function iconForCheckpoint(cp, i, lastIdx){
    const st = norm(cp.status||cp.note||'');
    if (i===0 || /depart|origin/.test(st)) return ICONS.warehouse;
    if (statusIsHold(st)) return ICONS.hand;
    if (i===lastIdx || statusIsArrived(st)) return ICONS.destination;
    return ICONS.checkpoint;
  }
  function makeRow(k,v){return v?`<tr><td class="key">${k}</td><td>${v}</td></tr>`:'';}

  function renderMeta(meta){
    if(!meta)return;
    const shipper = { Name: meta.shipper_name||'', Phone: meta.shipper_phone||'', Address: meta.shipper_address||'', Email: meta.shipper_email||'' };
    const receiver= { Name: meta.receiver_name||'', Phone: meta.receiver_phone||'', Address: meta.receiver_address||'', Email: meta.receiver_email||'' };
    const shipment = {
      'Type of Shipment': meta.shipment_type||'',
      'Weight': meta.weight||'',
      'Courier': meta.courier||'',
      'Packages': meta.packages||'',
      'Mode': meta.mode||'',
      'Product': meta.product||'',
      'Quantity': meta.quantity||'',
      'Payment Mode': meta.payment_mode||'',
      'Total Freight': meta.total_freight||'',
      'Carrier': meta.carrier||'',
      'Carrier Reference No.': meta.carrier_reference_no||'',
      'Departure Time': meta.departure_time||'',
      'Origin': meta.origin||'',
      'Destination': meta.destination||'',
      'Pickup Date': meta.pickup_date||'',
      'Pickup Time': meta.pickup_time||'',
      'Expected Delivery Date': meta.expected_delivery_date||'',
      'Comments': meta.comments||''
    };
    const fill=(sel,data)=>{document.querySelector(sel+' .body').innerHTML='<table>'+Object.entries(data).map(([k,v])=>makeRow(k,v)).join('')+'</table>';};
    fill('#panel-shipper', shipper);
    fill('#panel-receiver', receiver);
    fill('#panel-shipment', shipment);
    document.getElementById('stunnz-panels').style.display='flex';

    const pk = meta.packages_data || [];
    const pkgPanel = document.getElementById('panel-packages');
    if (Array.isArray(pk) && pk.length){
      let html = `<table class="stunnz-table">
        <thead><tr><th>Qty.</th><th>Piece Type</th><th>Description</th><th>Length (cm)</th><th>Width (cm)</th><th>Height (cm)</th><th>Weight (kg)</th></tr></thead><tbody>`;
      pk.forEach(p=>{html += `<tr><td>${p.qty||''}</td><td>${p.piece_type||''}</td><td>${p.description||''}</td><td>${p.length||''}</td><td>${p.width||''}</td><td>${p.height||''}</td><td>${p.weight||''}</td></tr>`;});
      html += `</tbody></table>`;
      pkgPanel.querySelector('.body').innerHTML = html;
      pkgPanel.style.display = 'block';
    } else pkgPanel.style.display='none';
  }

  /* ---------------- EDT + Progress ---------------- */
  function fmtTime(ms){ if(ms<0) ms=0; const s=Math.round(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60), ss=s%60; if(h>0)return `${h}h ${m}m ${ss}s`; if(m>0)return `${m}m ${ss}s`; return `${ss}s`; }
  function startEDT(totalMs, distanceText, modeLabel){
    edtWrap = edtWrap || document.getElementById('stunnz-edt');
    edtText = edtText || document.getElementById('stunnz-edt-text');
    edtDist = edtDist || document.getElementById('stunnz-edt-dist');
    edtMode = edtMode || document.getElementById('stunnz-edt-mode');
    edtEndAt = Date.now() + totalMs;
    edtWrap.style.display='block';
    edtDist.textContent = distanceText || 'â€”';
    edtMode.textContent = modeLabel || 'â€”';
    updateEDT(); clearInterval(edtTimer); edtTimer = setInterval(updateEDT, 1000);

    pWrap = pWrap || document.getElementById('stz-progress-wrap');
    pBar = pBar || document.getElementById('stz-progress-bar');
    pLabel = pLabel || document.getElementById('stz-progress-label');
    document.getElementById('stunnz-progress').style.display='block';
    pWrap.classList.remove('stz-progress-complete');
    setProgress(0);
  }
  function updateEDT(){ const msLeft = edtEndAt - Date.now(); edtText.textContent = fmtTime(msLeft); if (msLeft <= 0) stopEDT(); }
  function stopEDT(){ clearInterval(edtTimer); edtTimer=null; edtEndAt=null; }
  function setProgress(f){ if(!pBar||!pLabel) return; const pct=Math.max(0,Math.min(1,f)); pBar.style.transform=`scaleX(${pct})`; pLabel.textContent=`${Math.round(pct*100)}% Completed`; }
  function completeProgress(){ if(!pWrap) return; pWrap.classList.add('stz-progress-complete'); setProgress(1); }

	/* ---------------- Persistence (Server-Side via REST) ---------------- */
	// one place to hit the WP REST endpoints
	const PERSIST = {
	  save: async (code, obj) => {
		try {
		  await fetch('<?php echo rest_url("stunnz/v1/save_state"); ?>', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({ tracking_code: code, ...obj })
		  });
		} catch(e){ console.warn('Save state failed', e); }
	  },
	  load: async (code) => {
		try {
		  const r = await fetch('<?php echo rest_url("stunnz/v1/load_state"); ?>?tracking_code='+encodeURIComponent(code));
		  if (!r.ok) return null;
		  return await r.json();
		} catch(e){ console.warn('Load state failed', e); return null; }
	  },
	  clear: async (code) => {
		try {
		  await fetch('<?php echo rest_url("stunnz/v1/clear_state"); ?>?tracking_code='+encodeURIComponent(code), { method:'POST' });
		} catch(e){ console.warn('Clear state failed', e); }
	  }
	};

	// deterministic signature so saved state invalidates if checkpoints change
	function checkpointsSig(coords){
	  try { return coords.map(c=>`${(+c.lat).toFixed(3)},${(+c.lng).toFixed(3)}`).join('|'); }
	  catch(e){ return ''; }
	}


  /* ---------------- Path + Routes ---------------- */
  function pathTotalMeters(pts){
    let sum=0;
    for(let i=1;i<pts.length;i++){
      sum += google.maps.geometry.spherical.computeDistanceBetween(pts[i-1], pts[i]);
    }
    return sum;
  }
  function positionAlongPath(pts, meters){
    if(meters<=0) return { pos: pts[0], idx: 0, next: 1, frac: 0 };
    let acc=0;
    for(let i=1;i<pts.length;i++){
      const seg = google.maps.geometry.spherical.computeDistanceBetween(pts[i-1], pts[i]);
      if(acc+seg >= meters){
        const remain = meters-acc;
        const f = Math.max(0, Math.min(1, remain/seg));
        const lat = pts[i-1].lat() + (pts[i].lat()-pts[i-1].lat())*f;
        const lng = pts[i-1].lng() + (pts[i].lng()-pts[i-1].lng())*f;
        return { pos: new google.maps.LatLng(lat,lng), idx: i-1, next: i, frac: f };
      }
      acc += seg;
    }
    return { pos: pts[pts.length-1], idx: pts.length-2, next: pts.length-1, frac: 1 };
  }
  // road route (Directions)
  function getRoadPath(A,B){
    return new Promise(resolve=>{
      if(!directionsService) directionsService = new google.maps.DirectionsService();
      directionsService.route({ origin:A, destination:B, travelMode: google.maps.TravelMode.DRIVING }, (result, status)=>{
        if(status==='OK' && result?.routes?.[0]){
          resolve(result.routes[0].overview_path.slice());
        } else {
          resolve([A,B]);
        }
      });
    });
  }
  // curved arc for air/sea
  function buildCurvedArcPath(A, B, bulge=0.22, samples=48){
    const dist = google.maps.geometry.spherical.computeDistanceBetween(A,B);
    const mid  = google.maps.geometry.spherical.interpolate(A,B,0.5);
    const head = google.maps.geometry.spherical.computeHeading(A,B);
    const ctrl = google.maps.geometry.spherical.computeOffset(mid, dist*bulge, head+90);
    const pts=[];
    for(let i=0;i<=samples;i++){
      const t = i/samples;
      const p1 = google.maps.geometry.spherical.interpolate(A, ctrl, t);
      const p2 = google.maps.geometry.spherical.interpolate(ctrl, B, t);
      const p  = google.maps.geometry.spherical.interpolate(p1, p2, t);
      pts.push(p);
    }
    return pts;
  }
  function smoothPathChaikin(latlngs){
    if(latlngs.length<3) return latlngs;
    const out=[latlngs[0]];
    for(let i=0;i<latlngs.length-1;i++){
      const p=latlngs[i], q=latlngs[i+1];
      const P = new google.maps.LatLng( p.lat() + 0.75*(q.lat()-p.lat()), p.lng() + 0.75*(q.lng()-p.lng()) );
      const Q = new google.maps.LatLng( p.lat() + 0.25*(q.lat()-p.lat()), p.lng() + 0.25*(q.lng()-p.lng()) );
      out.push(Q,P);
    }
    out.push(latlngs[latlngs.length-1]);
    return out;
  }
  async function buildRoutePoints(modeKey, A, B){
    if(modeKey==='land') return await getRoadPath(A,B);
    const bulge = (modeKey==='air') ? 0.25 : 0.14;
    let arc = buildCurvedArcPath(A,B, bulge, 48);
    arc = smoothPathChaikin(arc);
    return arc;
  }

  /* ---------------- Movement along route + pause ---------------- */
  async function moveAlongRoute(routePts, totalMs, modeKey, modeLabel, code, segIndex){
    if(!mover) return false;
    const totalMeters = pathTotalMeters(routePts);
    if(totalMeters <= 0){ return false; }

    // set initial pos / heading
    const info0 = positionAlongPath(routePts, 0);
    mover.setPosition(info0.pos);
    const ahead0 = positionAlongPath(routePts, Math.min(totalMeters, 8));
    mover.setRotation(google.maps.geometry.spherical.computeHeading(info0.pos, ahead0.pos));
    mover.setMode(modeKey);
    mover.hideCountdown();

    // EDT + progress
    startEDT(totalMs, `${Math.round(totalMeters/1000)} km`, modeLabel);
    setProgress(0);

    // animate by elapsed time (so EDT, progress, icon stay in perfect sync)
    const startedAt = Date.now();
    let lastSave=0, lastTick = startedAt;

    async function tick(){
	  mover.isMoving = true;
      if(!animating) return false;
      const now = Date.now();
      const elapsed = now - startedAt;
      const f = Math.max(0, Math.min(1, elapsed/totalMs));
      const meters = totalMeters * f;

      const info = positionAlongPath(routePts, meters);
      mover.setPosition(info.pos);
	  
	  // === Blue trail animation ===
	if (trailLine && glowLine) {
	  trailLine.getPath().push(info.pos);
	  glowLine.getPath().push(info.pos);
	  // Limit number of trail points
	  if (trailLine.getPath().getLength() > 120) {
		trailLine.getPath().removeAt(0);
		glowLine.getPath().removeAt(0);
	  }
	}

      const ahead = positionAlongPath(routePts, Math.min(totalMeters, meters + Math.max(20, totalMeters*0.002)));
      const heading = google.maps.geometry.spherical.computeHeading(info.pos, ahead.pos);
      mover.setRotation(heading);

      // live UI
      const leftMeters = Math.max(0, totalMeters - meters);
      edtDist.textContent = `${Math.max(0, Math.round(leftMeters/1000))} km`;
      setProgress(f);

      // persist every ~1s
      if(now-lastSave>1000){
        lastSave=now;
        await PERSIST.save(code,{
          v:6, post_id:window._stzPostId||null,
          phase:'move',
          seg_index: segIndex,
          started_at: startedAt,
          total_ms: totalMs,
          route_fraction: f,
          checkpoints_sig: window._stzSig||''
        });
      }

      if(elapsed >= totalMs){
		mover.isMoving = false;
		mover.setRotation(google.maps.geometry.spherical.computeHeading(routePts[routePts.length-2], routePts[routePts.length-1]));
        setProgress(1); completeProgress(); stopEDT();
        mover.setPosition(routePts[routePts.length-1]);
        return true;
      }

      const delay = Math.max(16, 16 - (now - lastTick));
      lastTick = now;
      await new Promise(r=>setTimeout(r, delay));
      return tick();
    }
    return tick();
  }

	// ========================================================
	// Smoothly move truck from last checkpoint to new checkpoint
	// ========================================================
	async function handleNewLeg(prev, next) {
	  try {
		if (!prev || !next) return;
		console.log('Animating toward new checkpoint:', next.place);

		const geocoder = new google.maps.Geocoder();
		const from = await new Promise((res,rej)=>geocoder.geocode({address:prev.place},(r,s)=>s==='OK'?res(r[0].geometry.location):rej()));
		const to = await new Promise((res,rej)=>geocoder.geocode({address:next.place},(r,s)=>s==='OK'?res(r[0].geometry.location):rej()));

		const modeKey = 'land';
		const pts = await buildRoutePoints(modeKey, from, to);

		const distKm = pathTotalMeters(pts) / 1000;
		const speed = SPEEDS[modeKey] || SPEEDS.land;
		const etaSec = Math.max(5, Math.round((distKm / speed) * 3600));
		const totalMs = Math.max(3000, Math.floor(etaSec * 1000 * VISUAL_SCALE));

		// Sync EDT, KM, and progress
		startEDT(totalMs, `${Math.round(distKm)} km`, 'New Checkpoint');

		const ok = await moveAlongRoute(pts, totalMs, modeKey, 'New Checkpoint', 'LIVE', 999);
		if (!ok) { animating = false; return; }

		console.log('✅ Arrived at new checkpoint:', next.place);
	  } catch(e) {
		console.warn('Failed to animate new leg', e);
	  }
	}


  function startPauseCountdown(msLeft){
    if(!mover) return;
	mover.isMoving = false; // lean upright during pause
	
    function render(){
      const s = Math.max(0, Math.round(msLeft/1000));
      const m = Math.floor(s/60), ss=s%60;
      mover.showCountdown(`â± ${m}:${ss.toString().padStart(2,'0')} before next take-off`);
    }
    render();
    clearInterval(countdownTimer);
    countdownTimer = setInterval(()=>{
      msLeft -= 1000;
      if(msLeft <= 0){
        clearInterval(countdownTimer);
        mover.hideCountdown();
		mover.isMoving = true; // ✅ lean forward again when countdown completes
      } else render();
    }, 1000);
	mover.isMoving = true;
  }

	  /* ---------------- Timeline & Poller ---------------- */
	function renderTimeline(cps){
	  const tl = document.getElementById('stunnz-timeline');
	  if (!tl) return;
	  tl.innerHTML = '';
	  if (!Array.isArray(cps) || cps.length === 0) {
		tl.style.display = 'none';
		return;
	  }

	  cps.forEach((cp, i) => {
		const el = document.createElement('div');
		el.className = 'stunnz-cp';
		const status = (cp.status || '').toLowerCase();
		if (/in transit|enroute|arrived|delivered|departure|origin/.test(status)) el.classList.add('active');
		el.innerHTML = `
		  <div class="date">${cp.date || ''}</div>
		  <div>${cp.place || ''}${cp.status ? ' — ' + cp.status : ''}</div>
		`;
		tl.appendChild(el);
	  });

	  // Show only after tracking data is loaded
	  tl.style.display = 'flex';

	  // Optional: auto-scroll to end
	  setTimeout(() => { tl.scrollLeft = tl.scrollWidth; }, 200);
	}

	// ==============================
	// Enhanced Poller: Detect new checkpoints
	// ==============================
	function startPoller(code,len){
	  if(pollTimer) clearInterval(pollTimer);

	  pollTimer = setInterval(async()=>{
		try {
		  const r = await fetch(REST_ENDPOINT + '?tracking_code=' + encodeURIComponent(code));
		  const d = await r.json();

		  if (d && d.found && Array.isArray(d.checkpoints)) {
			window._stzCoords = window._stzCoords || [];
			window._stzSig = window._stzSig || checkpointsSig(window._stzCoords);

			const newSig = checkpointsSig(d.checkpoints);

			// detect if backend added new checkpoint
			if (newSig !== window._stzSig) {
			  console.log('🆕 New checkpoint detected via poller');
			  const oldCoords = window._stzCoords;
			  const newCoords = d.checkpoints.map(cp => ({ place: cp.place }));

			  if (oldCoords.length && newCoords.length > oldCoords.length) {
				const prev = oldCoords[oldCoords.length - 1];
				const next = newCoords[newCoords.length - 1];
				if (typeof handleNewLeg === 'function') handleNewLeg(prev, next);
			  }

			  window._stzSig = newSig;
			  window._stzCoords = newCoords;
			}
		  }
		} catch(e) {
		  console.warn('Poller fetch failed', e);
		}
	  }, POLL_INTERVAL);
	}


  
  function clearTrail(smooth=true){
  try {
    if (trailPulseTimer) { clearInterval(trailPulseTimer); trailPulseTimer = null; }
    if (!trailLine && !glowLine) return;

    if (!smooth) {
      if (trailLine) { trailLine.setMap(null); trailLine = null; }
      if (glowLine)  { glowLine.setMap(null);  glowLine  = null; }
      return;
    }

    const start = Date.now();
    const D = 2000; // fade duration (2s)
    const tLine = trailLine, gLine = glowLine;
    const iv = setInterval(()=>{
      const f = Math.min(1, (Date.now()-start)/D);
      const opMain = 0.9 * (1 - f);
      const opGlow = 0.25 * (1 - f);
      tLine && tLine.setOptions({ strokeOpacity: opMain });
      gLine && gLine.setOptions({ strokeOpacity: opGlow });
      if (f >= 1){
        clearInterval(iv);
        tLine && tLine.setMap(null);
        gLine && gLine.setMap(null);
        trailLine = null;
        glowLine = null;
      }
    }, 50);
  } catch(e){}
}

	// =========================
	// Auto reset map view logic
	// =========================
	function autoResetView(map, markers, legPolylines) {
	  if (!map || (!markers.length && !legPolylines.length)) return;

	  // Compute bounds that include all markers and all route points
	  const bounds = new google.maps.LatLngBounds();

	  markers.forEach(m => {
		if (m.getPosition) bounds.extend(m.getPosition());
	  });

	  legPolylines.forEach(pl => {
		if (pl.getPath) {
		  const path = pl.getPath();
		  for (let i = 0; i < path.getLength(); i++) {
			bounds.extend(path.getAt(i));
		  }
		}
	  });

	  // Fit to all visible elements
	  if (!bounds.isEmpty()) {
		map.fitBounds(bounds, {
		  top: 90,
		  bottom: 90,
		  left: 110,
		  right: 110
		});
	  }
	}


  /* ---------------- Main: renderMap ---------------- */
  function renderMap(data){
    loadGoogle(async()=>{
      if(!geocoder) geocoder=new google.maps.Geocoder();
      if(!directionsService) directionsService = new google.maps.DirectionsService();

      const styleUrl='<?php echo plugins_url("assets/map-style.json", dirname(__DIR__) . "/stunnz-dynamic-tracker.php"); ?>';
      let mapOpts={zoom:6,center:{lat:29.7,lng:-95.3},mapTypeId:'roadmap'};
      try{mapOpts.styles=await (await fetch(styleUrl)).json();}catch(e){}
      if(!map){map=new google.maps.Map(document.getElementById('stunnz-map'),mapOpts);}

      // clear previous
      markers.forEach(m=>m.setMap(null)); markers=[];
      legPolylines.forEach(pl=>pl.setMap(null)); legPolylines=[];

      const cps=data.checkpoints||[], cache=data.geocache||[];
      let coords=[];
	  
		  // ============================
	// Freight Mode Precedence Logic
	// ============================
	let displayModeText = '';
	let useGoogleRoute = true;
	if (data.meta && data.meta.mode) {
	  const mode = data.meta.mode.toLowerCase().trim();
	  if (mode === 'air') displayModeText = 'Air Shipping';
	  else if (mode === 'sea') displayModeText = 'Sea Freight';
	  else if (mode === 'land') displayModeText = 'Land Freight';
	}

	// Override Google live mode if backend mode is defined
	if (displayModeText) {
	  useGoogleRoute = (data.meta.mode.toLowerCase() === 'land'); // true only for land
	} else {
	  useGoogleRoute = true; // fallback to google live
	}

	// Display beside ETA + KM
	const kmEtaLabel = document.querySelector('.stunnz-eta-distance');
	if (kmEtaLabel) {
	  let existing = kmEtaLabel.textContent;
	  if (displayModeText && !existing.includes(displayModeText)) {
		kmEtaLabel.textContent = existing + ' | ' + displayModeText;
	  }
	}

	  
      for(let i=0;i<cps.length;i++){
        const cp=cps[i], c=cache[i];
        if(c&&c.lat){ coords.push({lat:+c.lat,lng:+c.lng,place:cp.place,status:cp.status}); continue; }
        try{
          const loc=await new Promise((res,rej)=>geocoder.geocode({address:cp.place},(r,s)=>s==='OK'?res(r[0].geometry.location):rej()));
          coords.push({lat:loc.lat(),lng:loc.lng(),place:cp.place,status:cp.status});
          fetch(SAVE_ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({post_id:data.post_id,index:i,place:cp.place,lat:loc.lat(),lng:loc.lng()})});
        }catch(e){}
      }

      window._stzPostId = data.post_id;
      window._stzSig = checkpointsSig(coords);

      // markers
      if(coords.length){
        const lastIdx = coords.length-1;
        coords.forEach((c,i)=>{
          const iconUrl = iconForCheckpoint(cps[i]||{}, i, lastIdx);
          const m=new google.maps.Marker({ position:c, map,
            icon:{ url:iconUrl, scaledSize:new google.maps.Size(18,18) }
          });
          markers.push(m);
        });
      }

      // draw routes for EACH leg in order (A->B, B->C, ...)
      async function drawAllLegs(){
        let bounds=new google.maps.LatLngBounds();
        for(let i=1;i<coords.length;i++){
          const A = new google.maps.LatLng(coords[i-1].lat, coords[i-1].lng);
          const B = new google.maps.LatLng(coords[i].lat, coords[i].lng);

          // decide mode for leg
          let modeKey = 'land';
          const stA = norm((cps[i-1]&&cps[i-1].status)||'');
          const stB = norm((cps[i]&&cps[i].status)||'');
          if(/air/.test(norm(data?.meta?.mode||''))) modeKey='air';
          else if(/sea|ocean/.test(norm(data?.meta?.mode||''))) modeKey='sea';
          if(/air/.test(stA+stB)) modeKey='air';
          if(/sea|port|harbor|harbour|dock/.test(stA+stB)) modeKey='sea';

          const pts = await buildRoutePoints(modeKey, A, B);
          const main = new google.maps.Polyline({
            path: pts,
            strokeColor: ROUTE_COLORS[modeKey] || '#ef4444',
            strokeOpacity: 0.95,
            strokeWeight: 1,
            geodesic: (modeKey!=='land'),
            map
          });
          const glow = new google.maps.Polyline({
            path: pts,
            strokeColor: ROUTE_COLORS[modeKey] || '#ef4444',
            strokeOpacity: 0.20,
            strokeWeight: 5,
            geodesic: (modeKey!=='land'),
            map
          });
          legPolylines.push(main, glow);
          pts.forEach(p=>bounds.extend(p));
        }
        if (!bounds.isEmpty()) {
          map.fitBounds(bounds,{padding:{top:90,bottom:90,left:110,right:110}});
        }
      }
      await drawAllLegs();
	  
	  // After drawing legs, auto fit to all routes and markers
	  autoResetView(map, markers, legPolylines);
	  map.setMapTypeId('roadmap');
	  // --- Watch for manual zoom/pan and auto-revert ---
		let resetTimeout;
		map.addListener('idle', () => {
		  clearTimeout(resetTimeout);
		  resetTimeout = setTimeout(() => {
			autoResetView(map, markers, legPolylines);
		  }, 3000); // waits 3 seconds after user stops moving/zooming
		});


      // mover overlay
      if(!mover){ mover = createMoverOverlay(map); }
      if(coords.length){ mover.setPosition(new google.maps.LatLng(coords[0].lat,coords[0].lng)); }
	  
	  
	  // === Create animated blue trail lines ===
	trailLine = new google.maps.Polyline({
	  path: [],
	  strokeColor: '#2196f3',
	  strokeOpacity: 0.9,
	  strokeWeight: 3,
	  map
	});
	glowLine = new google.maps.Polyline({
	  path: [],
	  strokeColor: '#2196f3',
	  strokeOpacity: 0.25,
	  strokeWeight: 8,
	  map
	});

      renderTimeline(cps);
      const code=document.getElementById('stunnz-code').value.trim();
      pendingNewData=null; startPoller(code,coords.length);

      // make sure the map is actually visible
      const mapEl = document.getElementById('stunnz-map');
      if (mapEl) {
        mapEl.style.display = 'block';
        if (!mapEl.style.height) mapEl.style.height = '500px';
        if (!mapEl.style.width)  mapEl.style.width  = '100%';
        mapEl.style.borderRadius = '10px';
        mapEl.style.overflow = 'hidden';
      }
      console.log('ðŸ—ºï¸ Map container made visible and ready');

      // handle status overrides (stop/jump)
      const shipmentStatus = norm(data.status||'');
	  if (statusIsHold(shipmentStatus)) {
		  animating=false;
		  if (mover) mover.isMoving = false; // 👈 NEW LINE: stop leaning
		  mover && mover.showCountdown('⛔ On Hold');
		  if (typeof clearTrail === 'function') clearTrail(true); // optional fade-out if defined
		  await PERSIST.save(code,{v:6, post_id:data.post_id, phase:'stopped', seg_index:0, checkpoints_sig: window._stzSig||''});
		  return;
		}

      if (statusIsArrived(shipmentStatus) && coords.length){
		 const last = coords[coords.length-1];
		 mover && mover.setPosition(new google.maps.LatLng(last.lat,last.lng));
		 mover && mover.hideCountdown();
		 if (mover) mover.isMoving = false; // 👈 NEW LINE: stand upright at destination
		 if (typeof clearTrail === 'function') clearTrail(true); // optional fade out
		 await PERSIST.clear(code);
		 return;
	  }

      // ===== Process: multi-leg with pause/persistence =====
      async function process(){
        if(coords.length<2) return;
        animating=true;

        let saved = await PERSIST.load(code);
        if(saved && (saved.post_id && saved.post_id !== data.post_id)) saved=null;
        if(saved && saved.checkpoints_sig && saved.checkpoints_sig !== window._stzSig) saved=null;

        let startLeg = 1;
        let resumePhase = null;
        let resumeFraction = 0;
        let resumeWaitLeft = 0;

        if (saved){
          if (saved.phase === 'move'){
            startLeg = Math.max(1, saved.seg_index);
            resumePhase = 'move';
            resumeFraction = saved.route_fraction || 0;
          } else if (saved.phase === 'wait'){
            startLeg = Math.max(1, saved.seg_index + 1);
            resumePhase = 'wait';
            const now = Date.now();
            resumeWaitLeft = Math.max(0, (saved.wait_end_at||0) - now);
          } else if (saved.phase === 'stopped'){
            animating=false;
            mover && mover.showCountdown('â›” Stopped');
            return;
          }
        }

        for(let s=startLeg; s<coords.length; s++){
          if(pendingNewData) break;

          const prev = coords[s-1], next = coords[s];
          const A=new google.maps.LatLng(prev.lat,prev.lng);
          const B=new google.maps.LatLng(next.lat,next.lng);

          // mode per leg
          let modeKey = 'land';
          const stA = norm((cps[s-1]&&cps[s-1].status)||'');
          const stB = norm((cps[s]&&cps[s].status)||'');
          if(/air/.test(norm(data?.meta?.mode||''))) modeKey='air';
          else if(/sea|ocean/.test(norm(data?.meta?.mode||''))) modeKey='sea';
          if(/air/.test(stA+stB)) modeKey='air';
          if(/sea|port|harbor|harbour|dock/.test(stA+stB)) modeKey='sea';
          const modeLabel = (modeKey==='air'?'Air Shipping': modeKey==='sea'?'Sea Freight':'Land Shipping');

          // route for this leg (match the visible line)
          const routePts = await buildRoutePoints(modeKey, A, B);
          const distKm = pathTotalMeters(routePts)/1000;

          // ETA scaling
          const speed = SPEEDS[modeKey] || SPEEDS.land;
          const etaSec = Math.max(5, Math.round((distKm / speed) * 3600));
          const totalMs = Math.max(2000, Math.floor(etaSec*1000*VISUAL_SCALE));

          // resume wait?
          if (resumePhase==='wait' && s===startLeg){
            let left = resumeWaitLeft || WAIT_BETWEEN_LEGS_MS;
            const end = Date.now() + left;
            startPauseCountdown(left);
            await PERSIST.save(code,{v:6, post_id:data.post_id, phase:'wait', seg_index:(s-1), wait_end_at:end, checkpoints_sig:window._stzSig||''});
            while(Date.now() < end){ await new Promise(r=>setTimeout(r,250)); if(!animating) return; }
            mover.hideCountdown();
            resumePhase=null;
          }
          // move (with resume fraction if any)
          const startFrac = (resumePhase==='move' && s===startLeg) ? Math.max(0, Math.min(1, resumeFraction)) : 0;
          startEDT(totalMs*(1-startFrac), `${Math.round(distKm*(1-startFrac))} km`, modeLabel);
          const ok = await moveAlongRoute(routePts, totalMs*(1-startFrac), modeKey, modeLabel, code, s);
          if(!ok){ animating=false; return; }

          // reached checkpoint s
          if (statusIsHold(stB)){
            animating=false;
            mover && mover.showCountdown('â›” On Hold');
            await PERSIST.save(code,{v:6, post_id:data.post_id, phase:'stopped', seg_index:s, checkpoints_sig:window._stzSig||''});
            return;
          }
          if (statusIsArrived(stB) || s===coords.length-1){
            await PERSIST.clear(code);
            animating=false;
            return;
          }

          // 5-min pause before NEXT leg
          const waitEnd = Date.now() + WAIT_BETWEEN_LEGS_MS;
          startPauseCountdown(WAIT_BETWEEN_LEGS_MS);
          await PERSIST.save(code,{v:6, post_id:data.post_id, phase:'wait', seg_index:s, wait_end_at:waitEnd,checkpoints_sig:window._stzSig||''});
          while(Date.now() < waitEnd){ await new Promise(r=>setTimeout(r,250)); if(!animating) return; }

          mover.hideCountdown();
          await PERSIST.clear(code)
        }
        animating=false;
        await PERSIST.clear(code);
        if(pendingNewData){const d=pendingNewData;pendingNewData=null;renderMap(d);}
      }
	  
	  setInterval(()=>{
  if (glowLine) {
    const opacity = 0.2 + 0.1 * Math.sin(Date.now()/400);
    glowLine.setOptions({ strokeOpacity: opacity });
  }
}, 150);

	  
      process().catch(()=>{ animating=false; });
	  
	  
    });
  }

  /* ---------------- Track button (patched for JSON safety) ---------------- */
  const trackBtn = document.getElementById('stunnz-btn');
  if (trackBtn) {
    trackBtn.addEventListener('click', async ()=>{
      const code = document.getElementById('stunnz-code').value.trim();
      console.log('Track button clicked, code:', code);
      if (!code) return;
      if (pollTimer) clearInterval(pollTimer);

      try {
        const res = await fetch(REST_ENDPOINT + '?tracking_code=' + encodeURIComponent(code));
        const text = await res.text();  // safer than .json()
        let d = {};
        try {
          d = JSON.parse(text);
          console.log('Parsed JSON:', d);
        } catch (err) {
          console.error('Invalid JSON returned:', text);
          document.getElementById('stunnz-msg').textContent = 'Invalid response from server.';
          return;
        }

        if (!d.found) {
          document.getElementById('stunnz-msg').textContent = 'Tracking code not found';
          return;
        }

        document.getElementById('stunnz-msg').textContent = 'Status: ' + (d.status || 'Unknown');
        if (d.meta) renderMeta(d.meta);
        renderMap(d);

      } catch (e) {
        console.error('Tracking fetch failed:', e);
        document.getElementById('stunnz-msg').textContent = 'Error connecting to tracking server.';
      }
    });
  }
} // end initStunnzTracker
})();

// --- Fallback binding (ensures tracker initializes even if DOM loads late) ---
window.addEventListener('load', () => {
  if (typeof initStunnzTracker === 'function') {
    console.log('Rebinding tracker on window load...');
    initStunnzTracker();
  }
});
</script>
