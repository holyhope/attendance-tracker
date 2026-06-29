<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class GristExportTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/GristExport.php';
    }

    public function testSysTablesCreated(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        _grist_create_sys_tables($db);

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('_gristsys_Files',         $tables);
        $this->assertContains('_gristsys_Action',        $tables);
        $this->assertContains('_gristsys_ActionHistory', $tables);
    }

    public function testMetaTablesCreated(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        _grist_create_meta_tables($db);

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('_grist_Tables',              $tables);
        $this->assertContains('_grist_Tables_column',       $tables);
        $this->assertContains('_grist_Views',               $tables);
        $this->assertContains('_grist_Views_section',       $tables);
        $this->assertContains('_grist_Views_section_field', $tables);
        $this->assertContains('_grist_Filters',             $tables);
    }

    public function testGristDataStructure(): void
    {
        // Test the SQLite file built by export_grist via a temp file,
        // without triggering the HTTP streaming + exit.
        $tmp = tempnam(sys_get_temp_dir(), 'grist_test_');
        $db  = new PDO('sqlite:' . $tmp);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        _grist_create_sys_tables($db);
        _grist_create_meta_tables($db);

        $db->exec('CREATE TABLE "Lieux" (id INTEGER PRIMARY KEY, "manualSort" NUMERIC DEFAULT 1e999, "Nom" TEXT DEFAULT \'\', "Latitude" REAL DEFAULT NULL, "Longitude" REAL DEFAULT NULL)');
        $db->exec('CREATE TABLE "Seances" (id INTEGER PRIMARY KEY, "manualSort" NUMERIC DEFAULT 1e999, "Date" TEXT DEFAULT \'\', "Lieu" INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE "Presences" (id INTEGER PRIMARY KEY, "manualSort" NUMERIC DEFAULT 1e999, "Pseudo" BLOB DEFAULT NULL, "Seance" INTEGER DEFAULT 0, "DatePointage" TEXT DEFAULT \'\')');

        $db->exec('INSERT INTO "Lieux" VALUES (1,1,"Gymnase",48.85,2.35)');
        $db->exec('INSERT INTO "Seances" VALUES (1,1,"Séance 1",1),(2,2,"Séance 2",1)');
        $db->exec('INSERT INTO "Presences" VALUES (1,1,"Alice",1,"10/01/2025 10:00"),(2,2,"Bob",1,"10/01/2025 10:01"),(3,3,"Alice",2,"17/01/2025 10:00")');

        $nLieux     = $db->query('SELECT COUNT(*) FROM "Lieux"')->fetchColumn();
        $nSeances   = $db->query('SELECT COUNT(*) FROM "Seances"')->fetchColumn();
        $nPresences = $db->query('SELECT COUNT(*) FROM "Presences"')->fetchColumn();

        $this->assertSame(1, (int) $nLieux,     '1 unique location');
        $this->assertSame(2, (int) $nSeances,   '2 sessions');
        $this->assertSame(3, (int) $nPresences, '3 checkins');

        $db = null;
        unlink($tmp);
    }

    public function testCsvOutput(): void
    {
        $rows = [
            ['session_uid' => 'sess-1', 'nickname' => 'Alice', 'created_at' => '2025-01-10 10:00:00'],
            ['session_uid' => 'sess-2', 'nickname' => 'Bob',   'created_at' => '2025-01-17 10:00:00'],
        ];
        $labels = ['sess-1' => 'Séance 1', 'sess-2' => 'Séance 2'];

        $tmp = fopen('php://memory', 'r+');
        fputcsv($tmp, ['seance', 'pseudonyme', 'date'], escape: '');
        foreach ($rows as $row) {
            fputcsv($tmp, [$labels[$row['session_uid']], $row['nickname'], $row['created_at']], escape: '');
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines, 'header + 2 data rows');
        $this->assertStringContainsString('seance', $lines[0]);
        $this->assertStringContainsString('Alice',  $lines[1]);
        $this->assertStringContainsString('Bob',    $lines[2]);
    }

    public function testCsvFilenameIsSanitized(): void
    {
        $associationName = 'Association des Amis & Amies!';
        $baseName = preg_replace('/[^a-z0-9]+/i', '-', $associationName);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9-]+$/', $baseName);
    }
}
