/* ══════════════════════════════
   SHARED: Show / hide password
══════════════════════════════ */
document.querySelectorAll('.pw-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp = document.getElementById(btn.dataset.target);
    inp.type        = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁' : '🙈';
  });
});

/* ══════════════════════════════
   REGISTER: Role toggle
══════════════════════════════ */
const roleRadios = document.querySelectorAll('input[name="role"]');
if (roleRadios.length) {
  const studentRow = document.getElementById('student-id-row');
  const teacherRow = document.getElementById('teacher-id-row');

  roleRadios.forEach(r => r.addEventListener('change', () => {
    const isStudent = document.getElementById('role_student').checked;
    if (studentRow) studentRow.classList.toggle('visible', isStudent);
    if (teacherRow) teacherRow.classList.toggle('visible', !isStudent);
    const sInput = document.getElementById('student_id');
    const tInput = document.getElementById('teacher_id');
    if (sInput) sInput.value = '';
    if (tInput) tInput.value = '';
  }));
}

/* ══════════════════════════════
   REGISTER: Password strength
══════════════════════════════ */
const pwInput = document.getElementById('password');
const fill    = document.getElementById('strength-fill');
const hint    = document.getElementById('strength-hint');

if (pwInput && fill && hint) {
  const levels = [
    { label: 'Too short',  color: '#f87171', pct: '15%'  },
    { label: 'Weak',       color: '#fb923c', pct: '35%'  },
    { label: 'Fair',       color: '#facc15', pct: '60%'  },
    { label: 'Good',       color: '#4ade80', pct: '80%'  },
    { label: 'Strong 💪',  color: '#34d399', pct: '100%' },
  ];

  pwInput.addEventListener('input', () => {
    const v = pwInput.value;
    let score = 0;
    if (v.length >= 6)                       score++;
    if (v.length >= 10)                      score++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
    if (/\d/.test(v))                        score++;
    if (/[^A-Za-z0-9]/.test(v))             score++;

    const lvl = v.length === 0 ? null : levels[Math.min(score, 4)];
    fill.style.width      = lvl ? lvl.pct   : '0';
    fill.style.background = lvl ? lvl.color : 'transparent';
    hint.textContent      = lvl ? lvl.label : 'Enter a password';
    hint.style.color      = lvl ? lvl.color : 'var(--text-dim)';
  });
}

/* ══════════════════════════════
   REGISTER: Confirm match
══════════════════════════════ */
const regForm = document.getElementById('register-form');
if (regForm) {
  regForm.addEventListener('submit', e => {
    const pw  = document.getElementById('password').value;
    const cpw = document.getElementById('password_confirm').value;
    if (pw !== cpw) {
      e.preventDefault();
      alert('Passwords do not match. Please check and try again.');
      document.getElementById('password_confirm').focus();
    }
  });
}