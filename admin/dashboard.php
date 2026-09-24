<?php
/**
 * Settings screen for AI SEO Connector. Variables in scope, set by
 * AISEOC_Admin::render_page(): $token, $actions, $logs, $nonce, $api_base,
 * $app_username, $status, $has_yoast, $has_rankmath, $accent_1, $accent_2,
 * $accent_1_rgb, $accent_2_rgb.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$masked_token = $token ? substr( $token, 0, 8 ) . '…' . substr( $token, -8 ) : '';
$is_paused    = $status['state'] === 'paused';

$groups = [
    'content' => [ 'label' => 'Content',   'desc' => 'Create, update and delete posts &amp; pages, featured images, taxonomies.' ],
    'seo'     => [ 'label' => 'SEO',       'desc' => 'Read and write Yoast or RankMath meta, run audits, ping sitemaps.' ],
    'media'   => [ 'label' => 'Media',     'desc' => 'Upload, list and delete media; fix alt text by ID or URL.' ],
    'site'    => [ 'label' => 'Site info', 'desc' => 'Read site, plugin and a few site settings; clear caches. No installs, no user or option writes.' ],
];

/**
 * Split "Recent activity" into genuinely actionable entries (a tool call, a
 * real error) versus routine blocked-auth noise (a bad token/password from
 * some scanner, correctly rejected). Shown separately so a non-technical
 * client doesn't read a wall of "Failed Bearer token attempt" lines as an
 * active break-in. Nothing is discarded, just grouped differently.
 */
$blocked_prefixes = [ 'Failed Bearer token attempt', 'Failed Basic Auth attempt', 'Rate limit hit' ];
$blocked_logs  = [];
$activity_logs = [];
foreach ( $logs as $entry ) {
    $is_blocked = false;
    foreach ( $blocked_prefixes as $prefix ) {
        if ( strpos( $entry['message'], $prefix ) === 0 ) { $is_blocked = true; break; }
    }
    if ( $is_blocked ) { $blocked_logs[] = $entry; } else { $activity_logs[] = $entry; }
}
?>
<style>
  /* ── Make this screen seamless: the app fills the whole content area ── */
  body.aiseoc-screen,
  body.aiseoc-screen #wpcontent{background:#05050a}
  body.aiseoc-screen #wpcontent{padding-left:0}
  body.aiseoc-screen #wpbody-content{padding-bottom:0}
  body.aiseoc-screen #wpfooter,
  body.aiseoc-screen #screen-meta,
  body.aiseoc-screen #screen-meta-links,
  body.aiseoc-screen .notice,
  body.aiseoc-screen div.updated,
  body.aiseoc-screen div.error,
  body.aiseoc-screen .update-nag{display:none !important}

  .aiseoc-app{
    --aiseoc-accent:<?php echo esc_attr( $accent_1 ); ?>;
    --aiseoc-accent-2:<?php echo esc_attr( $accent_2 ); ?>;
    --aiseoc-accent-rgb:<?php echo esc_attr( $accent_1_rgb ); ?>;
    --aiseoc-accent-2-rgb:<?php echo esc_attr( $accent_2_rgb ); ?>;
    position:relative;box-sizing:border-box;
    min-height:calc(100vh - var(--wp-admin--admin-bar--height,32px));
    padding:clamp(18px,3.2vw,44px) clamp(16px,3.6vw,56px) clamp(28px,4vw,64px);
    color:#e8e8f0;
    font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    background:
      radial-gradient(1100px 520px at 8% -8%,rgba(var(--aiseoc-accent-rgb),.20),transparent 62%),
      radial-gradient(900px 480px at 100% 0%,rgba(var(--aiseoc-accent-2-rgb),.14),transparent 58%),
      linear-gradient(rgba(255,255,255,.028) 1px,transparent 1px) 0 0/44px 44px,
      linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px) 0 0/44px 44px,
      #05050a;
  }
  .aiseoc-app *,.aiseoc-app *::before,.aiseoc-app *::after{box-sizing:border-box}
  .aiseoc-app :focus-visible{outline:2px solid rgba(var(--aiseoc-accent-rgb),.9);outline-offset:2px}

  /* Status colors: green connected, white awaiting, yellow idle, red paused */
  .aiseoc-s-green{--s:#34d399;--s-rgb:52,211,153}
  .aiseoc-s-yellow{--s:#fbbf24;--s-rgb:251,191,36}
  .aiseoc-s-red{--s:#f87171;--s-rgb:248,113,113}
  .aiseoc-s-white{--s:#e5e7eb;--s-rgb:229,231,235}

  @keyframes aiseoc-breathe{0%,100%{box-shadow:0 0 4px var(--s)}50%{box-shadow:0 0 15px var(--s)}}
  @keyframes aiseoc-ring{0%,100%{box-shadow:0 0 22px rgba(var(--s-rgb),.30),inset 0 0 16px rgba(var(--s-rgb),.16)}50%{box-shadow:0 0 38px rgba(var(--s-rgb),.50),inset 0 0 22px rgba(var(--s-rgb),.26)}}
  @keyframes aiseoc-rise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

  /* ── Header ── */
  .aiseoc-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .aiseoc-brand{display:flex;align-items:center;gap:12px;min-width:0}
  .aiseoc-logo{width:36px;height:36px;flex:none;filter:drop-shadow(0 0 14px rgba(var(--aiseoc-accent-rgb),.55))}
  .aiseoc-app h1{margin:0;padding:0;font-size:clamp(18px,2vw,24px);font-weight:650;letter-spacing:-.01em;line-height:1.1;color:#fff}
  .aiseoc-ver{margin-left:8px;font:500 11px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.04em;color:#767689}
  .aiseoc-lead{margin:10px 0 0;max-width:680px;font-size:13.5px;line-height:1.6;color:#9b9bb0}

  .aiseoc-pill{display:inline-flex;align-items:center;gap:9px;padding:7px 14px 7px 12px;border-radius:99px;
    border:1px solid rgba(var(--s-rgb),.35);background:rgba(var(--s-rgb),.09);color:var(--s);
    font:600 11.5px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase}
  .aiseoc-dot{width:8px;height:8px;border-radius:50%;background:var(--s);box-shadow:0 0 10px var(--s)}
  .aiseoc-s-green .aiseoc-dot{animation:aiseoc-breathe 2.4s ease-in-out infinite}

  /* ── Status hero ── */
  .aiseoc-hero{display:flex;align-items:center;gap:clamp(14px,2vw,26px);flex-wrap:wrap;
    margin:clamp(16px,2.2vw,28px) 0;padding:clamp(16px,2vw,24px);border-radius:18px;
    border:1px solid rgba(var(--s-rgb),.24);
    background:linear-gradient(120deg,rgba(var(--s-rgb),.09),rgba(255,255,255,.012) 58%)}
  .aiseoc-ring{position:relative;flex:none;width:56px;height:56px;border-radius:50%;
    border:1.5px solid rgba(var(--s-rgb),.55);background:radial-gradient(circle,rgba(var(--s-rgb),.16),transparent 70%)}
  .aiseoc-ring::after{content:'';position:absolute;inset:17px;border-radius:50%;background:var(--s);box-shadow:0 0 16px var(--s)}
  .aiseoc-s-green .aiseoc-ring{animation:aiseoc-ring 3s ease-in-out infinite}
  .aiseoc-hero-body{flex:1;min-width:220px}
  .aiseoc-hero-label{font-size:clamp(18px,2vw,23px);font-weight:650;letter-spacing:-.01em;color:var(--s)}
  .aiseoc-hero-detail{margin:5px 0 0;font-size:13px;line-height:1.55;color:#a3a3b8;max-width:640px}

  /* ── Layout ── */
  .aiseoc-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:clamp(12px,1.6vw,20px);align-items:stretch}
  .aiseoc-stack{display:flex;flex-direction:column;gap:clamp(12px,1.6vw,20px);min-width:0;margin-top:clamp(12px,1.6vw,20px)}
  .aiseoc-panel{padding:clamp(16px,1.8vw,24px);border-radius:16px;border:1px solid rgba(255,255,255,.07);
    background:rgba(255,255,255,.022);transition:border-color .2s ease;animation:aiseoc-rise .4s ease both}
  .aiseoc-panel:hover{border-color:rgba(255,255,255,.12)}
  .aiseoc-app h2.aiseoc-h{display:flex;align-items:center;gap:10px;margin:0 0 16px;padding:0;
    font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:#9a9ab0}
  .aiseoc-h::before{content:'';flex:none;width:6px;height:6px;border-radius:1px;transform:rotate(45deg);
    background:linear-gradient(135deg,var(--aiseoc-accent),var(--aiseoc-accent-2));box-shadow:0 0 10px rgba(var(--aiseoc-accent-rgb),.8)}
  .aiseoc-muted{margin:0 0 12px;font-size:12px;line-height:1.6;color:#7f7f94}

  /* ── Collapsible panels ── */
  .aiseoc-fold>summary{display:flex;align-items:center;gap:10px;cursor:pointer;list-style:none}
  .aiseoc-fold>summary::-webkit-details-marker{display:none}
  .aiseoc-fold>summary h2.aiseoc-h{margin:0;flex:1}
  .aiseoc-fold>summary::after{content:'';flex:none;width:7px;height:7px;margin-right:4px;transform:rotate(45deg);
    border-right:1.5px solid #8b8ba0;border-bottom:1.5px solid #8b8ba0;transition:transform .2s ease}
  .aiseoc-fold[open]>summary::after{transform:rotate(225deg)}
  .aiseoc-fold-body{margin-top:16px}
  .aiseoc-chip{padding:4px 10px;border-radius:99px;border:1px solid rgba(var(--s-rgb,255,255,255),.3);
    background:rgba(var(--s-rgb,255,255,255),.07);color:var(--s,#b4b4c6);font:600 10.5px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.04em}

  /* ── Fields / code ── */
  .aiseoc-field{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .aiseoc-label{flex:none;width:96px;font:500 11px/1.2 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.08em;text-transform:uppercase;color:#7a7a8e}
  .aiseoc-code{min-width:0;padding:8px 11px;border-radius:8px;border:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.35);
    font:12.5px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#c4c4ff;word-break:break-all;transition:border-color .2s ease}
  .aiseoc-code:hover{border-color:rgba(var(--aiseoc-accent-2-rgb),.45)}

  /* ── Buttons ── */
  .aiseoc-btn{appearance:none;cursor:pointer;padding:9px 15px;border-radius:9px;border:1px solid transparent;color:#fff;
    font:600 12.5px/1.2 ui-sans-serif,system-ui,sans-serif;letter-spacing:.01em;
    background:linear-gradient(135deg,var(--aiseoc-accent),var(--aiseoc-accent-2));box-shadow:0 6px 20px rgba(var(--aiseoc-accent-rgb),.30);
    transition:transform .18s ease,box-shadow .18s ease,filter .18s ease,background .18s ease}
  .aiseoc-btn:hover{transform:translateY(-1px);box-shadow:0 10px 26px rgba(var(--aiseoc-accent-rgb),.45);filter:brightness(1.07)}
  .aiseoc-btn:active{transform:none}
  .aiseoc-btn:disabled{opacity:.55;cursor:default;transform:none;filter:none}
  .aiseoc-btn.ghost{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.12);color:#d6d6e2;box-shadow:none}
  .aiseoc-btn.ghost:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.2);box-shadow:0 6px 16px rgba(0,0,0,.3)}
  .aiseoc-btn.danger{background:transparent;border-color:rgba(248,113,113,.35);color:#f87171;box-shadow:none}
  .aiseoc-btn.danger:hover{background:rgba(248,113,113,.1);border-color:rgba(248,113,113,.6)}

  /* ── Tool groups (switches) ── */
  .aiseoc-group{display:flex;align-items:center;gap:14px;padding:12px 2px;border-bottom:1px solid rgba(255,255,255,.055)}
  .aiseoc-group:last-of-type{border-bottom:none}
  .aiseoc-gtext{flex:1;min-width:0}
  .aiseoc-gname{display:block;font-size:13px;font-weight:600;color:#fff}
  .aiseoc-gtext p{margin:3px 0 0;font-size:12px;line-height:1.5;color:#8d8da2}
  .aiseoc-switch{position:relative;flex:none;width:38px;height:22px}
  .aiseoc-switch input{position:absolute;inset:0;z-index:1;margin:0;opacity:0;cursor:pointer}
  .aiseoc-switch span{position:absolute;inset:0;border-radius:99px;background:rgba(255,255,255,.11);transition:background .2s ease}
  .aiseoc-switch span::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s ease}
  .aiseoc-switch input:checked+span{background:linear-gradient(135deg,var(--aiseoc-accent),var(--aiseoc-accent-2))}
  .aiseoc-switch input:checked+span::after{transform:translateX(16px)}
  .aiseoc-switch input:focus-visible+span{outline:2px solid rgba(var(--aiseoc-accent-rgb),.9);outline-offset:2px}

  /* ── Doctor ── */
  .aiseoc-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));gap:8px;margin:14px 0 0;padding:0;list-style:none}
  .aiseoc-check{display:grid;grid-template-columns:22px 1fr;gap:10px;padding:10px 12px;border-radius:10px;
    border:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.025)}
  .aiseoc-check-ic{display:grid;place-items:center;width:20px;height:20px;border-radius:50%;
    border:1px solid rgba(var(--s-rgb),.4);background:rgba(var(--s-rgb),.14);color:var(--s);font-size:11px;font-weight:700}
  .aiseoc-check b{font-size:12.5px;color:#eee}
  .aiseoc-check p{margin:3px 0 0;font-size:12px;line-height:1.5;color:#9a9aae}
  .aiseoc-check p.fix{color:#c9c9dc}

  /* ── Activity ── */
  .aiseoc-log{max-height:260px;overflow-y:auto;font:11.5px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .aiseoc-log-row{display:flex;gap:10px;padding:6px 4px;border-bottom:1px solid rgba(255,255,255,.05);border-radius:6px;transition:background .15s ease}
  .aiseoc-log-row:hover{background:rgba(255,255,255,.03)}
  .aiseoc-log-row .lvl-warn{color:#f59e0b}.aiseoc-log-row .lvl-error{color:#f87171}.aiseoc-log-row .lvl-info{color:#7dd3a8}
  .aiseoc-empty{padding:22px;text-align:center;font-size:12px;color:#7a7a8e}
  .aiseoc-blocked summary{cursor:pointer;padding:6px 0;font-size:12px;color:#8a8a9c;transition:color .15s ease}
  .aiseoc-blocked summary:hover{color:#b6b6c6}
  .aiseoc-blocked .aiseoc-log-row{opacity:.6}

  /* ── Trust ── */
  .aiseoc-trust{border-color:rgba(52,211,153,.16);background:rgba(52,211,153,.03)}
  .aiseoc-trust h2.aiseoc-h{color:#7dd3a8}
  .aiseoc-trust h2.aiseoc-h::before{background:linear-gradient(135deg,#34d399,#10b981);box-shadow:0 0 10px rgba(16,185,129,.7)}
  .aiseoc-trust ul{margin:0;padding-left:18px;font-size:12.5px;line-height:1.7;color:#c4c4d2}
  .aiseoc-trust li{margin-bottom:6px}

  .aiseoc-toast{position:fixed;right:20px;bottom:20px;z-index:99999;display:none;padding:11px 18px;border-radius:10px;
    border:1px solid rgba(255,255,255,.12);background:rgba(22,22,30,.92);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);
    color:#fff;font-size:13px;box-shadow:0 12px 32px rgba(0,0,0,.5)}

  @media (max-width:600px){.aiseoc-label{width:100%}}
  @media (prefers-reduced-motion:reduce){.aiseoc-app *,.aiseoc-app *::before,.aiseoc-app *::after{animation:none !important;transition:none !important}}
</style>

<div class="aiseoc-app">

  <header class="aiseoc-top">
    <div class="aiseoc-brand">
      <svg class="aiseoc-logo" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <defs>
          <linearGradient id="aiseocLogoGrad" x1="4" y1="4" x2="28" y2="28" gradientUnits="userSpaceOnUse">
            <stop offset="0" style="stop-color:rgb(var(--aiseoc-accent-rgb))"/>
            <stop offset="1" style="stop-color:rgb(var(--aiseoc-accent-2-rgb))"/>
          </linearGradient>
        </defs>
        <circle cx="16" cy="16" r="12" stroke="url(#aiseocLogoGrad)" stroke-width="1.6" opacity=".4"/>
        <path d="M5 16h5.5l3-7 4.5 14 3-7H27" stroke="url(#aiseocLogoGrad)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <h1>AI SEO Connector<span class="aiseoc-ver">v<?php echo esc_html( AISEOC_VERSION ); ?></span></h1>
    </div>
    <div class="aiseoc-pill aiseoc-s-<?php echo esc_attr( $status['color'] ); ?>" id="aiseoc-pill" role="status" aria-live="polite">
      <span class="aiseoc-dot"></span><span id="aiseoc-pill-label"><?php echo esc_html( $status['label'] ); ?></span>
    </div>
  </header>
  <p class="aiseoc-lead">Lets your SEO platform read on-page data and apply approved fixes on this site, scoped to content, SEO and media.</p>

  <section class="aiseoc-hero aiseoc-s-<?php echo esc_attr( $status['color'] ); ?>" id="aiseoc-hero">
    <div class="aiseoc-ring" aria-hidden="true"></div>
    <div class="aiseoc-hero-body">
      <div class="aiseoc-hero-label" id="aiseoc-hero-label"><?php echo esc_html( $status['label'] ); ?></div>
      <p class="aiseoc-hero-detail" id="aiseoc-hero-detail"><?php echo esc_html( $status['detail'] ); ?></p>
    </div>
    <button class="aiseoc-btn ghost" id="aiseoc-pause" data-paused="<?php echo $is_paused ? '1' : '0'; ?>"><?php echo $is_paused ? 'Resume connection' : 'Pause connection'; ?></button>
  </section>

  <div class="aiseoc-cols">
    <section class="aiseoc-panel">
      <h2 class="aiseoc-h">Connection</h2>
      <div class="aiseoc-field"><span class="aiseoc-label">API base URL</span><span class="aiseoc-code"><?php echo esc_html( $api_base ); ?></span></div>
      <div class="aiseoc-field">
        <span class="aiseoc-label">Bearer token</span>
        <span class="aiseoc-code" id="aiseoc-token-display" data-token="<?php echo esc_attr( $token ); ?>"><?php echo esc_html( $masked_token ); ?></span>
        <button class="aiseoc-btn ghost" id="aiseoc-copy-token">Copy full token</button>
        <button class="aiseoc-btn ghost" id="aiseoc-regen-token">Regenerate</button>
      </div>
      <p class="aiseoc-muted">Paste the API base URL and token into your SEO platform's WordPress connection screen. Regenerating invalidates the old token immediately, everywhere it's used. Do it any time you suspect it's been exposed.</p>
    </section>
    <section class="aiseoc-panel">
      <h2 class="aiseoc-h">Access scope</h2>
      <?php foreach ( $groups as $key => $g ): ?>
        <div class="aiseoc-group">
          <div class="aiseoc-gtext"><span class="aiseoc-gname"><?php echo esc_html( $g['label'] ); ?></span><p><?php echo $g['desc']; ?></p></div>
          <label class="aiseoc-switch"><input type="checkbox" name="aiseoc_actions[]" value="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( $g['label'] ); ?>" <?php checked( in_array( $key, $actions, true ) ); ?>><span></span></label>
        </div>
      <?php endforeach; ?>
      <p class="aiseoc-muted" style="margin-top:12px">Detected SEO plugin: <strong style="color:#c8c8d6"><?php echo $has_yoast ? 'Yoast SEO' : ( $has_rankmath ? 'RankMath' : 'none detected' ); ?></strong></p>
      <button class="aiseoc-btn" id="aiseoc-save">Save access scope</button>
    </section>
  </div>

  <div class="aiseoc-stack">
    <details class="aiseoc-panel aiseoc-fold" id="aiseoc-doctor">
      <summary>
        <h2 class="aiseoc-h">Doctor</h2>
        <span class="aiseoc-chip" id="aiseoc-doctor-chip">Run diagnostics</span>
      </summary>
      <div class="aiseoc-fold-body">
        <p class="aiseoc-muted">Checks your PHP and WordPress versions, HTTPS, permalinks and SEO plugin, then makes a real request to this site's own API to confirm the connection path works end to end.</p>
        <button class="aiseoc-btn" id="aiseoc-doctor-run">Run checks</button>
        <ul class="aiseoc-checks" id="aiseoc-checks"></ul>
      </div>
    </details>
    <details class="aiseoc-panel aiseoc-fold">
      <summary>
        <h2 class="aiseoc-h">Application Password</h2>
        <span class="aiseoc-chip"><?php echo $app_username ? 'Configured' : 'Optional'; ?></span>
      </summary>
      <div class="aiseoc-fold-body">
        <p class="aiseoc-muted">An alternative to the token, using WordPress's own per-app credential system. Revocable any time from <strong>Users → Profile</strong> without touching this plugin.</p>
        <?php if ( $app_username ): ?>
          <p class="aiseoc-muted" id="aiseoc-app-username-label">WordPress user: <strong style="color:#fff"><?php echo esc_html( $app_username ); ?></strong> (password shown only once, at creation)</p>
        <?php else: ?>
          <p class="aiseoc-muted" id="aiseoc-no-app-pw">No Application Password has been created for this connection.</p>
        <?php endif; ?>
        <button class="aiseoc-btn ghost" id="aiseoc-create-app-pw"><?php echo $app_username ? 'Regenerate' : 'Create'; ?> Application Password</button>
        <div id="aiseoc-app-pw-block" style="display:none;margin-top:14px">
          <div class="aiseoc-field"><span class="aiseoc-label">Password</span><span class="aiseoc-code" id="aiseoc-app-pw-display"></span></div>
          <div class="aiseoc-field"><span class="aiseoc-label">Basic auth</span><span class="aiseoc-code" id="aiseoc-app-encoded-display"></span></div>
          <p class="aiseoc-muted" style="color:#f59e0b">Copy this now — WordPress will not show it again.</p>
        </div>
      </div>
    </details>
    <section class="aiseoc-panel">
      <h2 class="aiseoc-h">Recent activity</h2>
      <div class="aiseoc-log">
        <?php if ( empty( $activity_logs ) ): ?>
          <div class="aiseoc-empty">No activity yet.</div>
        <?php else: foreach ( $activity_logs as $entry ): ?>
          <div class="aiseoc-log-row">
            <span style="color:#5a5a68;white-space:nowrap"><?php echo esc_html( $entry['time'] ); ?></span>
            <span class="lvl-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span>
            <span style="color:#c8c8d4"><?php echo esc_html( $entry['message'] ); ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if ( ! empty( $blocked_logs ) ): ?>
      <details class="aiseoc-blocked" style="margin-top:14px">
        <summary>Blocked connection attempts (<?php echo count( $blocked_logs ); ?>)</summary>
        <p class="aiseoc-muted" style="margin:6px 0 10px">Requests with a missing or invalid token/password, automatically rejected and rate-limited (max 20 per 15 minutes per IP address). This is normal background noise on any public site (automated scanners probing random URLs) and does <strong>not</strong> mean your site was compromised or that anyone got in.</p>
        <div class="aiseoc-log">
          <?php foreach ( $blocked_logs as $entry ): ?>
            <div class="aiseoc-log-row">
              <span style="color:#5a5a68;white-space:nowrap"><?php echo esc_html( $entry['time'] ); ?></span>
              <span class="lvl-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span>
              <span style="color:#9a9aa8"><?php echo esc_html( $entry['message'] ); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>

      <button class="aiseoc-btn danger" id="aiseoc-clear-logs" style="margin-top:14px">Clear logs</button>
    </section>
    <section class="aiseoc-panel aiseoc-trust">
      <h2 class="aiseoc-h">What this connection can and can't do</h2>
      <ul>
        <li><strong>Your WordPress login is never shared.</strong> This plugin generates one random token, kept in this site's own database. Your admin password is never requested or stored.</li>
        <li><strong>Tool calls are limited to the groups you switch on above:</strong> Content, SEO, Media and Site info. There is no page-builder access, no plugin installs and no PHP execution, regardless of settings.</li>
        <li><strong>You can cut access instantly, at any time.</strong> Regenerate the token to invalidate it immediately, or pause the connection to reject every request until you resume. No confirmation is needed from anyone else.</li>
      </ul>
    </section>
  </div>
</div>

<div class="aiseoc-toast" id="aiseoc-toast"></div>

<script>
(function(){
  const $ = (id) => document.getElementById(id);
  const nonce = <?php echo wp_json_encode( $nonce ); ?>;
  const ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
  const post = (action, extra = {}) =>
    fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action, nonce, ...extra }) }).then(r => r.json());

  let toastTimer;
  function toast(msg, ok = true){
    const t = $('aiseoc-toast');
    t.textContent = msg;
    t.style.display = 'block';
    t.style.borderColor = ok ? '#166534' : '#7f1d1d';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, 3500);
  }

  function setColor(el, color){
    if (!el) return;
    [...el.classList].filter(c => c.startsWith('aiseoc-s-')).forEach(c => el.classList.remove(c));
    el.classList.add('aiseoc-s-' + color);
  }

  // Single place the connection state is painted: header pill, hero, pause
  // button and the WordPress menu badge all follow the same object.
  function applyStatus(s){
    setColor($('aiseoc-pill'), s.color);
    setColor($('aiseoc-hero'), s.color);
    $('aiseoc-pill-label').textContent = s.label;
    $('aiseoc-hero-label').textContent = s.label;
    $('aiseoc-hero-detail').textContent = s.detail;
    const pause = $('aiseoc-pause');
    if (pause){
      const paused = s.state === 'paused';
      pause.dataset.paused = paused ? '1' : '0';
      pause.textContent = paused ? 'Resume connection' : 'Pause connection';
    }
    const rgb = { green: '52,211,153', yellow: '251,191,36', red: '248,113,113', white: '229,231,235' }[s.color] || '229,231,235';
    const item = document.querySelector('#adminmenu li.toplevel_page_ai-seo-connector');
    if (item) item.style.setProperty('--aiseoc-dot-rgb', rgb);
  }

  // Poll while the tab is visible so "Connected" appears without a reload.
  setInterval(() => {
    if (document.visibilityState !== 'visible') return;
    post('aiseoc_status').then(d => { if (d.success) applyStatus(d.data); }).catch(() => {});
  }, 20000);

  function copyText(text){
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise((resolve, reject) => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
      ta.remove();
    });
  }

  $('aiseoc-copy-token')?.addEventListener('click', () => {
    copyText($('aiseoc-token-display').dataset.token)
      .then(() => toast('Token copied.'))
      .catch(() => toast('Could not copy automatically. Select the token and copy it manually.', false));
  });

  $('aiseoc-regen-token')?.addEventListener('click', () => {
    if (!confirm('Regenerate token? Any connection using the old token will need updating.')) return;
    post('aiseoc_regen').then(d => {
      if (!d.success) return;
      const t = d.data.token;
      const el = $('aiseoc-token-display');
      el.dataset.token = t;
      el.textContent = t.length > 16 ? t.slice(0, 8) + '…' + t.slice(-8) : '•'.repeat(t.length);
      applyStatus(d.data.status);
      toast('Token regenerated. Update the connection in your SEO platform.');
    });
  });

  $('aiseoc-pause')?.addEventListener('click', () => {
    const paused = $('aiseoc-pause').dataset.paused === '1';
    if (!paused && !confirm('Pause the connection? Every request from your platform will be rejected until you resume.')) return;
    post('aiseoc_toggle_pause', { paused: paused ? '' : '1' }).then(d => {
      if (!d.success) return toast('Could not change the connection state.', false);
      applyStatus(d.data.status);
      toast(paused ? 'Connection resumed.' : 'Connection paused.');
    });
  });

  $('aiseoc-create-app-pw')?.addEventListener('click', () => {
    const btn = $('aiseoc-create-app-pw');
    btn.textContent = 'Creating…';
    btn.disabled = true;
    post('aiseoc_create_app_pw').then(d => {
      btn.disabled = false;
      if (d.success) {
        const { password, encoded } = d.data;
        const noMsg = $('aiseoc-no-app-pw');
        if (noMsg) noMsg.style.display = 'none';
        $('aiseoc-app-pw-block').style.display = '';
        $('aiseoc-app-pw-display').textContent = password;
        $('aiseoc-app-encoded-display').textContent = 'Basic ' + encoded;
        btn.textContent = 'Regenerate Application Password';
        toast("Application Password created. Copy it now, it won't be shown again.");
      } else {
        btn.textContent = 'Create Application Password';
        toast('Error: ' + (d.data?.message || 'Unknown error'), false);
      }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Create Application Password'; toast('Request failed.', false); });
  });

  $('aiseoc-save')?.addEventListener('click', () => {
    const fd = new FormData();
    fd.append('action', 'aiseoc_save');
    fd.append('nonce', nonce);
    fd.append('log_level', 'info');
    document.querySelectorAll('input[name="aiseoc_actions[]"]:checked').forEach(c => fd.append('allowed_actions[]', c.value));
    fetch(ajaxurl, { method: 'POST', body: fd })
      .then(r => r.json()).then(d => toast(d.success ? 'Access scope saved.' : 'Error saving.', d.success));
  });

  $('aiseoc-clear-logs')?.addEventListener('click', () => {
    post('aiseoc_clear_logs').then(d => {
      if (!d.success) return;
      document.querySelectorAll('.aiseoc-log').forEach(el => { el.innerHTML = '<div class="aiseoc-empty">Log cleared.</div>'; });
      document.querySelector('.aiseoc-blocked')?.remove();
      toast('Logs cleared.');
    });
  });

  // ── Doctor ──
  const icons  = { pass: '✓', warn: '!', fail: '✕', info: 'i' };
  const colors = { pass: 'green', warn: 'yellow', fail: 'red', info: 'white' };
  let doctorRan = false;

  function renderDoctor(data){
    const list = $('aiseoc-checks');
    list.textContent = '';
    data.checks.forEach(c => {
      const li = document.createElement('li');
      li.className = 'aiseoc-check aiseoc-s-' + colors[c.status];
      const ic = document.createElement('span'); ic.className = 'aiseoc-check-ic'; ic.textContent = icons[c.status];
      const body = document.createElement('div');
      const b = document.createElement('b'); b.textContent = c.label;
      const p = document.createElement('p'); p.textContent = c.detail;
      body.append(b, p);
      if (c.fix){ const f = document.createElement('p'); f.className = 'fix'; f.textContent = 'Fix: ' + c.fix; body.append(f); }
      li.append(ic, body);
      list.append(li);
    });
    const s = data.summary;
    const chip = $('aiseoc-doctor-chip');
    chip.textContent = s.pass + ' passed' + (s.warn ? ' · ' + s.warn + ' warning' + (s.warn > 1 ? 's' : '') : '') + (s.fail ? ' · ' + s.fail + ' failed' : '');
    setColor(chip, s.fail ? 'red' : (s.warn ? 'yellow' : 'green'));
  }

  function runDoctor(){
    const btn = $('aiseoc-doctor-run');
    btn.disabled = true; btn.textContent = 'Running…';
    post('aiseoc_doctor').then(d => {
      btn.disabled = false; btn.textContent = 'Run again';
      if (d.success){ doctorRan = true; renderDoctor(d.data); } else { toast('Doctor could not run.', false); }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Run checks'; toast('Doctor request failed.', false); });
  }

  $('aiseoc-doctor-run')?.addEventListener('click', runDoctor);
  $('aiseoc-doctor')?.addEventListener('toggle', () => { if ($('aiseoc-doctor').open && !doctorRan) runDoctor(); });
})();
</script>
