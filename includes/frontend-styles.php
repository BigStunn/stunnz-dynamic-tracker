<?php /* Styles */ ?>
<style>
#stunnz-map {
  width: 100%;
  height: 500px;
  display: none; /* hidden until JS reveals it */
  border-radius: 10px;
  overflow: hidden;
  background: #e9ecef;
}

:root{
  --primary:#0ea5e9;
  --border:#e4e4e4;
  --shadow:0 6px 22px rgba(0,0,0,0.08);
  --radius:12px;
  --row-alt:#fafafa;
  --success:#22c55e;
}

.stunnz-wrap{max-width:1150px;margin:0 auto;font-family:'Segoe UI',Roboto,Arial,sans-serif;position:relative;}
.stunnz-form{display:flex;gap:0px;margin-bottom:14px;}
.stunnz-form input{flex:1;padding:10px 12px;font-size:16px;border:1px solid var(--border);border-radius:none;}
.stunnz-form button{padding:10px 16px;font-size:16px;background:var(--primary);color:#fff;border:none;border-radius:none;cursor:pointer;transition:.25s;}
.stunnz-form button:hover{filter:brightness(0.95);}

/* ETA Gradient Banner */
#stunnz-edt{display:none;margin:10px 0 8px 0;}
#stunnz-edt .pill{
  display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;
  width:100%;
  background: linear-gradient(135deg, #0ea5e9 0%, #22d3ee 50%, #67e8f9 100%);
  color:#073642;border:1px solid rgba(255,255,255,0.35);
  box-shadow:0 8px 28px rgba(14,165,233,0.35), inset 0 0 18px rgba(255,255,255,0.15);
  padding:14px 16px;border-radius:16px;
}
#stunnz-edt .left{display:flex;gap:12px;align-items:center;}
#stunnz-edt .dot{width:10px;height:10px;border-radius:50%;background:#022c43;opacity:.6;}
#stunnz-edt .title{font-weight:700;letter-spacing:.2px;}
#stunnz-edt .time{font-weight:700;}
#stunnz-edt .sep{opacity:.55;margin:0 6px;}
#stunnz-edt .meta{opacity:.9}
#stunnz-edt .mode{font-weight:600;opacity:.95}

/* Progress Bar */
#stunnz-progress{display:none;margin:6px 0 10px 0;}
.stz-progress-wrap{
  position:relative;width:100%;height:10px;border-radius:999px;
  background:rgba(2,44,67,0.08);overflow:hidden;
  box-shadow: inset 0 0 10px rgba(0,0,0,0.06);
}
.stz-progress-bar{
  position:absolute;inset:0;transform-origin:left center;
  transform:scaleX(0);
  background: linear-gradient(90deg, #0a6cff 0%, #00bcd4 50%, #00e676 100%);
  box-shadow: 0 0 12px rgba(0, 230, 118, 0.25), 0 0 24px rgba(14, 165, 233, 0.25);
  transition: transform 0.18s linear;
}
.stz-progress-label{
  position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
  font-size:12px;font-weight:700;color:#033649;text-shadow:0 1px 0 rgba(255,255,255,0.5);
}
.stz-progress-complete .stz-progress-bar{
  background:linear-gradient(90deg, var(--success) 0%, #3ee283 100%);
  box-shadow:0 0 16px rgba(34,197,94,0.45), 0 0 28px rgba(34,197,94,0.28);
}

/* Map + spinner */
#stunnz-map{width:100%;height:520px;background:#f9fbfc;display:none;margin-top:8px;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);}
#stunnz-spinner{
  display:none;position:absolute;inset:115px 0 0 0;
  background:rgba(255,255,255,0.85);
  z-index:50;align-items:center;justify-content:center;
  color:#0ea5e9;font-weight:600;transition:opacity .25s ease;
}
.stz-loader{
  border:4px solid rgba(14,165,233,0.25);
  border-top:4px solid #0ea5e9;border-radius:50%;
  width:40px;height:40px;animation:spin 1s linear infinite;margin-right:12px;
}
@keyframes spin{100%{transform:rotate(360deg);}}

/* Panels */
.stunnz-msg{margin-top:10px;font-size:15px;color:#333;}
.stunnz-panels{margin-top:20px;display:flex;flex-wrap:wrap;gap:20px;animation:fadeSlide .7s ease;}
.stunnz-half{flex:1 1 48%;min-width:320px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px;}
.stunnz-full{flex:1 1 100%;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px;}
.stunnz-panel h3{margin:0 0 12px;font-size:17px;color:#111;border-bottom:2px solid #0ea5e9;padding-bottom:6px;}
.stunnz-panel table{width:100%;border-collapse:collapse;font-size:15px;}
.stunnz-panel td{padding:8px 6px;vertical-align:top;border-top:1px solid var(--border);}
.stunnz-panel tr:nth-child(even){background:var(--row-alt);}
.stunnz-panel td.key{width:45%;font-weight:600;color:#222;}
.stunnz-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px;}
.stunnz-table th,.stunnz-table td{border:1px solid #ddd;padding:8px;text-align:center;}
.stunnz-table th{background:#f4f4f4;font-weight:600;}
.stunnz-table tr:nth-child(even){background:#fafafa;}

/* Timeline */
.stunnz-timeline{margin-top:25px;border-top:1px solid var(--border);padding-top:12px;}
.stunnz-cp{padding:8px 0;border-bottom:1px solid #f1f1f1;}
.stunnz-cp .date{font-weight:700;color:#111;}
@keyframes fadeSlide{from{opacity:0;transform:translateY(25px);}to{opacity:1;transform:translateY(0);}}

/* Overlay mover (gradient-glow + tilt) */
.stz-mover{
  position:absolute; left:0; top:0; transform:translate(-50%,-50%);
  will-change: transform, filter; pointer-events:none; z-index:30;
  transition: transform 0.6s ease-in-out;
  --glow: rgba(0,255,102,0.7);
}
.stz-mover img{display:block; width:52px; height:auto;}
@keyframes softPulse {
  0%   { filter: drop-shadow(0 0 6px var(--glow)) drop-shadow(0 0 1px rgba(0,0,0,.2)); }
  50%  { filter: drop-shadow(0 0 14px var(--glow)) drop-shadow(0 0 3px rgba(0,0,0,.25)); }
  100% { filter: drop-shadow(0 0 6px var(--glow)) drop-shadow(0 0 1px rgba(0,0,0,.2)); }
}
.stz-mover.glow { animation: softPulse 2.6s ease-in-out infinite; }
.stz-mover.land { --glow: rgba(255, 44, 44, 1); }
.stz-mover.air  { --glow: rgba(0, 150, 255, 1); }
.stz-mover.sea  { --glow: rgba(255,215,0,0.78); }

@media(max-width:768px){
  .stunnz-form{flex-direction:column;}
  .stunnz-form button{width:100%;}
  .stunnz-half{flex:1 1 100%;}
}
/* --- Soft Motion Trail --- */
.stz-mover::after {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 140%;
  height: 140%;
  transform: translate(-50%, -50%) scale(0.7);
  border-radius: 50%;
  filter: blur(18px);
  opacity: 0.0;
  transition: opacity 0.25s ease-out, background 0.4s ease;
  z-index: -1;
}

.stz-mover.glow::after {
  opacity: 0.65;
}

/* Color per mode */
.stz-mover.land::after { background: radial-gradient(circle, rgba(34,197,94,0.8) 0%, rgba(34,197,94,0.0) 80%); }
.stz-mover.air::after  { background: radial-gradient(circle, rgba(14,165,233,0.8) 0%, rgba(14,165,233,0.0) 80%); }
.stz-mover.sea::after  { background: radial-gradient(circle, rgba(245,158,11,0.8) 0%, rgba(245,158,11,0.0) 80%); }

/* ===========================
   Horizontal Shipment Route
   =========================== */
#stunnz-timeline {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
  gap: 50px;
  padding: 18px 25px;
  background: linear-gradient(90deg, #f9fafb, #ffffff);
  border-radius: 10px;
  box-shadow: inset 0 0 4px rgba(0,0,0,0.05);
  margin-top: 25px;
  scrollbar-width: thin;
  scrollbar-color: #888 #f0f0f0;
  display: none; /* hidden until tracking data loads */
}

#stunnz-timeline::-webkit-scrollbar {
  height: 6px;
}
#stunnz-timeline::-webkit-scrollbar-thumb {
  background: #bbb;
  border-radius: 10px;
}
#stunnz-timeline::-webkit-scrollbar-track {
  background: #f0f0f0;
}

/* Checkpoint node */
.stunnz-cp {
  flex: 0 0 auto;
  position: relative;
  text-align: center;
  min-width: 120px;
}

.stunnz-cp::before {
  content: "";
  position: absolute;
  top: -8px;
  left: 50%;
  transform: translateX(-50%);
  width: 14px;
  height: 14px;
  background: #2563eb;
  border-radius: 50%;
  box-shadow: 0 0 6px rgba(37,99,235,0.6);
}

.stunnz-cp:not(:last-child)::after {
  content: "";
  position: absolute;
  top: -2px;
  left: 100%;
  width: 50px;
  height: 2px;
  background: #2563eb;
  opacity: 0.7;
}

.stunnz-cp .date {
  display: block;
  font-size: 11px;
  color: #6b7280;
  margin-bottom: 3px;
}
.stunnz-cp div {
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}

.stunnz-cp.active::before {
  background: #22c55e;
  box-shadow: 0 0 8px rgba(34,197,94,0.7);
}

</style>