<?php
/**
 * Usomaji wa picha ya template ya alama (OCR).
 *
 * Picha husomwa upande wa kivinjari (Tesseract.js) na maandishi/maneno
 * hutumwa hapa. Moduli hii inaoanisha maandishi hayo na watahiniwa wa
 * darasa/somo husika na kutoa alama zilizoonekana kwenye safu ya Score.
 *
 * Mpangilio wa template: S/N | Admission No | Candidate No | Jina | Sex | Score
 */

if (!function_exists('ocr_key')) {
    /** Safisha namba/maandishi kwa kulinganisha: herufi kubwa na tarakimu pekee. */
    function ocr_key($v) {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$v)));
    }
}

if (!function_exists('ocr_score_value')) {
    /**
     * Jaribu kutafsiri token moja kuwa alama.
     * Hurudisha [thamani, ubora] au null. Ubora: 1.0 = safi, 0.8 = imerekebishwa.
     */
    function ocr_score_value($token) {
        $orig = trim((string)$token);
        if ($orig === '') return null;
        $u = strtoupper($orig);

        if (in_array($u, ['ABS', 'ABSENT', 'X'], true)) return ['ABS', 1.0];

        if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/', $u)) {
            $v = (float)$u;
            if ($v >= 0 && $v <= 100) return [$u, 1.0];
        }

        // Marekebisho ya makosa ya kawaida ya OCR (O->0, I->1, S->5, B->8, Z->2)
        // hufanyika tu kama token ina angalau tarakimu moja halisi.
        if (preg_match('/[0-9]/', $orig)) {
            $d = strtr($orig, [
                'O' => '0', 'o' => '0', 'I' => '1', 'i' => '1', 'l' => '1', '|' => '1',
                'B' => '8', 'S' => '5', 's' => '5', 'Z' => '2', 'z' => '2', ',' => '.',
            ]);
            if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/', $d)) {
                $v = (float)$d;
                if ($v >= 0 && $v <= 100) return [$d, 0.8];
            }
        }
        return null;
    }
}

if (!function_exists('ocr_extract_scores')) {
    /**
     * Chambua alama kutoka maandishi/maneno ya OCR.
     *
     * @param array  $candidates Watahiniwa kwa mpangilio wa template.
     *                           Kila mmoja: student_id, admission_no, cand_no,
     *                           first_name, middle_name, last_name, sex.
     * @param string $text       Maandishi yote ya OCR (fallback).
     * @param array  $words      Maneno ya OCR: [{text, confidence, bbox:{x0,y0,x1,y1}}]
     * @param array  $lines      Mistari ya Tesseract: [{text, confidence, words:[...]}]
     * @return array Kila mwanafunzi: student_id, sn, admission_no, cand_no, name,
     *               sex, score, state (ok|low|none), source (namba|sn|none), raw, conf
     */
    function ocr_extract_scores(array $candidates, $text, array $words = [], array $lines = []) {
        $n = count($candidates);
        $out = [];
        foreach ($candidates as $i => $c) {
            $out[$i] = ['score' => '', 'state' => 'none', 'source' => 'none', 'raw' => '', 'conf' => 0.0];
        }
        if ($n === 0) return [];

        // Ramani ya namba za usajili/candidate -> index ya mtahiniwa.
        $keyMap = [];
        foreach ($candidates as $i => $c) {
            foreach ([$c['admission_no'] ?? '', $c['cand_no'] ?? ''] as $k) {
                $nk = ocr_key($k);
                if (strlen($nk) >= 3 && !isset($keyMap[$nk])) $keyMap[$nk] = $i;
            }
        }

        $rows = [];

        /* ---- Kwanza: mistari halisi ya Tesseract (hushughulikia picha iliyopinda) ---- */
        if ($lines) {
            foreach ($lines as $ln) {
                if (!is_array($ln)) continue;
                $lw = [];
                $lwords = $ln['words'] ?? [];
                if (is_array($lwords)) {
                    foreach ($lwords as $w) {
                        if (!is_array($w)) continue;
                        $t = trim((string)($w['text'] ?? ''));
                        if ($t === '') continue;
                        $b = is_array($w['bbox'] ?? null) ? $w['bbox'] : [];
                        $lw[] = [
                            't'    => $t,
                            'x0'   => (float)($b['x0'] ?? 0),
                            'x1'   => (float)($b['x1'] ?? 0),
                            'cy'   => ((float)($b['y0'] ?? 0) + (float)($b['y1'] ?? 0)) / 2,
                            'h'    => max(1.0, (float)($b['y1'] ?? 0) - (float)($b['y0'] ?? 0)),
                            'conf' => (float)($w['confidence'] ?? 0),
                        ];
                    }
                }
                if (!$lw && trim((string)($ln['text'] ?? '')) !== '') {
                    foreach (preg_split('/\s+/', trim((string)$ln['text'])) as $t) {
                        if ($t === '') continue;
                        $lw[] = ['t' => $t, 'x0' => 0.0, 'x1' => 0.0, 'cy' => 0.0, 'h' => 12.0, 'conf' => 0.0];
                    }
                }
                if ($lw) {
                    // Usipange upya kama hakuna nafasi za x (mstari umetokana na maandishi pekee).
                    if (count(array_unique(array_column($lw, 'x0'))) > 1) {
                        usort($lw, function ($a, $b) { return $a['x0'] <=> $b['x0']; });
                    }
                    $rows[] = $lw;
                }
            }
        }

        /* ---- Fallback 1: panga maneno kwa nafasi ya y (bbox) ---- */
        if (!$rows) {
            $ws = [];
            foreach ($words as $w) {
                if (!is_array($w)) continue;
                $t = trim((string)($w['text'] ?? ''));
                if ($t === '') continue;
                $b = $w['bbox'] ?? null;
                if (!is_array($b) || !isset($b['x0'], $b['x1'], $b['y0'], $b['y1'])) continue;
                $ws[] = [
                    't'    => $t,
                    'x0'   => (float)$b['x0'],
                    'x1'   => (float)$b['x1'],
                    'cy'   => ((float)$b['y0'] + (float)$b['y1']) / 2,
                    'h'    => max(1.0, (float)$b['y1'] - (float)$b['y0']),
                    'conf' => (float)($w['confidence'] ?? 0),
                ];
            }
            if ($ws) {
                usort($ws, function ($a, $b) { return $a['cy'] <=> $b['cy']; });
                $hs = array_column($ws, 'h');
                sort($hs);
                $med = $hs ? $hs[(int)floor(count($hs) / 2)] : 12.0;
                $tol = max(6.0, $med * 0.6);
                $group = []; $center = null;
                foreach ($ws as $w) {
                    if ($center === null || abs($w['cy'] - $center) <= $tol) {
                        $group[] = $w;
                        $center = $center === null ? $w['cy'] : (($center * (count($group) - 1)) + $w['cy']) / count($group);
                    } else {
                        $rows[] = $group; $group = [$w]; $center = $w['cy'];
                    }
                }
                if ($group) $rows[] = $group;
                foreach ($rows as &$r) usort($r, function ($a, $b) { return $a['x0'] <=> $b['x0']; });
                unset($r);
            }
        }

        /* ---- Fallback 2: tumia mistari ya maandishi ---- */
        if (!$rows) {
            foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
                $tokens = preg_split('/\s+/', trim($line));
                if (!$tokens || $tokens === ['']) continue;
                $r = [];
                foreach ($tokens as $t) {
                    $r[] = ['t' => $t, 'x0' => 0.0, 'x1' => 0.0, 'cy' => 0.0, 'h' => 12.0, 'conf' => 0.0];
                }
                $rows[] = $r;
            }
        }

        /* ---- Tafuta mstari wa kichwa cha jedwali na anza baada yake ---- */
        $start = 0;
        foreach ($rows as $ri => $r) {
            $joined = ocr_key(implode('', array_map(function ($w) { return $w['t']; }, $r)));
            if (strpos($joined, 'ADMISSIONNO') !== false || strpos($joined, 'CANDIDATENO') !== false) {
                $start = $ri + 1;
                break;
            }
        }

        $used = [];
        for ($ri = $start; $ri < count($rows); $ri++) {
            $r = $rows[$ri];
            $tokens = array_map(function ($w) { return $w['t']; }, $r);
            if (!$tokens) continue;

            // 1) Linganisha namba ya usajili / candidate
            $idx = -1; $keyPos = -1;
            foreach ($tokens as $p => $t) {
                $k = ocr_key($t);
                if ($k !== '' && isset($keyMap[$k]) && !isset($used[$keyMap[$k]])) {
                    $idx = $keyMap[$k]; $keyPos = $p; break;
                }
            }

            // 2) Fallback: tumia S/N ya mwanzo wa mstari
            if ($idx < 0) {
                $first = ltrim((string)($tokens[0] ?? ''), '#');
                if (preg_match('/^[0-9]{1,3}$/', $first)) {
                    $sn = (int)$first;
                    if ($sn >= 1 && $sn <= $n && !isset($used[$sn - 1])) { $idx = $sn - 1; $keyPos = -1; }
                }
            }
            if ($idx < 0 || isset($used[$idx])) continue;

            $rawLine = implode(' ', $tokens);
            $scanFrom = $keyPos >= 0 ? $keyPos + 1 : 1;

            // 3) Chukua token ya mwisho yenye alama, baada ya namba ya usajili
            $score = ''; $quality = 0.0; $scoreConf = 0.0;
            for ($p = count($tokens) - 1; $p >= $scanFrom; $p--) {
                $tk = ocr_key($tokens[$p]);
                if ($tk !== '' && isset($keyMap[$tk])) continue; // usichukue namba ya usajili/candidate
                $sv = ocr_score_value($tokens[$p]);
                if ($sv !== null) {
                    $score = $sv[0]; $quality = $sv[1];
                    $scoreConf = isset($r[$p]['conf']) ? (float)$r[$p]['conf'] : 0.0;
                    break;
                }
            }

            if ($score === '') {
                $used[$idx] = true;
                $out[$idx]['raw'] = $rawLine;
                continue;
            }

            $state = ($quality >= 1.0 && ($scoreConf === 0.0 || $scoreConf >= 60)) ? 'ok' : 'low';
            $out[$idx] = [
                'score'  => $score,
                'state'  => $state,
                'source' => $keyPos >= 0 ? 'namba' : 'sn',
                'raw'    => $rawLine,
                'conf'   => $scoreConf,
            ];
            $used[$idx] = true;
        }

        $result = [];
        foreach ($candidates as $i => $c) {
            $result[] = array_merge([
                'student_id'   => (int)($c['student_id'] ?? 0),
                'sn'           => $i + 1,
                'admission_no' => (string)($c['admission_no'] ?? ''),
                'cand_no'      => (string)($c['cand_no'] ?? ''),
                'name'         => strtoupper(full_name($c)),
                'sex'          => strtoupper((string)($c['sex'] ?? '')),
            ], $out[$i]);
        }
        return $result;
    }
}
