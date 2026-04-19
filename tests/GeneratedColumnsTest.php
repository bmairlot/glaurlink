<?php

namespace Ancalagon\Glaurlink\Tests;

use Ancalagon\Glaurlink\CompositeKey;
use Ancalagon\Glaurlink\Model;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use mysqli;

abstract class TimestampModelSchema extends Model
{
    protected static string $table = 'timestamp_models';
    protected static array $fillable = ['name'];
    protected static array $generated = ['created_at', 'updated_at'];

    public ?int $id = null;
    public string $name;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}

class TimestampModel extends TimestampModelSchema {}

abstract class UninitializedModelSchema extends Model
{
    protected static string $table = 'uninitialized_models';
    protected static array $fillable = ['name', 'email'];

    public ?int $id = null;
    public string $name;
    public string $email;
    public ?string $bio = null;
}

class UninitializedModel extends UninitializedModelSchema {}

abstract class ExplicitPkModelSchema extends Model
{
    protected static string $table = 'explicit_pk_models';
    protected static array $fillable = ['name'];

    public ?int $id = null;
    public string $name = '';
}

class ExplicitPkModel extends ExplicitPkModelSchema {}

abstract class CompositeTimestampModelSchema extends Model
{
    use CompositeKey;

    protected static string $table = 'composite_ts_models';
    protected static array $fillable = ['label'];
    protected static array $generated = ['created_at'];

    public int $tenant_id;
    public int $item_id;
    public string $label = '';
    public ?string $created_at = null;
}

class CompositeTimestampModel extends CompositeTimestampModelSchema {}

class GeneratedColumnsTest extends TestCase
{
    private mysqli $dbh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbh = new mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASSWORD, TEST_DB_NAME, TEST_DB_PORT);

        $ref = new ReflectionProperty(CompositeTimestampModelSchema::class, 'primaryKeys');
        $ref->setValue(null, ['tenant_id', 'item_id']);

        $this->dbh->query("DROP TABLE IF EXISTS timestamp_models");
        $this->dbh->query("
            CREATE TABLE timestamp_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $this->dbh->query("DROP TABLE IF EXISTS uninitialized_models");
        $this->dbh->query("
            CREATE TABLE uninitialized_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                bio TEXT NULL
            )
        ");

        $this->dbh->query("DROP TABLE IF EXISTS explicit_pk_models");
        $this->dbh->query("
            CREATE TABLE explicit_pk_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL DEFAULT ''
            )
        ");

        $this->dbh->query("DROP TABLE IF EXISTS composite_ts_models");
        $this->dbh->query("
            CREATE TABLE composite_ts_models (
                tenant_id INT NOT NULL,
                item_id INT NOT NULL,
                label VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (tenant_id, item_id)
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->dbh->query("DROP TABLE IF EXISTS timestamp_models");
        $this->dbh->query("DROP TABLE IF EXISTS uninitialized_models");
        $this->dbh->query("DROP TABLE IF EXISTS explicit_pk_models");
        $this->dbh->query("DROP TABLE IF EXISTS composite_ts_models");
        $this->dbh->close();
        parent::tearDown();
    }

    // --- 1. initializeProperties no longer throws ---

    public function testUninitializedNonNullablePropertyDoesNotThrow(): void
    {
        $model = new UninitializedModel();
        $this->assertNull($model->id);
        $this->assertNull($model->bio);
    }

    public function testAccessingUninitializedPropertyThrowsPhpError(): void
    {
        $model = new UninitializedModel();
        $this->expectException(\Error::class);
        $_ = $model->name;
    }

    // --- 2. AUTO_INCREMENT PK — developer value wins ---

    public function testExplicitPkValueIsRespected(): void
    {
        $model = new ExplicitPkModel(['name' => 'explicit']);
        $model->id = 42;
        $model->insert($this->dbh);

        $this->assertEquals(42, $model->id);

        $row = $this->dbh->query("SELECT * FROM explicit_pk_models WHERE id = 42")->fetch_assoc();
        $this->assertNotNull($row);
        $this->assertEquals('explicit', $row['name']);
    }

    public function testNullPkUsesAutoIncrement(): void
    {
        $model = new ExplicitPkModel(['name' => 'auto']);
        $model->save($this->dbh);

        $this->assertNotNull($model->id);
        $this->assertGreaterThan(0, $model->id);
    }

    // --- 3. $generated omission — INSERT ---

    public function testGeneratedColumnOmittedOnInsertWhenNull(): void
    {
        $model = new TimestampModel();
        $model->name = 'test';
        $model->save($this->dbh);

        $row = $this->dbh->query(
            "SELECT * FROM timestamp_models WHERE id = {$model->id}"
        )->fetch_assoc();

        $this->assertNotNull($row['created_at']);
        $this->assertNull($model->created_at);
    }

    public function testGeneratedColumnIncludedOnInsertWhenExplicit(): void
    {
        $model = new TimestampModel();
        $model->name = 'test';
        $model->created_at = '2025-01-01 00:00:00';
        $model->save($this->dbh);

        $row = $this->dbh->query(
            "SELECT * FROM timestamp_models WHERE id = {$model->id}"
        )->fetch_assoc();

        $this->assertEquals('2025-01-01 00:00:00', $row['created_at']);
    }

    // --- 4. $generated omission — UPDATE ---

    public function testGeneratedColumnOmittedOnUpdateWhenNull(): void
    {
        $this->dbh->query(
            "INSERT INTO timestamp_models (name, created_at) VALUES ('orig', '2025-06-01 12:00:00')"
        );
        $id = $this->dbh->insert_id;

        $model = TimestampModel::find($this->dbh, ['id' => $id]);
        $model->name = 'updated';
        $model->updated_at = null;
        $model->save($this->dbh);

        $row = $this->dbh->query("SELECT * FROM timestamp_models WHERE id = $id")->fetch_assoc();
        $this->assertEquals('updated', $row['name']);
    }

    public function testGeneratedColumnIncludedOnUpdateWhenExplicit(): void
    {
        $this->dbh->query(
            "INSERT INTO timestamp_models (name) VALUES ('orig')"
        );
        $id = $this->dbh->insert_id;

        $model = TimestampModel::find($this->dbh, ['id' => $id]);
        $model->name = 'updated';
        $model->updated_at = '2030-12-31 23:59:59';
        $model->save($this->dbh);

        $row = $this->dbh->query("SELECT * FROM timestamp_models WHERE id = $id")->fetch_assoc();
        $this->assertEquals('2030-12-31 23:59:59', $row['updated_at']);
    }

    // --- 5. Uninitialized non-generated property ---

    public function testUninitializedPropertyOmittedFromInsert(): void
    {
        $model = new UninitializedModel();
        $model->name = 'test';
        $model->email = 'test@example.com';
        $model->save($this->dbh);

        $this->assertNotNull($model->id);

        $row = $this->dbh->query(
            "SELECT * FROM uninitialized_models WHERE id = {$model->id}"
        )->fetch_assoc();

        $this->assertEquals('test', $row['name']);
        $this->assertEquals('test@example.com', $row['email']);
    }

    // --- 6. Rehydration is opt-in ---

    public function testSaveWithoutRehydrateDoesNotRefresh(): void
    {
        $model = new TimestampModel();
        $model->name = 'no-rehydrate';
        $model->save($this->dbh);

        $this->assertNull($model->created_at);
    }

    public function testSaveWithRehydrateRefreshesProperties(): void
    {
        $model = new TimestampModel();
        $model->name = 'rehydrate';
        $model->save($this->dbh, rehydrate: true);

        $this->assertNotNull($model->created_at);
        $this->assertEquals('rehydrate', $model->name);
    }

    public function testInsertWithRehydrateRefreshesProperties(): void
    {
        $model = new TimestampModel();
        $model->name = 'insert-rehydrate';
        $model->insert($this->dbh, rehydrate: true);

        $this->assertNotNull($model->id);
        $this->assertNotNull($model->created_at);
    }

    public function testInsertWithoutRehydrateDoesNotRefresh(): void
    {
        $model = new TimestampModel();
        $model->name = 'insert-no-rehydrate';
        $model->insert($this->dbh);

        $this->assertNotNull($model->id);
        $this->assertNull($model->created_at);
    }

    // --- 7. Composite key parity ---

    public function testCompositeKeyGeneratedOmittedOnInsert(): void
    {
        $model = new CompositeTimestampModel();
        $model->tenant_id = 1;
        $model->item_id = 100;
        $model->label = 'item';
        $model->save($this->dbh);

        $row = $this->dbh->query(
            "SELECT * FROM composite_ts_models WHERE tenant_id = 1 AND item_id = 100"
        )->fetch_assoc();

        $this->assertNotNull($row['created_at']);
        $this->assertNull($model->created_at);
    }

    public function testCompositeKeyGeneratedExplicitValueWins(): void
    {
        $model = new CompositeTimestampModel();
        $model->tenant_id = 1;
        $model->item_id = 200;
        $model->label = 'explicit';
        $model->created_at = '2025-07-04 00:00:00';
        $model->save($this->dbh);

        $row = $this->dbh->query(
            "SELECT * FROM composite_ts_models WHERE tenant_id = 1 AND item_id = 200"
        )->fetch_assoc();

        $this->assertEquals('2025-07-04 00:00:00', $row['created_at']);
    }

    public function testCompositeKeyRehydration(): void
    {
        $model = new CompositeTimestampModel();
        $model->tenant_id = 2;
        $model->item_id = 300;
        $model->label = 'rehydrate';
        $model->save($this->dbh, rehydrate: true);

        $this->assertNotNull($model->created_at);
    }

    public function testCompositeKeyInsertRehydration(): void
    {
        $model = new CompositeTimestampModel();
        $model->tenant_id = 2;
        $model->item_id = 400;
        $model->label = 'insert-rehydrate';
        $model->insert($this->dbh, rehydrate: true);

        $this->assertNotNull($model->created_at);
    }

    public function testCompositeKeyGeneratedOmittedOnUpdate(): void
    {
        $this->dbh->query(
            "INSERT INTO composite_ts_models (tenant_id, item_id, label) VALUES (3, 500, 'orig')"
        );

        $model = CompositeTimestampModel::find($this->dbh, ['tenant_id' => 3, 'item_id' => 500]);
        $model->label = 'updated';
        $model->created_at = null;
        $model->save($this->dbh);

        $row = $this->dbh->query(
            "SELECT * FROM composite_ts_models WHERE tenant_id = 3 AND item_id = 500"
        )->fetch_assoc();

        $this->assertEquals('updated', $row['label']);
    }

    // --- 8. JSON serialization with uninitialized properties ---

    public function testJsonSerializeSkipsUninitializedProperties(): void
    {
        $model = new UninitializedModel();
        $json = json_encode($model);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('bio', $data);
        $this->assertArrayNotHasKey('name', $data);
        $this->assertArrayNotHasKey('email', $data);
    }
}