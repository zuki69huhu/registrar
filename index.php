<?php session_start(); ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register - OTP demo</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #1a365d;
      --primary-light: #2c5282;
      --accent: #c9a227;      --accent-light: #ecc94b;
      --bg-dark: #0f172a;
      --bg-card-hover: rgba(255, 255, 255, 0.07); /* Added for dark mode hover */
      --bg-card: rgba(255, 255, 255, 0.05);
      --text-primary: #f1f5f9;
      --text-secondary: #94a3b8;
      --border: rgba(255, 255, 255, 0.1);
      --glass: rgba(15, 23, 42, 0.7);
      --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      --orb-1: var(--primary-light);
      --orb-2: var(--accent);
      --orb-3: #4f46e5;
      --bg-gradient: var(--bg-dark);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-gradient);
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 40px 0;
      justify-content: center;
      overflow-x: hidden;
      position: relative;
      color: var(--text-primary);
    }

    /* Animated Background */
    .bg-animation {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      overflow: hidden;
    }

    .bg-animation .orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.4;
      animation: float 20s infinite ease-in-out;
    }

    .orb-1 {
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, var(--orb-1), transparent);
      top: -100px;
      left: -100px;
      animation-delay: 0s;
    }

    .orb-2 {
      width: 300px;
      height: 300px;
      background: radial-gradient(circle, var(--orb-2), transparent);
      bottom: -50px;
      right: -50px;
      animation-delay: -5s;
    }

    .orb-3 {
      width: 250px;
      height: 250px;
      background: radial-gradient(circle, var(--orb-3), transparent);
      top: 50%;
      left: 50%;
      animation-delay: -10s;
    }

    @keyframes float {
      0%, 100% { transform: translate(0, 0) scale(1); }
      25% { transform: translate(50px, -50px) scale(1.1); }
      50% { transform: translate(-30px, 30px) scale(0.9); }
      75% { transform: translate(20px, -20px) scale(1.05); }
    }

    /* Light Mode Variables */
    body.light-mode {
      --primary: #e0e7ff; /* Light blue */
      --primary-light: #c7d2fe;
      --accent: #fcd34d; /* Yellow */
      --accent-light: #fde68a;
      --bg-dark: #f8fafc; /* Light background */
      --bg-card: #ffffff; /* White card */
      --bg-card-hover: #f0f4f8; /* Lighter hover for cards */
      --text-primary: #1e293b; /* Dark text */
      --text-secondary: #475569; /* Medium dark text */
      --border: #e2e8f0; /* Light border */
      --glass: rgba(255, 255, 255, 0.8); /* Lighter glass effect */
      --shadow: 0 10px 20px rgba(0, 0, 0, 0.1); /* Lighter shadow */
      --orb-1: #93c5fd; /* Soft blue */
      --orb-2: #fde047; /* Soft yellow */
      --orb-3: #c4b5fd; /* Soft purple */
      --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e0e7ff 100%);
    }

    /* Adjustments for light mode elements */
    body.light-mode .brand-panel h1 {
      background: linear-gradient(135deg, var(--text-primary), var(--primary-light));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    body.light-mode .tab-switcher a.active {
      color: var(--text-primary); /* Darker text for active tab in light mode */
      background: var(--accent);
      box-shadow: 0 4px 15px rgba(252, 211, 77, 0.3);
    }

    /* Theme Switcher Top Right */
    .theme-switcher {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1001;
      display: flex;
      gap: 5px;
      background: var(--glass);
      padding: 5px;
      border-radius: 50px;
      border: 1px solid var(--border);
      backdrop-filter: blur(15px);
      box-shadow: var(--shadow);
    }

    .theme-switcher button {
      background: transparent;
      border: none;
      color: var(--text-secondary);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
    }

    .theme-switcher button:hover, .theme-switcher button.active {
      color: var(--accent);
      background: var(--bg-card-hover);
    }


    /* Grid Pattern Overlay */
    .grid-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-image: 
        linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
      background-size: 50px 50px;
      z-index: 1;
      pointer-events: none;
    }

    /* Main Container */
    .container {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 1200px;
      padding: 20px;
      display: flex;
      gap: 0;
      /* min-height removed for better responsiveness */
    }

    /* Left Panel - Branding */
    .brand-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      padding: 40px 60px;
      color: var(--text-primary);
      position: relative;
    }

    .brand-panel .logo {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 40px;
    }

    .brand-panel .logo-img {
      width: 180px;
      height: 180px;
      object-fit: contain;
      mix-blend-mode: multiply;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .brand-panel .logo-img:hover {
      transform: scale(1.1) rotate(-3deg);
    }

    .brand-panel h1 {
      font-family: 'Playfair Display', serif;
      font-size: 4rem;
      font-weight: 700;
      line-height: 1.1;
      margin-bottom: 20px;
      background: linear-gradient(135deg, var(--text-primary), var(--accent-light));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .brand-panel p {
      font-size: 1.1rem;
      color: var(--text-secondary);
      line-height: 1.7;
      max-width: 400px;
    }

    .features {
      margin-top: 40px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .feature-item {
      display: flex;
      align-items: center;
      gap: 15px;
      padding: 15px 20px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
    }

    .feature-item:hover {
      border-color: var(--accent);
      transform: translateX(5px);
    }

    .feature-item i {
      width: 40px;
      height: 40px;
      background: rgba(201, 162, 39, 0.1);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--accent);
      font-size: 16px;
    }

    .feature-item span {
      font-weight: 500;
      color: var(--text-primary);
    }

    /* Right Panel - Forms */
    .forms-panel {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }

    .form-card {
      width: 100%;
      max-width: 440px;
      background: var(--glass);
      backdrop-filter: blur(20px);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 40px;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }

    .form-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--accent), var(--primary-light), var(--accent));
    }

    .form-header {
      text-align: center;
      margin-bottom: 30px;
    }

    .form-header h2 {
      font-family: 'Playfair Display', serif;
      font-size: 1.8rem;
      color: var(--text-primary);
      margin-bottom: 8px;
    }

    .form-header p {
      color: var(--text-secondary);
      font-size: 0.95rem;
    }

    /* Tab Switcher */
    .tab-switcher {
      display: flex;
      background: rgba(255,255,255,0.05);
      border-radius: 12px;
      padding: 4px;
      margin-bottom: 30px;
      position: relative;
    }

    .tab-switcher a {
      flex: 1;
      padding: 12px;
      text-align: center;
      border: none;
      background: transparent;
      color: var(--text-secondary);
      font-family: 'Inter', sans-serif;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      border-radius: 10px;
      transition: all 0.3s ease;
      position: relative;
      z-index: 1;
      text-decoration: none;
    }

    .tab-switcher a.active {
      color: var(--primary);
      background: var(--accent);
      box-shadow: 0 4px 15px rgba(201, 162, 39, 0.3);
    }

    /* Form Groups */
    .form-group {
      margin-bottom: 20px;
      position: relative;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: var(--text-secondary);
      font-size: 0.85rem;
      font-weight: 500;
    }

    .input-wrapper {
      position: relative;
    }

    .input-wrapper > i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-secondary);
      font-size: 16px;
      transition: color 0.3s ease;
      pointer-events: none;
    }

    .input-wrapper input,
    .input-wrapper select {
      width: 100%;
      padding: 14px 16px 14px 48px;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: var(--text-primary);
      font-family: 'Inter', sans-serif;
      font-size: 0.95rem;
      transition: all 0.3s ease;
    }

    .input-wrapper select {
      appearance: none;
      cursor: pointer;
    }

    .input-wrapper select option {
      background-color: var(--bg-dark);
      color: var(--text-primary);
    }

    /* Custom Dropdown Arrow */
    .input-wrapper:has(select)::after {
      content: '\f107';
      font-family: 'Font Awesome 6 Free';
      font-weight: 900;
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-secondary);
      pointer-events: none;
      font-size: 14px;
    }

    .input-wrapper input::placeholder {
      color: #475569;
    }

    .input-wrapper input:focus {
      outline: none;
      border-color: var(--accent);
      background: rgba(255,255,255,0.08);
      box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.1);
    }

    body.light-mode .input-wrapper input:focus {
      box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.2);
    }

    .input-wrapper:focus-within > i {
      color: var(--accent);
    }

    .input-wrapper .toggle-password {
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--text-secondary);
      cursor: pointer;
      font-size: 14px;
      padding: 0;
    }

    .input-wrapper .toggle-password:hover {
      color: var(--accent);
    }

    /* Name Row */
    .name-row {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 12px;
    }

    /* Submit Button */
    .btn-submit {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, var(--accent), var(--accent-light));
      border: none;
      border-radius: 12px;
      color: var(--primary);
      font-family: 'Inter', sans-serif;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }
    body.light-mode .btn-submit {
      background: linear-gradient(135deg, var(--accent), var(--accent-light));
      color: var(--text-primary);
    }

    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 30px rgba(201, 162, 39, 0.4);
    }

    .btn-submit:active {
      transform: translateY(0);
    }
    body.light-mode .btn-submit:hover {
      box-shadow: 0 10px 30px rgba(252, 211, 77, 0.4);
    }

    /* Divider */
    .divider {
      display: flex;
      align-items: center;
      margin: 24px 0;
      color: var(--text-secondary);
      font-size: 0.85rem;
    }

    .divider::before,
    .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
    }

    .divider span {
      padding: 0 16px;
    }

    /* Social Login */
    .social-login {
      display: flex;
      gap: 12px;
    }

    .social-btn {
      flex: 1;
      padding: 12px;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: var(--text-primary);
      font-size: 1.1rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .social-btn:hover {
      background: rgba(255,255,255,0.1);
      border-color: var(--accent);
      transform: translateY(-2px);
    }
    body.light-mode .social-btn {
      background: var(--bg-card);
    }

    /* Switch Link */
    .switch-text {
      text-align: center;
      margin-top: 24px;
      color: var(--text-secondary);
      font-size: 0.9rem;
    }

    .switch-text a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
      cursor: pointer;
      transition: color 0.3s ease;
    }

    .switch-text a:hover {
      color: var(--accent-light);
    }

    /* Toast Notification */
    .toast {
      position: fixed;
      top: 24px;
      right: 24px;
      padding: 16px 24px;
      background: var(--glass);
      backdrop-filter: blur(20px);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: var(--text-primary);
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 12px;
      transform: translateX(400px);
      transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      z-index: 1000;
      box-shadow: var(--shadow);
    }

    .toast.show {
      transform: translateX(0);
    }

    .toast.success {
      border-left: 4px solid #10b981;
    }

    .toast.error {
      border-left: 4px solid #ef4444;
    }

    /* Responsive */
    @media (max-width: 900px) {
      .container {
        flex-direction: column;
      }

      .brand-panel {
        padding: 30px;
        text-align: center;
        align-items: center;
      }

      .theme-switcher {
        top: 10px;
        right: 10px;
      }

      .brand-panel h1 {
        font-size: 2.2rem;
      }

      .brand-panel .logo-img {
        width: 100px;
        height: 100px;
      }

      .features {
        display: none;
      }

      .forms-panel {
        padding: 0 20px 40px;
      }

      .brand-panel {
        padding: 20px; /* Further reduce padding for very small screens */
      }
    }
    
    /* Ensure content doesn't get cut off on short viewports */
    @media (max-height: 850px) {
      body { align-items: flex-start; }
    }

    @media (max-width: 480px) {
      .form-card {
        padding: 30px 20px;
      }

      .name-row {
        grid-template-columns: 1fr;
      }

      .brand-panel h1 {
        font-size: 1.8rem;
      }
    }

    /* Shake Animation for errors */
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-8px); }
      75% { transform: translateX(8px); }
    }

    .shake {
      animation: shake 0.4s ease;
    }

    /* Scrollable Register Form */
    #register-section form {
      max-height: calc(100vh - 340px);
      min-height: 300px;
      overflow-y: auto;
      overflow-x: hidden;
      padding-right: 12px;
      margin-right: -4px;
    }

    #register-section form::-webkit-scrollbar {
      width: 6px;
    }
    #register-section form::-webkit-scrollbar-track {
      background: transparent;
    }
    #register-section form::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.2);
      border-radius: 10px;
    }
    #register-section form::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.3);
    }
    
    body.light-mode #register-section form::-webkit-scrollbar-thumb {
      background: rgba(0, 0, 0, 0.2);
    }
    body.light-mode #register-section form::-webkit-scrollbar-thumb:hover {
      background: rgba(0, 0, 0, 0.3);
    }

    /* ─────────────────────────────────────────────────────────────
       RESPONSIVE REFINEMENTS — phones & tablets.
       Appended last so these win over the base rules above.
       ───────────────────────────────────────────────────────────── */

    /* Tablet and below: brand panel stacks above the form card */
    @media (max-width: 1024px) {
      body {
        align-items: flex-start;
        padding: 24px 0;
        padding-left: env(safe-area-inset-left);
        padding-right: env(safe-area-inset-right);
      }

      .container {
        flex-direction: column;
        gap: 8px;
        max-width: 640px;
        padding: 16px;
      }

      .brand-panel {
        padding: 8px 8px 0;
        text-align: center;
        align-items: center;
        justify-content: center;
      }
      .brand-panel .logo { justify-content: center; margin-bottom: 16px; }
      .brand-panel .logo-img { width: 110px; height: 110px; }
      .brand-panel h1 { font-size: 2.4rem; margin-bottom: 12px; }
      .brand-panel p { font-size: 1rem; max-width: 520px; margin: 0 auto; }
      .features { display: none; }

      .forms-panel { padding: 0; width: 100%; }
      .form-card { max-width: 520px; margin: 0 auto; }

      /* The register form scrolled inside a fixed-height box, which collapses on
         short screens. Let the page scroll instead. */
      #register-section form {
        max-height: none;
        min-height: 0;
        overflow: visible;
        padding-right: 0;
        margin-right: 0;
      }
    }

    /* Phones */
    @media (max-width: 640px) {
      body { padding: 16px 0; }
      .container { padding: 12px; }

      .theme-switcher { top: 10px; right: 10px; padding: 4px; }
      .theme-switcher button { width: 32px; height: 32px; }

      .brand-panel { padding: 30px 4px 0; }
      .brand-panel .logo-img { width: 84px; height: 84px; }
      .brand-panel h1 { font-size: 1.9rem; }
      .brand-panel p { font-size: 0.92rem; line-height: 1.6; }

      .form-card { padding: 26px 20px; border-radius: 20px; }
      .form-header { margin-bottom: 22px; }
      .form-header h2 { font-size: 1.5rem; }
      .tab-switcher { margin-bottom: 22px; }
      .tab-switcher a { padding: 13px 8px; }

      /* 16px stops iOS from zooming the page when a field takes focus */
      .input-wrapper input,
      .input-wrapper select {
        font-size: 16px;
        padding: 13px 14px 13px 44px;
      }
      .input-wrapper > i { left: 14px; }
      .input-wrapper .toggle-password { right: 10px; padding: 6px; }

      .btn-submit { padding: 15px; font-size: 1rem; }
      .name-row { grid-template-columns: 1fr; gap: 0; }

      /* Slide the toast up from the bottom — it's off-screen to the right otherwise */
      .toast {
        top: auto;
        bottom: calc(16px + env(safe-area-inset-bottom));
        left: 16px;
        right: 16px;
        transform: translateY(160%);
      }
      .toast.show { transform: translateY(0); }
    }

    @media (max-width: 380px) {
      .form-card { padding: 22px 16px; }
      .brand-panel h1 { font-size: 1.65rem; }
      .social-login { gap: 8px; }
    }

    /* Landscape phones: the brand block would otherwise fill the whole screen */
    @media (max-height: 560px) and (max-width: 1024px) {
      .brand-panel .logo-img { width: 64px; height: 64px; }
      .brand-panel h1 { font-size: 1.6rem; margin-bottom: 8px; }
      .brand-panel p { display: none; }
    }
  </style>
    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
</head>
<body>
  <script>
    localStorage.removeItem('admin-active-section');
    localStorage.removeItem('registrar-active-section');
  </script>
  <!-- Animated Background -->
  <div class="bg-animation">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>

  <!-- Theme Switcher -->
  <div class="theme-switcher">
    <button onclick="setTheme('light')" id="btn-light" title="Light Mode"><i class="fas fa-sun"></i></button>
    <button onclick="setTheme('dark')" id="btn-dark" title="Dark Mode"><i class="fas fa-moon"></i></button>
  </div>

  <!-- Grid Pattern Overlay -->
  <div class="grid-overlay"></div>

  <!-- Main Container -->
  <div class="container">
    <!-- Left Panel - Branding -->
    <div class="brand-panel">
      <div class="logo">
        <img src="assets/img/logo.jpg" alt="Logo"  class="logo-img" onerror="this.style.display='none'">
      </div>
      <h1>Registrar Office</h1>
      <p>Secure document repository and monitoring system. Manage, track, and monitor all official records with enterprise-grade security.</p>
    </div>

    <!-- Right Panel - Forms -->
    <div class="forms-panel">
      <div class="form-card">
        <div class="tab-switcher">
          <a href="javascript:void(0)" onclick="toggleTab('login')" id="login-tab">Login</a>
          <a href="javascript:void(0)" onclick="toggleTab('register')" id="register-tab" class="active">Register</a>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
          <div class="msg" style="padding: 12px 16px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 10px; color: #60a5fa; font-size: 14px; margin-bottom: 20px;">
            <?= htmlentities($_SESSION['flash']); unset($_SESSION['flash']); ?>
          </div>
        <?php endif; ?>

        <!-- Login Section -->
        <div id="login-section" style="display: none;">
          <div class="form-header">
            <h2>Welcome Back</h2>
            <p>Sign in to your secure account</p>
          </div>
          <form method="post" action="process_login.php">
            <div class="form-group">
              <label>Email Address</label>
              <div class="input-wrapper">
                <i class="fas fa-envelope"></i>
                <input type="text" name="email" placeholder="name@isap-mcnp.edu.ph" required>
              </div>
            </div>
            <div class="form-group">
              <label>Password</label>
              <div class="input-wrapper">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="login_password" placeholder="••••••••" required>
                <button type="button" class="toggle-password" onclick="togglePassword('login_password', this)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div style="text-align: right; margin-top: 8px;">
                <a href="forgot_password.php" style="color: var(--accent); text-decoration: none; font-size: 0.85rem; font-weight: 500;">Forgot Password?</a>
              </div>
            </div>
            <button type="submit" class="btn-submit">Sign In</button>
          </form>
        </div>

        <!-- Register Section -->
        <div id="register-section">
          <div class="form-header">
            <h2>Create Account</h2>
            <p>Register for secure document access</p>
          </div>
          <form method="post" action="process_register.php" autocomplete="off">
          <div class="name-row">
            <div class="form-group">
              <label>First Name</label>
              <div class="input-wrapper">
                <i class="fas fa-user"></i>
                <input type="text" name="first_name" placeholder="John" required>
              </div>
            </div>
            <div class="form-group">
              <label>Middle Name</label>
              <div class="input-wrapper">
                <i class="fas fa-user"></i>
                <input type="text" name="middle_name" placeholder="Quincy">
              </div>
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <div class="input-wrapper">
                <i class="fas fa-user"></i>
                <input type="text" name="last_name" placeholder="Doe" required>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Select Role</label>
            <div class="input-wrapper">
              <i class="fas fa-user-tag"></i>
              <select name="role" id="role-select" required>
                <option value="" disabled selected>Select your role</option>
                <option value="registrar">Registrar</option>
                <option value="student">Student</option>
              </select>
            </div>
          </div>

          <div class="form-group" id="institution-group" style="display: none;">
            <label>Institution</label>
            <div class="input-wrapper">
              <i class="fas fa-building"></i>
              <select name="institution" id="institution-select">
                <option value="" disabled selected>Select your institution</option>
                <option value="MCNP">MCNP</option>
                <option value="ISAP">ISAP</option>
              </select>
            </div>
          </div>

          <div class="form-group" id="department-group" style="display: none;">
            <label>Department</label>
            <div class="input-wrapper" id="department-wrapper">
              <i class="fas fa-sitemap"></i>
              <input type="text" name="department" placeholder="e.g., Computer Science">
            </div>
          </div>

          <div class="form-group">
            <label>Email Address</label>
            <div class="input-wrapper">
              <i class="fas fa-envelope"></i>
              <input type="email" name="email" placeholder="name@isap-mcnp.edu.ph" required>
            </div>
          </div>

          <div class="form-group" id="teacher-id-group" style="display: none;">
            <label>Teacher ID</label>
            <div class="input-wrapper">
              <i class="fas fa-id-badge"></i>
            <input type="text" name="teacher_id" placeholder="0001" maxlength="4" pattern="\d{4}" title="Please enter exactly 4 numbers (e.g., 0001)">
            </div>
          </div>

          <div class="form-group" id="admin-id-group" style="display: none;">
            <label>Admin ID</label>
            <div class="input-wrapper">
              <i class="fas fa-user-shield"></i>
              <input type="text" name="admin_id" placeholder="ADM-XXXX">
            </div>
          </div>

          <div class="form-group" id="student-id-group" style="display: none;">
            <label>Student ID</label>
            <div class="input-wrapper">
              <i class="fas fa-id-card"></i>
              <input type="text" name="student_id" placeholder="2024-XXXX" required>
            </div>
          </div>

          <div class="form-group" id="year-level-group" style="display: none;">
            <label>Year Level</label>
            <div class="input-wrapper">
              <i class="fas fa-layer-group"></i>
              <select name="year_level" id="year-level-select">
                <option value="" disabled selected>Select your year level</option>
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
              </select>
            </div>
          </div>

          <div class="form-group" id="section-group" style="display: none;">
            <label>Section</label>
            <div class="input-wrapper">
              <i class="fas fa-users"></i>
              <select name="section" id="section-select">
                <option value="" disabled selected>Select your section</option>
                <?php foreach(range('A', 'Z') as $letter): ?>
                  <option value="<?= $letter ?>">Section <?= $letter ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Password</label>
            <div class="input-wrapper">
              <i class="fas fa-lock"></i>
              <input type="password" name="password" id="password" minlength="6" placeholder="Create a strong password" required>
              <button type="button" class="toggle-password" onclick="togglePassword('password', this)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-wrapper">
              <i class="fas fa-lock"></i>
              <input type="password" name="password_confirm" id="password_confirm" minlength="6" placeholder="Confirm your password" required>
              <button type="button" class="toggle-password" onclick="togglePassword('password_confirm', this)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-submit">Register &amp; Send OTP</button>
          </form>
        </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    function togglePassword(id, btn) {
      const input = document.getElementById(id);
      const icon = btn.querySelector('i');
      if (input.type === "password") {
        input.type = "text";
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = "password";
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }

    function toggleTab(type) {
      const loginSection = document.getElementById('login-section');
      const registerSection = document.getElementById('register-section');
      const loginTab = document.getElementById('login-tab');
      const registerTab = document.getElementById('register-tab');

      if (type === 'login') {
        loginSection.style.display = 'block';
        registerSection.style.display = 'none';
        loginTab.classList.add('active');
        registerTab.classList.remove('active');
      } else {
        loginSection.style.display = 'none';
        registerSection.style.display = 'block';
        loginTab.classList.remove('active');
        registerTab.classList.add('active');
      }
      const isLogin = type === 'login';
      if (isLogin && loginTab.classList.contains('active')) return;
      if (!isLogin && registerTab.classList.contains('active')) return;

      const activeSection = isLogin ? registerSection : loginSection;
      const targetSection = isLogin ? loginSection : registerSection;
      
      const exitClass = isLogin ? 'exit-register' : 'exit-login';
      const enterClass = isLogin ? 'active-login' : 'active-register';

      activeSection.classList.remove('active-login', 'active-register');
      activeSection.classList.add(exitClass);

      setTimeout(() => {
        activeSection.style.display = 'none';
        activeSection.classList.remove(exitClass);
        
        targetSection.style.display = 'block';
        targetSection.classList.remove('exit-login', 'exit-register');
        void targetSection.offsetWidth; // force reflow
        targetSection.classList.add(enterClass);
        
        if (isLogin) {
            loginTab.classList.add('active');
            registerTab.classList.remove('active');
        } else {
            loginTab.classList.remove('active');
            registerTab.classList.add('active');
        }
      }, 250);
    }

    document.getElementById('role-select').addEventListener('change', function() {
      const role = this.value;
      const studentIdGroup = document.getElementById('student-id-group');
      const teacherIdGroup = document.getElementById('teacher-id-group');
      const adminIdGroup = document.getElementById('admin-id-group');
      const instGroup = document.getElementById('institution-group');
      const deptGroup = document.getElementById('department-group');
      
      const isStudent = (role === 'student');
      const isRegistrar = (role === 'registrar');
      const isAdmin = (role === 'systemadmin');

      // Ipakita ang Admin ID kung system admin
      adminIdGroup.style.display = isAdmin ? 'block' : 'none';
      adminIdGroup.querySelector('input').required = isAdmin;

      // Ipakita ang Teacher ID kung registrar
      teacherIdGroup.style.display = isRegistrar ? 'block' : 'none';
      teacherIdGroup.querySelector('input').required = isRegistrar;

      // Ipakita ang Student ID at Year Level kung student
      studentIdGroup.style.display = isStudent ? 'block' : 'none';
      studentIdGroup.querySelector('input').required = isStudent;
      const yearLevelGroup = document.getElementById('year-level-group');
      if (yearLevelGroup) {
          yearLevelGroup.style.display = isStudent ? 'block' : 'none';
          yearLevelGroup.querySelector('select').required = isStudent;
      }
      const sectionGroup = document.getElementById('section-group');
      if (sectionGroup) {
          sectionGroup.style.display = isStudent ? 'block' : 'none';
          sectionGroup.querySelector('select').required = isStudent;
      }

      // Ipakita ang Institution at Department kung Student o Registrar
      instGroup.style.display = (isStudent || isRegistrar) ? 'block' : 'none';
      instGroup.querySelector('select').required = (isStudent || isRegistrar);
      deptGroup.style.display = (isStudent || isRegistrar) ? 'block' : 'none';

      // I-refresh ang department UI base sa kasalukuyang role
      document.getElementById('institution-select').dispatchEvent(new Event('change'));
    });

    document.getElementById('institution-select').addEventListener('change', function() {
      const wrapper = document.getElementById('department-wrapper');
      const role = document.getElementById('role-select').value;
      const inst = this.value;

      if (!inst || (role !== 'student' && role !== 'registrar')) {
        wrapper.innerHTML = '<i class="fas fa-sitemap"></i><input type="text" name="department" placeholder="Select institution first" readonly>';
        return;
      }

      const depts = {
        'ISAP': ['BS Accountancy', 'BS Business administration', 'BS Computer engineering', 'BS Criminology', 'BS Custom Administration', 'BS Hospitality management', 'BS Information Technology', 'BS Physical education', 'BS Psychology', 'BS Secondary education', 'BS Social work', 'BS Tourism management'],
        'MCNP': ['BS Medical laboratory science', 'BS Midwifery', 'BS Nursing', 'BS Pharmacy', 'BS Physical therapy', 'BS Radiologic technology', 'Dental technology']
      };

      const list = depts[inst] || [];

      if (role === 'student') {
        let html = '<i class="fas fa-sitemap"></i><select name="department" required><option value="" disabled selected>Select Department</option>';
        list.forEach(d => html += `<option value="${d}">${d}</option>`);
        html += '</select>';
        wrapper.innerHTML = html;
      } else if (role === 'registrar') {
        let html = '<div style="display: grid; grid-template-columns: 1fr; gap: 8px; margin-top: 10px; max-height: 180px; overflow-y: auto; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 12px; border: 1px solid var(--border);">';
        list.forEach(d => {
          html += `<label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.85rem; color: var(--text-secondary);">
                    <input type="checkbox" name="departments[]" value="${d}" style="width: 16px; height: 16px; margin: 0;"> ${d}
                  </label>`;
        });
        html += '</div>';
        wrapper.innerHTML = '<i class="fas fa-sitemap" style="display:none;"></i>' + html;
      }
    });

    function setTheme(theme) {
      // Remove existing theme classes
      document.body.classList.remove('light-mode', 'dark-mode');
      document.querySelectorAll('.theme-switcher button').forEach(btn => btn.classList.remove('active'));
      
      // Add new class if not 'normal' (assuming default is your 'Normal/Dark' design)
      if (theme !== 'normal') {
        document.body.classList.add(theme + '-mode');
      }
      
      document.getElementById('btn-' + theme).classList.add('active');
      localStorage.setItem('preferred-theme', theme);
    }

    document.addEventListener('DOMContentLoaded', function() {
      // Initialize theme from storage
      const savedTheme = localStorage.getItem('preferred-theme') || 'normal';
      setTheme(savedTheme);

      // Awtomatikong lilipat sa login tab kung may 'tab=login' sa URL
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('tab') === 'login') {
        toggleTab('login');
        const loginSection = document.getElementById('login-section');
        const registerSection = document.getElementById('register-section');
        const loginTab = document.getElementById('login-tab');
        const registerTab = document.getElementById('register-tab');
        
        registerSection.style.display = 'none';
        registerSection.classList.remove('active-register');
        loginSection.style.display = 'block';
        loginSection.classList.add('active-login');
        
        loginTab.classList.add('active');
        registerTab.classList.remove('active');
      }
    });
  </script>
</body>
</html>
