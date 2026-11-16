<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();

/* ---------- Visaidizi vya bulk upload ---------- */
function subj_cell($v) {
    if (is_float($v) && floor($v) == $v) return (string)(int)$v;
    return trim((string)$v);
}
function subj_parse_level($v) {
    $v = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$v)));
    if ($v === '') return 'B';
    if (in_array($v, ['O', 'ORDINARY', 'O-LEVEL', 'O LEVEL', 'ORD'], true)) return 'O';
    if (in_array($v, ['A', 'ADVANCE', 'ADVANCED', 'A-LEVEL', 'A LEVEL', 'ADV'], true)) return 'A';
    if (in_array($v, ['B', 'BOTH', 'O & A', 'O&A', 'O AND A', 'ORDINARY & ADVANCE', 'ORDINARY AND ADVANCE', 'ALL'], true)) return 'B';
    return null;
}
function subj_parse_bool($v) {
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['1', 'YES', 'Y', 'TRUE', 'NDIYO', 'NDIO'], true) ? 1 : 0;
}

$HEAD = ['SUBJECT_CODE', 'SUBJECT_NAME', 'NECTA_ABBREVIATION', 'LEVEL', 'SUBSIDIARY'];

/* ---------- Template ya Excel ---------- */
if (isset($_GET['template'])) {
    if (!load_vendor()) { http_response_code(500); die('The .xlsx template requires PhpSpreadsheet. Run "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $headStyle = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        'alignment' => ['horizontal' => 'center'],
    ];
    $ws = $wb->getActiveSheet(); $ws->setTitle('Subjects');
    $ws->fromArray($HEAD, null, 'A1');
    $ws->getStyle('A1:E1')->applyFromArray($headStyle);
    // Misimbo ihifadhiwe kama maandishi ili sifuri ya mwanzo (mf. 011) isipotee.
    $ws->getStyle('A2:A500')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
    foreach (range(1, 5) as $i) $ws->getColumnDimensionByColumn($i)->setWidth(24);
    $ws->freezePane('A2');

    /* Karatasi ya mifano: haisomwi wakati wa upload (mfumo husoma karatasi ya kwanza pekee). */
    $ex = $wb->createSheet(); $ex->setTitle('Examples');
    $ex->fromArray($HEAD, null, 'A1');
    $ex->getStyle('A1:E1')->applyFromArray($headStyle);
    $examples = [
        ['011', 'Civics', 'CIV', 'O', 'NO'],
        ['042', 'Basic Mathematics', 'B/MATH', 'O', 'NO'],
        ['031', 'Physics', 'PHY', 'O', 'NO'],
        ['121', 'General Studies', 'GS', 'A', 'YES'],
    ];
    foreach ($examples as $i => $row) {
        foreach ($row as $j => $v) {
            $ex->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1) . ($i + 2), (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
    foreach (range(1, 5) as $i) $ex->getColumnDimensionByColumn($i)->setWidth(24);

    $help = $wb->createSheet(); $help->setTitle('Instructions');
    $lines = ['INSTRUCTIONS', '',
        'Fill one row per subject on the "Subjects" sheet (starting from row 2). Do not change the column headers (row 1).',
        'The "Examples" sheet shows how to fill the columns; do not upload it (the system reads only the first "Subjects" sheet).',
        'SUBJECT_CODE: required, digits only, 2 to 4 characters (e.g. 011, 042, 060). Must be unique within the file.',
        'SUBJECT_NAME: required (max 120 characters).',
        'NECTA_ABBREVIATION: optional (e.g. BIO, B/MATH), max 20 characters.',
        'LEVEL: O = Ordinary, A = Advance, B = Ordinary & Advance. If left blank the system uses B.',
        'SUBSIDIARY: YES if it is an extra subject (GS/BAM) not counted in points; otherwise NO.',
        'A new subject is automatically linked to classes with a matching level.',
        'Limit: 500 subjects per upload. Subjects that already exist in the system are skipped and only new ones are added. If the file has any error, NO subject is saved and you will see all errors.'];
    foreach ($lines as $i => $l) $help->setCellValue('A' . ($i + 1), $l);
    $help->getStyle('A1')->getFont()->setBold(true);
    $help->getColumnDimension('A')->setWidth(120);
    $wb->setActiveSheetIndex(0);
    send_xlsx($wb, 'subjects_template');
}

$report = null;
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'save') {
            $id = (int)($_POST['id'] ?? 0); $code = trim($_POST['code'] ?? ''); $name = trim($_POST['name'] ?? ''); $abbr = strtoupper(trim($_POST['abbr'] ?? ''));
            $lvl = in_array($_POST['level'] ?? 'B', ['O', 'A', 'B'], true) ? $_POST['level'] : 'B'; $sub = isset($_POST['is_subsidiary']) ? 1 : 0;
            if (!preg_match('/^\d{2,4}$/', $code)) flash('danger', 'Subject code must be 2 to 4 digits (e.g. 011, 042).');
            elseif ($name === '') flash('danger', 'Subject name is required.');
            else {
                if ($id) q('UPDATE subjects SET code = ?, name = ?, abbr = ?, level = ?, is_subsidiary = ? WHERE id = ?', [$code, $name, $abbr, $lvl, $sub, $id]);
                else { q('INSERT INTO subjects (code, name, abbr, level, is_subsidiary) VALUES (?, ?, ?, ?, ?)', [$code, $name, $abbr, $lvl, $sub]); $id = (int)db()->lastInsertId(); }

                /* Somo jipya/level mpya huingizwa kiotomatiki kwenye madarasa yanayolingana
                   ili lionekane kwenye ratiba (vipindi vinavyohitajika) bila kikomo cha masomo.
                   is_compulsory = 0: kategoria ya taarifa (masomo yote hufanywa na wanafunzi wote). */
                $levels = $lvl === 'B' ? ['O', 'A'] : [$lvl];
                $in = implode(',', array_fill(0, count($levels), '?'));
                foreach (q_all("SELECT id FROM classes WHERE level IN ($in)", $levels) as $c) {
                    q('INSERT IGNORE INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, 0)', [(int)$c['id'], (int)$id]);
                }
                flash('success', 'Subject saved.');
            }
        } elseif ($a === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ((int)q_val('SELECT COUNT(*) FROM scores WHERE subject_id = ?', [$id]) > 0) flash('danger', 'This subject has saved scores; it cannot be deleted.');
            else { q('DELETE FROM subjects WHERE id = ?', [$id]); flash('success', 'Subject deleted.'); }
        } elseif ($a === 'bulk_delete') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), function ($v) { return $v > 0; })));
            if (!$ids) { flash('danger', 'No subject was selected.'); }
            else {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $scored = [];
                foreach (q_all("SELECT DISTINCT subject_id FROM scores WHERE subject_id IN ($in)", $ids) as $r) $scored[(int)$r['subject_id']] = true;
                $deletable = []; $blocked = [];
                foreach ($ids as $sid) { if (isset($scored[$sid])) $blocked[] = $sid; else $deletable[] = $sid; }

                $n = 0;
                if ($deletable) {
                    $din = implode(',', array_fill(0, count($deletable), '?'));
                    db()->beginTransaction();
                    q("DELETE FROM subjects WHERE id IN ($din)", $deletable);
                    $n = count($deletable);
                    db()->commit();
                }
                $bnames = [];
                if ($blocked) {
                    $bin = implode(',', array_fill(0, count($blocked), '?'));
                    $bnames = array_column(q_all("SELECT name FROM subjects WHERE id IN ($bin) ORDER BY name", $blocked), 'name');
                }
                if ($bnames) {
                    $msg = '<b>' . $n . '</b> subject(s) deleted. Not deleted because they have saved scores: <b>' . h(implode(', ', $bnames)) . '</b>.';
                    flash($n ? 'warning' : 'danger', $msg);
                } else {
                    flash('success', '<b>' . $n . '</b> subject(s) deleted.');
                }
            }
        } elseif ($a === 'import') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('The file was not uploaded correctly.');
            if ($_FILES['file']['size'] > 5 * 1024 * 1024) throw new Exception('The file is larger than 5MB.');
            $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') throw new Exception('Use an Excel (.xlsx) file only. CSV is not accepted.');
            $rows = read_table($_FILES['file']['tmp_name'], 'xlsx');

            $hi = -1; $map = [];
            foreach (array_slice($rows, 0, 6, true) as $i => $r) {
                $norm = [];
                foreach ($r as $ci => $v) $norm[$ci] = str_replace(' ', '_', strtoupper(trim((string)$v)));
                if (in_array('SUBJECT_CODE', $norm, true) || in_array('CODE', $norm, true)) {
                    $hi = $i;
                    foreach ($norm as $ci => $nm) if ($nm !== '') $map[$nm] = $ci;
                    break;
                }
            }
            if ($hi < 0) throw new Exception('The table header (SUBJECT_CODE, SUBJECT_NAME, ...) was not found. Use the system template.');

            $aliases = [
                'SUBJECT_CODE' => ['SUBJECT_CODE', 'CODE', 'MSIMBO'],
                'SUBJECT_NAME' => ['SUBJECT_NAME', 'NAME', 'JINA'],
                'NECTA_ABBREVIATION' => ['NECTA_ABBREVIATION', 'ABBR', 'ABBREVIATION', 'KIFUPI'],
                'LEVEL' => ['LEVEL', 'KIDATO_LA_SOMO'],
                'SUBSIDIARY' => ['SUBSIDIARY', 'SUB', 'SOMO_LA_ZIADA'],
            ];
            $get = function ($r, $key) use ($map, $aliases) {
                foreach ($aliases[$key] as $alias) {
                    if (array_key_exists($alias, $map) && array_key_exists($map[$alias], $r)) return subj_cell($r[$map[$alias]]);
                }
                return '';
            };

            $exCodes = [];
            foreach (q_all('SELECT code FROM subjects') as $x) $exCodes[strtoupper(trim((string)$x['code']))] = true;
            $seen = []; $ok = []; $errors = []; $skip = [];
            for ($i = $hi + 1; $i < count($rows); $i++) {
                $r = $rows[$i]; $line = $i + 1;
                if (!array_filter($r, function ($x) { return trim((string)$x) !== ''; })) continue;
                $code = strtoupper($get($r, 'SUBJECT_CODE'));
                $name = preg_replace('/\s+/', ' ', $get($r, 'SUBJECT_NAME'));
                $abbr = strtoupper($get($r, 'NECTA_ABBREVIATION'));
                $lvl = subj_parse_level($get($r, 'LEVEL'));
                $sub = subj_parse_bool($get($r, 'SUBSIDIARY'));

                $e = [];
                if ($code === '') $e[] = 'SUBJECT_CODE is empty';
                elseif (!preg_match('/^\d{2,4}$/', $code)) $e[] = "SUBJECT_CODE '$code' is invalid (digits, 2-4)";
                elseif (isset($exCodes[$code])) { $skip[] = $name !== '' ? $name : $code; continue; }   // somo lipo tayari: lirukwe
                elseif (isset($seen[$code])) $e[] = "SUBJECT_CODE '$code' is duplicated in the file (line " . $seen[$code] . ')';
                if ($name === '') $e[] = 'SUBJECT_NAME is empty';
                elseif (mb_strlen($name, 'UTF-8') > 120) $e[] = 'SUBJECT_NAME is too long (max 120)';
                if (mb_strlen($abbr, 'UTF-8') > 20) $e[] = 'NECTA_ABBREVIATION is too long (max 20)';
                if ($lvl === null) $e[] = "LEVEL '" . $get($r, 'LEVEL') . "' is not recognised (use O, A or B)";

                if ($e) { $errors[] = 'Line ' . $line . ': ' . implode('; ', $e); continue; }
                $seen[$code] = $line;
                $ok[] = ['code' => $code, 'name' => $name, 'abbr' => $abbr, 'level' => $lvl, 'sub' => $sub];
            }
            if (count($ok) + count($errors) > 500) throw new Exception('The file contains more than 500 subjects. Split it and upload in batches.');
            if ($errors) $report = ['errors' => $errors];
            elseif (!$ok && !$skip) $report = ['errors' => ['No subject rows were found in the file.']];
            else {
                db()->beginTransaction();
                foreach ($ok as $s) {
                    q('INSERT INTO subjects (code, name, abbr, level, is_subsidiary) VALUES (?, ?, ?, ?, ?)', [$s['code'], $s['name'], $s['abbr'], $s['level'], $s['sub']]);
                    $newId = (int)db()->lastInsertId();
                    $levels = $s['level'] === 'B' ? ['O', 'A'] : [$s['level']];
                    $inL = implode(',', array_fill(0, count($levels), '?'));
                    foreach (q_all("SELECT id FROM classes WHERE level IN ($inL)", $levels) as $c) {
                        q('INSERT IGNORE INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, 0)', [(int)$c['id'], $newId]);
                    }
                }
                db()->commit();
                $msg = '<b>' . count($ok) . '</b> subject(s) added successfully from Excel.';
                if ($skip) $msg .= ' <b>' . count($skip) . '</b> subject(s) already existed and were skipped: ' . h(implode(', ', array_slice($skip, 0, 10))) . (count($skip) > 10 ? ' ...' : '');
                flash($ok ? 'success' : 'warning', $msg);
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        $report = ['errors' => [$e->getCode() == 23000 ? 'A subject code already exists in the database. Nothing was saved.' : 'Database error: ' . $e->getMessage()]];
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        $report = ['errors' => [$e->getMessage()]];
    }
    if (!$report) redirect('super/subjects.php');
}

$edit = isset($_GET['edit']) ? q_one('SELECT * FROM subjects WHERE id = ?', [(int)$_GET['edit']]) : null;

/* Masomo yaliyosajiliwa kabla ya kipengele cha auto-add (hayana darasa lolote)
   huingizwa kiotomatiki kwenye madarasa yenye level inayolingana. */
foreach (q_all('SELECT * FROM subjects s WHERE NOT EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.subject_id = s.id)') as $orphan) {
    $levels = $orphan['level'] === 'B' ? ['O', 'A'] : [$orphan['level']];
    $in = implode(',', array_fill(0, count($levels), '?'));
    foreach (q_all("SELECT id FROM classes WHERE level IN ($in)", $levels) as $c) {
        q('INSERT IGNORE INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, 0)', [(int)$c['id'], (int)$orphan['id']]);
    }
}

/* ---------- Filters ---------- */
$fq = trim($_GET['q'] ?? '');
$fl = in_array($_GET['level'] ?? '', ['O', 'A', 'B'], true) ? $_GET['level'] : '';
$fs = ($_GET['sub'] ?? '') === '1';
$where = []; $args = [];
if ($fq !== '') { $where[] = '(s.name LIKE ? OR s.code LIKE ? OR s.abbr LIKE ?)'; $like = '%' . $fq . '%'; array_push($args, $like, $like, $like); }
if ($fl !== '') { $where[] = 's.level = ?'; $args[] = $fl; }
if ($fs) { $where[] = 's.is_subsidiary = 1'; }
$rows = q_all('SELECT s.*, (SELECT COUNT(*) FROM class_subjects cs WHERE cs.subject_id = s.id) AS n_cls FROM subjects s' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.is_subsidiary, s.name', $args);
$filtered = $fq !== '' || $fl !== '' || $fs;

$stat = q_one("SELECT COUNT(*) AS total, COALESCE(SUM(level = 'O'), 0) AS o, COALESCE(SUM(level = 'A'), 0) AS a, COALESCE(SUM(level = 'B'), 0) AS b, COALESCE(SUM(is_subsidiary = 1), 0) AS sub FROM subjects");
$nTotal = (int)$stat['total']; $nO = (int)$stat['o']; $nA = (int)$stat['a']; $nB = (int)$stat['b']; $nSub = (int)$stat['sub'];
layout_start('Subjects', 'subjects'); ?>

<style>
.sbj-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.sbj-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.sbj-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.sbj-hero .inner{position:relative;z-index:2;min-width:0}
.sbj-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.sbj-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.sbj-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:640px;line-height:1.45}
.sbj-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.sbj-hero .actions .btn{font-weight:700;border-radius:10px;padding:.5rem .95rem}
.sbj-hero .btn-light{color:#0b3d91;border:0;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.sbj-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.sbj-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.sbj-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.sbj-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.sbj-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.sbj-stat.b .ico{background:#eaf1fe;color:#1b5bc4}
.sbj-stat.s .ico{background:#fdf1dc;color:#b06a00}
.sbj-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.sbj-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.sbj-toolbar{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:12px 14px;box-shadow:0 2px 10px rgba(11,61,145,.05);margin-bottom:16px}
.sbj-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.sbj-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;border-bottom:1px solid #eef2fa}
.sbj-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.sbj-card-h .t i{color:#2c6ed5}
.sbj-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.sbj-table tbody td{padding-top:.68rem;padding-bottom:.68rem;border-color:#f0f4fb;vertical-align:middle}
.sbj-table tbody tr:hover{background:#fafcff}
.sbj-table tr.sbj-editing{background:#fffdf4;box-shadow:inset 3px 0 0 #d39b1b}
.sbj-table tr.sbj-editing:hover{background:#fff9e8}
.sbj-mark{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:1rem;color:#fff;flex:0 0 auto;background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 3px 8px rgba(11,61,145,.22)}
.sbj-mark.o{background:linear-gradient(135deg,#0b8a3e,#20b15b);box-shadow:0 3px 8px rgba(11,138,62,.22)}
.sbj-mark.a{background:linear-gradient(135deg,#5b3fb5,#8a6fe0);box-shadow:0 3px 8px rgba(91,63,181,.22)}
.sbj-name{font-weight:700;color:#16203a;line-height:1.25}
.sbj-meta{font-size:.71rem;color:#8a99b0;display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap}
.sbj-code{font-size:.68rem;font-weight:800;background:#eef3fd;color:#0b3d91;border:1px solid #d9e6fb;border-radius:6px;padding:1px 6px;letter-spacing:.02em}
.sbj-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:3px 9px;border:1px solid;white-space:nowrap}
.sbj-pill.o{background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.sbj-pill.a{background:#f0ecfd;color:#5b3fb5;border-color:#ddd3f8}
.sbj-pill.b{background:#eaf1fe;color:#1b5bc4;border-color:#cfe0fb}
.sbj-subbadge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:800;color:#8a5a00;background:#fdf1dc;border:1px solid #f5dfb2;border-radius:999px;padding:2px 8px}
.sbj-count{display:inline-flex;align-items:center;gap:5px;font-size:.8rem;color:#33415c;font-weight:600}
.sbj-count i{color:#8a99b0}
.sbj-actions{display:inline-flex;gap:5px;align-items:center}
.sbj-actions .btn{--bs-btn-padding-y:.26rem;--bs-btn-padding-x:.46rem;--bs-btn-font-size:.75rem;border-radius:8px}
.sbj-empty{text-align:center;padding:2.6rem 1rem;color:#8a99b0}
.sbj-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.sbj-form-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden;scroll-margin-top:16px}
.sbj-form-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;background:linear-gradient(120deg,#0f4aa8,#2c6ed5);color:#fff}
.sbj-form-h .t{font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.sbj-form-h .t i{color:#cfe0ff}
.sbj-form-h .badge{background:rgba(255,255,255,.18)!important;color:#fff!important;border:1px solid rgba(255,255,255,.45)!important}
.sbj-form-body{padding:16px}
.sbj-form-card .form-label{font-size:.76rem;font-weight:800;color:#33415c;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.28rem}
.sbj-form-card .form-control,.sbj-form-card .form-select{border-color:#dfe7f5;font-size:.86rem;padding:.48rem .7rem;border-radius:9px}
.sbj-form-card .form-control:focus{border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.sbj-form-card .form-text{font-size:.71rem;color:#8a99b0}
.sbj-form-card .input-group-text{background:#f4f7fe;border-color:#dfe7f5;color:#5b7bb5;font-size:.88rem;border-radius:9px 0 0 9px}
.sbj-form-card .input-group .form-control{border-radius:0 9px 9px 0}
.sbj-level-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:.15rem}
.sbj-level-opt{position:relative}
.sbj-level-opt input{position:absolute;opacity:0;width:1px;height:1px}
.sbj-level-opt label{display:block;height:100%;border:1.5px solid #dfe7f5;border-radius:10px;padding:8px 6px;cursor:pointer;background:#fff;text-align:center;transition:border-color .15s,background .15s,box-shadow .15s}
.sbj-level-opt label .lv-t{font-size:.73rem;font-weight:800;color:#33415c;display:flex;align-items:center;justify-content:center;gap:4px}
.sbj-level-opt label .lv-t i{color:#8a99b0;font-size:.85rem}
.sbj-level-opt label .lv-s{display:block;font-size:.6rem;color:#8a99b0;margin-top:1px;line-height:1.25}
.sbj-level-opt label:hover{border-color:#a9c4f2;background:#fafcff}
.sbj-level-opt input:checked + label{border-color:#2c6ed5;background:#eef4ff;box-shadow:0 0 0 3px rgba(44,110,213,.12)}
.sbj-level-opt input:checked + label .lv-t{color:#0b3d91}
.sbj-level-opt input:checked + label .lv-t i{color:#2c6ed5}
.sbj-level-opt input:focus-visible + label{outline:2px solid #7aa5ea;outline-offset:2px}
.sbj-preview{border:1px dashed #cfdcf0;background:linear-gradient(180deg,#fbfdff,#f3f8ff);border-radius:12px;padding:10px 12px;margin:0 0 14px}
.sbj-preview .pv-l{font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;font-weight:800;color:#8a99b0;margin-bottom:7px;display:flex;align-items:center;gap:5px}
.sbj-preview .pv-l i{color:#2c6ed5}
.sbj-preview .pv-row{display:flex;align-items:center;gap:9px;min-height:38px}
.sbj-preview .pv-name{font-weight:700;color:#16203a;font-size:.9rem;line-height:1.2;word-break:break-word}
.sbj-preview .pv-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:3px}
.sbj-preview .sbj-mark{width:34px;height:34px;font-size:.9rem}
.sbj-preview .pv-hint{font-size:.64rem;color:#8a99b0;margin-top:6px}
.sbj-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:9px 10px;font-size:.72rem;color:#3f5a80;line-height:1.4;margin-top:14px}
.sbj-tip i{color:#2c6ed5;font-size:.9rem;flex:0 0 auto;margin-top:1px}
.sbj-form-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.sbj-form-actions .btn{font-weight:700;border-radius:9px;padding:.5rem .9rem}
.sbj-form-actions .btn-primary{flex:1 1 auto}
.sbj-edit-note{display:flex;align-items:center;gap:8px;background:#fff8e6;border:1px solid #ffe1a3;color:#7a5300;border-radius:10px;padding:8px 10px;font-size:.78rem;margin-bottom:12px}
@media(min-width:992px){.sbj-form-card{position:sticky;top:16px}}
@media(max-width:575px){.sbj-hero{padding:18px}.sbj-hero h1{font-size:1.32rem}}
@media print{.sbj-hero{background:#fff!important;color:#000!important;box-shadow:none}.sbj-hero .actions,.sbj-actions,.sbj-form-card,.sbj-toolbar,.sbj-bulk{display:none!important}}
</style>

<div class="sbj-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-journals"></i> Curriculum</div>
    <h1>Subjects</h1>
    <p>Register O-Level and A-Level subjects, set NECTA abbreviations, mark subsidiary subjects, and keep class links up to date.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light btn-sm" href="subjects.php#subject-form"><i class="bi bi-plus-lg me-1"></i> New subject</a>
    <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#bulkSubjects"><i class="bi bi-file-earmark-excel me-1"></i> Bulk upload</button>
  </div>
</div>

<div class="sbj-stats">
  <div class="sbj-stat"><span class="ico"><i class="bi bi-journals"></i></span><div><div class="v"><?php echo $nTotal; ?></div><div class="l">Total subjects</div></div></div>
  <div class="sbj-stat g"><span class="ico"><i class="bi bi-book"></i></span><div><div class="v"><?php echo $nO; ?></div><div class="l">O-Level</div></div></div>
  <div class="sbj-stat a"><span class="ico"><i class="bi bi-mortarboard"></i></span><div><div class="v"><?php echo $nA; ?></div><div class="l">A-Level</div></div></div>
  <div class="sbj-stat b"><span class="ico"><i class="bi bi-collection"></i></span><div><div class="v"><?php echo $nB; ?></div><div class="l">O &amp; A</div></div></div>
  <div class="sbj-stat s"><span class="ico"><i class="bi bi-star"></i></span><div><div class="v"><?php echo $nSub; ?></div><div class="l">Subsidiary</div></div></div>
</div>
<?php if ($report): ?>
<div class="alert alert-danger small border-0 shadow-sm"><b>Nothing was saved.</b> Fix the errors and upload again:<ul class="mb-0"><?php foreach (array_slice($report['errors'], 0, 40) as $er) echo '<li>' . h($er) . '</li>'; ?></ul><?php if (count($report['errors']) > 40) echo '... and ' . (count($report['errors']) - 40) . ' more errors.'; ?></div>
<?php endif; ?>

<div class="sbj-toolbar no-print">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-lg-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search subject name, code or abbreviation" value="<?php echo h($fq); ?>">
      </div>
    </div>
    <div class="col-6 col-lg-2">
      <select name="level" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All levels</option>
        <?php foreach (['O' => 'O-Level', 'A' => 'A-Level', 'B' => 'O & A'] as $k => $v) echo '<option value="' . $k . '"' . ($fl === $k ? ' selected' : '') . '>' . $v . '</option>'; ?>
      </select>
    </div>
    <div class="col-6 col-lg-2">
      <select name="sub" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All subjects</option>
        <option value="1" <?php echo $fs ? 'selected' : ''; ?>>Subsidiary only</option>
      </select>
    </div>
    <div class="col-lg-3 d-flex gap-2">
      <button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
      <?php if ($filtered): ?><a class="btn btn-outline-secondary btn-sm" href="subjects.php" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
  </form>
</div>

<div class="collapse <?php echo $report ? 'show' : ''; ?> mb-3 sbj-bulk" id="bulkSubjects">
  <div class="sbj-card"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div style="max-width:560px">
        <div class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-excel text-success me-1"></i>Bulk upload subjects (Excel)</div>
        <p class="small text-muted mb-0">Download the template, fill one subject per row, then upload the <b>.xlsx</b> file. Maximum 5MB or 500 subjects. If the file has any error, nothing is saved.</p>
      </div>
      <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="import">
        <input type="file" name="file" accept=".xlsx" class="form-control form-control-sm" style="max-width:300px" required>
        <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Upload</button>
        <a class="btn btn-outline-success btn-sm" href="?template=1"><i class="bi bi-download me-1"></i>Template</a>
      </form>
    </div>
  </div></div>
</div>

<form id="bulkForm" method="post" onsubmit="return subjectsConfirmBulk()">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="sbj-card">
      <div class="sbj-card-h">
        <span class="t"><i class="bi bi-list-ul"></i> Subjects list</span>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Showing <?php echo count($rows); ?> of <?php echo $nTotal; ?></span>
          <span class="small text-muted"><b id="subjSelCount">0</b> selected</span>
          <button type="submit" form="bulkForm" id="subjBulkDelete" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-trash3 me-1"></i>Delete</button>
        </div>
      </div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0 sbj-table">
        <thead><tr><th class="ps-3" style="width:40px"><input type="checkbox" class="form-check-input" id="subjAll" title="Select all"></th><th>Subject</th><th>Level</th><th>Subsidiary</th><th><i class="bi bi-diagram-3 me-1"></i>Classes</th><th class="text-end pe-3">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
          <tr<?php echo $edit && (int)$edit['id'] === (int)$s['id'] ? ' class="sbj-editing"' : ''; ?>>
            <td class="ps-3"><input type="checkbox" class="form-check-input subj-check" name="ids[]" value="<?php echo (int)$s['id']; ?>" form="bulkForm"></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="sbj-mark <?php echo strtolower(h($s['level'])); ?>"><i class="bi bi-journal-bookmark"></i></span>
                <div style="min-width:0">
                  <div class="sbj-name"><?php echo h($s['name']); ?></div>
                  <div class="sbj-meta"><span class="sbj-code"><?php echo h($s['code']); ?></span><?php echo trim((string)$s['abbr']) !== '' ? '<span>NECTA: ' . h($s['abbr']) . '</span>' : ''; ?></div>
                </div>
              </div>
            </td>
            <td><span class="sbj-pill <?php echo strtolower(h($s['level'])); ?>"><?php echo $s['level'] === 'B' ? 'O &amp; A' : h($s['level']) . '-Level'; ?></span></td>
            <td><?php echo $s['is_subsidiary'] ? '<span class="sbj-subbadge"><i class="bi bi-star-fill"></i>Subsidiary</span>' : '<span class="text-muted small">&mdash;</span>'; ?></td>
            <td><span class="sbj-count"><i class="bi bi-diagram-3"></i><?php echo (int)$s['n_cls']; ?></span></td>
            <td class="text-end pe-3 text-nowrap">
              <div class="sbj-actions justify-content-end">
                <a class="btn btn-outline-secondary" href="?edit=<?php echo (int)$s['id']; ?>#subject-form" title="Edit subject"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this subject?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><button class="btn btn-outline-danger" title="Delete subject"><i class="bi bi-trash3"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="sbj-empty"><i class="bi bi-journals"></i><div class="fw-bold text-dark mb-1"><?php echo $filtered ? 'No subjects match the filters you applied.' : 'No subjects yet.'; ?></div><div class="small"><?php echo $filtered ? 'Change the filters or clear the search.' : 'Create your first subject using the form.'; ?></div></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="sbj-form-card" id="subject-form">
      <div class="sbj-form-h">
        <span class="t"><i class="bi bi-<?php echo $edit ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $edit ? 'Edit subject' : 'New subject'; ?></span>
        <?php if ($edit): ?><span class="badge">Editing</span><?php endif; ?>
      </div>
      <div class="card-body sbj-form-body">
        <?php if ($edit): ?><div class="sbj-edit-note"><i class="bi bi-info-circle"></i><span>You are editing <b><?php echo h($edit['name']); ?></b>. Changes apply everywhere this subject is used.</span></div><?php endif; ?>

        <div class="sbj-preview" aria-hidden="true">
          <div class="pv-l"><i class="bi bi-eye"></i> Live preview</div>
          <div class="pv-row">
            <span class="sbj-mark<?php echo ($edit['level'] ?? 'B') === 'O' ? ' o' : (($edit['level'] ?? 'B') === 'A' ? ' a' : ''); ?>" id="sbj-pv-mark"><i class="bi bi-journal-bookmark"></i></span>
            <div style="min-width:0">
              <div class="pv-name" id="sbj-pv-name"><?php echo h($edit['name'] ?? 'Subject name'); ?></div>
              <div class="pv-meta">
                <span class="sbj-code" id="sbj-pv-code"><?php echo h($edit['code'] ?? '—'); ?></span>
                <span class="sbj-pill <?php echo strtolower($edit['level'] ?? 'B') === 'o' ? 'o' : (strtolower($edit['level'] ?? 'B') === 'a' ? 'a' : 'b'); ?>" id="sbj-pv-pill"><?php echo ($edit['level'] ?? 'B') === 'O' ? 'O-Level' : (($edit['level'] ?? 'B') === 'A' ? 'A-Level' : 'O & A'); ?></span>
                <span class="sbj-subbadge" id="sbj-pv-sub" style="<?php echo !empty($edit['is_subsidiary']) ? '' : 'display:none'; ?>"><i class="bi bi-star-fill"></i>Subsidiary</span>
              </div>
            </div>
          </div>
          <div class="pv-hint">This is how the subject will look in the list.</div>
        </div>

        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
          <div class="mb-3">
            <label class="form-label" for="sbj-code">Subject code <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-hash"></i></span>
              <input id="sbj-code" name="code" class="form-control" required inputmode="numeric" maxlength="4" pattern="[0-9]{2,4}" title="2 to 4 digits" value="<?php echo h($edit['code'] ?? ''); ?>" placeholder="e.g. 011" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,4)">
            </div>
            <div class="form-text">Digits only, 2&ndash;4 characters. Must be unique.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sbj-name">Subject name <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-journal-text"></i></span>
              <input id="sbj-name" name="name" class="form-control" required value="<?php echo h($edit['name'] ?? ''); ?>" placeholder="e.g. Basic Mathematics" autocomplete="off">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sbj-abbr">NECTA abbreviation</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
              <input id="sbj-abbr" name="abbr" class="form-control" maxlength="20" value="<?php echo h($edit['abbr'] ?? ''); ?>" placeholder="e.g. B/MATH">
            </div>
            <div class="form-text">Optional, up to 20 characters.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Level</label>
            <div class="sbj-level-grid">
              <div class="sbj-level-opt">
                <input type="radio" id="sbj-level-o" name="level" value="O" <?php echo ($edit['level'] ?? 'B') === 'O' ? 'checked' : ''; ?>>
                <label for="sbj-level-o"><span class="lv-t"><i class="bi bi-book"></i>O-Level</span><span class="lv-s">CSEE</span></label>
              </div>
              <div class="sbj-level-opt">
                <input type="radio" id="sbj-level-a" name="level" value="A" <?php echo ($edit['level'] ?? 'B') === 'A' ? 'checked' : ''; ?>>
                <label for="sbj-level-a"><span class="lv-t"><i class="bi bi-mortarboard"></i>A-Level</span><span class="lv-s">ACSEE</span></label>
              </div>
              <div class="sbj-level-opt">
                <input type="radio" id="sbj-level-b" name="level" value="B" <?php echo ($edit['level'] ?? 'B') === 'B' ? 'checked' : ''; ?>>
                <label for="sbj-level-b"><span class="lv-t"><i class="bi bi-collection"></i>O &amp; A</span><span class="lv-s">Both</span></label>
              </div>
            </div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_subsidiary" id="sb" <?php echo !empty($edit['is_subsidiary']) ? 'checked' : ''; ?>>
            <label class="form-check-label small" for="sb">Subsidiary subject <span class="text-muted">(GS/BAM &mdash; not counted in points)</span></label>
          </div>
          <div class="sbj-form-actions">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo $edit ? 'Save changes' : 'Save subject'; ?></button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="subjects.php"><i class="bi bi-x-lg me-1"></i>Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="sbj-tip"><i class="bi bi-lightbulb"></i><span>Saving a new subject automatically links it to classes with a matching level. Edit the class links later from <b>Classes &rarr; Subjects</b>.</span></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  /* Bulk selection */
  var all = document.getElementById('subjAll');
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.subj-check'));
  var btn = document.getElementById('subjBulkDelete');
  var cnt = document.getElementById('subjSelCount');
  function refresh() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    if (btn) btn.disabled = n === 0;
    if (cnt) cnt.textContent = n;
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
  }
  if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
  window.subjectsConfirmBulk = function () {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    if (!n) return false;
    return confirm('Delete ' + n + ' selected subject(s)? Subjects with saved scores will not be deleted.');
  };

  /* Live preview */
  var codeEl = document.getElementById('sbj-code');
  var nameEl = document.getElementById('sbj-name');
  var subEl = document.getElementById('sb');
  var mark = document.getElementById('sbj-pv-mark');
  var nm = document.getElementById('sbj-pv-name');
  var cd = document.getElementById('sbj-pv-code');
  var pill = document.getElementById('sbj-pv-pill');
  var subB = document.getElementById('sbj-pv-sub');
  if (!nameEl) return;
  var map = {
    O: { c: ' o', cls: 'sbj-pill o', t: 'O-Level', icon: 'book' },
    A: { c: ' a', cls: 'sbj-pill a', t: 'A-Level', icon: 'mortarboard' },
    B: { c: '', cls: 'sbj-pill b', t: 'O & A', icon: 'journal-bookmark' }
  };
  function sync() {
    var lv = document.querySelector('input[name="level"]:checked');
    var m = map[lv ? lv.value : 'B'] || map.B;
    nm.textContent = nameEl.value.trim() || 'Subject name';
    cd.textContent = (codeEl.value.trim() || '—');
    mark.className = 'sbj-mark' + m.c;
    mark.innerHTML = '<i class="bi bi-' + m.icon + '"></i>';
    pill.className = m.cls;
    pill.textContent = m.t;
    if (subB) subB.style.display = (subEl && subEl.checked) ? 'inline-flex' : 'none';
  }
  codeEl.addEventListener('input', sync);
  nameEl.addEventListener('input', sync);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="level"]'), function (r) {
    r.addEventListener('change', sync);
  });
  if (subEl) subEl.addEventListener('change', sync);
  sync();
})();
</script>
<?php layout_end();
