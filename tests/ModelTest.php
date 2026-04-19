<?php

namespace Ancalagon\Glaurlink\Tests;

use Ancalagon\Glaurlink\Exception;
use PHPUnit\Framework\TestCase;
use mysqli;

abstract class TestModelSchema extends \Ancalagon\Glaurlink\Model
{
    protected static string $table = 'test_models';
    protected static array $fillable = ['name', 'age', 'is_active'];

    public ?int $id = null;
    public string $name;
    public int $age = 0;
    public bool $is_active = false;
    public ?float $score = null;
}

class TestModel extends TestModelSchema {}

class ModelTest extends TestCase
{
    private mysqli $dbh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbh = new mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASSWORD, TEST_DB_NAME, TEST_DB_PORT);

        $this->dbh->query("DROP TABLE IF EXISTS test_models");
        $this->dbh->query("
            CREATE TABLE test_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                age INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT FALSE,
                score FLOAT NULL
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->dbh->query("DROP TABLE IF EXISTS test_models");
        $this->dbh->close();
        parent::tearDown();
    }

    public function testConstruction()
    {
        $model = new TestModel();
        $this->assertNull($model->id);
        $this->assertEquals(0, $model->age);
        $this->assertFalse($model->is_active);
        $this->assertNull($model->score);
    }

    public function testConstructionWithAttributes()
    {
        $model = new TestModel([
            'name' => 'Test User',
            'age' => 25,
            'is_active' => true
        ]);

        $this->assertEquals('Test User', $model->name);
        $this->assertEquals(25, $model->age);
        $this->assertTrue($model->is_active);
    }

    public function testTypeValidation()
    {
        $this->expectException(\TypeError::class);
        new TestModel(['age' => 'not an integer']);
    }

    public function testBooleanTypeCoercion()
    {
        $model = new TestModel(['is_active' => 1]);
        $this->assertTrue($model->is_active);

        $model = new TestModel(['is_active' => '0']);
        $this->assertFalse($model->is_active);
    }

    public function testNumericTypeCoercion()
    {
        $model = new TestModel([
            'age' => '25',
            'score' => '98.6'
        ]);

        $this->assertIsInt($model->age);
        $this->assertEquals(25, $model->age);
        $this->assertIsFloat($model->score);
        $this->assertEquals(98.6, $model->score);
    }

    public function testFind()
    {
        $this->dbh->query("
            INSERT INTO test_models (name, age, is_active, score)
            VALUES ('Test User', 30, 1, 95.5)
        ");
        $id = $this->dbh->insert_id;

        $model = TestModel::find($this->dbh, ['id' => $id]);

        $this->assertNotNull($model);
        $this->assertEquals($id, $model->id);
        $this->assertEquals('Test User', $model->name);
        $this->assertEquals(30, $model->age);
        $this->assertTrue($model->is_active);
        $this->assertEquals(95.5, $model->score);
    }

    public function testCollection()
    {
        $this->dbh->query("
            INSERT INTO test_models (name, age, is_active) VALUES
            ('User 1', 25, 1),
            ('User 2', 30, 0),
            ('User 3', 35, 1)
        ");

        $models = TestModel::collection($this->dbh, conditions: ['is_active' => true]);

        $this->assertCount(2, $models);
        $this->assertTrue($models[0]->is_active);
        $this->assertTrue($models[1]->is_active);
    }

    public function testCount()
    {
        $this->dbh->query("
            INSERT INTO test_models (name, age, is_active) VALUES
            ('User 1', 25, 1),
            ('User 2', 30, 0),
            ('User 3', 35, 1)
        ");

        $count = TestModel::count($this->dbh, ['is_active' => true]);
        $this->assertEquals(2, $count);
    }

    public function testSave()
    {
        $model = new TestModel([
            'name' => 'New User',
            'age' => 28,
            'is_active' => true,
            'score' => 88.5
        ]);

        $this->assertTrue($model->save($this->dbh));
        $this->assertNotNull($model->id);

        $result = $this->dbh->query("SELECT * FROM test_models WHERE id = {$model->id}");
        $data = $result->fetch_assoc();

        $this->assertEquals('New User', $data['name']);
        $this->assertEquals(28, $data['age']);
        $this->assertEquals(1, $data['is_active']);
        $this->assertEquals(88.5, $data['score']);
    }

    public function testJsonSerialize()
    {
        $model = new TestModel([
            'name' => 'JSON Test',
            'age' => 32,
            'is_active' => true,
            'score' => 91.5
        ]);

        $json = json_encode($model);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('name', $data);
        $this->assertEquals('JSON Test', $data['name']);
        $this->assertEquals(32, $data['age']);
        $this->assertTrue($data['is_active']);
        $this->assertEquals(91.5, $data['score']);
    }

    public function testInvalidPropertyAccess()
    {
        $this->expectException(Exception::class);
        $model = new TestModel();
        $model->undefined_property = 'value';
    }
}