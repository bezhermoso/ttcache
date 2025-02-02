<?php declare(strict_types=1);

namespace Square\TTCache;

use Square\TTCache\ReturnDirective\BypassCache;
use Square\TTCache\ReturnDirective\GetTaggedValue;
use Square\TTCache\Store\ShardedMemcachedStore;
use Square\TTCache\Tags\HeritableTag;
use Square\TTCache\TTCache;
use PHPUnit\Framework\TestCase;
use Memcached;
use Square\TTCache\Tags\ShardingTag;

class TTCacheTest extends TestCase
{
    protected TTCache $tt;

    protected Memcached $mc;

    public function setUp() : void
    {
        $this->mc = new Memcached;
        $this->mc->addServers([['memcached', 11211]]);
        $this->mc->flush();

        $store = new ShardedMemcachedStore($this->mc);
        $store->setShardingKey('hello');

        $this->tt = new TTCache($store);
    }

    /**
     * @test
     */
    function cache()
    {
        $v = $this->tt->remember('testkey', 0, [], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        // If we use the same key but a different callback that returns something different, we should still get
        // the previously cached value.
        $v = $this->tt->remember('testkey', 0, [], fn () => 'hello 2');
        $this->assertEquals('hello 1', $v);
    }

    /**
     * @test
     */
    function clear_by_tags()
    {
        $v = $this->tt->remember('testkey', 0, ['tag', 'other:tag'], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        // now it's cached
        $v = $this->tt->remember('testkey', 0, [], fn () => 'hello 2');
        $this->assertEquals('hello 1', $v);
        // clear `tag`
        $this->tt->clearTags('tag');
        $v = $this->tt->remember('testkey', 0, [], fn () => 'hello 2');
        $this->assertEquals('hello 2', $v);
    }

    /**
     * @test
     */
    function avoids_root_tags_contamination()
    {
        $v = $this->tt->remember('testkey', 0, ['tag', 'other:tag'], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        $v = $this->tt->remember('testkey2', 0, [], fn () => 'hello 2');
        $this->assertEquals('hello 2', $v);
        // now it's cached
        $v = $this->tt->remember('testkey2', 0, [], fn () => 'hello 3');
        $this->assertEquals('hello 2', $v);
        // clear `tag`
        $this->tt->clearTags('tag');
        $v = $this->tt->remember('testkey2', 0, [], fn () => 'hello 3');
        $this->assertEquals('hello 2', $v);
    }

    /**
     * @test
     */
    function tree_cache()
    {
        $built = $this->tt->remember('main', 0, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', 0, ['sub:2'], function () {
                return "world!";
            });

            return $out;
        });

        $this->assertEquals($built, 'hello dear world!');

        // Now it's cached
        $built = $this->tt->remember('main', 0, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello dear world!');

        // clear one of the sub tags
        $this->tt->clearTags('sub:1');
        // sub2 is still in cache
        $sub2 = $this->tt->remember('sub2', 0, [], fn () => 'oh no');
        $this->assertEquals('world!', $sub2);

        $built = $this->tt->remember('main', 0, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello wholesome world!');
    }

    /**
     * @test
     */
    function handles_exceptions()
    {
        $built = fn () => $this->tt->remember('main', 0, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', 0, ['sub:2'], function () {
                throw new \Exception('whoopsie');
            });

            return $out;
        });

        try {
            $built();
        } catch (\Exception $e) {
            // nothing
        }
        $this->assertEquals(' dear ', $this->tt->remember('sub', 0, [], fn () => 'failure'));
        $this->assertEquals('failure', $this->tt->remember('main', 0, [], fn () => 'failure'));
        $this->assertEquals('failure', $this->tt->remember('sub2', 0, [], fn () => 'failure'));
    }

    /**
     * @test
     */
    function retrieving_parts_of_a_collection_still_applies_all_tags()
    {
        $coll = [
            new BlogPost('abc', 'Learn PHP the curved way', '...'),
            new BlogPost('def', 'Learn Python the curved way', '...'),
            new BlogPost('ghi', 'Learn Javascript the curved way', '...'),
            new BlogPost('klm', 'Learn Rust the curved way', '...'),
            new BlogPost('nop', 'Learn Go the curved way', '...'),
        ];

        $store = new class($this->mc) extends ShardedMemcachedStore {
            public $requestedKeys = [];
            public function __construct($mc)
            {
                parent::__construct($mc);
            }

            public function get($key, $default = null)
            {
                $this->requestedKeys[] = $key;
                return parent::get($key);
            }
        };
        $store->setShardingKey('hello');

        $this->tt = new TTCache($store);

        $built = fn() => $this->tt->remember('full-collection', 0, [], function () use ($coll) {
            $posts = [];
            $keys = [];
            foreach ($coll as $post) {
                $keys[] = __CLASS__.':blog-collection:'.$post->id;
            }
            $this->tt->load($keys);

            foreach ($coll as $post) {
                $key = __CLASS__.':blog-collection:'.$post->id;
                $posts[] = $this->tt->remember($key, 0, ['post:'.$post->id], fn () => "<h1>$post->title</h1><hr /><div>$post->content</div>");
            }

            return $posts;
        });


        $this->assertEquals([
            "<h1>Learn PHP the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Python the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Javascript the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Rust the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Go the curved way</h1><hr /><div>...</div>",
        ], $built());
        $this->assertEquals([
            "k:full-collection",
            "k:Square\TTCache\TTCacheTest:blog-collection:abc",
            "k:Square\TTCache\TTCacheTest:blog-collection:def",
            "k:Square\TTCache\TTCacheTest:blog-collection:ghi",
            "k:Square\TTCache\TTCacheTest:blog-collection:klm",
            "k:Square\TTCache\TTCacheTest:blog-collection:nop",
        ], $store->requestedKeys);

        // When we call `built()` again, all the data should be pre-loaded and therefore come without talking to MC
        $store->requestedKeys = [];
        $built();
        $this->assertEquals([
            "k:full-collection",
        ], $store->requestedKeys);

        // Clear tag for "abc" and change the title for "abc"
        $this->tt->clearTags('post:'.$coll[0]->id);
        $store->requestedKeys = [];
        $coll[0]->title = 'Learn PHP the straight way';
        $this->assertEquals([
            "<h1>Learn PHP the straight way</h1><hr /><div>...</div>",
            "<h1>Learn Python the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Javascript the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Rust the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Go the curved way</h1><hr /><div>...</div>",
        ], $built());
        $this->assertEquals([
            "k:full-collection",
            "k:Square\TTCache\TTCacheTest:blog-collection:abc",
        ], $store->requestedKeys);

        // Newly cached value still contains all the tags. So clearing by another tag will also work.
        $this->tt->clearTags('post:'.$coll[1]->id);
        $store->requestedKeys = [];
        $coll[1]->title = 'Learn Python the straight way';
        $this->assertEquals([
            "<h1>Learn PHP the straight way</h1><hr /><div>...</div>",
            "<h1>Learn Python the straight way</h1><hr /><div>...</div>",
            "<h1>Learn Javascript the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Rust the curved way</h1><hr /><div>...</div>",
            "<h1>Learn Go the curved way</h1><hr /><div>...</div>",
        ], $built());
        $this->assertEquals([
            "k:full-collection",
            "k:Square\TTCache\TTCacheTest:blog-collection:def",
        ], $store->requestedKeys);
    }

    /**
     * @test
     */
    function if_sub_ttl_expires_then_sup_expires_too()
    {
        $built = $this->tt->remember('main',0, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', 1, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', 0, ['sub:2'], function () {
                return "world!";
            });

            return $out;
        });

        $this->assertEquals($built, 'hello dear world!');

        // Now it's cached
        $built = $this->tt->remember('main', 0, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello dear world!');

        sleep(1);

        // Now it's been evicted due to ttl
        $built = $this->tt->remember('main', 0, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello wholesome world!');
    }

    /**
     * @test
     */
    function cache_can_be_bypassed_based_on_result()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main',0, [], function () use ($counter) {
            $counter->increment();
            return new BypassCache('hello');
        });

        $this->assertEquals($built(), 'hello');
        $this->assertEquals(1, $counter->get());
        // The value is not getting cached, subsequent calls still increase the counter
        $this->assertEquals($built(), 'hello');
        $this->assertEquals(2, $counter->get());
    }

    /**
     * @test
     */
    function can_retrieve_value_and_its_tags()
    {
        $built = fn () => $this->tt->remember('main',0, ['abc', 'def'], function () {
            return new GetTaggedValue('hello');
        });

        $this->assertTrue($built() instanceof TaggedValue);
        $this->assertEquals($built()->value, 'hello');
        $this->assertEquals(array_keys($built()->tags), ['t:abc', 't:def']);
    }

    /**
     * @test
     */
    function deep_tree_cache()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', 0, [], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', 0, ['sub:2'], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', 0, ['sub:3'], function () use ($counter) {
                        $counter->increment();
                        return '!';
                    });
                    return $out;
                });
                return $out;
            });
            return $out;
        });

        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4);

        // Now it's cached
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0); // no callbacks were called

        // clear one of the sub tags
        $counter->reset();
        $this->tt->clearTags('sub:1');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 2); // 2 levels of callbacks were called

        // clear deepest sub
        $counter->reset();
        $this->tt->clearTags('sub:3');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 4 levels of callbacks were called
    }

    /**
     * @test
     */
    function tags_can_get_inherited_from_parent_node()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', 0, ['main', new HeritableTag('global')], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', 0, ['sub:2', new HeritableTag('subglobal')], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', 0, ['sub:3'], function () use ($counter) {
                        $counter->increment();
                        return '!';
                    });
                    return $out;
                });
                return $out;
            });
            return $out;
        });

        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4);

        // Now it's cached
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0); // no callbacks were called

        // clear one of the sub tags
        $counter->reset();
        $this->tt->clearTags('sub:1');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 2); // 2 levels of callbacks were called

        // clear the heritable tag
        $counter->reset();
        $this->tt->clearTags('global');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 4 levels of callbacks were called

        // clear the heritable tag
        $counter->reset();
        $this->tt->clearTags('subglobal');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 4 levels of callbacks were called
    }

    /**
     * @test
     */
    function tags_use_wrap_to_add_tags_without_caching()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->wrap(['main', new HeritableTag('global')], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->wrap(['sub:2', new HeritableTag('subglobal')], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', 0, ['sub:3'], function () use ($counter) {
                        $counter->increment();
                        return '!';
                    });
                    return $out;
                });
                return $out;
            });
            return $out;
        });

        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4);

        // Now it's cached but the first call to `wrap` still doesn't cache anything
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 1); // no callbacks were called

        // clear the top level heritable tag should clear everything even though it was added on `wrap` which
        // by itself does not cache anything
        $counter->reset();
        $this->tt->clearTags('global');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 2 levels of callbacks were called

        // Same happens with the other heritable tag
        $counter->reset();
        $this->tt->clearTags('global');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 2 levels of callbacks were called

        // Clearing `sub:2` added via a nested call to `wrap` also works
        $counter->reset();
        $this->tt->clearTags('sub:2');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 3); // 2 levels of callbacks were called
    }

    /**
     * @test
     */
    function additional_tags()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', 0, [], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', 0, ['sub:1', 'subs:all'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', 0, ['sub:2'], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', 0, ['sub:3', ...Tags::fromMap(['subs' => 'deep','ocean' => 'verydeep'])], function () use ($counter) {
                        $counter->increment();
                        return '!';
                    });
                    return $out;
                });
                return $out;
            });
            return $out;
        });

        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4);

        // Now it's cached
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0); // no callbacks were called

        // clear one of the additional sub tags
        $counter->reset();
        $this->tt->clearTags('subs:all');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 2); // 2 levels of callbacks were called

        // clear deepest sub
        $counter->reset();
        $this->tt->clearTags('subs:deep');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // 4 levels of callbacks were called

        // clear deepest sub
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0); // cached again

        // clear deepest sub
        $counter->reset();
        $this->tt->clearTags('ocean:verydeep');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4); // also goes to all levels
    }

    /**
     * @test
     */
    public function sharding_tags_clear_swaths_of_cache()
    {
        $main = $this->counter();
        $sub = $this->counter();
        $built = fn () => $this->tt->remember('main', 0, [new ShardingTag('shard', 'abc', 2)], fn () => 'main'.$main->increment());
        $built2 = fn () => $this->tt->remember('sub', 0, [new ShardingTag('shard', 'def', 2)], fn () => 'sub'.$sub->increment());

        $this->assertEquals('main1', $built());
        $this->assertEquals('sub1', $built2());
        // Now they're cached
        $this->assertEquals('main1', $built());
        $this->assertEquals('sub1', $built2());
        // clear a shard tag clears only the value that was on that shard
        $this->tt->clearTags('shard:0');
        $this->assertEquals('main2', $built());
        $this->assertEquals('sub1', $built2());
        $this->tt->clearTags('shard:1');
        $this->assertEquals('main2', $built());
        $this->assertEquals('sub2', $built2());
    }

    public function counter()
    {
        return new class () {
            protected $c = 0;
            public function increment()
            {
                return ++$this->c;
            }

            public function get()
            {
                return $this->c;
            }

            public function reset()
            {
                $this->c = 0;
            }
        };
    }
}

class BlogPost
{
    public string $id;

    public string $title;

    public string $content;

    public function __construct(string $id, string $title, string $content)
    {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
    }
}
