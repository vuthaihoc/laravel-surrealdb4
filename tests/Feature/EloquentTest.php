<?php

namespace LaravelSurrealDb\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EloquentTest extends TestCase
{
    protected array $tables = ['sde_post_tag', 'sde_tags', 'sde_posts', 'sde_users'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sde_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('admin')->default(false);
            $table->json('settings')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sde_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('sde_users')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('views')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        Schema::create('sde_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('sde_post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('sde_posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('sde_tags')->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });
    }

    protected function seedUsers(): SdeUser
    {
        $ada = SdeUser::create(['name' => 'Ada', 'email' => 'ada@x.io', 'admin' => true, 'settings' => ['theme' => 'dark', 'langs' => ['vi', 'en']]]);
        $bob = SdeUser::create(['name' => 'Bob', 'email' => 'bob@x.io']);

        $ada->posts()->createMany([
            ['title' => 'First', 'views' => 10, 'published' => true],
            ['title' => 'Second', 'views' => 3],
        ]);
        $bob->posts()->create(['title' => 'Bob post', 'views' => 7, 'published' => true]);

        return $ada;
    }

    public function test_crud_and_casts(): void
    {
        $user = SdeUser::create(['name' => 'Ada', 'email' => 'ada@x.io', 'admin' => true, 'settings' => ['theme' => 'dark'], 'verified_at' => CarbonImmutable::parse('2026-01-02 03:04:05')]);

        $this->assertIsInt($user->id);
        $this->assertTrue($user->wasRecentlyCreated);

        $fresh = SdeUser::find($user->id);
        $this->assertTrue($fresh->admin);
        $this->assertSame(['theme' => 'dark'], $fresh->settings);
        $this->assertSame('2026-01-02 03:04:05', $fresh->verified_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($fresh->created_at);

        $fresh->update(['name' => 'Ada L.', 'settings->theme' => 'light']);
        $this->assertSame(['theme' => 'light'], $fresh->refresh()->settings);
        $this->assertSame('Ada L.', $fresh->name);

        $this->assertSame(1, SdeUser::where('settings->theme', 'light')->count());
        $this->assertSame(1, SdeUser::where('admin', true)->where('verified_at', '<', now())->count());

        $fresh->delete();
        $this->assertSame(0, SdeUser::count());
        $this->assertSame(1, SdeUser::withTrashed()->count());
        $this->assertTrue(SdeUser::onlyTrashed()->first()->trashed());

        SdeUser::withTrashed()->first()->restore();
        $this->assertSame(1, SdeUser::count());

        SdeUser::first()->forceDelete();
        $this->assertSame(0, SdeUser::withTrashed()->count());
    }

    public function test_first_or_create_and_update_or_create(): void
    {
        $a = SdeUser::firstOrCreate(['email' => 'ada@x.io'], ['name' => 'Ada']);
        $b = SdeUser::firstOrCreate(['email' => 'ada@x.io'], ['name' => 'Other']);
        $this->assertSame($a->id, $b->id);
        $this->assertSame('Ada', $b->name);

        $c = SdeUser::updateOrCreate(['email' => 'ada@x.io'], ['name' => 'Ada 2']);
        $this->assertSame($a->id, $c->id);
        $this->assertSame('Ada 2', SdeUser::find($a->id)->name);

        $d = SdeUser::createOrFirst(['email' => 'ada@x.io'], ['name' => 'Ignored']);
        $this->assertSame($a->id, $d->id);

        $this->assertSame(1, SdeUser::upsert([['email' => 'ada@x.io', 'name' => 'Ada 3'], ['email' => 'eve@x.io', 'name' => 'Eve']], ['email'], ['name']) > 0 ? 1 : 0);
        $this->assertSame(['Ada 3', 'Eve'], SdeUser::orderBy('id')->pluck('name')->all());
    }

    public function test_relations(): void
    {
        $ada = $this->seedUsers();

        $this->assertSame(['First', 'Second'], $ada->posts()->orderBy('id')->pluck('title')->all());
        $this->assertSame('Ada', SdePost::where('title', 'Second')->first()->user->name);

        $users = SdeUser::with(['posts' => fn ($q) => $q->where('published', true)])->orderBy('id')->get();
        $this->assertSame([1, 1], $users->map(fn ($user) => $user->posts->count())->all());

        $counts = SdeUser::withCount(['posts', 'posts as popular_posts_count' => fn ($q) => $q->where('views', '>', 5)])->orderBy('id')->get();
        $this->assertSame([2, 1], $counts->pluck('posts_count')->all());
        $this->assertSame([1, 1], $counts->pluck('popular_posts_count')->all());

        $this->assertSame(['Ada'], SdeUser::whereHas('posts', fn ($q) => $q->where('views', '>', 8))->pluck('name')->all());
        $this->assertSame(['Bob'], SdeUser::whereHas('posts', fn ($q) => $q->where('title', 'like', 'Bob%'))->pluck('name')->all());
        $this->assertSame(['Ada'], SdeUser::has('posts', '>=', 2)->pluck('name')->all());
        $this->assertSame([], SdeUser::doesntHave('posts')->pluck('name')->all());
        $this->assertSame(['Ada'], SdeUser::whereRelation('posts', 'published', false)->pluck('name')->all());

        // Dates in a nested query: a Carbon is cast to timestamp, a date string inlined.
        $this->assertSame(2, SdeUser::whereHas('posts', fn ($q) => $q->where('created_at', '>=', now()->subDay()))->count());
        $this->assertSame(2, SdeUser::whereHas('posts', fn ($q) => $q->where('created_at', '>=', now()->subDay()->toDateTimeString()))->count());
        $this->assertSame(0, SdeUser::whereHas('posts', fn ($q) => $q->where('created_at', '>', now()->addDay()->toDateString()))->count());
        $this->assertSame(10, SdeUser::withMax('posts', 'views')->find($ada->id)->posts_max_views);
        $this->assertEquals(13, SdeUser::withSum('posts', 'views')->find($ada->id)->posts_sum_views);

        $latest = SdeUser::addSelect(['latest_post' => SdePost::select('title')->whereColumn('user_id', 'sde_users.id')->where('views', '>', 1)->orderByDesc('id')->limit(1)])->orderBy('id')->pluck('latest_post')->all();
        $this->assertSame(['Second', 'Bob post'], $latest);
    }

    public function test_belongs_to_many(): void
    {
        $ada = $this->seedUsers();
        $post = $ada->posts()->first();

        $tags = collect(['php', 'laravel', 'surrealdb'])->map(fn ($name) => SdeTag::create(['name' => $name]));
        $post->tags()->attach($tags->pluck('id'));
        $this->assertSame(['laravel', 'php', 'surrealdb'], $post->tags()->orderBy('name')->pluck('name')->all());

        $post->tags()->detach($tags[0]->id);
        $post->tags()->sync([$tags[1]->id, $tags[2]->id]);
        $this->assertSame(2, $post->tags()->count());

        $this->assertSame(['First'], SdePost::whereHas('tags', fn ($q) => $q->where('name', 'surrealdb'))->pluck('title')->all());
        $this->assertSame(1, SdeTag::whereHas('posts', fn ($q) => $q->where('sde_posts.id', $post->id))->where('name', 'laravel')->count());
    }

    public function test_mass_updates_and_deletes(): void
    {
        $ada = $this->seedUsers();

        $this->assertSame(2, SdePost::where('published', true)->increment('views'));
        $this->assertSame(11, SdePost::where('title', 'First')->value('views'));

        // whereHas() reads a subquery: SurrealDB refuses it in UPDATE/DELETE, so the driver writes by id.
        $this->assertSame(2, SdePost::whereHas('user', fn ($q) => $q->where('admin', true))->update(['published' => false]));
        $this->assertSame(1, SdePost::where('published', true)->count());

        $this->assertSame(1, $ada->posts()->where('title', 'like', 'Sec%')->delete());
        $this->assertSame(2, SdePost::count());

        SdeUser::where('id', $ada->id)->forceDelete();
        $this->assertSame(['Bob post'], SdePost::pluck('title')->all());
    }

    public function test_pagination_and_chunks(): void
    {
        $this->seedUsers();

        $page = SdePost::orderBy('id')->paginate(2);
        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->lastPage());

        $titles = [];
        SdePost::chunkById(2, function ($posts) use (&$titles) {
            foreach ($posts as $post) {
                $titles[] = $post->title;
            }
        });
        $this->assertSame(['First', 'Second', 'Bob post'], $titles);

        $this->assertTrue(SdePost::where('views', '>', 5)->exists());
        $this->assertFalse(SdePost::where('views', '>', 50)->exists());
    }
}

class SdeUser extends Model
{
    use SoftDeletes;

    protected $table = 'sde_users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin' => 'boolean', 'settings' => 'array', 'verified_at' => 'datetime'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SdePost::class, 'user_id');
    }
}

class SdePost extends Model
{
    protected $table = 'sde_posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'views' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SdeUser::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SdeTag::class, 'sde_post_tag', 'post_id', 'tag_id');
    }
}

class SdeTag extends Model
{
    protected $table = 'sde_tags';

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(SdePost::class, 'sde_post_tag', 'tag_id', 'post_id');
    }
}
