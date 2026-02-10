<?php
declare(strict_types=1);

namespace Ksfraser\Database\Tests\Unit;

use Ksfraser\Database\DbManager;
use Ksfraser\Database\DummyPdo;
use PHPUnit\Framework\TestCase;

final class DbManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        TestDbManager::reset();
        parent::tearDown();
    }

    public function testGetConfigBuildsDsnAndCaches(): void
    {
        $file1 = $this->writeTempConfig([
            'mysql_user' => 'u',
            'mysql_pass' => 'p',
            'mysql_db' => 'db',
            'mysql_host' => 'h',
            'mysql_port' => 1234,
        ]);

        $cfg1 = TestDbManager::getConfig($file1);
        $this->assertSame('u', $cfg1['mysql_user']);
        $this->assertSame('mysql:host=h;port=1234;dbname=db;charset=utf8mb4', $cfg1['dsn']);

        $file2 = $this->writeTempConfig([
            'mysql_user' => 'u2',
            'mysql_db' => 'db2',
            'mysql_host' => 'h2',
            'mysql_port' => 3306,
        ]);

        // Still cached.
        $cfg2 = TestDbManager::getConfig($file2);
        $this->assertSame($cfg1, $cfg2);
    }

    public function testGetPdoUsesDummyPdoWhenNoDrivers(): void
    {
        $file = $this->writeTempConfig([
            'dsn' => 'mysql:host=whatever;port=3306;dbname=x;charset=utf8mb4',
        ]);

        TestDbManager::$drivers = [];
        $pdo = TestDbManager::getPdo($file);

        $this->assertInstanceOf(DummyPdo::class, $pdo);

        // Exercise DummyStatement patterns through helper methods.
        $v = TestDbManager::fetchValue('SELECT 42 AS test');
        $this->assertSame(42, $v);

        TestDbManager::execute('CREATE TEMPORARY TABLE IF NOT EXISTS t1');
        TestDbManager::execute('INSERT INTO t1 (id) VALUES (?)', [99]);
        $rows = TestDbManager::fetchAll('SELECT * FROM t1');
        $this->assertSame([99], $rows);
    }

    public function testGetPdoFallsBackToSqliteWhenMysqlUnavailable(): void
    {
        $file = $this->writeTempConfig([
            'dsn' => 'mysql:host=whatever;port=3306;dbname=x;charset=utf8mb4',
        ]);

        TestDbManager::$drivers = ['sqlite'];
        $pdo = TestDbManager::getPdo($file);

        $this->assertInstanceOf(\PDO::class, $pdo);

        // Ensure helpers run on sqlite.
        TestDbManager::execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        TestDbManager::execute('INSERT INTO t (v) VALUES (?)', ['abc']);
        $val = TestDbManager::fetchValue('SELECT v FROM t WHERE id = 1');
        $this->assertSame('abc', $val);
    }

    public function testGetPdoFallsBackToDummyWhenSqliteCreationFails(): void
    {
        $file = $this->writeTempConfig([
            'dsn' => 'mysql:host=whatever;port=3306;dbname=x;charset=utf8mb4',
        ]);

        TestDbManager::$drivers = ['sqlite'];
        TestDbManager::$throwOnCreatePdo = true;

        $pdo = TestDbManager::getPdo($file);
        $this->assertInstanceOf(DummyPdo::class, $pdo);
    }

    public function testQueryHelpersWorkWithDummyFieldDefaultsShape(): void
    {
        $file = $this->writeTempConfig([
            'dsn' => 'mysql:host=whatever;port=3306;dbname=x;charset=utf8mb4',
        ]);

        TestDbManager::$drivers = [];
        TestDbManager::getPdo($file);

        TestDbManager::execute('CREATE TEMPORARY TABLE IF NOT EXISTS abc_header_field_defaults');
        TestDbManager::execute('INSERT INTO abc_header_field_defaults (field_name, field_value) VALUES (?, ?)', ['x', 'y']);

        $row = TestDbManager::fetchOne('SELECT field_value FROM abc_header_field_defaults WHERE field_name = ?', ['x']);
        // DummyStatement returns either an assoc row or []/false depending on query.
        $this->assertIsArray($row);
        $this->assertSame('y', $row['field_value']);

        $rows = TestDbManager::fetchAll('SELECT field_name, field_value FROM abc_header_field_defaults WHERE field_name = ?', ['x']);
        $this->assertSame([['field_name' => 'x', 'field_value' => 'y']], $rows);

        $all = TestDbManager::fetchAll('SELECT field_name, field_value FROM abc_header_field_defaults');
        $this->assertSame([['field_name' => 'x', 'field_value' => 'y']], $all);
    }

    /** @param array<string, mixed> $config */
    private function writeTempConfig(array $config): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ksf-db-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = tempnam($dir, 'db_config_');
        if ($file === false) {
            $this->fail('Failed to create temp config');
        }

        $php = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($file, $php);
        return $file;
    }
}

final class TestDbManager extends DbManager
{
    /** @var array<int, string> */
    public static array $drivers = ['sqlite'];

    public static bool $throwOnCreatePdo = false;

    public static function reset(): void
    {
        $ref = new \ReflectionClass(DbManager::class);

        foreach (['config', 'pdo'] as $propName) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        self::$drivers = ['sqlite'];
        self::$throwOnCreatePdo = false;
    }

    protected static function getAvailableDrivers(): array
    {
        return self::$drivers;
    }

    protected static function createPdo(string $dsn, $user = null, $pass = null): \PDO
    {
        if (self::$throwOnCreatePdo) {
            throw new \PDOException('forced');
        }
        return parent::createPdo($dsn, $user, $pass);
    }
}
