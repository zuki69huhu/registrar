<?php session_start(); ?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Done</title>
<style>body { font-family: Arial, sans-serif; max-width: 450px; margin: 40px auto; padding: 16px; line-height: 1.5; overflow-wrap: anywhere; }</style>    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body>
  <?php if (!empty($_SESSION['flash'])) { echo "<p>" . htmlentities($_SESSION['flash']) . "</p>"; unset($_SESSION['flash']); } ?>
  <p>Registration & verification finished. You can now <a href="index.php">return</a>.</p>
</body>
</html>
