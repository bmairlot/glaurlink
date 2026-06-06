<?php

namespace Ancalagon\Glaurlink\Tests;

use Ancalagon\Glaurlink\Exception;
use PHPUnit\Framework\TestCase;
use mysqli;

abstract class TransactionTestSchema extends \Ancalagon\Glaurlink\Model
{
    protected static string $table = 'transaction_test_models';
    protected static array $fillable = ['name'];

    public ?int $id = null;
    public string $name;
}

class TransactionTestModel extends TransactionTestSchema {}

class TransactionTest extends TestCase
{
    private mysqli $dbh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbh = new mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASSWORD, TEST_DB_NAME, TEST_DB_PORT);

        $this->dbh->query("DROP TABLE IF EXISTS transaction_test_models");
        $this->dbh->query("
            CREATE TABLE transaction_test_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    protected function tearDown(): void
    {
        $this->dbh->query("DROP TABLE IF EXISTS transaction_test_models");
        $this->dbh->close();
        parent::tearDown();
    }

    public function testCommitPersists()
    {
        $id = TransactionTestModel::transaction($this->dbh, function (mysqli $dbh) {
            $model = new TransactionTestModel(['name' => 'committed']);
            $model->save($dbh);
            return $model->id;
        });

        $this->assertNotNull($id);
        $this->assertEquals(1, TransactionTestModel::count($this->dbh));

        $found = TransactionTestModel::find($this->dbh, ['id' => $id]);
        $this->assertNotNull($found);
        $this->assertEquals('committed', $found->name);
    }

    public function testRollbackOnException()
    {
        $original = new \RuntimeException('boom');

        try {
            TransactionTestModel::transaction($this->dbh, function (mysqli $dbh) use ($original) {
                (new TransactionTestModel(['name' => 'doomed']))->save($dbh);
                throw $original;
            });
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            // Non-Glaurlink throwables are wrapped, original kept as previous.
            $this->assertSame($original, $e->getPrevious());
        }

        $this->assertEquals(0, TransactionTestModel::count($this->dbh));
    }

    public function testGlaurlinkExceptionPassesThroughUnwrapped()
    {
        $original = new Exception('glaurlink failure');

        try {
            TransactionTestModel::transaction($this->dbh, function () use ($original) {
                throw $original;
            });
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertSame($original, $e);
        }

        $this->assertEquals(0, TransactionTestModel::count($this->dbh));
    }

    public function testReturnValuePassthrough()
    {
        $value = TransactionTestModel::transaction($this->dbh, fn () => 42);
        $this->assertSame(42, $value);

        $model = TransactionTestModel::transaction($this->dbh, function (mysqli $dbh) {
            $model = new TransactionTestModel(['name' => 'returned']);
            $model->save($dbh);
            return $model;
        });

        $this->assertInstanceOf(TransactionTestModel::class, $model);
        $this->assertNotNull($model->id);
    }

    public function testManualCommitPersists()
    {
        TransactionTestModel::beginTransaction($this->dbh);
        (new TransactionTestModel(['name' => 'manual-commit']))->save($this->dbh);
        TransactionTestModel::commit($this->dbh);

        $this->assertEquals(1, TransactionTestModel::count($this->dbh));
    }

    public function testManualRollbackDiscards()
    {
        TransactionTestModel::beginTransaction($this->dbh);
        (new TransactionTestModel(['name' => 'manual-rollback']))->save($this->dbh);
        TransactionTestModel::rollback($this->dbh);

        $this->assertEquals(0, TransactionTestModel::count($this->dbh));
    }

    public function testRollbackUnderSilentReportMode()
    {
        // Without throwing report mode, failed queries return false instead of
        // throwing; the catch(Throwable) path must still roll back.
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            try {
                TransactionTestModel::transaction($this->dbh, function (mysqli $dbh) {
                    (new TransactionTestModel(['name' => 'silent']))->save($dbh);
                    throw new \RuntimeException('boom');
                });
            } catch (Exception $e) {
                // expected
            }

            $this->assertEquals(0, TransactionTestModel::count($this->dbh));
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }
    }
}
