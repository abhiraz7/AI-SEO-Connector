<?php
/**
 * Settings screen for AI SEO Connector. Variables in scope, set by
 * AISEOC_Admin::render_page(): $token, $actions, $logs, $nonce, $api_base,
 * $app_username, $status, $has_yoast, $has_rankmath.
 *
 * Deliberately plain: it reuses WordPress's own classes (.wrap, .button,
 * .button-primary, .description) and the admin color scheme variable, so it
 * follows whatever admin look the site owner has chosen. The only CSS here is
 * layout and the status dot.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$masked_token = $token ? substr( $token, 0, 8 ) . '…' . substr( $token, -8 ) : '';
$is_paused    = $status['state'] === 'paused';
$site_host    = wp_parse_url( home_url(), PHP_URL_HOST ) ?: home_url();

$groups = [
    'seo'     => [ 'label' => 'SEO',       'desc' => 'Read and write Yoast or RankMath meta and run audits.' ],
    'content' => [ 'label' => 'Content',   'desc' => 'Create, update and delete posts and pages, featured images, taxonomies.' ],
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
  .aiseoc{max-width:760px}
  .aiseoc h1{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .aiseoc-ver{font-size:12px;font-weight:400;color:#646970}
  .aiseoc-site{margin:2px 0 0;color:#646970}

  /* Status colors: green connected, grey awaiting, amber idle, red paused */
  .aiseoc-s-green{--s:#00a32a}
  .aiseoc-s-yellow{--s:#dba617}
  .aiseoc-s-red{--s:#d63638}
  .aiseoc-s-white{--s:#8c8f94}
  .aiseoc-status{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:#1d2327}
  .aiseoc-dot{width:8px;height:8px;border-radius:50%;background:var(--s,#8c8f94)}

  .aiseoc-sec{margin-top:24px;padding-top:20px;border-top:1px solid #dcdcde}
  .aiseoc-sec h2{margin:0 0 12px;padding:0;font-size:14px}
  .aiseoc-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px}
  .aiseoc-key{flex:none;width:90px;color:#646970}
  .aiseoc-code{min-width:0;padding:4px 8px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;
    font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-all}
  .aiseoc .description{margin:4px 0 0}

  .aiseoc-perm{display:flex;align-items:flex-start;gap:8px;margin:0 0 10px}
  .aiseoc-perm input{margin-top:2px}
  .aiseoc-perm b{display:block;font-weight:500}

  .aiseoc details{margin-top:12px;padding-top:12px;border-top:1px solid #dcdcde}
  .aiseoc summary{cursor:pointer;font-size:14px;font-weight:600}
  .aiseoc details>div{margin-top:12px}
  .aiseoc-chip{margin-left:8px;font-size:12px;font-weight:400;color:var(--s,#646970)}

  .aiseoc-checks{margin:12px 0 0;padding:0;list-style:none}
  .aiseoc-check{display:grid;grid-template-columns:18px 1fr;gap:8px;padding:6px 0}
  .aiseoc-check-ic{font-weight:700;color:var(--s)}
  .aiseoc-check p{margin:2px 0 0;color:#646970}

  .aiseoc-log{max-height:240px;overflow-y:auto;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .aiseoc-log-row{display:flex;gap:10px;padding:3px 0;border-bottom:1px solid #f0f0f1}
  .aiseoc-log-row .t{color:#8c8f94;white-space:nowrap}
  .aiseoc-log-row .lvl-warn{color:#996800}.aiseoc-log-row .lvl-error{color:#d63638}.aiseoc-log-row .lvl-info{color:#00a32a}
  .aiseoc-empty{padding:8px 0;color:#646970}
  .aiseoc-blocked .aiseoc-log-row{opacity:.7}
  .aiseoc-trust{margin:0;padding-left:18px}
  .aiseoc-trust li{margin-bottom:6px}

  .aiseoc-toast{position:fixed;right:20px;bottom:20px;z-index:99999;display:none;padding:10px 14px;
    border-left:4px solid #00a32a;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);font-size:13px}
</style>

<div class="wrap aiseoc">

  <h1>
    SEO Connector
    <span class="aiseoc-status aiseoc-s-<?php echo esc_attr( $status['color'] ); ?>" id="aiseoc-pill" role="status" aria-live="polite">
      <span class="aiseoc-dot"></span><span id="aiseoc-pill-label"><?php echo esc_html( $status['label'] ); ?></span>
    </span>
    <span class="aiseoc-ver">v<?php echo esc_html( AISEOC_VERSION ); ?></span>
  </h1>
  <p class="aiseoc-site"><?php echo esc_html( $site_host ); ?></p>

  <section class="aiseoc-sec">
    <h2>Connection</h2>
    <p class="aiseoc-row" style="margin-bottom:4px">
      <span id="aiseoc-detail"><?php echo esc_html( $status['detail'] ); ?></span>
      <button class="button" id="aiseoc-pause" data-paused="<?php echo $is_paused ? '1' : '0'; ?>"><?php echo $is_paused ? 'Resume' : 'Pause'; ?></button>
    </p>
    <div class="aiseoc-row" style="margin-top:16px"><span class="aiseoc-key">API URL</span><span class="aiseoc-code"><?php echo esc_html( $api_base ); ?></span></div>
    <div class="aiseoc-row">
      <span class="aiseoc-key">Token</span>
      <span class="aiseoc-code" id="aiseoc-token-display" data-token="<?php echo esc_attr( $token ); ?>"><?php echo esc_html( $masked_token ); ?></span>
      <button class="button" id="aiseoc-copy-token">Copy</button>
      <button class="button" id="aiseoc-regen-token">Regenerate</button>
    </div>
    <p class="description">Paste these into your SEO platform. Regenerating invalidates the old token immediately.</p>
  </section>

  <section class="aiseoc-sec">
    <h2>Permissions</h2>
    <?php foreach ( $groups as $key => $g ): ?>
      <label class="aiseoc-perm">
        <input type="checkbox" name="aiseoc_actions[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $actions, true ) ); ?>>
        <span><b><?php echo esc_html( $g['label'] ); ?></b><span class="description"><?php echo esc_html( $g['desc'] ); ?></span></span>
      </label>
    <?php endforeach; ?>
    <p class="description">SEO plugin detected: <?php echo $has_yoast ? 'Yoast SEO' : ( $has_rankmath ? 'RankMath' : 'none' ); ?></p>
    <p><button class="button button-primary" id="aiseoc-save">Save permissions</button></p>
  </section>

  <section class="aiseoc-sec">
    <h2>Tools</h2>

    <details id="aiseoc-doctor">
      <summary>Doctor <span class="aiseoc-chip" id="aiseoc-doctor-chip"></span></summary>
      <div>
        <p class="description">Checks PHP, WordPress, HTTPS, permalinks and your SEO plugin, then makes a real request to this site's own API.</p>
        <p><button class="button" id="aiseoc-doctor-run">Run checks</button></p>
        <ul class="aiseoc-checks" id="aiseoc-checks"></ul>
      </div>
    </details>

    <details>
      <summary>Recent activity</summary>
      <div>
        <div class="aiseoc-log">
          <?php if ( empty( $activity_logs ) ): ?>
            <div class="aiseoc-empty">No activity yet.</div>
          <?php else: foreach ( $activity_logs as $entry ): ?>
            <div class="aiseoc-log-row">
              <span class="t"><?php echo esc_html( $entry['time'] ); ?></span>
              <span class="lvl-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span>
              <span><?php echo esc_html( $entry['message'] ); ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <?php if ( ! empty( $blocked_logs ) ): ?>
        <details class="aiseoc-blocked">
          <summary>Blocked attempts (<?php echo count( $blocked_logs ); ?>)</summary>
          <div>
            <p class="description">Requests with a missing or invalid token, rejected automatically and rate-limited. This is normal background noise from scanners on any public site; it does not mean anyone got in.</p>
            <div class="aiseoc-log">
              <?php foreach ( $blocked_logs as $entry ): ?>
                <div class="aiseoc-log-row">
                  <span class="t"><?php echo esc_html( $entry['time'] ); ?></span>
                  <span><?php echo esc_html( $entry['message'] ); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </details>
        <?php endif; ?>
        <p><button class="button" id="aiseoc-clear-logs">Clear logs</button></p>
      </div>
    </details>

    <details>
      <summary>Application password <span class="aiseoc-chip"><?php echo $app_username ? 'Configured' : 'Optional'; ?></span></summary>
      <div>
        <p class="description">An alternative to the token, using WordPress's own credentials. Revocable any time from Users → Profile.</p>
        <?php if ( $app_username ): ?>
          <p id="aiseoc-app-username-label">WordPress user: <strong><?php echo esc_html( $app_username ); ?></strong> (password is shown only once, when created)</p>
        <?php else: ?>
          <p id="aiseoc-no-app-pw">No application password has been created.</p>
        <?php endif; ?>
        <p><button class="button" id="aiseoc-create-app-pw"><?php echo $app_username ? 'Regenerate' : 'Create'; ?> application password</button></p>
        <div id="aiseoc-app-pw-block" style="display:none">
          <div class="aiseoc-row"><span class="aiseoc-key">Password</span><span class="aiseoc-code" id="aiseoc-app-pw-display"></span></div>
          <div class="aiseoc-row"><span class="aiseoc-key">Basic auth</span><span class="aiseoc-code" id="aiseoc-app-encoded-display"></span></div>
          <p class="description"><strong>Copy this now. WordPress will not show it again.</strong></p>
        </div>
      </div>
    </details>

    <details>
      <summary>What this connection can and can't do</summary>
      <div>
        <ul class="aiseoc-trust">
          <li><strong>Your WordPress login is never shared.</strong> The plugin generates one random token, kept in this site's database. Your admin password is never requested or stored.</li>
          <li><strong>Tool calls are limited to the permissions you switch on above.</strong> There is no page-builder access, no plugin installs and no PHP execution, whatever the settings.</li>
          <li><strong>You can cut access instantly.</strong> Regenerate the token to invalidate it, or pause the connection to reject every request until you resume.</li>
        </ul>
      </div>
    </details>
  </section>
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
    t.style.borderLeftColor = ok ? '#00a32a' : '#d63638';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, 3500);
  }

  function setColor(el, color){
    if (!el) return;
    [...el.classList].filter(c => c.startsWith('aiseoc-s-')).forEach(c => el.classList.remove(c));
    el.classList.add('aiseoc-s-' + color);
  }

  // Single place the connection state is painted: status label, detail line,
  // pause button and the WordPress menu badge all follow the same object.
  function applyStatus(s){
    setColor($('aiseoc-pill'), s.color);
    $('aiseoc-pill-label').textContent = s.label;
    $('aiseoc-detail').textContent = s.detail;
    const pause = $('aiseoc-pause');
    if (pause){
      const paused = s.state === 'paused';
      pause.dataset.paused = paused ? '1' : '0';
      pause.textContent = paused ? 'Resume' : 'Pause';
    }
    const rgb = { green: '0,163,42', yellow: '219,166,23', red: '214,54,56', white: '140,143,148' }[s.color] || '140,143,148';
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
        btn.textContent = 'Regenerate application password';
        toast("Application password created. Copy it now, it won't be shown again.");
      } else {
        btn.textContent = 'Create application password';
        toast('Error: ' + (d.data?.message || 'Unknown error'), false);
      }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Create application password'; toast('Request failed.', false); });
  });

  $('aiseoc-save')?.addEventListener('click', () => {
    const fd = new FormData();
    fd.append('action', 'aiseoc_save');
    fd.append('nonce', nonce);
    document.querySelectorAll('input[name="aiseoc_actions[]"]:checked').forEach(c => fd.append('allowed_actions[]', c.value));
    fetch(ajaxurl, { method: 'POST', body: fd })
      .then(r => r.json()).then(d => toast(d.success ? 'Permissions saved.' : 'Error saving.', d.success));
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
      if (c.fix){ const f = document.createElement('p'); f.textContent = 'Fix: ' + c.fix; body.append(f); }
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
