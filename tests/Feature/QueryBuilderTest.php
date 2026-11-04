<?php

namespace LaravelSurrealDb\Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueryBuilderTest extends TestCase
{
    protected array $tables = ['sd_posts', 'sd_users'];

    /** Ids of Ada, Bob and Cid: sequences do not restart on SurrealDB Cloud. */
    protected int $ada;

    protected int $bob;

    protected int $cid;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sd_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->integer('votes')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('sd_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('sd_users')->cascadeOnDelete();
            $table->string('title');
        });

        $this->ada = DB::table('sd_users')->insertGetId(['name' => 'Ada', 'email' => 'ada@x.io', 'active' => true, 'votes' => 5, 'meta' => json_encode(['theme' => 'dark', 'tags' => ['x', 'y'], 'n' => ['k' => 1]])]);
        $this->bob = DB::table('sd_users')->insertGetId(['name' => 'Bob', 'email' => 'bob@x.io', 'active' => false, 'votes' => 3, 'meta' => json_encode(['theme' => 'light', 'tags' => ['z']])]);
        $this->cid = DB::table('sd_users')->insertGetId(['name' => 'Cid', 'email' => 'cid@x.io', 'active' => true, 'votes' => 1, 'meta' => null]);

        DB::table('sd_posts')->insert([
            ['user_id' => $this->ada, 'title' => 'Hello'],
            ['user_id' => $this->ada, 'title' => 'World'],
            ['user_id' => $this->bob, 'title' => 'Bye'],
        ]);
    }

    protected function users(): Builder
    {
        return DB::table('sd_users');
    }

    public function test_selects(): void
    {
        $this->assertSame(['Ada', 'Cid'], $this->users()->where('active', true)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Bob'], $this->users()->whereIn('id', [$this->bob])->pluck('name')->all());
        $this->assertSame(['Ada', 'Bob'], $this->users()->whereBetween('votes', [2, 10])->orderBy('id')->pluck('name')->all());
        $this->assertSame(['Bob'], $this->users()->whereLike('name', 'b%')->pluck('name')->all());
        $this->assertSame([], $this->users()->whereLike('name', 'b%', caseSensitive: true)->pluck('name')->all());
        $this->assertSame(['Bob'], $this->users()->where('name', 'like', 'B%')->pluck('name')->all());
        $this->assertSame(['Cid'], $this->users()->whereNull('meta')->pluck('name')->all());
        $this->assertSame(3, $this->users()->count());
        $this->assertSame(9, (int) $this->users()->sum('votes'));
        $this->assertSame(5, $this->users()->max('votes'));
        $this->assertEquals(3, $this->users()->avg('votes'));
        $this->assertTrue($this->users()->where('id', $this->ada)->exists());
        $this->assertTrue($this->users()->where('id', $this->cid + 100)->doesntExist());
        $this->assertCount(2, $this->users()->select('active')->distinct()->get());
        $this->assertSame(3, $this->users()->inRandomOrder()->count());
        $this->assertSame('Ada', $this->users()->lockForUpdate()->find($this->ada)->name);
    }

    public function test_joins_unions_and_subqueries(): void
    {
        $this->assertSame(['Bye', 'Hello', 'World'], $this->users()->join('sd_posts', 'sd_posts.user_id', '=', 'sd_users.id')->orderBy('sd_posts.title')->pluck('sd_posts.title')->all());
        $this->assertCount(4, $this->users()->leftJoin('sd_posts', 'sd_posts.user_id', '=', 'sd_users.id')->select('sd_users.name', 'sd_posts.title')->get());
        $this->assertSame(['Ada', 'Bob'], $this->users()->whereExists(fn ($q) => $q->from('sd_posts')->whereColumn('sd_posts.user_id', 'sd_users.id'))->orderBy('id')->pluck('name')->all());
        $this->assertSame(['Ada', 'Bob'], $this->users()->whereIn('id', DB::table('sd_users')->select('id')->where('votes', '>', 2))->orderBy('id')->pluck('name')->all());

        $counts = $this->users()->select('name')->selectSub(DB::table('sd_posts')->selectRaw('count(*)')->whereColumn('sd_posts.user_id', 'sd_users.id'), 'posts')->orderBy('id')->pluck('posts', 'name')->all();
        $this->assertSame(['Ada' => 2, 'Bob' => 1, 'Cid' => 0], $counts);

        $this->assertCount(2, $this->users()->select('name')->where('id', $this->ada)->union($this->users()->select('name')->where('id', $this->bob))->get());
        $this->assertSame(2, DB::query()->fromSub($this->users()->select('name', 'votes'), 'u')->where('votes', '>', 2)->count());

        $grouped = DB::table('sd_posts')->select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->havingRaw('count(*) > ?', [1])->get();
        $this->assertSame([$this->ada], $grouped->pluck('user_id')->all());
    }

    public function test_pagination_and_chunking(): void
    {
        $this->assertSame(3, $this->users()->orderBy('id')->paginate(2)->total());
        $this->assertCount(1, $this->users()->orderBy('id')->paginate(2, page: 2)->items());
        $this->assertCount(2, $this->users()->orderBy('id')->cursorPaginate(2)->items());

        $seen = [];
        $this->users()->chunkById(2, function ($users) use (&$seen) {
            foreach ($users as $user) {
                $seen[] = $user->name;
            }
        });
        $this->assertSame(['Ada', 'Bob', 'Cid'], $seen);
        $this->assertSame(3, $this->users()->lazyById(1)->count());
        $this->assertSame(3, iterator_count($this->users()->cursor()));
    }

    public function test_inserts_and_upserts(): void
    {
        $id = $this->users()->insertGetId(['name' => 'Dan', 'email' => 'dan@x.io']);
        $this->assertGreaterThan($this->cid, $id);

        $this->assertSame(0, $this->users()->insertOrIgnore(['name' => 'Ada again', 'email' => 'ada@x.io']));
        $this->assertSame('Ada', $this->users()->where('email', 'ada@x.io')->value('name'));

        $this->users()->upsert([
            ['name' => 'Ada L.', 'email' => 'ada@x.io', 'votes' => 7],
            ['name' => 'Eve', 'email' => 'eve@x.io', 'votes' => 2],
        ], ['email'], ['name', 'votes']);
        $this->assertSame(['Ada L.', 7], array_values((array) $this->users()->where('email', 'ada@x.io')->first(['name', 'votes'])));
        $this->assertSame('Eve', $this->users()->where('email', 'eve@x.io')->value('name'));

        $this->assertTrue($this->users()->updateOrInsert(['email' => 'fay@x.io'], ['name' => 'Fay']));
        $this->assertTrue($this->users()->updateOrInsert(['email' => 'fay@x.io'], ['name' => 'Fay 2']));
        $this->assertSame('Fay 2', $this->users()->where('email', 'fay@x.io')->value('name'));
    }

    public function test_updates_and_deletes(): void
    {
        $this->assertSame(1, $this->users()->where('id', $this->ada)->update(['name' => 'Ada L.']));
        $this->assertSame(2, $this->users()->where('active', true)->increment('votes', 10));
        $this->assertSame(15, $this->users()->where('id', $this->ada)->value('votes'));
        $this->assertSame(1, $this->users()->where('id', $this->bob)->decrement('votes'));
        $this->assertSame(1, $this->users()->where('id', $this->cid)->delete());
        $this->assertSame(1, $this->users()->delete($this->bob));
        $this->assertSame(1, $this->users()->count());
        // ON DELETE CASCADE.
        $this->assertSame(2, DB::table('sd_posts')->count());
    }

    public function test_writes_by_id_where_surrealdb_refuses_the_statement(): void
    {
        // LIKE, joins, limits and subqueries are refused in UPDATE/DELETE: the driver writes by id.
        $this->assertSame(1, $this->users()->where('name', 'like', 'B%')->update(['votes' => 30]));
        $this->assertSame(30, $this->users()->where('id', $this->bob)->value('votes'));

        // Ada has two matching posts: one user is updated, as with PostgreSQL's UPDATE ... FROM.
        $this->assertSame(1, $this->users()->join('sd_posts', 'sd_posts.user_id', '=', 'sd_users.id')->where('sd_posts.title', '!=', 'Bye')->update(['sd_users.votes' => 40]));
        $this->assertSame(40, $this->users()->where('id', $this->ada)->value('votes'));

        $this->assertSame(1, $this->users()->orderBy('id', 'desc')->limit(1)->update(['votes' => 50]));
        $this->assertSame(50, $this->users()->where('id', $this->cid)->value('votes'));

        $this->assertSame(2, $this->users()->whereIn('id', DB::table('sd_users')->select('id')->where('votes', '<', 45))->update(['active' => false]));
        $this->assertSame(['Cid'], $this->users()->where('active', true)->pluck('name')->all());

        $this->assertSame(1, DB::table('sd_posts')->join('sd_users', 'sd_users.id', '=', 'sd_posts.user_id')->where('sd_users.name', 'Bob')->delete());
        $this->assertSame(1, DB::table('sd_posts')->whereLike('title', 'w%')->delete());
        $this->assertSame(['Hello'], DB::table('sd_posts')->pluck('title')->all());

        $this->assertSame(1, $this->users()->whereNotExists(fn ($q) => $q->from('sd_posts')->whereColumn('sd_posts.user_id', 'sd_users.id'))->orderBy('id')->limit(1)->delete());
        $this->assertSame(['Ada', 'Cid'], $this->users()->orderBy('id')->pluck('name')->all());
    }

    /**
     * SurrealDB Cloud reads a number, array or object through `->>` as false,
     * and a foreign key column inside a nested query as true (see
     * KnownIssues): these tests compare strings and avoid such subqueries.
     */
    public function test_json(): void
    {
        $this->assertSame(['Ada'], $this->users()->where('meta->theme', 'dark')->pluck('name')->all());
        $this->assertSame(['Ada'], $this->users()->whereJsonContains('meta->tags', 'x')->pluck('name')->all());
        $this->assertSame(['Ada'], $this->users()->whereJsonContains('meta->tags', ['x', 'y'])->pluck('name')->all());
        $this->assertSame(['Bob'], $this->users()->whereJsonDoesntContain('meta->tags', 'x')->pluck('name')->all());
        $this->assertSame(['Ada'], $this->users()->whereJsonContainsKey('meta->n')->pluck('name')->all());
        $this->assertSame(['Bob', 'Cid'], $this->users()->whereJsonDoesntContainKey('meta->n')->orderBy('id')->pluck('name')->all());
        $this->assertSame('dark', $this->users()->where('id', $this->ada)->value('meta->theme'));
    }

    public function test_json_path_updates(): void
    {
        $this->assertSame(2, $this->users()->whereIn('id', [$this->ada, $this->cid])->update(['meta->theme' => 'blue', 'meta->n->k' => 2, 'votes' => 9]));

        $this->assertSame(['n' => ['k' => 2], 'tags' => ['x', 'y'], 'theme' => 'blue'], json_decode((string) $this->users()->where('id', $this->ada)->value('meta'), true));
        $this->assertSame(['n' => ['k' => 2], 'theme' => 'blue'], json_decode((string) $this->users()->where('id', $this->cid)->value('meta'), true));
        $this->assertSame(['light', 9, 9], [$this->users()->where('id', $this->bob)->value('meta->theme'), $this->users()->where('id', $this->ada)->value('votes'), $this->users()->where('id', $this->cid)->value('votes')]);

        $this->users()->where('id', $this->bob)->update(['meta->tags[0]' => 'w']);
        $this->assertSame(['tags' => ['w'], 'theme' => 'light'], json_decode((string) $this->users()->where('id', $this->bob)->value('meta'), true));
    }

    public function test_truncate(): void
    {
        DB::table('sd_posts')->truncate();

        $this->assertSame(0, DB::table('sd_posts')->count());
        DB::table('sd_posts')->insert(['user_id' => $this->ada, 'title' => 'Again']);
        $this->assertSame(['Again'], DB::table('sd_posts')->pluck('title')->all());
    }
}
