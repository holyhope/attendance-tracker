<?php
declare(strict_types=1);

/**
 * Streams a .grist (SQLite) file download for a list of checkin rows.
 * Each row must have: nickname (string), created_at (string datetime).
 */
function export_grist(array $rows, string $sessionUid): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'grist_');
    $db  = new PDO('sqlite:' . $tmp);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── _gristsys tables ──────────────────────────────────────────────────────
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
    $db->exec("INSERT INTO _gristsys_ActionHistoryBranch (id,name,actionRef) VALUES
        (1,'shared',0),(2,'local_sent',0),(3,'local_unsent',0)");

    // ── _grist metadata tables ────────────────────────────────────────────────
    $db->exec('
        CREATE TABLE IF NOT EXISTS "_grist_DocInfo" (
            id INTEGER PRIMARY KEY, "docId" TEXT DEFAULT \'\', "peers" TEXT DEFAULT \'\',
            "basketId" TEXT DEFAULT \'\', "schemaVersion" INTEGER DEFAULT 0,
            "timezone" TEXT DEFAULT \'\', "documentSettings" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_Tables" (
            id INTEGER PRIMARY KEY, "tableId" TEXT DEFAULT \'\',
            "primaryViewId" INTEGER DEFAULT 0, "summarySourceTable" INTEGER DEFAULT 0,
            "onDemand" BOOLEAN DEFAULT 0, "rawViewSectionRef" INTEGER DEFAULT 0,
            "recordCardViewSectionRef" INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_Tables_column" (
            id INTEGER PRIMARY KEY, "parentId" INTEGER DEFAULT 0,
            "parentPos" NUMERIC DEFAULT 1e999, "colId" TEXT DEFAULT \'\',
            "type" TEXT DEFAULT \'\', "widgetOptions" TEXT DEFAULT \'\',
            "isFormula" BOOLEAN DEFAULT 0, "formula" TEXT DEFAULT \'\',
            "label" TEXT DEFAULT \'\', "description" TEXT DEFAULT \'\',
            "untieColIdFromLabel" BOOLEAN DEFAULT 0, "summarySourceCol" INTEGER DEFAULT 0,
            "displayCol" INTEGER DEFAULT 0, "visibleCol" INTEGER DEFAULT 0,
            "rules" TEXT DEFAULT NULL, "reverseCol" INTEGER DEFAULT 0,
            "recalcWhen" INTEGER DEFAULT 0, "recalcDeps" TEXT DEFAULT NULL);
        CREATE TABLE IF NOT EXISTS "_grist_Views" (
            id INTEGER PRIMARY KEY, "name" TEXT DEFAULT \'\',
            "type" TEXT DEFAULT \'\', "layoutSpec" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_Views_section" (
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
        CREATE TABLE IF NOT EXISTS "_grist_Views_section_field" (
            id INTEGER PRIMARY KEY, "parentId" INTEGER DEFAULT 0,
            "parentPos" NUMERIC DEFAULT 1e999, "colRef" INTEGER DEFAULT 0,
            "width" INTEGER DEFAULT 0, "widgetOptions" TEXT DEFAULT \'\',
            "displayCol" INTEGER DEFAULT 0, "visibleCol" INTEGER DEFAULT 0,
            "filter" TEXT DEFAULT \'\', "rules" TEXT DEFAULT NULL);
        CREATE TABLE IF NOT EXISTS "_grist_TabBar" (
            id INTEGER PRIMARY KEY, "viewRef" INTEGER DEFAULT 0,
            "tabPos" NUMERIC DEFAULT 1e999);
        CREATE TABLE IF NOT EXISTS "_grist_Pages" (
            id INTEGER PRIMARY KEY, "viewRef" INTEGER DEFAULT 0,
            "indentation" INTEGER DEFAULT 0, "pagePos" NUMERIC DEFAULT 1e999,
            "shareRef" INTEGER DEFAULT 0, "options" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_Imports" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "origFileName" TEXT DEFAULT \'\', "parseFormula" TEXT DEFAULT \'\',
            "delimiter" TEXT DEFAULT \'\', "doublequote" BOOLEAN DEFAULT 0,
            "escapechar" TEXT DEFAULT \'\', "quotechar" TEXT DEFAULT \'\',
            "skipinitialspace" BOOLEAN DEFAULT 0, "encoding" TEXT DEFAULT \'\',
            "hasHeaders" BOOLEAN DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_External_database" (
            id INTEGER PRIMARY KEY, "host" TEXT DEFAULT \'\', "port" INTEGER DEFAULT 0,
            "username" TEXT DEFAULT \'\', "dialect" TEXT DEFAULT \'\',
            "database" TEXT DEFAULT \'\', "storage" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_External_table" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "databaseRef" INTEGER DEFAULT 0, "tableName" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_TableViews" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0, "viewRef" INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_TabItems" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0, "viewRef" INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_Validations" (
            id INTEGER PRIMARY KEY, "formula" TEXT DEFAULT \'\',
            "name" TEXT DEFAULT \'\', "tableRef" INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_REPL_Hist" (
            id INTEGER PRIMARY KEY, "code" TEXT DEFAULT \'\',
            "outputText" TEXT DEFAULT \'\', "errorText" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_Attachments" (
            id INTEGER PRIMARY KEY, "fileIdent" TEXT DEFAULT \'\',
            "fileName" TEXT DEFAULT \'\', "fileType" TEXT DEFAULT \'\',
            "fileSize" INTEGER DEFAULT 0, "fileExt" TEXT DEFAULT \'\',
            "imageHeight" INTEGER DEFAULT 0, "imageWidth" INTEGER DEFAULT 0,
            "timeDeleted" DATETIME DEFAULT NULL, "timeUploaded" DATETIME DEFAULT NULL);
        CREATE TABLE IF NOT EXISTS "_grist_Triggers" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "eventTypes" TEXT DEFAULT NULL, "isReadyColRef" INTEGER DEFAULT 0,
            "actions" TEXT DEFAULT \'\', "label" TEXT DEFAULT \'\',
            "memo" TEXT DEFAULT \'\', "enabled" BOOLEAN DEFAULT 0,
            "watchedColRefList" TEXT DEFAULT NULL, "options" TEXT DEFAULT \'\',
            "condition" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_ACLRules" (
            id INTEGER PRIMARY KEY, "resource" INTEGER DEFAULT 0,
            "permissions" INTEGER DEFAULT 0, "principals" TEXT DEFAULT \'\',
            "aclFormula" TEXT DEFAULT \'\', "aclColumn" INTEGER DEFAULT 0,
            "aclFormulaParsed" TEXT DEFAULT \'\', "permissionsText" TEXT DEFAULT \'\',
            "rulePos" NUMERIC DEFAULT 1e999, "userAttributes" TEXT DEFAULT \'\',
            "memo" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_ACLResources" (
            id INTEGER PRIMARY KEY, "tableId" TEXT DEFAULT \'\', "colIds" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_ACLPrincipals" (
            id INTEGER PRIMARY KEY, "type" TEXT DEFAULT \'\', "userEmail" TEXT DEFAULT \'\',
            "userName" TEXT DEFAULT \'\', "groupName" TEXT DEFAULT \'\',
            "instanceId" TEXT DEFAULT \'\');
        CREATE TABLE IF NOT EXISTS "_grist_ACLMemberships" (
            id INTEGER PRIMARY KEY, "parent" INTEGER DEFAULT 0, "child" INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_Filters" (
            id INTEGER PRIMARY KEY, "viewSectionRef" INTEGER DEFAULT 0,
            "colRef" INTEGER DEFAULT 0, "filter" TEXT DEFAULT \'\',
            "pinned" BOOLEAN DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_Cells" (
            id INTEGER PRIMARY KEY, "tableRef" INTEGER DEFAULT 0,
            "colRef" INTEGER DEFAULT 0, "rowId" INTEGER DEFAULT 0,
            "root" BOOLEAN DEFAULT 0, "parentId" INTEGER DEFAULT 0,
            "type" INTEGER DEFAULT 0, "content" TEXT DEFAULT \'\',
            "userRef" TEXT DEFAULT \'\', "timeCreated" DATETIME DEFAULT NULL,
            "timeUpdated" DATETIME DEFAULT NULL, "resolved" BOOLEAN DEFAULT 0);
        CREATE TABLE IF NOT EXISTS "_grist_Shares" (
            id INTEGER PRIMARY KEY, "linkId" TEXT DEFAULT \'\',
            "options" TEXT DEFAULT \'\', "label" TEXT DEFAULT \'\',
            "description" TEXT DEFAULT \'\');
    ');

    // ── Document info ─────────────────────────────────────────────────────────
    $db->exec("INSERT INTO \"_grist_DocInfo\" (id, schemaVersion, timezone, documentSettings)
        VALUES (1, 46, 'Europe/Paris', '{\"locale\":\"fr-FR\",\"engine\":\"python3\"}')");

    // ── Table: Presences ──────────────────────────────────────────────────────
    // Sections: 1=primary view grid, 2=raw data, 3=record card
    $db->exec("INSERT INTO \"_grist_Tables\" (id, tableId, primaryViewId, rawViewSectionRef, recordCardViewSectionRef)
        VALUES (1, 'Presences', 1, 2, 3)");

    // Columns: 1=manualSort, 2=Pseudonyme, 3=Date
    $db->exec("INSERT INTO \"_grist_Tables_column\"
        (id, parentId, parentPos, colId, type, isFormula, label) VALUES
        (1, 1, 1, 'manualSort', 'ManualSortPos', 0, 'manualSort'),
        (2, 1, 2, 'Pseudonyme', 'Text',          0, 'Pseudonyme'),
        (3, 1, 3, 'Date',       'Text',          0, 'Date')");

    // View
    $db->exec("INSERT INTO \"_grist_Views\" (id, name, type) VALUES (1, 'Presences', 'raw_data')");

    // Sections
    $db->exec("INSERT INTO \"_grist_Views_section\"
        (id, tableRef, parentId, parentKey, defaultWidth, borderWidth) VALUES
        (1, 1, 1, 'record', 100, 1),
        (2, 1, 0, 'record', 100, 1),
        (3, 1, 0, 'single', 100, 1)");

    // Fields (cols 2 and 3 for each section)
    $db->exec("INSERT INTO \"_grist_Views_section_field\"
        (id, parentId, parentPos, colRef) VALUES
        (1, 1, 2, 2), (2, 1, 3, 3),
        (3, 2, 2, 2), (4, 2, 3, 3),
        (5, 3, 2, 2), (6, 3, 3, 3)");

    // Tab bar + pages
    $db->exec("INSERT INTO \"_grist_TabBar\" (id, viewRef, tabPos) VALUES (1, 1, 1)");
    $db->exec("INSERT INTO \"_grist_Pages\" (id, viewRef, indentation, pagePos) VALUES (1, 1, 0, 1)");

    // ── Data table ────────────────────────────────────────────────────────────
    $db->exec('CREATE TABLE "Presences" (
        id INTEGER PRIMARY KEY,
        "manualSort" NUMERIC DEFAULT 1e999,
        "Pseudonyme" BLOB DEFAULT NULL,
        "Date" BLOB DEFAULT NULL
    )');

    $stmt = $db->prepare('INSERT INTO "Presences" (id, manualSort, "Pseudonyme", "Date") VALUES (?, ?, ?, ?)');
    foreach ($rows as $i => $row) {
        $date = (new DateTimeImmutable($row['created_at']))->format('d/m/Y H:i');
        $stmt->execute([$i + 1, $i + 1, $row['nickname'], $date]);
    }

    $db = null; // close before reading

    $filename = 'presences-' . preg_replace('/[^a-z0-9_-]/i', '-', $sessionUid) . '.grist';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
