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
  --text-muted: #5a5a68;
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
      <div class="card-title">Sign in</div>
      <div class="card-sub">We'll send a PIN to your email</div>
      <form onsubmit="handleLogin(event)">
        <input class="login-input" type="email" id="emailInput" placeholder="you@example.com" required autofocus>
        <button class="login-btn" type="submit" id="loginBtn">Continue</button>
      </form>
    </div>

    <?php if ( Noodled_Settings::allow_registration() ) : ?>
    <div style="margin-top:12px;text-align:center" id="regLink">
      <a href="#" onclick="showStep('stepRegister')" style="color:var(--text-muted);font-size:12px;text-decoration:none">Don't have an account? Create one</a>
    </div>
    <?php endif; ?>

    <div id="stepPin" style="display:none">
      <div class="card-title">Enter PIN</div>
      <div class="card-sub">Check your email for a 6-digit code</div>
      <form onsubmit="handlePin(event)">
        <input class="login-input" type="text" id="pinInput" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required autofocus style="text-align:center;font-size:24px;letter-spacing:8px">
        <button class="login-btn" type="submit" id="pinBtn">Sign in</button>
      </form>
      <div style="margin-top:12px;text-align:center">
        <a href="#" onclick="backToEmail()" style="color:var(--text-muted);font-size:12px;text-decoration:none">Use a different email</a>
      </div>
    </div>

    <div class="login-msg" id="loginMsg">
      <?php if ( ! empty( $login_error ) ) : ?>
        <span class="error"><?php echo esc_html( $login_error ); ?></span>
      <?php endif; ?>
    </div>

    <?php if ( Noodled_Settings::allow_registration() ) : ?>
    <div id="stepRegister" style="display:none">
      <div class="card-title">Create account</div>
      <div class="card-sub">Enter your details to get started</div>
      <form onsubmit="handleRegister(event)">
        <input class="login-input" type="text" id="regName" placeholder="Your name" required>
        <input class="login-input" type="email" id="regEmail" placeholder="Email address" required>
        <button class="login-btn" type="submit" id="regBtn">Create account</button>
      </form>
      <div style="margin-top:12px;text-align:center">
        <a href="#" onclick="showStep('stepEmail')" style="color:var(--text-muted);font-size:12px;text-decoration:none">Already have an account? Sign in</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="features">
    <div class="feature">
      <div class="feature-icon">&#128221;</div>
      <div class="feature-text">Markdown notes with rich editing</div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128274;</div>
      <div class="feature-text">Private notebooks with sharing</div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128260;</div>
      <div class="feature-text">Syncs with desktop app</div>
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
  btn.textContent = 'Sending...';
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
      btn.textContent = 'Continue';
    } else {
      document.getElementById('stepEmail').style.display = 'none';
      document.getElementById('stepPin').style.display = 'block';
      msg.textContent = '';
      document.getElementById('pinInput').focus();
    }
  } catch (err) {
    msg.textContent = 'Something went wrong. Try again.';
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
  btn.textContent = 'Verifying...';
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
      btn.textContent = 'Sign in';
    } else {
      msg.innerHTML = '&#10003; Welcome back, ' + data.name + '!';
      msg.className = 'login-msg success';
      setTimeout(() => window.location.href = '<?php echo esc_url( Noodled_App::get_app_url() ); ?>' + '?_=' + Date.now(), 500);
    }
  } catch (err) {
    msg.textContent = 'Something went wrong. Try again.';
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
  btn.textContent = 'Creating...';
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
      msg.innerHTML = '&#10003; Account created! Waiting for admin approval.';
      msg.className = 'login-msg success';
    } else {
      msg.innerHTML = '&#10003; Account created! A PIN has been sent to your email.';
      msg.className = 'login-msg success';
      loginEmail = email;
      setTimeout(() => showStep('stepPin'), 1500);
    }
  } catch (err) {
    msg.textContent = 'Something went wrong. Try again.';
    msg.className = 'login-msg error';
  }

  btn.disabled = false;
  btn.textContent = 'Create account';
}
</script>

</body>
</html>
