<?php
declare(strict_types=1);

/**
 * Streams a .grist (SQLite) file with:
 *   - Table Lieux    : one row per unique location  (Nom, Latitude, Longitude)
 *   - Table Seances  : one row per session  (Date, Lieu[Ref:Lieux])
 *   - Table Presences: one row per checkin  (Pseudo, Seance[Ref:Seances], DatePointage)
 *   - One page per session + one "Séances" page (with linked Lieux detail)
 *
 * @param array  $rows             Each row: session_uid, nickname, created_at
 * @param array  $sessionLabels    session_uid => human-readable label
 * @param array  $sessionLocations session_uid => address string
 * @param array  $sessionCoords    session_uid => ['lat' => float|null, 'lon' => float|null]
 * @param string $baseName         Sanitized association name used in the filename
 */
function export_grist(array $rows, array $sessionLabels, array $sessionLocations = [], array $sessionCoords = [], string $baseName = 'presences'): void
{
    // ── Group rows by session ─────────────────────────────────────────────────
    $sessionRows = [];
    foreach ($rows as $row) {
        $sessionRows[$row['session_uid']][] = $row;
    }
    $uids = array_keys($sessionLabels); // all calendar sessions
    $n    = count($uids);

    // ── Deduplicate locations → Lieux table ───────────────────────────────────
    $addressToLieuId = []; // address => lieu row id (1-based)
    $lieuId = 1;
    foreach ($uids as $uid) {
        $addr = $sessionLocations[$uid] ?? '';
        if ($addr !== '' && !isset($addressToLieuId[$addr])) {
            $addressToLieuId[$addr] = $lieuId++;
        }
    }
    $nLieux = count($addressToLieuId);

    // session_uid → Seances row id / Lieux row id
    $seanceRowId  = [];
    $sessionLieuId = [];
    foreach ($uids as $i => $uid) {
        $seanceRowId[$uid]  = $i + 1;
        $addr = $sessionLocations[$uid] ?? '';
        $sessionLieuId[$uid] = $addressToLieuId[$addr] ?? 0;
    }

    // ── View / section id layout ──────────────────────────────────────────────
    //
    // Views  1..N  : one per session (Presences filtered)
    // View   N+1   : Séances (grid + linked Lieux card)
    //
    // Sections:
    //  1 = Lieux raw data   (parentId=0,   tableRef=1)
    //  2 = Lieux card       (parentId=0,   tableRef=1, single)
    //  3 = Seances primary  (parentId=N+1, tableRef=2)
    //  4 = Seances raw data (parentId=0,   tableRef=2)
    //  5 = Seances card     (parentId=0,   tableRef=2, single)
    //  6 = Lieux linked     (parentId=N+1, tableRef=1, driven by section 3 via COL_S_LIEU)
    //  7 = Presences raw    (parentId=0,   tableRef=3)
    //  8 = Presences card   (parentId=0,   tableRef=3, single)
    //  9+i (i=0..N-1) = Presences per-session (parentId=i+1, tableRef=3)
    $viewSeances = $n + 1;

    // Column ids
    // Lieux    : 1=manualSort  2=Nom       3=Latitude    4=Longitude
    // Seances  : 5=manualSort  6=Date      7=Lieu(Ref)
    // Presences: 8=manualSort  9=Pseudo   10=Seance(Ref) 11=DatePointage
    $COL_L_NOM    = 2;
    $COL_L_LAT    = 3;
    $COL_L_LON    = 4;
    $COL_S_DATE   = 6;
    $COL_S_LIEU   = 7;
    $COL_P_PSEUDO = 9;
    $COL_P_SEANCE = 10;
    $COL_P_DATE   = 11;

    // ── Build SQLite file ─────────────────────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'grist_');
    $db  = new PDO('sqlite:' . $tmp);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    _grist_create_sys_tables($db);
    _grist_create_meta_tables($db);

    $db->exec("INSERT INTO \"_grist_DocInfo\" (id, schemaVersion, timezone, documentSettings)
        VALUES (1, 46, 'Europe/Paris', '{\"locale\":\"fr-FR\",\"engine\":\"python3\"}')");

    // ── Tables ───────────────────────────────────────────────────────────────
    $stmtTbl = $db->prepare("INSERT INTO \"_grist_Tables\"
        (id, tableId, primaryViewId, rawViewSectionRef, recordCardViewSectionRef)
        VALUES (?, ?, ?, ?, ?)");
    $stmtTbl->execute([1, 'Lieux',     0,            1, 2]);
    $stmtTbl->execute([2, 'Seances',   $viewSeances, 4, 5]);
    $stmtTbl->execute([3, 'Presences', 1,            7, 8]);

    // ── Columns ──────────────────────────────────────────────────────────────
    $stmtCol = $db->prepare("INSERT INTO \"_grist_Tables_column\"
        (id, parentId, parentPos, colId, type, isFormula, label, visibleCol)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?)");
    // Lieux
    $stmtCol->execute([ 1, 1, 1, 'manualSort',   'ManualSortPos', 'manualSort',       0]);
    $stmtCol->execute([ 2, 1, 2, 'Nom',           'Text',          'Nom',              0]);
    $stmtCol->execute([ 3, 1, 3, 'Latitude',      'Numeric',       'Latitude',         0]);
    $stmtCol->execute([ 4, 1, 4, 'Longitude',     'Numeric',       'Longitude',        0]);
    // Seances
    $stmtCol->execute([ 5, 2, 1, 'manualSort',   'ManualSortPos', 'manualSort',       0]);
    $stmtCol->execute([ 6, 2, 2, 'Date',          'Text',          'Date',             0]);
    $stmtCol->execute([ 7, 2, 3, 'Lieu',          'Ref:Lieux',     'Lieu',    $COL_L_NOM]);
    // Presences
    $stmtCol->execute([ 8, 3, 1, 'manualSort',   'ManualSortPos', 'manualSort',        0]);
    $stmtCol->execute([ 9, 3, 2, 'Pseudo',        'Text',          'Pseudo',            0]);
    $stmtCol->execute([10, 3, 3, 'Seance',        'Ref:Seances',   'Séance',  $COL_S_DATE]);
    $stmtCol->execute([11, 3, 4, 'DatePointage',  'Text',          'Date du pointage',  0]);

    // ── Views ────────────────────────────────────────────────────────────────
    $stmtView = $db->prepare("INSERT INTO \"_grist_Views\" (id, name, type, layoutSpec) VALUES (?, ?, ?, ?)");
    foreach ($uids as $i => $uid) {
        $viewId = $i + 1;
        $secId  = 9 + $i;
        $ls     = ($i === 0) ? '' : json_encode(['children' => [['leaf' => $secId]]]);
        $stmtView->execute([$viewId, $sessionLabels[$uid], ($i === 0) ? 'raw_data' : 'empty', $ls]);
    }
    $stmtView->execute([$viewSeances, 'Séances', 'raw_data', '']);

    // ── Sections ─────────────────────────────────────────────────────────────
    $stmtSec = $db->prepare("INSERT INTO \"_grist_Views_section\"
        (id, tableRef, parentId, parentKey, defaultWidth, borderWidth) VALUES (?, ?, ?, ?, 100, 1)");
    $stmtSec->execute([1, 1, 0,            'record']); // Lieux raw data
    $stmtSec->execute([2, 1, 0,            'single']); // Lieux card
    $stmtSec->execute([3, 2, $viewSeances, 'record']); // Seances primary
    $stmtSec->execute([4, 2, 0,            'record']); // Seances raw data
    $stmtSec->execute([5, 2, 0,            'single']); // Seances card
    $stmtSec->execute([6, 1, 0, 'record']); // Lieux linked (unused view slot)
    $stmtSec->execute([7, 3, 0,            'record']); // Presences raw data
    $stmtSec->execute([8, 3, 0,            'single']); // Presences card
    for ($i = 0; $i < $n; $i++) {
        $stmtSec->execute([9 + $i, 3, $i + 1, 'record']); // per-session
    }

    // ── Fields ───────────────────────────────────────────────────────────────
    $stmtFld = $db->prepare("INSERT INTO \"_grist_Views_section_field\"
        (id, parentId, parentPos, colRef) VALUES (?, ?, ?, ?)");
    $fid = 1;
    // Lieux raw + card (1,2): Nom + Latitude + Longitude
    foreach ([1, 2] as $secId) {
        $stmtFld->execute([$fid++, $secId, 2, $COL_L_NOM]);
        $stmtFld->execute([$fid++, $secId, 3, $COL_L_LAT]);
        $stmtFld->execute([$fid++, $secId, 4, $COL_L_LON]);
    }
    // Seances primary + raw + card (3,4,5): Date + Lieu
    foreach ([3, 4, 5] as $secId) {
        $stmtFld->execute([$fid++, $secId, 2, $COL_S_DATE]);
        $stmtFld->execute([$fid++, $secId, 3, $COL_S_LIEU]);
    }
    // Presences raw + card (7,8): Pseudo + Seance + DatePointage
    foreach ([7, 8] as $secId) {
        $stmtFld->execute([$fid++, $secId, 2, $COL_P_PSEUDO]);
        $stmtFld->execute([$fid++, $secId, 3, $COL_P_SEANCE]);
        $stmtFld->execute([$fid++, $secId, 4, $COL_P_DATE]);
    }
    // Per-session sections: Pseudo + DatePointage
    for ($i = 0; $i < $n; $i++) {
        $stmtFld->execute([$fid++, 9 + $i, 2, $COL_P_PSEUDO]);
        $stmtFld->execute([$fid++, 9 + $i, 3, $COL_P_DATE]);
    }

    // ── TabBar + Pages ────────────────────────────────────────────────────────
    $stmtTab  = $db->prepare("INSERT INTO \"_grist_TabBar\" (id, viewRef, tabPos) VALUES (?,?,?)");
    $stmtPage = $db->prepare("INSERT INTO \"_grist_Pages\" (id, viewRef, indentation, pagePos) VALUES (?,?,0,?)");
    for ($i = 1; $i <= $n + 1; $i++) {
        $stmtTab->execute([$i, $i, $i]);
        $stmtPage->execute([$i, $i, $i]);
    }

    // ── Data ─────────────────────────────────────────────────────────────────
    $db->exec('CREATE TABLE "Lieux" (
        id INTEGER PRIMARY KEY,
        "manualSort" NUMERIC DEFAULT 1e999,
        "Nom"       TEXT DEFAULT \'\',
        "Latitude"  REAL DEFAULT NULL,
        "Longitude" REAL DEFAULT NULL
    )');
    $db->exec('CREATE TABLE "Seances" (
        id INTEGER PRIMARY KEY,
        "manualSort" NUMERIC DEFAULT 1e999,
        "Date"  TEXT    DEFAULT \'\',
        "Lieu"  INTEGER DEFAULT 0
    )');
    $db->exec('CREATE TABLE "Presences" (
        id INTEGER PRIMARY KEY,
        "manualSort"   NUMERIC DEFAULT 1e999,
        "Pseudo"       BLOB    DEFAULT NULL,
        "Seance"       INTEGER DEFAULT 0,
        "DatePointage" TEXT    DEFAULT \'\'
    )');

    $insLieu = $db->prepare('INSERT INTO "Lieux" (id, manualSort, "Nom", "Latitude", "Longitude") VALUES (?,?,?,?,?)');
    foreach ($addressToLieuId as $addr => $lid) {
        $coords = null;
        foreach ($uids as $uid) {
            if (($sessionLocations[$uid] ?? '') === $addr) {
                $coords = $sessionCoords[$uid] ?? null;
                break;
            }
        }
        $insLieu->execute([$lid, $lid, $addr, $coords['lat'] ?? null, $coords['lon'] ?? null]);
    }

    $insSeance = $db->prepare('INSERT INTO "Seances" (id, manualSort, "Date", "Lieu") VALUES (?,?,?,?)');
    foreach ($uids as $i => $uid) {
        $insSeance->execute([$i + 1, $i + 1, $sessionLabels[$uid], $sessionLieuId[$uid]]);
    }

    $rowId   = 1;
    $insPres = $db->prepare('INSERT INTO "Presences" (id, manualSort, "Pseudo", "Seance", "DatePointage") VALUES (?,?,?,?,?)');
    foreach ($uids as $uid) {
        foreach ($sessionRows[$uid] ?? [] as $row) {
            $date = (new DateTimeImmutable($row['created_at']))->format('d/m/Y H:i');
            $insPres->execute([$rowId, $rowId, $row['nickname'], $seanceRowId[$uid], $date]);
            $rowId++;
        }
    }

    // ── Filters: each session section shows only its seance ───────────────────
    $stmtFilter = $db->prepare("INSERT INTO \"_grist_Filters\"
        (id, viewSectionRef, colRef, filter, pinned) VALUES (?,?,?,?,1)");
    for ($i = 0; $i < $n; $i++) {
        $stmtFilter->execute([
            $i + 1,
            9 + $i,
            $COL_P_SEANCE,
            json_encode(['included' => [$i + 1]]),
        ]);
    }

    $db = null;

    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"{$baseName}-presences.grist\"");
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function _grist_create_sys_tables(PDO $db): void
{
    $db->exec('
        CREATE TABLE _gristsys_Files (
            id INTEGER PRIMARY KEY, ident TEXT UNIQUE, data BLOB, storageId TEXT);
        CREATE TABLE _gristsys_Action (
            id INTEGER PRIMARY KEY, "actionNum" BLOB DEFAULT 0, "time" BLOB DEFAULT 0,
            "user" BLOB DEFAULT \'\', "desc" BLOB DEFAULT \'\', "otherId" BLOB DEFAULT 0,
            "linkId" BLOB DEFAULT 0, "json" BLOB DEFAULT \'\');
        CREATE TABLE _gristsys_Action_step (
            id INTEGER PRIMARY KEY, "actionRef" INTEGER DEFAULT 0, "step" INTEGER DEFAULT 0,
            "doBundleIdx" INTEGER DEFAULT 0, "undoBundleIdx" INTEGER DEFAULT 0);
        CREATE TABLE _gristsys_ActionHistory (
            id INTEGER PRIMARY KEY, actionHash TEXT UNIQUE,
            parentRef INTEGER, actionNum INTEGER, body BLOB);
        CREATE TABLE _gristsys_ActionHistoryBranch (
            id INTEGER PRIMARY KEY, name TEXT UNIQUE, actionRef INTEGER);
        CREATE TABLE _gristsys_FileInfo (
            id INTEGER PRIMARY KEY, ident TEXT UNIQUE, storageId TEXT, uploaded INTEGER);
        CREATE TABLE _gristsys_PluginData (
            id INTEGER PRIMARY KEY, pluginId TEXT DEFAULT \'\',
            key TEXT DEFAULT \'\', value TEXT DEFAULT \'\');
    ');
    $db->exec("INSERT INTO _gristsys_ActionHistoryBranch (id, name, actionRef) VALUES
        (1,'shared',0),(2,'local_sent',0),(3,'local_unsent',0)");
}

function _grist_create_meta_tables(PDO $db): void
{
    $db->exec('
        CREATE TABLE "_grist_DocInfo" (
            id INTEGER PRIMARY KEY, "docId" TEXT DEFAULT \'\', "peers" TEXT DEFAULT \'\',
            "basketId" TEXT DEFAULT \'\', "schemaVersion" INTEGER DEFAULT 0,
            "timezone" TEXT DEFAULT \'\', "documentSettings" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_Tables" (
            id INTEGER PRIMARY KEY, "tableId" TEXT DEFAULT \'\',
            "primaryViewId" INTEGER DEFAULT 0, "summarySourceTable" INTEGER DEFAULT 0,
            "onDemand" BOOLEAN DEFAULT 0, "rawViewSectionRef" INTEGER DEFAULT 0,
            "recordCardViewSectionRef" INTEGER DEFAULT 0);
        CREATE TABLE "_grist_Tables_column" (
            id INTEGER PRIMARY KEY, "parentId" INTEGER DEFAULT 0,
            "parentPos" NUMERIC DEFAULT 1e999, "colId" TEXT DEFAULT \'\',
            "type" TEXT DEFAULT \'\', "widgetOptions" TEXT DEFAULT \'\',
            "isFormula" BOOLEAN DEFAULT 0, "formula" TEXT DEFAULT \'\',
            "label" TEXT DEFAULT \'\', "description" TEXT DEFAULT \'\',
            "untieColIdFromLabel" BOOLEAN DEFAULT 0, "summarySourceCol" INTEGER DEFAULT 0,
            "displayCol" INTEGER DEFAULT 0, "visibleCol" INTEGER DEFAULT 0,
            "rules" TEXT DEFAULT NULL, "reverseCol" INTEGER DEFAULT 0,
            "recalcWhen" INTEGER DEFAULT 0, "recalcDeps" TEXT DEFAULT NULL);
        CREATE TABLE "_grist_Views" (
            id INTEGER PRIMARY KEY, "name" TEXT DEFAULT \'\',
            "type" TEXT DEFAULT \'\', "layoutSpec" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_Views_section" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "parentId" INTEGER DEFAULT 0, "parentKey" TEXT DEFAULT \'\',
            "title" TEXT DEFAULT \'\', "description" TEXT DEFAULT \'\',
            "defaultWidth" INTEGER DEFAULT 0, "borderWidth" INTEGER DEFAULT 0,
            "theme" TEXT DEFAULT \'\', "options" TEXT DEFAULT \'\',
            "chartType" TEXT DEFAULT \'\', "layoutSpec" TEXT DEFAULT \'\',
            "filterSpec" TEXT DEFAULT \'\', "sortColRefs" TEXT DEFAULT \'\',
            "linkSrcSectionRef" INTEGER DEFAULT 0, "linkSrcColRef" INTEGER DEFAULT 0,
            "linkTargetColRef" INTEGER DEFAULT 0, "embedId" TEXT DEFAULT \'\',
            "rules" TEXT DEFAULT NULL, "shareOptions" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_Views_section_field" (
            id INTEGER PRIMARY KEY, "parentId" INTEGER DEFAULT 0,
            "parentPos" NUMERIC DEFAULT 1e999, "colRef" INTEGER DEFAULT 0,
            "width" INTEGER DEFAULT 0, "widgetOptions" TEXT DEFAULT \'\',
            "displayCol" INTEGER DEFAULT 0, "visibleCol" INTEGER DEFAULT 0,
            "filter" TEXT DEFAULT \'\', "rules" TEXT DEFAULT NULL);
        CREATE TABLE "_grist_TabBar" (
            id INTEGER PRIMARY KEY, "viewRef" INTEGER DEFAULT 0,
            "tabPos" NUMERIC DEFAULT 1e999);
        CREATE TABLE "_grist_Pages" (
            id INTEGER PRIMARY KEY, "viewRef" INTEGER DEFAULT 0,
            "indentation" INTEGER DEFAULT 0, "pagePos" NUMERIC DEFAULT 1e999,
            "shareRef" INTEGER DEFAULT 0, "options" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_Imports" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "origFileName" TEXT DEFAULT \'\', "parseFormula" TEXT DEFAULT \'\',
            "delimiter" TEXT DEFAULT \'\', "doublequote" BOOLEAN DEFAULT 0,
            "escapechar" TEXT DEFAULT \'\', "quotechar" TEXT DEFAULT \'\',
            "skipinitialspace" BOOLEAN DEFAULT 0, "encoding" TEXT DEFAULT \'\',
            "hasHeaders" BOOLEAN DEFAULT 0);
        CREATE TABLE "_grist_External_database" (
            id INTEGER PRIMARY KEY, "host" TEXT DEFAULT \'\', "port" INTEGER DEFAULT 0,
            "username" TEXT DEFAULT \'\', "dialect" TEXT DEFAULT \'\',
            "database" TEXT DEFAULT \'\', "storage" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_External_table" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "databaseRef" INTEGER DEFAULT 0, "tableName" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_TableViews" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0, "viewRef" INTEGER DEFAULT 0);
        CREATE TABLE "_grist_TabItems" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0, "viewRef" INTEGER DEFAULT 0);
        CREATE TABLE "_grist_Validations" (
            id INTEGER PRIMARY KEY, "formula" TEXT DEFAULT \'\',
            "name" TEXT DEFAULT \'\', "tableRef" INTEGER DEFAULT 0);
        CREATE TABLE "_grist_REPL_Hist" (
            id INTEGER PRIMARY KEY, "code" TEXT DEFAULT \'\',
            "outputText" TEXT DEFAULT \'\', "errorText" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_Attachments" (
            id INTEGER PRIMARY KEY, "fileIdent" TEXT DEFAULT \'\',
            "fileName" TEXT DEFAULT \'\', "fileType" TEXT DEFAULT \'\',
            "fileSize" INTEGER DEFAULT 0, "fileExt" TEXT DEFAULT \'\',
            "imageHeight" INTEGER DEFAULT 0, "imageWidth" INTEGER DEFAULT 0,
            "timeDeleted" DATETIME DEFAULT NULL, "timeUploaded" DATETIME DEFAULT NULL);
        CREATE TABLE "_grist_Triggers" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "eventTypes" TEXT DEFAULT NULL, "isReadyColRef" INTEGER DEFAULT 0,
            "actions" TEXT DEFAULT \'\', "label" TEXT DEFAULT \'\',
            "memo" TEXT DEFAULT \'\', "enabled" BOOLEAN DEFAULT 0,
            "watchedColRefList" TEXT DEFAULT NULL, "options" TEXT DEFAULT \'\',
            "condition" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_ACLRules" (
            id INTEGER PRIMARY KEY, "resource" INTEGER DEFAULT 0,
            "permissions" INTEGER DEFAULT 0, "principals" TEXT DEFAULT \'\',
            "aclFormula" TEXT DEFAULT \'\', "aclColumn" INTEGER DEFAULT 0,
            "aclFormulaParsed" TEXT DEFAULT \'\', "permissionsText" TEXT DEFAULT \'\',
            "rulePos" NUMERIC DEFAULT 1e999, "userAttributes" TEXT DEFAULT \'\',
            "memo" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_ACLResources" (
            id INTEGER PRIMARY KEY, "tableId" TEXT DEFAULT \'\', "colIds" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_ACLPrincipals" (
            id INTEGER PRIMARY KEY, "type" TEXT DEFAULT \'\', "userEmail" TEXT DEFAULT \'\',
            "userName" TEXT DEFAULT \'\', "groupName" TEXT DEFAULT \'\',
            "instanceId" TEXT DEFAULT \'\');
        CREATE TABLE "_grist_ACLMemberships" (
            id INTEGER PRIMARY KEY, "parent" INTEGER DEFAULT 0, "child" INTEGER DEFAULT 0);
        CREATE TABLE "_grist_Filters" (
            id INTEGER PRIMARY KEY, "viewSectionRef" INTEGER DEFAULT 0,
            "colRef" INTEGER DEFAULT 0, "filter" TEXT DEFAULT \'\',
            "pinned" BOOLEAN DEFAULT 0);
        CREATE TABLE "_grist_Cells" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "colRef" INTEGER DEFAULT 0, "rowId" INTEGER DEFAULT 0,
            "root" BOOLEAN DEFAULT 0, "parentId" INTEGER DEFAULT 0,
            "type" INTEGER DEFAULT 0, "content" TEXT DEFAULT \'\',
            "userRef" TEXT DEFAULT \'\', "timeCreated" DATETIME DEFAULT NULL,
            "timeUpdated" DATETIME DEFAULT NULL, "resolved" BOOLEAN DEFAULT 0);
        CREATE TABLE "_grist_Shares" (
            id INTEGER PRIMARY KEY, "linkId" TEXT DEFAULT \'\',
            "options" TEXT DEFAULT \'\', "label" TEXT DEFAULT \'\',
            "description" TEXT DEFAULT \'\');
    ');
}
