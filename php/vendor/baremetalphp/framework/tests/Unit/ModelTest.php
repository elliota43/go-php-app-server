<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\Model;
use BareMetalPHP\Support\Collection;
use Tests\TestCase;

class ModelTest extends TestCase
{
    protected bool $needsDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test table
        $this->createTable('users', <<<SQL
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        SQL);
    }

    public function testCanCreateModel(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        
        $this->assertInstanceOf(Model::class, $user);
        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testCanSaveModel(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        $result = $user->save();
        
        $this->assertTrue($result);
        $this->assertNotNull($user->id);
        $this->assertTrue($user->exists());
    }

    public function testCanFindModel(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        $user->save();
        
        $found = TestUser::find($user->id);
        
        $this->assertNotNull($found);
        $this->assertEquals('John', $found->name);
    }

    public function testCanUpdateModel(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        $user->save();
        
        $user->update(['name' => 'Jane']);
        
        $found = TestUser::find($user->id);
        $this->assertEquals('Jane', $found->name);
    }

    public function testCanDeleteModel(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        $user->save();
        $id = $user->id;
        
        $result = $user->delete();
        
        $this->assertTrue($result);
        $this->assertNull(TestUser::find($id));
    }

    public function testCanUseStaticCreate(): void
    {
        $user = TestUser::create([
            'name' => 'John',
            'email' => 'john@example.com'
        ]);
        
        $this->assertNotNull($user->id);
        $this->assertTrue($user->exists());
    }

    public function testCanUseFirstOrCreate(): void
    {
        // First call creates
        $user1 = TestUser::firstOrCreate(
            ['email' => 'john@example.com'],
            ['name' => 'John']
        );
        
        // Second call finds existing
        $user2 = TestUser::firstOrCreate(
            ['email' => 'john@example.com'],
            ['name' => 'Jane']
        );
        
        $this->assertEquals($user1->id, $user2->id);
        $this->assertEquals('John', $user2->name); // Original name preserved
    }

    public function testCanUseUpdateOrCreate(): void
    {
        // First call creates
        $user1 = TestUser::updateOrCreate(
            ['email' => 'john@example.com'],
            ['name' => 'John']
        );
        
        // Second call updates
        $user2 = TestUser::updateOrCreate(
            ['email' => 'john@example.com'],
            ['name' => 'Jane']
        );
        
        $this->assertEquals($user1->id, $user2->id);
        $this->assertEquals('Jane', $user2->name); // Updated
    }

    public function testCanUseFindOrFail(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        
        $found = TestUser::findOrFail($user->id);
        $this->assertEquals('John', $found->name);
        
        $this->expectException(\RuntimeException::class);
        TestUser::findOrFail(99999);
    }

    public function testCanFillAttributes(): void
    {
        $user = new TestUser();
        $user->fill(['name' => 'John', 'email' => 'john@example.com']);
        
        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testCanRefreshModel(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user->name = 'Jane';
        
        $user->refresh();
        
        $this->assertEquals('John', $user->name); // Refreshed from DB
    }

    public function testCanGetFreshInstance(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user->name = 'Jane';
        
        $fresh = $user->fresh();
        
        $this->assertEquals('John', $fresh->name);
        $this->assertNotSame($user, $fresh);
    }

    public function testCanQueryModels(): void
    {
        TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        TestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        
        $users = TestUser::query()->where('name', '=', 'John')->get();
        
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertEquals(1, $users->count());
    }

    public function testCanGetAllModels(): void
    {
        TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        TestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        
        $all = TestUser::all();
        
        $this->assertEquals(2, $all->count());
    }

    public function testCanUseAccessors(): void
    {
        $user = new TestUser(['name' => 'John', 'email' => 'john@example.com']);
        $user->save();
        
        // Accessor should be called
        $this->assertEquals('John <john@example.com>', $user->display_name);
    }

    public function testCanUseMutators(): void
    {
        $user = new TestUser();
        $user->email = 'JOHN@EXAMPLE.COM';
        
        // Mutator should lowercase it
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testCanConvertToArray(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        
        $array = $user->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('email', $array);
    }
}

// Test model class
class TestUser extends Model
{
    protected static string $table = 'users';

    public function getDisplayNameAttribute(): string
    {
        return $this->name . ' <' . $this->email . '>';
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }
}

