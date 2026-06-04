<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HMPWDP2MTN"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-HMPWDP2MTN');
</script>
<title><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?></title>
<meta name="theme-color" content="#111113">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="noodled">
<link rel="manifest" href="<?php echo esc_url( NOODLED_URL . 'assets/manifest.json' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOODLED_URL . 'assets/icon-192.png' ); ?>">
<style>
:root {
  --bg: #111113;
  --bg-card: #1a1a1f;
  --bg-input: #252530;
  --text: #b0b0b8;
  --text-muted: #9a9aac; /* >= 4.5:1 on the dark cards (was #5a5a68, ~2.9:1) */
  --text-bright: #e8e8ef;
  --accent: <?php echo esc_attr( Noodled_Settings::get_accent_color() ); ?>;
  --accent-hover: #2563eb;
  --accent-glow: rgba(59, 130, 246, 0.15);
  --border: #2a2a35;
  --green: #34d399;
  --red: #f87171;
}
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

/* Ambient glow */
body::before {
  content: '';
  position: fixed;
  top: -40%;
  left: 50%;
  transform: translateX(-50%);
  width: 600px;
  height: 600px;
  background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

.login-wrapper {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 40px;
}

/* Brand */
.brand {
  text-align: center;
}

.brand-logo {
  font-size: 56px;
  font-weight: 800;
  color: var(--text-bright);
  letter-spacing: -2.5px;
  line-height: 1;
  margin-bottom: 12px;
}

.brand-logo span {
  color: var(--accent);
}

.brand-tagline {
  font-size: 15px;
  color: var(--text-muted);
  letter-spacing: 0.3px;
}

/* Card */
.login-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 36px;
  width: 380px;
  max-width: 90vw;
  backdrop-filter: blur(20px);
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-bright);
  margin-bottom: 4px;
}

.card-sub {
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 24px;
}

.login-input {
  width: 100%;
  padding: 12px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-input);
  color: var(--text-bright);
  font-size: 14px;
  outline: none;
  margin-bottom: 12px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.login-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-glow);
}

.login-input::placeholder { color: var(--text-muted); }

.login-btn {
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: 10px;
  background: var(--accent);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, transform 0.1s;
  letter-spacing: 0.2px;
}

.login-btn:hover { background: var(--accent-hover); }
.login-btn:active { transform: scale(0.98); }

/* Link-styled buttons (were <a href="#">; now real, keyboard-operable buttons). */
.login-link-btn {
  background: none; border: none; padding: 4px; margin: 0 auto; display: inline-block;
  color: var(--text-muted); font-size: 12px; cursor: pointer; font-family: inherit;
}
.login-link-btn:hover { color: var(--text-bright); }
.login-link-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
.login-btn:disabled { opacity: 0.5; cursor: default; transform: none; }

.login-msg {
  margin-top: 16px;
  font-size: 13px;
  text-align: center;
  min-height: 20px;
}

.login-msg.error { color: var(--red); }
.login-msg.success { color: var(--green); }

/* Features */
.features {
  display: flex;
  gap: 32px;
  margin-top: 8px;
}

.feature {
  text-align: center;
  max-width: 120px;
}

.feature-icon {
  font-size: 20px;
  margin-bottom: 6px;
  opacity: 0.7;
}

.feature-text {
  font-size: 11px;
  color: var(--text-muted);
  line-height: 1.4;
}

/* Footer */
.login-footer {
  position: fixed;
  bottom: 20px;
  font-size: 11px;
  color: var(--text-muted);
  letter-spacing: 0.5px;
  z-index: 1;
}

/* Responsive */
@media (max-width: 480px) {
  .brand-logo { font-size: 40px; }
  .features { gap: 20px; }
  .login-card { padding: 28px; }
}
</style>
</head>
<body>

<div class="login-wrapper">
  <div class="brand">
    <div class="brand-logo"><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?></div>
    <div class="brand-tagline"><?php echo esc_html( Noodled_Settings::get_brand_tagline() ); ?></div>
  </div>

  <div class="login-card">
    <div id="stepEmail">
      <div class="card-title"><?php esc_html_e( 'Sign in', 'noodled' ); ?></div>
      <div class="card-sub"><?php esc_html_e( "We'll send a PIN to your email", 'noodled' ); ?></div>
      <form onsubmit="handleLogin(event)">
        <input class="login-input" type="email" id="emailInput" placeholder="<?php esc_attr_e( 'you@example.com', 'noodled' ); ?>" aria-label="<?php esc_attr_e( 'Email address', 'noodled' ); ?>" required autofocus>
        <button class="login-btn" type="submit" id="loginBtn"><?php esc_html_e( 'Continue', 'noodled' ); ?></button>
      </form>
    </div>

    <?php if ( Noodled_Settings::allow_registration() ) : ?>
    <div style="margin-top:12px;text-align:center" id="regLink">
      <button type="button" class="login-link-btn" onclick="showStep('stepRegister')"><?php esc_html_e( "Don't have an account? Create one", 'noodled' ); ?></button>
    </div>
    <?php endif; ?>

    <div id="stepPin" style="display:none">
      <div class="card-title"><?php esc_html_e( 'Enter PIN', 'noodled' ); ?></div>
      <div class="card-sub"><?php esc_html_e( 'Check your email for a 6-digit code', 'noodled' ); ?></div>
      <form onsubmit="handlePin(event)">
        <input class="login-input" type="text" id="pinInput" placeholder="000000" aria-label="<?php esc_attr_e( '6-digit PIN', 'noodled' ); ?>" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required autofocus style="text-align:center;font-size:24px;letter-spacing:8px">
        <button class="login-btn" type="submit" id="pinBtn"><?php esc_html_e( 'Sign in', 'noodled' ); ?></button>
      </form>
      <div style="margin-top:12px;text-align:center">
        <button type="button" class="login-link-btn" onclick="backToEmail()"><?php esc_html_e( 'Use a different email', 'noodled' ); ?></button>
      </div>
    </div>

    <div class="login-msg" id="loginMsg">
      <?php if ( ! empty( $login_error ) ) : ?>
        <span class="error"><?php echo esc_html( $login_error ); ?></span>
      <?php endif; ?>
    </div>

    <?php if ( Noodled_Settings::allow_registration() ) : ?>
    <div id="stepRegister" style="display:none">
      <div class="card-title"><?php esc_html_e( 'Create account', 'noodled' ); ?></div>
      <div class="card-sub"><?php esc_html_e( 'Enter your details to get started', 'noodled' ); ?></div>
      <form onsubmit="handleRegister(event)">
        <input class="login-input" type="text" id="regName" placeholder="<?php esc_attr_e( 'Your name', 'noodled' ); ?>" aria-label="<?php esc_attr_e( 'Your name', 'noodled' ); ?>" required>
        <input class="login-input" type="email" id="regEmail" placeholder="<?php esc_attr_e( 'Email address', 'noodled' ); ?>" aria-label="<?php esc_attr_e( 'Email address', 'noodled' ); ?>" required>
        <button class="login-btn" type="submit" id="regBtn"><?php esc_html_e( 'Create account', 'noodled' ); ?></button>
      </form>
      <div style="margin-top:12px;text-align:center">
        <button type="button" class="login-link-btn" onclick="showStep('stepEmail')"><?php esc_html_e( 'Already have an account? Sign in', 'noodled' ); ?></button>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="features">
    <div class="feature">
      <div class="feature-icon">&#128221;</div>
      <div class="feature-text"><?php esc_html_e( 'Markdown notes with rich editing', 'noodled' ); ?></div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128274;</div>
      <div class="feature-text"><?php esc_html_e( 'Private notebooks with sharing', 'noodled' ); ?></div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128260;</div>
      <div class="feature-text"><?php esc_html_e( 'Syncs with desktop app', 'noodled' ); ?></div>
    </div>
  </div>
</div>

<div class="login-footer"><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?> v<?php echo NOODLED_VERSION; ?></div>

<script>
let loginEmail = '';

async function handleLogin(e) {
  e.preventDefault();
  loginEmail = document.getElementById('emailInput').value;
  const btn = document.getElementById('loginBtn');
  const msg = document.getElementById('loginMsg');

  btn.disabled = true;
  btn.textContent = '<?php echo esc_js( __( 'Sending…', 'noodled' ) ); ?>';
  msg.textContent = '';
  msg.className = 'login-msg';

  try {
    const res = await fetch('<?php echo esc_url( rest_url( 'noodled/v1/auth/login' ) ); ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: loginEmail }),
    });
    const data = await res.json();

    if (data.error) {
      msg.textContent = data.error;
      msg.className = 'login-msg error';
      btn.disabled = false;
      btn.textContent = '<?php echo esc_js( __( 'Continue', 'noodled' ) ); ?>';
    } else {
      document.getElementById('stepEmail').style.display = 'none';
      document.getElementById('stepPin').style.display = 'block';
      msg.textContent = '';
      document.getElementById('pinInput').focus();
    }
  } catch (err) {
    msg.textContent = '<?php echo esc_js( __( 'Something went wrong. Try again.', 'noodled' ) ); ?>';
    msg.className = 'login-msg error';
    btn.disabled = false;
    btn.textContent = 'Continue';
  }
}

async function handlePin(e) {
  e.preventDefault();
  const pin = document.getElementById('pinInput').value;
  const btn = document.getElementById('pinBtn');
  const msg = document.getElementById('loginMsg');

  btn.disabled = true;
  btn.textContent = '<?php echo esc_js( __( 'Verifying…', 'noodled' ) ); ?>';
  msg.textContent = '';
  msg.className = 'login-msg';

  try {
    const res = await fetch('<?php echo esc_url( rest_url( 'noodled/v1/auth/pin' ) ); ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: loginEmail, pin }),
    });
    const data = await res.json();

    if (data.error) {
      msg.textContent = data.error;
      msg.className = 'login-msg error';
      btn.disabled = false;
      btn.textContent = '<?php echo esc_js( __( 'Sign in', 'noodled' ) ); ?>';
    } else {
      /* translators: %s is the member's name */
      msg.textContent = '✓ ' + '<?php echo esc_js( __( 'Welcome back, %s!', 'noodled' ) ); ?>'.replace('%s', data.name);
      msg.className = 'login-msg success';
      setTimeout(() => window.location.href = '<?php echo esc_url( Noodled_App::get_app_url() ); ?>' + '?_=' + Date.now(), 500);
    }
  } catch (err) {
    msg.textContent = '<?php echo esc_js( __( 'Something went wrong. Try again.', 'noodled' ) ); ?>';
    msg.className = 'login-msg error';
    btn.disabled = false;
    btn.textContent = 'Sign in';
  }
}

function showStep(id) {
  ['stepEmail', 'stepPin', 'stepRegister'].forEach(s => {
    const el = document.getElementById(s);
    if (el) el.style.display = s === id ? 'block' : 'none';
  });
  const regLink = document.getElementById('regLink');
  if (regLink) regLink.style.display = id === 'stepEmail' ? 'block' : 'none';
  document.getElementById('loginMsg').textContent = '';
  document.getElementById('loginMsg').className = 'login-msg';
}

function backToEmail() { showStep('stepEmail'); }

async function handleRegister(e) {
  e.preventDefault();
  const name = document.getElementById('regName').value;
  const email = document.getElementById('regEmail').value;
  const btn = document.getElementById('regBtn');
  const msg = document.getElementById('loginMsg');

  btn.disabled = true;
  btn.textContent = '<?php echo esc_js( __( 'Creating…', 'noodled' ) ); ?>';
  msg.textContent = '';

  try {
    const res = await fetch('<?php echo esc_url( rest_url( 'noodled/v1/auth/register' ) ); ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, email }),
    });
    const data = await res.json();

    if (data.error) {
      msg.textContent = data.error;
      msg.className = 'login-msg error';
    } else if (data.pending) {
      msg.textContent = '✓ ' + '<?php echo esc_js( __( 'Account created! Waiting for admin approval.', 'noodled' ) ); ?>';
      msg.className = 'login-msg success';
    } else {
      msg.textContent = '✓ ' + '<?php echo esc_js( __( 'Account created! A PIN has been sent to your email.', 'noodled' ) ); ?>';
      msg.className = 'login-msg success';
      loginEmail = email;
      setTimeout(() => showStep('stepPin'), 1500);
    }
  } catch (err) {
    msg.textContent = '<?php echo esc_js( __( 'Something went wrong. Try again.', 'noodled' ) ); ?>';
    msg.className = 'login-msg error';
  }

  btn.disabled = false;
  btn.textContent = '<?php echo esc_js( __( 'Create account', 'noodled' ) ); ?>';
}
</script>

</body>
</html>
