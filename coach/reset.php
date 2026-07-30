<?php
// ============================================================
//  InlineComp – coach wachtwoord-reset-pagina
//
//  Doel van de reset-mail (api/coach_account.php → wachtwoord_vergeten):
//    https://inlineresults.devriesen.com/coach/reset.php?token=...
//  Zelfstandige pagina (geen DB/login) die het nieuwe wachtwoord POST naar
//  api/coach_account.php?action=wachtwoord_reset.
// ============================================================
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$token = $_GET['token'] ?? '';
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>InlineComp – Wachtwoord opnieuw instellen</title>
<style>
  :root { --blauw:#1b5faa; --grijs:#f4f6f9; --tekst:#222; --fout:#b71c1c; --ok:#2e7d32; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
         background:var(--grijs); color:var(--tekst); display:flex; min-height:100vh;
         align-items:center; justify-content:center; padding:16px; }
  .kaart { background:#fff; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.08);
           padding:28px 24px; width:100%; max-width:380px; }
  h1 { font-size:1.25rem; margin:0 0 4px; color:var(--blauw); }
  p.sub { margin:0 0 20px; color:#555; font-size:.9rem; }
  label { display:block; font-size:.85rem; font-weight:600; margin:14px 0 6px; }
  input { width:100%; padding:11px 12px; border:1px solid #ccd3dc; border-radius:8px;
          font-size:1rem; }
  button { width:100%; margin-top:20px; padding:12px; border:0; border-radius:8px;
           background:var(--blauw); color:#fff; font-size:1rem; font-weight:600;
           cursor:pointer; }
  button:disabled { opacity:.6; cursor:default; }
  .melding { margin-top:16px; font-size:.9rem; padding:10px 12px; border-radius:8px; display:none; }
  .melding.fout { display:block; background:#fdecea; color:var(--fout); }
  .melding.ok   { display:block; background:#e7f5e9; color:var(--ok); }
  a { color:var(--blauw); }
</style>
</head>
<body>
  <div class="kaart">
    <h1>Nieuw wachtwoord instellen</h1>
    <p class="sub">Kies een nieuw wachtwoord voor je InlineComp coach-account.</p>
    <form id="frm" autocomplete="off">
      <label for="pw1">Nieuw wachtwoord</label>
      <input type="password" id="pw1" minlength="8" required placeholder="minstens 8 tekens">
      <label for="pw2">Herhaal wachtwoord</label>
      <input type="password" id="pw2" minlength="8" required>
      <button type="submit" id="btn">Wachtwoord opslaan</button>
    </form>
    <div class="melding" id="melding"></div>
  </div>
<script>
  const TOKEN = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
  const frm = document.getElementById('frm');
  const meld = document.getElementById('melding');
  const btn = document.getElementById('btn');
  function toon(cls, tekst) { meld.className = 'melding ' + cls; meld.textContent = tekst; }

  if (!TOKEN) {
    frm.style.display = 'none';
    toon('fout', 'Geen geldige reset-link. Vraag een nieuwe aan via de coach-app.');
  }

  frm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const pw1 = document.getElementById('pw1').value;
    const pw2 = document.getElementById('pw2').value;
    if (pw1.length < 8) { toon('fout', 'Wachtwoord moet minstens 8 tekens zijn.'); return; }
    if (pw1 !== pw2)   { toon('fout', 'De wachtwoorden komen niet overeen.'); return; }
    btn.disabled = true;
    try {
      const res = await fetch('../api/coach_account.php?action=wachtwoord_reset', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, wachtwoord: pw1 }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) { toon('fout', data.error || 'Er ging iets mis.'); btn.disabled = false; return; }
      frm.style.display = 'none';
      toon('ok', 'Je wachtwoord is aangepast. Je kunt nu inloggen in de coach-app.');
      setTimeout(() => { window.location.href = './'; }, 2500);
    } catch (err) {
      toon('fout', 'Netwerkfout — probeer het opnieuw.');
      btn.disabled = false;
    }
  });
</script>
</body>
</html>
