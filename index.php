<?php
require_once __DIR__ . '/lib/bootstrap.php';
try { $n = (int)q_val('SELECT COUNT(*) FROM users'); } catch (Exception $e) { $n = -1; }
if ($n === 0) redirect('install.php');
redirect(home_url());
