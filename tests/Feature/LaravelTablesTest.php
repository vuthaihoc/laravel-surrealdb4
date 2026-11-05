<?php

namespace LaravelSurrealDb\Tests\Feature;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Laravel's own tables, from the default migrations of a new application, and
 * the database drivers that use them. The first test migrates; the others
 * empty the tables with delete(): truncate() restarts the ids, and on
 * SurrealDB Cloud the first id taken inside a transaction after that fails
 * with a transaction conflict (tests/KnownIssues).
 */
class LaravelTablesTest extends TestCase
{
    /** @var list<string> */
    protected const TABLES = ['notifications', 'failed_jobs', 'job_batches', 'jobs', 'cache_locks', 'cache', 'sessions', 'password_reset_tokens', 'users'];

    protected static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$migrated) {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }

            return;
        }

        foreach ([...self::TABLES, 'migrations'] as $table) {
            Schema::dropIfExists($table);
        }

        $path = (string) realpath(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
        $this->artisan('migrate', ['--path' => [$path, $path.'/notifications'], '--realpath' => true])->assertSuccessful();
        self::$migrated = true;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', AppUser::class);
        $app['config']->set('queue.batching.database', 'surrealdb');
        $app['config']->set('queue.failed.database', 'surrealdb');
    }

    protected function onCloud(): bool
    {
        return config('database.connections.surrealdb.instance') !== '';
    }

    public function test_migrations_create_enforced_keys(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        AppUser::create(['name' => 'Ada', 'email' => 'ada@x.io', 'password' => 'x']);

        try {
            AppUser::create(['name' => 'Other', 'email' => 'ada@x.io', 'password' => 'x']);
            $this->fail('users.email is not unique.');
        } catch (UniqueConstraintViolationException) {
        }

        DB::table('cache_locks')->insert(['key' => 'k', 'owner' => 'a', 'expiration' => time() + 60]);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('cache_locks')->insert(['key' => 'k', 'owner' => 'b', 'expiration' => time() + 60]);
    }

    public function test_cache(): void
    {
        $cache = Cache::store('database');

        $cache->put('k', 'v', 60);
        $this->assertSame('v', $cache->get('k'));
        $this->assertFalse($cache->add('k', 'other', 60));

        $cache->putMany(['a' => 1, 'b' => [2]], 60);
        $this->assertSame(['a' => 1, 'b' => [2]], $cache->many(['a', 'b']));

        $cache->put('n', 1, 60);
        $this->assertSame(3, $cache->increment('n', 2));
        $this->assertSame(2, $cache->decrement('n'));

        $this->assertSame('r', $cache->remember('rem', 60, fn () => 'r'));
        $this->assertSame('r', $cache->remember('rem', 60, fn () => 'other'));

        $cache->forever('f', 1);
        $cache->forget('f');
        $this->assertNull($cache->get('f'));

        $cache->put('old', 1, -1);
        $this->assertNull($cache->get('old'));

        $cache->flush();
        $this->assertNull($cache->get('k'));
    }

    public function test_cache_locks(): void
    {
        $first = Cache::store('database')->lock('l', 10);
        $second = Cache::store('database')->lock('l', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());

        $first->release();
        $this->assertTrue($second->get());
        $this->assertSame($second->owner(), Cache::store('database')->restoreLock('l', $second->owner())->owner());

        Cache::store('database')->lock('l')->forceRelease();
        $this->assertTrue($first->get());
    }

    public function test_sessions(): void
    {
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);

        $handler->write('s1', 'one');
        $handler->write('s1', 'two');
        $this->assertSame('two', $handler->read('s1'));

        $handler->destroy('s1');
        $this->assertSame('', $handler->read('s1'));

        DB::table('sessions')->insert(['id' => 'old', 'payload' => 'x', 'last_activity' => 1]);
        $this->assertSame(1, $handler->gc(120));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_auth_and_password_reset(): void
    {
        $user = AppUser::create(['name' => 'Ada', 'email' => 'ada@x.io', 'password' => Hash::make('secret')]);

        $this->assertTrue(Auth::attempt(['email' => 'ada@x.io', 'password' => 'secret']));
        $this->assertFalse(Auth::attempt(['email' => 'ada@x.io', 'password' => 'wrong']));

        Auth::login($user, true);
        $this->assertSame(60, strlen((string) $user->fresh()?->remember_token));

        $broker = Password::broker();
        $token = $broker->createToken($user);
        $this->assertTrue($broker->tokenExists($user, $token));

        $broker->deleteToken($user);
        $this->assertFalse($broker->tokenExists($user, $token));
    }

    public function test_notifications(): void
    {
        $user = AppUser::create(['name' => 'Ada', 'email' => 'ada@x.io', 'password' => 'x']);
        $user->notify(new HelloNotification);

        $this->assertSame(1, $user->unreadNotifications()->count());
        $this->assertSame(['message' => 'hello'], $user->notifications()->first()?->data);

        if ($this->onCloud()) {
            $this->markTestIncomplete('SurrealDB Cloud: an update by a uuid primary key changes no row (tests/KnownIssues).');
        }

        $user->unreadNotifications->markAsRead();
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_queue(): void
    {
        $queue = Queue::connection('database');

        $queue->push(new NoopJob);
        $this->assertSame(1, $queue->size());

        $job = $queue->pop();
        $this->assertSame(NoopJob::class, $job?->resolveName());
        $this->assertNull($queue->pop());

        $job->delete();
        $this->assertSame(0, $queue->size());

        $queue->push(new NoopJob);
        $queue->pop()?->release();
        $this->assertSame(1, $queue->size());
        $this->assertSame(2, $queue->pop()?->attempts());
    }

    public function test_failed_jobs(): void
    {
        $failer = $this->app->make('queue.failer');
        $uuid = (string) Str::uuid();

        $failer->log('database', 'default', (string) json_encode(['uuid' => $uuid]), new RuntimeException('Failed.'));
        $this->assertSame($uuid, $failer->find($uuid)?->id);
        $this->assertCount(1, $failer->all());

        $this->assertTrue($failer->forget($uuid));
        $this->assertNull($failer->find($uuid));
    }

    public function test_job_batches(): void
    {
        $batch = Bus::batch([new NoopJob, new NoopJob])->name('import')->onConnection('database')->dispatch();

        $found = Bus::findBatch($batch->id);
        $this->assertSame(2, $found?->totalJobs);
        $this->assertSame('import', $found->name);
        $this->assertSame(2, Queue::connection('database')->size());

        $found->cancel();
        $this->assertTrue(Bus::findBatch($batch->id)?->cancelled());
    }
}

class AppUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

class HelloNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['message' => 'hello'];
    }
}

class NoopJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function handle(): void {}
}
