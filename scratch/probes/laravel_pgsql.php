<?php
// Runs typical Laravel operations through the stock `pgsql` driver against
// SurrealDB 4 to list what the driver has to change.
require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$db = new Capsule;
$db->addConnection([
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5436, 'database' => 'main/main',
    'username' => 'root', 'password' => 'root', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable',
]);
$db->setAsGlobal();
$db->bootEloquent();
$c = $db->getConnection();
$schema = $c->getSchemaBuilder();

function t(string $label, Closure $fn): void {
    try {
        $r = $fn();
        echo "OK  $label => ", substr(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION), 0, 300), "\n";
    } catch (Throwable $e) {
        echo "ERR $label => ", substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 400), "\n";
    }
}

foreach (['posts', 'users', 'migrations', 'tags', 'post_tag'] as $tb) { t("raw drop $tb", fn () => $c->statement("drop table if exists \"$tb\"")); }
t('hasTable(missing)', fn () => $schema->hasTable('users'));
t('create migrations', fn () => $schema->create('migrations', function (Blueprint $t) { $t->increments('id'); $t->string('migration'); $t->integer('batch'); }));
t('create users', fn () => $schema->create('users', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('email')->unique(); $t->boolean('active')->default(true); $t->integer('votes')->default(0);
    $t->json('meta')->nullable(); $t->jsonb('doc')->nullable(); $t->decimal('price', 8, 2)->nullable(); $t->float('score')->nullable(); $t->date('born')->nullable();
    $t->uuid('uuid')->nullable(); $t->text('bio')->nullable(); $t->enum('role', ['a', 'b'])->default('a'); $t->timestamps(); $t->softDeletes();
}));
t('create posts', fn () => $schema->create('posts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('title'); $t->index(['user_id', 'title']); $t->timestamps(); }));
t('hasTable(users)', fn () => $schema->hasTable('users'));
t('getTables', fn () => $schema->getTables());
t('getTableListing', fn () => $schema->getTableListing());
t('hasColumn', fn () => $schema->hasColumn('users', 'email'));
t('getColumns', fn () => $schema->getColumns('users'));
t('getIndexes', fn () => $schema->getIndexes('users'));
t('getForeignKeys', fn () => $schema->getForeignKeys('posts'));
t('getViews', fn () => $schema->getViews());
t('getTypes', fn () => $schema->getTypes());
t('alter add column', fn () => $schema->table('users', function (Blueprint $t) { $t->string('nick')->nullable(); }));
t('alter change column', fn () => $schema->table('users', function (Blueprint $t) { $t->string('nick', 100)->nullable()->change(); }));
t('alter rename column', fn () => $schema->table('users', function (Blueprint $t) { $t->renameColumn('nick', 'nickname'); }));
t('alter drop column', fn () => $schema->table('users', function (Blueprint $t) { $t->dropColumn('nickname'); }));
t('rename table', fn () => $schema->rename('posts', 'articles'));
t('rename back', fn () => $schema->rename('articles', 'posts'));

$q = fn () => $c->table('users');
t('insertGetId', fn () => $q()->insertGetId(['name' => 'Ada', 'email' => 'ada@x.io', 'meta' => json_encode(['a' => 1, 'tags' => ['x', 'y']]), 'doc' => json_encode(['n' => ['k' => 1]]), 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05']));
t('insert many', fn () => $q()->insert([['name' => 'Bob', 'email' => 'bob@x.io', 'active' => false, 'votes' => 3], ['name' => 'Cid', 'email' => 'cid@x.io', 'active' => true, 'votes' => 5]]));
t('insertOrIgnore', fn () => $q()->insertOrIgnore([['name' => 'Bob', 'email' => 'bob@x.io']]));
t('upsert', fn () => $q()->upsert([['name' => 'Bob2', 'email' => 'bob@x.io'], ['name' => 'Dan', 'email' => 'dan@x.io']], ['email'], ['name']));
t('updateOrInsert', fn () => $q()->updateOrInsert(['email' => 'eve@x.io'], ['name' => 'Eve']));
t('get', fn () => $q()->orderBy('id')->get(['id', 'name', 'active', 'votes']));
t('find', fn () => $q()->find(1));
t('where bool', fn () => $q()->where('active', true)->count());
t('whereIn', fn () => $q()->whereIn('id', [1, 2])->pluck('name'));
t('whereLike', fn () => $q()->whereLike('name', 'b%')->pluck('name'));
t('whereLike cs', fn () => $q()->whereLike('name', 'B%', true)->pluck('name'));
t('where like', fn () => $q()->where('name', 'like', 'B%')->pluck('name'));
t('where ilike', fn () => $q()->where('name', 'ilike', 'b%')->pluck('name'));
t('whereDate', fn () => $q()->whereDate('created_at', '2026-01-02')->count());
t('whereYear', fn () => $q()->whereYear('created_at', 2026)->count());
t('whereMonth', fn () => $q()->whereMonth('created_at', 1)->count());
t('whereTime', fn () => $q()->whereTime('created_at', '>=', '03:00')->count());
t('whereJson path', fn () => $q()->where('meta->a', 1)->count());
t('whereJson path str', fn () => $q()->where('doc->n->k', 1)->count());
t('whereJsonContains', fn () => $q()->whereJsonContains('meta->tags', 'x')->count());
t('whereJsonDoesntContain', fn () => $q()->whereJsonDoesntContain('meta->tags', 'z')->count());
t('whereJsonContainsKey', fn () => $q()->whereJsonContainsKey('meta->a')->count());
t('whereJsonLength', fn () => $q()->whereJsonLength('meta->tags', 2)->count());
t('select json path', fn () => $q()->where('id', 1)->value('meta->tags'));
t('update json path', fn () => $q()->where('id', 1)->update(['meta->a' => 2]));
t('after json update', fn () => $q()->where('id', 1)->value('meta'));
t('increment', fn () => $q()->where('id', 2)->increment('votes', 2));
t('decrement', fn () => $q()->where('id', 2)->decrement('votes'));
t('aggregates', fn () => [$q()->sum('votes'), $q()->avg('votes'), $q()->max('votes'), $q()->min('votes')]);
t('exists', fn () => $q()->where('id', 1)->exists());
t('doesntExist', fn () => $q()->where('id', 999)->doesntExist());
t('groupBy having', fn () => $q()->select('active', $c->raw('count(*) as n'))->groupBy('active')->having('n', '>', 0)->get());
t('groupBy havingRaw', fn () => $q()->select('active', $c->raw('count(*) as n'))->groupBy('active')->havingRaw('count(*) > ?', [0])->get());
t('distinct', fn () => $q()->distinct()->pluck('active'));
t('paginate', fn () => $q()->orderBy('id')->paginate(2)->toArray()['total']);
t('simplePaginate', fn () => count($q()->orderBy('id')->simplePaginate(2)->items()));
t('cursorPaginate', fn () => count($q()->orderBy('id')->cursorPaginate(2)->items()));
t('chunk', fn () => $q()->orderBy('id')->chunk(2, fn () => null));
t('chunkById', fn () => $q()->chunkById(2, fn () => null));
t('lazyById', fn () => $q()->lazyById(2)->count());
t('cursor', fn () => iterator_count($q()->cursor()));
t('inRandomOrder', fn () => $q()->inRandomOrder()->count());
t('lockForUpdate', fn () => $q()->lockForUpdate()->count());
t('sharedLock', fn () => $q()->sharedLock()->count());
t('skip locked', fn () => $q()->lock('for update skip locked')->count());
t('insert post', fn () => $c->table('posts')->insert(['user_id' => 1, 'title' => 'Hello']));
t('insert post bad fk', fn () => $c->table('posts')->insert(['user_id' => 999, 'title' => 'Bad']));
t('join', fn () => $q()->join('posts', 'posts.user_id', '=', 'users.id')->select('users.name', 'posts.title')->get());
t('leftJoin', fn () => $q()->leftJoin('posts', 'posts.user_id', '=', 'users.id')->select('users.name', 'posts.title')->orderBy('users.id')->get());
t('whereExists', fn () => $q()->whereExists(fn ($s) => $s->from('posts')->whereColumn('posts.user_id', 'users.id'))->pluck('name'));
t('whereIn subquery', fn () => $q()->whereIn('id', $c->table('posts')->select('user_id'))->pluck('name'));
t('subselect', fn () => $q()->select('name')->selectSub($c->table('posts')->selectRaw('count(*)')->whereColumn('posts.user_id', 'users.id'), 'pc')->orderBy('id')->get());
t('union', fn () => $q()->select('name')->where('id', 1)->union($q()->select('name')->where('id', 2))->get());
t('unionAll', fn () => $q()->select('name')->where('id', 1)->unionAll($q()->select('name')->where('id', 1))->get());
t('fromSub', fn () => $c->query()->fromSub($q()->select('name', 'votes'), 'u')->where('votes', '>', 1)->count());
t('withExpression?', fn () => $q()->whereRaw('"votes" between ? and ?', [1, 10])->count());
t('update with join', fn () => $q()->join('posts', 'posts.user_id', '=', 'users.id')->where('posts.title', 'Hello')->update(['users.votes' => 10]));
t('update with limit', fn () => $q()->orderBy('id')->limit(1)->update(['votes' => 11]));
t('update where like', fn () => $q()->where('name', 'like', 'Ada%')->update(['votes' => 12]));
t('update whereIn sub', fn () => $q()->whereIn('id', $c->table('posts')->select('user_id'))->update(['votes' => 13]));
t('delete with join', fn () => $c->table('posts')->join('users', 'posts.user_id', '=', 'users.id')->where('users.name', 'zzz')->delete());
t('delete with limit', fn () => $c->table('posts')->where('title', 'zzz')->limit(1)->delete());
t('delete where like', fn () => $c->table('posts')->where('title', 'like', 'zzz%')->delete());
t('soft delete', fn () => $q()->where('id', 3)->update(['deleted_at' => '2026-03-03 00:00:00']));
t('whereNull', fn () => $q()->whereNull('deleted_at')->count());
t('delete user cascade', fn () => $q()->where('id', 1)->delete());
t('posts after cascade', fn () => $c->table('posts')->count());
t('transaction commit', fn () => $c->transaction(function () use ($q) { $q()->insert(['name' => 'T1', 'email' => 't1@x.io']); return $q()->where('email', 't1@x.io')->count(); }));
t('transaction rollback', function () use ($c, $q) { try { $c->transaction(function () use ($q) { $q()->insert(['name' => 'T2', 'email' => 't2@x.io']); throw new RuntimeException('x'); }); } catch (RuntimeException) {} return $q()->where('email', 't2@x.io')->count(); });
t('nested savepoint', function () use ($c, $q) { $c->beginTransaction(); $q()->insert(['name' => 'T3', 'email' => 't3@x.io']); $c->beginTransaction(); $q()->insert(['name' => 'T4', 'email' => 't4@x.io']); $c->rollBack(); $c->commit(); return $q()->whereIn('email', ['t3@x.io', 't4@x.io'])->pluck('email'); });
t('read after write in tx', fn () => $c->transaction(function () use ($q) { $q()->where('email', 't1@x.io')->update(['votes' => 99]); return $q()->where('email', 't1@x.io')->value('votes'); }));
t('truncate', fn () => $c->table('posts')->truncate());
t('dates', fn () => $q()->where('created_at', '>', '2020-01-01 00:00:00')->count());
t('emoji', function () use ($q) { $q()->insert(['name' => "it's 🚀 \\ chào ?", 'email' => 'e@x.io']); return $q()->where('email', 'e@x.io')->value('name'); });
t('uuid', function () use ($q) { $q()->insert(['name' => 'U', 'email' => 'u@x.io', 'uuid' => '7f0c8b0e-4b4a-4c1a-9b65-2f6b4b9f0e11']); return $q()->where('uuid', '7f0c8b0e-4b4a-4c1a-9b65-2f6b4b9f0e11')->value('uuid'); });
t('decimal float', function () use ($q) { $q()->where('email', 'u@x.io')->update(['price' => 12.5, 'score' => 1.25, 'born' => '2000-01-01']); return $q()->where('email', 'u@x.io')->first(['price', 'score', 'born']); });
t('enum bad', fn () => $q()->insert(['name' => 'X', 'email' => 'x@x.io', 'role' => 'zzz']));
t('select *', fn () => $q()->where('email', 'u@x.io')->first());
t('dropColumns softDeletes', fn () => $schema->table('users', fn (Blueprint $t) => $t->dropSoftDeletes()));
t('dropIndex', fn () => $schema->table('posts', fn (Blueprint $t) => $t->dropIndex(['user_id', 'title'])));
t('dropForeign', fn () => $schema->table('posts', fn (Blueprint $t) => $t->dropForeign(['user_id'])));
t('dropUnique', fn () => $schema->table('users', fn (Blueprint $t) => $t->dropUnique(['email'])));
t('dropAllTables', fn () => $schema->dropAllTables());
t('tables after', fn () => $schema->getTableListing());
