# Mfumo wa Matokeo - SHULE NYINGI (Wilaya + Shule + Walimu + Wanafunzi)

Mfumo kamili wa shule nyingi: usajili wa wanafunzi, madarasa, masomo, walimu, mitihani ya wilaya na ya shule, alama, matokeo ya mtindo wa NECTA, ulinganisho wa shule (matokeo ya wilaya), export ya PDF/Excel, na portal ya mwanafunzi.
PHP 7.4+ / MySQL-MariaDB. Folda: `matokeo`; database: `matokeo_db` (haiingiliani na mfumo wako wa zamani `ngais`).

## Roles
| Role | Anaweza |
|---|---|
| **super** (wilaya/DSEO) | Kuongeza/kuzima shule, kutengeneza admin wa shule, kusimamia madarasa/masomo/gredi/miaka (vya pamoja kwa shule zote), kuunda **mitihani ya wilaya** (kuchagua shule zinazoshiriki), kufunga/kuchapisha, **kuona jedwali la kulinganisha shule (council)**, na "kuingia" kwenye shule yoyote kama admin wake. |
| **admin** (mkuu wa shule) | Kwenye shule yake tu: wanafunzi (import Excel `.xlsx`, PIN), walimu pamoja na masomo wanayofundisha, ugawaji wa masomo, **ratiba/scheme/logbook/mitala**, mitihani ya shule, kusajili watahiniwa, kuingiza alama, kuchapisha matokeo ya shule yake. |
| **teacher** | Kwenye shule yake: kuingiza alama za masomo aliyopangiwa (mtihani ukiwa *open*), upload Excel (.xlsx), kuona matokeo ya madarasa yake, na **ratiba/scheme/logbook/mitala** ya masomo aliyopangiwa. |
| **mwanafunzi** | Anachagua shule + PREMS No (au Admission No ya zamani) + PIN; huona matokeo yaliyochapishwa na shule yake pekee, na hupakua ripoti yake (PDF/Excel). |

**Nafasi za walimu (`position`):** ukurasa wa *Teachers → Register teacher* una chaguo la **Role / Wadhifa**: `Teacher`, `Academic Master`, `Second Master`, `Headmaster` na `Admin`. Wadhifa huamua ufikiaji: `Teacher` pekee hubaki na ufikiaji wa mwalimu; Academic Master, Second Master na Headmaster hupata ufikiaji wa admin wa shule. Jina la wadhifa linaonekana kwenye orodha ya walimu, topbar na ukurasa wa mwalimu wa wilaya.

## Usakinishaji (XAMPP)
1. Washa Apache na MySQL. Nakili folda `matokeo` kwenda `C:\xampp\htdocs\matokeo`.
2. phpMyAdmin → **Import** → `database/schema.sql`.
3. Fungua `http://localhost/matokeo/` → `install.php`: tengeneza **super admin** (jina la mfumo, mwaka, akaunti).
4. `composer install` inahitajika kwa score upload ya Excel (`.xlsx`) na export za PDF/Excel. Bila Composer: score upload haitafanya kazi; PDF → ukurasa wa Print ("Save as PDF").
5. (Hiari, kujaribu) import `database/sample_data.sql` baada ya hatua ya 3 (shule 2 za mfano, wanafunzi, mtihani wa wilaya).
6. **Siri za mazingira:** nakili `config.example.php` kuwa `config.local.php` (`cp config.example.php config.local.php`) kisha weka `DB_*`, `APP_URL` na SMTP hapo. `config.local.php` haipakiwi kwenye Git (`.gitignore`), hivyo nenosiri halisi halionekani GitHub; `config.php` ina chaguo-msingi za development tu.

## Kuweka mtandaoni (deploy kwa FTP)
Kila `git push` kwenye branch `main` inapeleka mabadiliko kwenye shared hosting kiotomatiki kupitia GitHub Actions (`.github/workflows/deploy.yml`). Workflow hujenga `vendor/` (kwa kuwa haipo Git) kisha hupakia kwa FTP faili zilizobadilika pekee.

**Mara moja: GitHub → Settings → Secrets and variables → Actions** — ongeza secrets hizi nne:

| Secret | Thamani | Mfano |
|---|---|---|
| `FTP_SERVER` | hostname ya FTP (bila `ftp://`) | `ftp.shule.ac.tz` |
| `FTP_USERNAME` | mtumiaji wa FTP (tengeneza akaunti maalum kwenye cPanel → FTP Accounts, ipe folda ya site pekee) | `deploy@shule.ac.tz` |
| `FTP_PASSWORD` | nenosiri la akaunti hiyo | — |
| `FTP_SERVER_DIR` | folda ya site kwenye server, **mwisho uwe na `/`** | `public_html/matokeo/` |

Kisha *Actions → "Deploy kwa FTP" → Run workflow* kuendesha mara ya kwanza kwa mkono.

**Mara moja: kwenye server**
1. phpMyAdmin → Import `database/schema.sql`, kisha migrations unazohitaji (tazama sehemu za chini).
2. Tengeneza `config.local.php` **kwenye server** (cPanel File Manager au FTP) ukiwa na `DB_*` za server, `APP_URL` na SMTP. Faili hili halipo Git, hivyo deploy haiwezi kulipakia wala kulifuta.
3. Hakikisha `storage/` ina ruhusa ya kuandika (folda `755`, faili `644`).
4. Fungua site → `install.php` kutengeneza super admin, kisha **futa `install.php`** (hujifunga baada ya kutumika, lakini ni salama kuiondoa).
5. Hakuna Composer ya server inayohitajika — `vendor/` hujengwa kwenye GitHub Actions na kupakiwa.

**Mambo ya kuzingatia**
- `database/` haipakiwi kwenye server (SQL zisiweze kupakuliwa kwa mtandao). Mabadiliko ya schema hufanywa kwa mkono.
- Usiweke `dangerous-clean-slate: true` kwenye workflow — itafuta `config.local.php` na faili za uploads zilizo kwenye server.
- FTP ni ya maandishi wazi; ikiwa hosting inaunga mkono, washa `protocol: ftps` kwenye workflow na tumia HTTPS kila mahali.
- Deploy ya kwanza ni ndefu (inapakia `vendor/`); baadaye ni faili zilizobadilika tu, kwa kawaida dakika 1–2. Ufuatilie kwenye *Actions*.

## Mpangilio wa kazi
**Wilaya (super):** Schools → ongeza shule (+ akaunti ya admin wa shule) → Classes/Subjects/Grading (kama inahitajika) → District Exams → chagua madarasa na shule → baada ya shule kuingiza alama: *Matokeo ya wilaya* (council) → chapisha.
**Shule (admin):** Teachers (orodha ya walimu → *Register teacher*) → Assignments (stream maalum) → Students (import) → Exams → mtihani (chagua **kidato kwa check box**, kisha **masomo ya mtihani** kwa kila kidato) → *Sajili watahiniwa* → walimu waingize alama → Matokeo → *Chapisha kwa wanafunzi* → toa PIN (Students → PIN).

Kichwa cha juu (topbar) huonyesha **jina la mfumo (iEPAS)** kama kichwa kikuu na **jina la shule** chini yake; ukurasa wa dashboard una hero yenye jina la shule, mkoa/wilaya, centre no na vitufe vya mkato.

### Usajili wa wanafunzi kwa Excel (iEPAS)
Kwenye *Students → Import (Excel)* bofya **Pakua template (.xlsx)**. Template hutumia format ya iEPAS na hujazwa kiotomatiki na **centre number, jina la shule, mwezi na mwaka** wa shule inayopakua.

- Safu: `S/N`, `PREMS_NO`, `FIRST_NAME`, `MIDDLE_NAME`, `LAST_NAME`, `SEX`, `CLASS`, `STREAM`, `DOB`, `NATIONALITY`, `GUARDIAN_NAME`, `GUARDIAN_PHONE`. **Hakuna Admission No** — PREMS No ndiyo kitambulisho cha mwanafunzi.
- **Safu za lazima (zenye rangi):** `PREMS_NO`, `FIRST_NAME`, `LAST_NAME`, `SEX`, `CLASS`, `GUARDIAN_NAME`, `GUARDIAN_PHONE`. Kama moja haijajazwa, **hakuna mwanafunzi atakayesajiliwa**.
- **PREMS No** lazima iwe ya kipekee **katika shule zote** (ndiyo inayotofautisha mwanafunzi mmoja na mwingine).
- `NATIONALITY` ikiwa tupu mfumo hutumia `TANZANIA`. `STREAM`, `MIDDLE_NAME` na `DOB` ni hiari.
- Faili likiwa na makosa, mfumo hau-hifadhi mwanafunzi yeyote, huonyesha makosa yote, na hutoa kitufe cha **kupakua faili lako lenye makosa** (safu ya `ERRORS` na mistari yenye makosa iliyotiwa rangi nyekundu) ili uirekebishe na kupakia tena.
- Wakati wa kupakia, **progress bar** inaonyesha asilimia; ikikamilika mfumo hutoa ujumbe wa mafanikio na kukupeleka kwenye orodha ya wanafunzi.

Wakati wa usajili, **Cheque Number** (ambayo ni `username` ya kuingia) **inatakiwa kuwa tarakimu 7 hadi 10** (mf. `1234567`). Nenosiri la mwalimu **hujazwa kiotomatiki** kuwa **jina lake la mwisho likianza na herufi kubwa** (mf. `MASANJA` → `Masanja`); admin anaweza kulibadilisha kabla ya kuhifadhi.

**Kuingia (login):** mwalimu/admin huingiza **Cheque Number** yake kwenye sehemu ya *Cheque Number / Barua pepe*; wasimamizi (super) wanaweza kutumia barua pepe. Akaunti zilizo na username za zamani (zisizo tarakimu) zinaendelea kuingia kwa username ile ile.

## Moduli ya Kufundisha (teaching/)
Kiungo **Kufundisha** kwenye menyu ya admin na mwalimu. Mwalimu huona na kusimamia madarasa/masomo aliyopangiwa tu; admin huona yote.

- **Masomo anayofundisha mwalimu:** huchaguliwa kwenye fomu ya *Teachers & Users* kwa **dropdown sita** (Somo 1 hadi Somo 6); kila dropdown ni somo moja, hivyo mwalimu anaweza kuchagua hadi masomo 6 bila kushikilia Ctrl. Mfumo humruhusu mwalimu kuingiza alama za masomo hayo tu. Ugawaji wa **stream** maalum hufanyika kwenye ukurasa wa *Assignments*: kwanza chagua mwalimu, kisha orodha huonyesha **masomo aliyosajiliwa mwalimu huyo pekee** (hayaruhusiwi kuongezwa somo asilosajiliwa).
- **Ratiba** (`teaching/timetable.php` + `teaching/timetable_new.php`): ratiba **huhifadhiwa** kwenye database na huchapishwa (Publish). Muundo wa MSSIS — safu za **DAY** na **FORM**, vipindi vilivyohesabiwa (`01`, `02`, …) na saa zake, safu za **B (BREAK/tukio)** zinazochukua urefu wote, na kila kisanduku kina **somo + (jina la mwalimu)**.
  - *Ukurasa wa kuona* (`timetable.php`): vichujio vya mwaka, hali (Active/Pending/Archived), kundi, darasa, **stream**, somo na mwalimu; meta (tarehe ya kuchapishwa, kundi, vipindi/siku, muda, saa, Jumamosi/Jumapili); Publish; export `.xlsx`, PDF A4/A3 na print. Mwalimu huona ratiba Active na vipindi vyake tu.
  - *Ukurasa wa kuunda* (`timetable_new.php`, admin): kundi (Kawaida/Advanced/Zote), vipindi 1–14 kwa siku, dakika 15–120 kwa kipindi, saa ya kuanza/kumaliza, Jumamosi/Jumapili, mapumziko na matukio yanayoweza kuongezwa/kufutwa, **streams (si lazima)** na **vipindi vinavyohitajika kwa wiki kwa kila darasa, stream na somo** (vinabadilishwa). Kizalisha hupanga vipindi bila kugongana kwa mwalimu, kisha huhifadhi kama Pending.
  - *Streams:* kwenye `admin/assignments.php` mwalimu anaweza kupangiwa darasa/somo kwa **stream** fulani (mf. `A`); acha wazi kwa darasa zima. Kwenye ukurasa wa kuunda ratiba andika streams zikitenganishwa kwa koma (mf. `A, B`); kila stream hupata safu yake ya ratiba (`Form I A`, `Form I B`) na mahitaji yake ya vipindi. Mwalimu hawezi kufundisha stream mbili kwa kipindi kimoja.
  - Vichujio vya kundi: Kawaida = `classes.level` `O`, Advanced = `A`, Zote = zote mbili.
- **Scheme of Work** (`teaching/scheme.php`): hujazwa moja kwa moja kutoka mada za mitala; kama hakuna mada hutoa template tupu ya wiki 13. Export `.xlsx` na print.
- **Logbook** (`teaching/logbook.php`): kuingiza/kufuta kipindi kilichofundishwa (tarehe, kipindi, mada, shughuli, maoni). Export `.xlsx` na print.
- **Mitala (Syllabus)** (`teaching/syllabus.php`): kuingiza/kufuta mada kwa muhula na wiki (mada, mada ndogo, malengo, shughuli, nyenzo, tathmini, marejeo). Mada hizi hutumika kwenye scheme.
- **Lugha:** kurasa zote za moduli hii zinaonyesha maandishi kwa **Kiingereza**; kiungo cha menyu kuu kinabaki **Kufundisha**.
- **Masomo mapya:** somo lolote jipya linalosajiliwa na super admin huingizwa kiotomatiki kwenye `class_subjects` kwa madarasa yenye level inayolingana (`B` = O na A). Hivyo huonekana mara moja kwenye orodha ya **vipindi vinavyohitajika kwa wiki** kwenye ukurasa wa kuunda ratiba, bila kikomo cha idadi ya masomo. Kikomo pekee ni nafasi za vipindi kwa wiki. **Subject code** lazima iwe namba pekee yenye tarakimu **2 hadi 4** (mf. `011`, `042`, `060`); namba ndefu au herufi hukataliwa (kwenye fomu na server).
- **Bulk upload ya masomo:** kwenye ukurasa wa *Subjects* bofya **Bulk Upload (Excel)**, pakua template ya `.xlsx`, jaza karatasi ya **Subjects** (karatasi ya **Mifano** inaonyesha format; **Maelekezo** ina maelezo), kisha upload. Mfumo hukagua kila mstari (subject code ya namba 2–4, jina, level, subsidiary, misimbo inayojirudia); faili likiwa na kosa lolote **hakuna somo litakalohifadhiwa** na makosa yote yanaonyeshwa. Somo lililofanikiwa huingizwa kwenye `class_subjects` kulingana na level yake.
- **Bulk delete ya masomo:** chagua masomo kwa checkboxes (au tumia checkbox ya kichwa kuchagua yote) kisha bofya **Bulk Delete** mara moja. Masomo yenye alama zilizohifadhiwa (`scores`) hayafutwi; mfumo unayaacha na kuorodhesha majina yao.


**Kwa mfumo ulioshasakinishwa:** import `database/migration_student_nationality.sql` mara moja ili kuongeza safu ya `nationality` na kufanya `PREMS No` kuwa ya kipekee shule zote. Kisha import `database/migration_teaching.sql` mara moja (phpMyAdmin → Import). Huunda majedwali `syllabus_topics`, `subject_logs`, `timetables`, `timetable_requirements` na `timetable_entries` matupu pekee; data iliyopo haibadilishwi. Kisha import `database/migration_streams.sql` mara moja ili kuongeza safu ya `stream` kwenye `teacher_assignments`, `timetable_requirements` na `timetable_entries` (data iliyopo inabaki; stream tupu = darasa zima).

**Masomo ya mtihani kwa kila kidato:** import `database/migration_exam_subjects.sql` mara moja. Huunda jedwali `exam_class_subjects` linalohifadhi masomo yaliyochaguliwa kwenye fomu ya mtihani. Bila rekodi za kidato, mfumo hutumia masomo yote ya darasa (mitihani ya zamani inaendelea kufanya kazi). Somo lenye alama zilizohifadhiwa haliwezi kuondolewa kwenye mtihani.

**Tarehe ya kusajiliwa somo:** import `database/migration_assignment_dates.sql` mara moja ili kuongeza `teacher_assignments.created_at`. Inatumika kwenye ukurasa wa *Classes → Subjects →* alama ya jicho (*shule zilizosajili somo*) kuonyesha tarehe ambayo somo lilisajiliwa kwa mwalimu wa shule husika; pasipo safu hii mfumo hutumia tarehe ya akaunti ya mwalimu.

**Nafasi/wadhifa wa watumiaji:** import `database/migration_user_positions.sql` mara moja ili kuongeza safu ya `users.position` (Teacher, Academic Master, Second Master, Headmaster, Admin) na kuweka thamani kwa watumiaji waliopo. `role` inabaki kuwa ngazi ya ufikiaji; `position` ni wadhifa unaoonekana.

## Nyaraka za mtihani (CAL, ISAL, Sitting Plan, Registered Stats)
Kwenye *Admin → Exams →* fungua mtihani (*Exam view*), kwenye kadi ya madarasa:

- Kila darasa lina mstari wake wa kichwa (jina la darasa + idadi ya masomo) ukiwa na vitufe **Results**, **Score Progress**, **CAL**, **Stats** na **Sitting** kwenye mstari huo huo; chini yake masomo yanaonekana kama vitufe vidogo (code + jina) kama kwenye ukurasa wa wilaya, bila border ya ndani.
- Chini ya jedwali la madarasa kuna orodha ya **masomo yote yaliyosajiliwa** kwenye mtihani; kila somo lina kitufe cha **ISAL** chenye icon ya download kinachopakua *Individual Subject Attendance List* ya darasa na somo husika.
- Nyaraka zote hujazwa kiotomatiki na mkoa, wilaya, centre no, jina la shule, jina la mtihani, mwezi/mwaka na tarehe ya mtihani. CAL, ISAL na Sitting Plan zina nafasi za sahihi za mkuu wa shule/mtihani.
- **Score Progress per Subject** ina ukurasa wake (`admin/exam_progress.php`) unaofunguliwa kwa kitufe cha **Score Progress** kwenye mstari wa darasa; ukurasa huo una kitufe cha **Back to Exam View** na muhtasari wa masomo yaliyokamilika na yaliyobaki.
- Kwenye *Super → Exams →* fungua mtihani wa wilaya: masomo ya kila darasa yanaonekana kama vitufe. Kwenye kichwa cha jedwali la **Participants** upande wa kulia kuna kitufe cha **Registered Stats (all schools)** kinachopakua takwimu za pamoja za shule zote (safu moja kwa kila shule, idadi ya watahiniwa kwa kila somo, na jumla). Super anaweza pia kupakua nyaraka za shule moja kwa kuipitisha `school_id`; admin wa shule hulazimishwa kutumia shule yake mwenyewe.

## Kupakia alama (score_upload.php)
Kila ukurasa wa somo una njia tatu:
1. **Template & Excel:** pakua template ya somo (`.xlsx`), jaza safu ya **Score**, kisha pakia faili hiyo (`.xlsx` pekee).
   - Template inatumia format rasmi ya **iEPAS** (`storage/templates/iepas_score_template.xlsx`) yenye safu tano: `S/N`, `Name of Student`, `Sex`, `Index Number`, `Score`.
   - Kabla ya kupakuliwa, mfumo hujaza kiotomatiki sehemu zote zenye maneno **`JINA LA SHULE`**, **`JINA LA MTIHANI`**, **`JINA LA SOMO`** na **`KIDATO CHA MTIHANI`** kwa jina la shule, mtihani, somo na kidato husika. Safu za S/N, jina, jinsia na Index Number hujazwa kwa watahiniwa wote wa darasa; mwalimu huingiza alama kwenye safu ya **Score** pekee.
   - **Index Number** huchukuliwa kutoka `exam_candidates.cand_no`; kama haipo, mfumo hutumia `students.admission_no`. Wakati wa upload, safu ya `Index Number`, `Admission No` au `Candidate No` inakubalika.
   - Jedwali huishia kwenye **mwanafunzi wa mwisho** wa darasa; safu zote tupu za chini zinaondolewa, hivyo hakuna mistari au kurasa tupu (mfano: wanafunzi 20 → mistari 7–26; wanafunzi 5 → mistari 7–11).
   - Usibadilisha vichwa vya safu wala kuongeza safu mpya; faili lazima liwe `.xlsx` na si zaidi ya 5MB.
2. **Scan Picha (OCR):** piga picha ya template iliyojazwa au chagua picha iliyopo. Usomaji hufanyika ndani ya kivinjari (Tesseract.js), kisha mfumo hukuonyesha jedwali la ukaguzi lililojaa alama zilizoonekana. **Hakikisha kila alama kabla ya kuhifadhi** — OCR ya maandishi ya mkono inaweza kukosea. Picha haihifadhiwi kwenye server. *Inahitaji mtandao* kwa mara ya kwanza ili kupakua injini ya OCR kutoka CDN.
3. **Jaza Moja kwa Moja:** andika Admission No na alama moja baada ya nyingine.

## Kureset nenosiri / PIN
| Nani | Njia |
|---|---|
| Walimu, admin wa shule, super | **Self-service:** login → *Umesahau nenosiri?* → weka barua pepe/Cheque Number → kiungo cha kureset (kinatumika mara moja, dakika 60, max maombi 3 kwa saa) kinatumwa kwa email ya akaunti. |
| Mwalimu / admin wa shule asiye na email | Admin wa shule: *Teachers & Users → Reset nenosiri* (linatengeneza nenosiri la muda linaloonyeshwa mara moja). Admin wa shule: super akiingia shuleni. Super mwingine: *Settings → Reset nenosiri*. |
| Mwanafunzi (hana email) | Admin wa shule: *Students → Reset PIN* (PIN ya muda ya tarakimu 6 inaonyeshwa mara moja). |
Nenosiri/PIN za muda **hulazimisha kubadilishwa** mtumiaji akiingia mara ya kwanza. Kiungo cha email hakifichui kama akaunti ipo (jibu ni lilelile).

**Barua pepe (XAMPP):** `mail()` ya XAMPP kwa kawaida haitumi barua halisi. Chaguo: (a) weka SMTP kwenye `config.local.php` (mfano Gmail + App Password, inahitaji `composer install`), au (b) kujaribu tu: kiungo cha kureset kinaandikwa kwenye `storage/mail.log` (folda imezuiwa kufikiwa kwa mtandao). Weka pia `APP_URL` kwenye `config.local.php` ili viungo vya barua visitegemee kichwa cha ombi.

**Kwa mfumo ulioshasakinishwa:** endesha `database/migration_password_reset.sql` mara moja, kisha nakili faili za `password_reset_update.zip`.

## Matokeo ya wilaya (council)
Kwa mtihani na darasa: kila shule inayoshiriki, idadi ya watahiniwa, mgawanyo wa Division I/II/III/IV/0, INC/ABS, **wastani wa points** na **% ya waliofaulu (I-IV)**; shule hupangwa kwa wastani mdogo wa points (ikilingana, Division I nyingi zaidi). Ni kipimo cha mfumo huu (si "GPA ya shule" rasmi ya NECTA). Export: PDF/Excel/Print.

## Uchambuzi wa Matokeo (Results Analysis)
`super/results_analysis.php` ni ukurasa wa muhtasari: takwimu za mtihani (shule zilizochapisha, watahiniwa, madarasa, masomo, GPA ya jumla, kiwango cha ufaulu), mgawanyo wa Division I–IV/0 pamoja na INC/ABS, na kadi za kila sehemu. Kila sehemu ina ukurasa wake wenye kitufe cha **Back to Results Analysis**, kichaguzi cha mtihani, **Print**, na **Export PDF** ya sehemu hiyo pekee. Data yote hutengenezwa na `lib/results_analysis.php`.

| Ukurasa | Sehemu |
| --- | --- |
| `results_schools.php` | Schools Ranking |
| `results_wards.php` | Wards Ranking |
| `results_districts.php` | Districts Ranking |
| `results_regions.php` | Regions Ranking |
| `results_subjects.php` | Subjects Ranking |
| `results_subject_analysis.php` | Overall Subjects Analysis |
| `results_grades.php` | Grades Analysis Subjectwise |
| `results_teachers.php` | Overall Teachers Ranking |
| `results_top_students.php` | Overall Ten Students |
| `results_top_students_subjectwise.php` | Overall Ten Students Subjectwise |
| `results_teachers_subjectwise.php` | Teachers' Ranking Subjectwise |
| `results_general.php` | General schools' Results |

Ukurasa wa muhtasari pia una **Export PDF** inayozalisha nyaraka yote kwa mpigo. Sehemu zinafunguka tu kwa mitihani yenye matokeo yaliyochapishwa.

## Muonekano wa kurasa za super (UI)
`super/schools.php`, `super/classes.php`, `super/subjects.php`, `super/grading.php` na `super/settings.php` zimeundwa upya kwa Kiingereza na muonekano wa kisasa unaoshirikiwa:
- Hero ya rangi ya bluu (gradient) yenye kichwa, maelezo na kitufe cha hatua.
- Kadi za takwimu (stat cards) zinazoonyesha jumla, hai/zilizozimwa, wanafunzi na viungo vya masomo.
- Upau wa vichujio (tafuta, mkoa, wilaya) na jedwali la kisasa lenye avatars/chips, status pills na vitendo vya icon.
- Fomu ya "New/Edit" inayobaki (sticky) kando ya jedwali, ikiwa na **live preview**, kadi za kuchagua level (O-Level/A-Level) na namba ya mpangilio inayopendekezwa kiotomatiki, pamoja na ujumbe wa mafanikio/makosa kwa Kiingereza.
- Ukurasa wa Grading una kadi mbili za level (O-Level kijani, A-Level zambarau) zenye jedwali linalohesabu **Range** papo hapo, kitufe cha Add grade, Save na Reset NECTA, pamoja na kadi ya sheria za division za NECTA.
- Ukurasa wa Settings & Years una hero yenye mwaka wa sasa, kadi 4 za takwimu, fomu ya jina la mfumo yenye **live preview**, orodha ya miaka ya masomo yenye "Make current", na usimamizi wa super admins (avatar, status, reset password, na fomu ya kuongeza).
- Ukurasa wa **My Account** (`profile.php`) una hero yenye jina la mtumiaji aliyengia, kadi ya taarifa za akaunti (avatar, role, username/PREMS, shule), na fomu ya kubadilisha nenosiri/PIN yenye show/hide, **strength meter** na checklist ya masharti; mtumiaji aliyewekewa nenosiri la muda anapata kibonge cha hatua za lazima.
- Mitindo ya kuchapisha (print) inaficha vitendo na fomu.

Muonekano huo huo unatumika pia kwenye `admin/students.php`: hero, kadi 5 za takwimu (jumla, active, female, male, PIN), vichujio vya darasa/hali/tafuta, jedwali lenye avatar, PREMS chip, PIN na status pills, vitendo vya icon, na pagination.

Lugha ya menyu ya jumla (sidebar/topbar) inabaki Kiswahili; kurasa binafsi tu ndizo zilizotafsiriwa.

## Sheria za NECTA (gredi zinaweza kubadilishwa na super: Grading)
- O-Level: A≥75, B≥65, C≥45, D≥30, F. Division kwa masomo 7 bora: I 7-17, II 18-21, III 22-25, IV 26-33, 0 34-35.
- A-Level: A≥80, B≥70, C≥60, D≥50, E≥40, S≥35, F. Division kwa masomo 3 bora ya principal: I 3-9, II 10-12, III 13-17, IV 18-19, 0 20-21. Subsidiary (GS, BAM): S/F tu.
- Pungufu ya masomo yanayohitajika → INC; hakufanya somo lolote → ABS. Muundo unafanana na NECTA; si hati rasmi ya Baraza.

## Muundo wa folda
```
config.php, install.php, login.php, logout.php, profile.php, index.php
scores.php, score_upload.php, results.php, export.php     (shule iliyo hai)
super/    dashboard, schools, school_form, exams, exam_form, exam_view, council, classes, class_subjects, subjects, grading, settings,
          results_analysis (muhtasari), results_schools, results_wards, results_districts, results_regions, results_subjects,
          results_subject_analysis, results_grades, results_teachers, results_top_students, results_top_students_subjectwise,
          results_teachers_subjectwise, results_general
admin/    dashboard, students, student_form, student_import, student_pins, teachers, assignments, exams, exam_form, exam_view, exam_progress, exam_download, settings
teacher/  dashboard, results_list
teaching/ index, timetable, timetable_new, scheme, logbook, syllabus
student/  dashboard, report
lib/      bootstrap, grading, render, export, exam_docs, results_analysis, layout, teaching, timetable
assets/   img/iepas-logo.png
database/ schema.sql, sample_data.sql, migration_password_reset.sql, migration_teaching.sql, migration_streams.sql, migration_exam_subjects.sql, migration_assignment_dates.sql, migration_user_positions.sql
```

## Usalama na mipaka ya data
Kila query ya wanafunzi, walimu, watahiniwa na alama inachujwa kwa shule ya mtumiaji; admin/teacher hawawezi kuona shule nyingine; mwanafunzi huona ripoti yake tu na ikiwa imechapishwa. Hash ya nenosiri/PIN, CSRF, kufunga akaunti baada ya majaribio 5, `install.php` hujifunga. Tumia HTTPS na weka `DB_PASS` halisi kwenye `config.local.php` (sio `config.php`) kabla ya kuweka mtandaoni.

## Mapungufu
- Madarasa, masomo na gredi ni vya pamoja kwa shule zote (hudhibitiwa na super); shule haziwezi kuwa na gredi tofauti.
- Mwanafunzi anayehama shule hahamishwi kiotomatiki (tengeneza rekodi mpya kwenye shule mpya).
- Mazingira ya kuandikwa hayakuwa na PHP/MySQL, kwa hiyo msimbo haujaendeshwa; jaribu kwanza kwa `sample_data.sql`.
