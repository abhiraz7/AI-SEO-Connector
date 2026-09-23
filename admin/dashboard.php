<?php
/**
 * Settings screen for AI SEO Connector. All variables below ($token, $enabled,
 * $actions, $logs, $nonce, $api_base, $app_username, $has_yoast, $has_rankmath)
 * are set by AISEOC_Admin::render_page() just before this file is included.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$masked_token = $token ? substr( $token, 0, 8 ) . '…' . substr( $token, -8 ) : '';
$groups = [
    'content' => [ 'label' => 'Content',      'desc' => 'Create/update/delete posts &amp; pages, featured images, taxonomies.' ],
    'seo'     => [ 'label' => 'SEO (Yoast)',   'desc' => 'Read/write Yoast SEO meta, run audits, ping sitemaps.' ],
    'media'   => [ 'label' => 'Media',         'desc' => 'Upload/list/delete media, fix alt text (by ID or by URL).' ],
    'site'    => [ 'label' => 'Site info',     'desc' => 'Read-only site/plugin info, flush cache. No installs, no user or option writes.' ],
];

/**
 * Split "Recent activity" into genuinely actionable entries (a tool call, a
 * real error) versus routine blocked-auth noise (a bad token/password from
 * some scanner, correctly rejected by the rate limiter). Shown separately
 * below so a non-technical client doesn't read a wall of "Failed Bearer
 * token attempt" lines as evidence of an active break-in -- it's the
 * security working as intended, not an incident. Nothing is discarded,
 * just grouped differently.
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
  @keyframes aiseoc-mesh-drift{
    0%,100%{background-position:0% 0%,100% 100%,50% 20%,0 0}
    50%{background-position:100% 40%,10% 60%,60% 100%,0 0}
  }
  @keyframes aiseoc-badge-pulse{
    0%,100%{box-shadow:0 0 0 1px rgba(51,194,136,.35),0 0 10px rgba(51,194,136,.35)}
    50%{box-shadow:0 0 0 1px rgba(51,194,136,.55),0 0 20px rgba(51,194,136,.65)}
  }
  @keyframes aiseoc-fade-up{
    from{opacity:0;transform:translateY(6px)}
    to{opacity:1;transform:translateY(0)}
  }

  .aiseoc-wrap{
    position:relative;
    --aiseoc-accent:<?php echo esc_attr( $accent_1 ); ?>;
    --aiseoc-accent-2:<?php echo esc_attr( $accent_2 ); ?>;
    max-width:min(1400px, 94vw);margin:24px auto;
    padding:36px 32px 44px;
    border-radius:22px;
    color:#e7e7ee;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:
      radial-gradient(circle at 12% 12%, color-mix(in srgb, var(--aiseoc-accent) 55%, transparent), transparent 42%),
      radial-gradient(circle at 88% 8%, color-mix(in srgb, var(--aiseoc-accent-2) 50%, transparent), transparent 38%),
      radial-gradient(circle at 50% 95%, rgba(16,185,129,.14), transparent 45%),
      #0a0a10;
    background-size:220% 220%,220% 220%,220% 220%,auto;
    animation:aiseoc-mesh-drift 24s ease-in-out infinite;
    box-shadow:0 30px 80px rgba(0,0,0,.45),0 1px 0 rgba(255,255,255,.04) inset;
    border:1px solid rgba(255,255,255,.06);
  }
  .aiseoc-wrap h1{
    font-size:27px;font-weight:700;margin:0 0 6px;letter-spacing:-.3px;
  }
  .aiseoc-wrap h1 .aiseoc-title-grad{
    background:linear-gradient(135deg,#ffffff 0%,#cfcfff 55%,#9d9dff 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .aiseoc-wrap .aiseoc-sub{color:#a3a3b0;font-size:13.5px;line-height:1.55;margin:0 0 28px;max-width:640px}

  .aiseoc-card{
    position:relative;overflow:hidden;
    background:rgba(22,22,30,.58);
    -webkit-backdrop-filter:blur(18px) saturate(140%);
    backdrop-filter:blur(18px) saturate(140%);
    border:1px solid rgba(255,255,255,.08);
    border-radius:14px;padding:24px 26px;margin-bottom:20px;
    box-shadow:0 10px 30px rgba(0,0,0,.28),0 1px 0 rgba(255,255,255,.03) inset;
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease;
    animation:aiseoc-fade-up .4s ease both;
  }
  .aiseoc-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,var(--aiseoc-accent),var(--aiseoc-accent-2),#06b6d4);opacity:.55;
  }
  .aiseoc-card:hover{
    transform:translateY(-2px);
    border-color:rgba(255,255,255,.14);
    box-shadow:0 16px 44px rgba(0,0,0,.4),0 1px 0 rgba(255,255,255,.05) inset;
  }
  .aiseoc-card h2{
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;
    margin:0 0 16px;color:#b7b7ff;display:flex;align-items:center;gap:8px;
  }
  .aiseoc-card h2::before{
    content:'';width:6px;height:6px;border-radius:50%;flex:none;
    background:linear-gradient(135deg,var(--aiseoc-accent),var(--aiseoc-accent-2));
    box-shadow:0 0 10px rgba(139,92,246,.85);
  }

  .aiseoc-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
  .aiseoc-code{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    background:rgba(10,10,16,.7);border:1px solid rgba(255,255,255,.08);
    border-radius:7px;padding:8px 11px;font-size:12.5px;color:#b3b3ff;word-break:break-all;
    transition:border-color .2s ease;
  }
  .aiseoc-code:hover{border-color:rgba(139,92,246,.4)}

  .aiseoc-btn{
    background:linear-gradient(135deg,var(--aiseoc-accent) 0%,var(--aiseoc-accent-2) 100%);
    color:#fff;border:none;border-radius:8px;padding:9px 16px;
    font-size:12.5px;font-weight:600;cursor:pointer;letter-spacing:.01em;
    box-shadow:0 4px 16px rgba(99,102,241,.35);
    transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;
  }
  .aiseoc-btn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(99,102,241,.5);filter:brightness(1.06)}
  .aiseoc-btn:active{transform:translateY(0)}
  .aiseoc-btn.ghost{
    background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);color:#d3d3db;
    box-shadow:none;
  }
  .aiseoc-btn.ghost:hover{background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.2);transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.3)}
  .aiseoc-btn.danger{
    background:transparent;border:1px solid rgba(248,113,113,.35);color:#f87171;box-shadow:none;
  }
  .aiseoc-btn.danger:hover{background:rgba(248,113,113,.1);border-color:rgba(248,113,113,.6);transform:translateY(-1px)}

  .aiseoc-group{display:flex;align-items:flex-start;gap:10px;padding:12px 4px;border-bottom:1px solid rgba(255,255,255,.06);border-radius:8px;transition:background .18s ease}
  .aiseoc-group:hover{background:rgba(255,255,255,.03)}
  .aiseoc-group:last-child{border-bottom:none}
  .aiseoc-group label{font-weight:600;color:#fff;font-size:13px}
  .aiseoc-group p{margin:3px 0 0;color:#9a9aa8;font-size:12px;line-height:1.5}
  .aiseoc-group input[type="checkbox"]{accent-color:var(--aiseoc-accent-2)}

  .aiseoc-log{max-height:260px;overflow-y:auto;font-family:ui-monospace,monospace;font-size:11.5px}
  .aiseoc-log-row{display:flex;gap:10px;padding:6px 4px;border-bottom:1px solid rgba(255,255,255,.05);border-radius:6px;transition:background .15s ease}
  .aiseoc-log-row:hover{background:rgba(255,255,255,.03)}
  .aiseoc-log-row .lvl-warn{color:#f59e0b}.aiseoc-log-row .lvl-error{color:#f87171}.aiseoc-log-row .lvl-info{color:#7dd3a8}

  .aiseoc-toast{
    position:fixed;bottom:20px;right:20px;
    background:rgba(22,22,30,.85);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);
    border:1px solid rgba(255,255,255,.12);color:#fff;padding:11px 18px;border-radius:10px;
    font-size:13px;z-index:9999;display:none;box-shadow:0 12px 32px rgba(0,0,0,.5);
  }

  .aiseoc-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600;letter-spacing:.02em}
  .aiseoc-badge.on{background:rgba(15,42,32,.9);color:#4ade9c;animation:aiseoc-badge-pulse 2.6s ease-in-out infinite}
  .aiseoc-badge.off{background:#2e1613;color:#ff6b5e}

  .aiseoc-trust{border-color:rgba(125,211,168,.18);background:rgba(20,26,23,.6)}
  .aiseoc-trust h2{color:#7dd3a8}
  .aiseoc-trust h2::before{background:linear-gradient(135deg,#34d399,#10b981);box-shadow:0 0 10px rgba(16,185,129,.7)}
  .aiseoc-trust ul{margin:8px 0 0;padding-left:18px;color:#c8c8d0;font-size:12.5px;line-height:1.65}
  .aiseoc-trust li{margin-bottom:5px}

  .aiseoc-blocked summary{cursor:pointer;font-size:12px;color:#8a8a95;padding:6px 0;transition:color .15s ease}
  .aiseoc-blocked summary:hover{color:#b3b3c0}
  .aiseoc-blocked .aiseoc-log-row{opacity:0.6}
</style>

<h1>🛰️ <span class="aiseoc-title-grad">AI SEO Connector</span></h1>
<p class="aiseoc-sub">Lets the VtechSEO platform read on-page SEO data and apply approved fixes on this site. Scoped to content, SEO and media only — no page-builder control, no plugin installs, no PHP execution.</p>

<div class="aiseoc-card aiseoc-trust">
  <h2>What this connection can and can't do</h2>
  <ul>
    <li><strong>Your WordPress login is never shared.</strong> This plugin generates one random token, kept in this site's own database. Nothing else — not your admin password, not your email — ever leaves this server.</li>
    <li><strong>Access is scoped and listed below.</strong> The VtechSEO platform can only use the tool groups you enable in "Enabled tool groups" beneath this card: Content, SEO, Media, and read-only Site info. There is no page-builder access, no plugin installs, no PHP execution, no arbitrary database access — ever, regardless of settings.</li>
    <li><strong>You can revoke access instantly, at any time.</strong> Click "Regenerate" next to the token to invalidate it immediately, or uncheck "Enabled" to reject every request until you turn it back on. No confirmation needed from anyone else.</li>
  </ul>
</div>

<div class="aiseoc-card">
  <h2>Connection</h2>
  <div class="aiseoc-row">
    <span class="aiseoc-badge <?php echo $enabled === '1' ? 'on' : 'off'; ?>"><?php echo $enabled === '1' ? 'Enabled' : 'Disabled'; ?></span>
    <label style="font-size:13px;color:#c8c8d0"><input type="checkbox" id="aiseoc-enabled" <?php checked( $enabled, '1' ); ?>> Enabled</label>
  </div>
  <div class="aiseoc-row"><strong style="font-size:12px;color:#9a9aa5;width:110px">API base URL</strong><span class="aiseoc-code"><?php echo esc_html( $api_base ); ?></span></div>
  <div class="aiseoc-row">
    <strong style="font-size:12px;color:#9a9aa5;width:110px">Bearer token</strong>
    <span class="aiseoc-code" id="aiseoc-token-display" data-token="<?php echo esc_attr( $token ); ?>"><?php echo esc_html( $masked_token ); ?></span>
    <button class="aiseoc-btn ghost" id="aiseoc-copy-token">Copy full token</button>
    <button class="aiseoc-btn ghost" id="aiseoc-regen-token">🔄 Regenerate</button>
  </div>
  <p style="font-size:11.5px;color:#7a7a85;margin:8px 0 0">Paste the API base URL and token into VtechSEO's WordPress connection screen. Regenerating immediately invalidates the old token everywhere it's used — do this any time you suspect it's been exposed.</p>
</div>

<div class="aiseoc-card">
  <h2>Alternative: WordPress Application Password</h2>
  <p style="font-size:12px;color:#9a9aa5;margin:0 0 10px">WordPress's own built-in per-app credential system — revocable any time from <strong>Users → Profile</strong> without touching this plugin.</p>
  <?php if ( $app_username ): ?>
    <p style="font-size:12px;color:#c8c8d0" id="aiseoc-app-username-label">WordPress user: <strong style="color:#fff"><?php echo esc_html( $app_username ); ?></strong> (password shown only once, at creation)</p>
  <?php else: ?>
    <p style="font-size:12px;color:#7a7a85" id="aiseoc-no-app-pw">No Application Password created yet for this connection.</p>
  <?php endif; ?>
  <button class="aiseoc-btn ghost" id="aiseoc-create-app-pw">🔑 <?php echo $app_username ? 'Regenerate' : 'Create'; ?> Application Password</button>
  <div id="aiseoc-app-pw-block" style="display:none;margin-top:10px">
    <div class="aiseoc-row"><strong style="font-size:12px;color:#9a9aa5;width:80px">Password</strong><span class="aiseoc-code" id="aiseoc-app-pw-display"></span></div>
    <div class="aiseoc-row"><strong style="font-size:12px;color:#9a9aa5;width:80px">Basic auth</strong><span class="aiseoc-code" id="aiseoc-app-encoded-display"></span></div>
    <p style="font-size:11px;color:#f59e0b">Copy this now — WordPress will not show it again.</p>
  </div>
</div>

<div class="aiseoc-card">
  <h2>Enabled tool groups</h2>
  <?php foreach ( $groups as $key => $g ): ?>
    <div class="aiseoc-group">
      <input type="checkbox" name="aiseoc_actions[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $actions, true ) ); ?> style="margin-top:3px">
      <div><label><?php echo esc_html( $g['label'] ); ?></label><p><?php echo $g['desc']; ?></p></div>
    </div>
  <?php endforeach; ?>
  <p style="font-size:11.5px;color:#7a7a85;margin:10px 0 0">
    Detected SEO plugin: <strong style="color:#c8c8d0"><?php echo $has_yoast ? 'Yoast SEO' : ( $has_rankmath ? 'RankMath' : 'none detected' ); ?></strong>
  </p>
  <button class="aiseoc-btn" id="aiseoc-save" style="margin-top:12px">Save settings</button>
</div>

<div class="aiseoc-card">
  <h2>Recent activity</h2>
  <div class="aiseoc-log">
    <?php if ( empty( $activity_logs ) ): ?>
      <div style="text-align:center;padding:24px;color:#7a7a85;font-size:12px">No activity yet.</div>
    <?php else: foreach ( $activity_logs as $entry ): ?>
      <div class="aiseoc-log-row">
        <span style="color:#5a5a65;white-space:nowrap"><?php echo esc_html( $entry['time'] ); ?></span>
        <span class="lvl-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span>
        <span style="color:#c8c8d0"><?php echo esc_html( $entry['message'] ); ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ( ! empty( $blocked_logs ) ): ?>
  <details class="aiseoc-blocked" style="margin-top:14px">
    <summary>Blocked connection attempts (<?php echo count( $blocked_logs ); ?>)</summary>
    <p style="font-size:11.5px;color:#7a7a85;margin:6px 0 10px">These are requests with a missing or invalid token/password — automatically rejected and rate-limited (max 20 per 15 minutes per IP address). This is normal background noise on any public site (automated scanners probing random URLs) and does <strong>not</strong> mean your site was compromised or that anyone got in.</p>
    <div class="aiseoc-log">
      <?php foreach ( $blocked_logs as $entry ): ?>
        <div class="aiseoc-log-row">
          <span style="color:#5a5a65;white-space:nowrap"><?php echo esc_html( $entry['time'] ); ?></span>
          <span class="lvl-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span>
          <span style="color:#9a9aa5"><?php echo esc_html( $entry['message'] ); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <button class="aiseoc-btn danger" id="aiseoc-clear-logs" style="margin-top:12px">Clear logs</button>
</div>

<div class="aiseoc-toast" id="aiseoc-toast"></div>

<script>
(function(){
  const $ = (id) => document.getElementById(id);
  const nonce = <?php echo wp_json_encode( $nonce ); ?>;
  const ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

  function toast(msg, ok = true){
    const t = $('aiseoc-toast');
    t.textContent = msg;
    t.style.display = 'block';
    t.style.borderColor = ok ? '#166534' : '#7f1d1d';
    setTimeout(() => { t.style.display = 'none'; }, 3500);
  }

  $('aiseoc-copy-token')?.addEventListener('click', () => {
    const full = $('aiseoc-token-display').dataset.token;
    navigator.clipboard.writeText(full).then(() => toast('Token copied.'));
  });

  $('aiseoc-regen-token')?.addEventListener('click', () => {
    if (!confirm('Regenerate token? Any connection using the old token will need updating.')) return;
    fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action: 'aiseoc_regen', nonce }) })
      .then(r => r.json()).then(d => {
        if (d.success) {
          const t = d.data.token;
          const masked = t.length > 16 ? t.slice(0, 8) + '…' + t.slice(-8) : '•'.repeat(t.length);
          const el = $('aiseoc-token-display');
          el.dataset.token = t;
          el.textContent = masked;
          toast('Token regenerated — update your VtechSEO connection.');
        }
      });
  });

  $('aiseoc-create-app-pw')?.addEventListener('click', () => {
    const btn = $('aiseoc-create-app-pw');
    btn.textContent = 'Creating…';
    btn.disabled = true;
    fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action: 'aiseoc_create_app_pw', nonce }) })
      .then(r => r.json()).then(d => {
        btn.disabled = false;
        if (d.success) {
          const { username, password, encoded } = d.data;
          const noMsg = $('aiseoc-no-app-pw');
          if (noMsg) noMsg.style.display = 'none';
          const block = $('aiseoc-app-pw-block');
          if (block) block.style.display = '';
          $('aiseoc-app-pw-display').textContent = password;
          $('aiseoc-app-encoded-display').textContent = 'Basic ' + encoded;
          btn.textContent = '🔄 Regenerate Application Password';
          toast("Application Password created! Copy it now — it won't be shown again.");
        } else {
          toast('Error: ' + (d.data?.message || 'Unknown error'), false);
        }
      }).catch(() => { btn.disabled = false; toast('Request failed.', false); });
  });

  $('aiseoc-save')?.addEventListener('click', () => {
    const fd = new FormData();
    fd.append('action', 'aiseoc_save');
    fd.append('nonce', nonce);
    if ($('aiseoc-enabled').checked) fd.append('enabled', '1');
    fd.append('log_level', 'info');
    document.querySelectorAll('input[name="aiseoc_actions[]"]:checked').forEach(c => fd.append('allowed_actions[]', c.value));
    fetch(ajaxurl, { method: 'POST', body: fd })
      .then(r => r.json()).then(d => toast(d.success ? 'Settings saved.' : 'Error saving.', d.success));
  });

  $('aiseoc-clear-logs')?.addEventListener('click', () => {
    fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({ action: 'aiseoc_clear_logs', nonce }) })
      .then(r => r.json()).then(d => {
        if (d.success) {
          document.querySelectorAll('.aiseoc-log').forEach(el => {
            el.innerHTML = '<div style="text-align:center;padding:24px;color:#7a7a85;font-size:12px">Log cleared.</div>';
          });
          document.querySelector('.aiseoc-blocked')?.remove();
          toast('Logs cleared.');
        }
      });
  });
})();
</script>
