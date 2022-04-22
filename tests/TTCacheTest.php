<?php declare(strict_types=1);

namespace Square\TTCache;

use Closure;
use Psr\SimpleCache\CacheInterface;
use Square\TTCache\ReturnDirective\BypassCache;
use Square\TTCache\ReturnDirective\GetTaggedValue;
use Square\TTCache\Store\ShardedMemcachedStore;
use Square\TTCache\Tags\HeritableTag;
use Square\TTCache\TTCache;
use PHPUnit\Framework\TestCase;
use Memcached;
use Square\TTCache\Tags\ShardingTag;

abstract class TTCacheTest extends TestCase
{
    protected TTCache $tt;

    protected Memcached $mc;

    protected $keySeparator = ':';

    public function setUp() : void
    {
        $this->tt = $this->getTTCache();
    }

    /**
     * @return Closure
     */
    abstract public function getKeyHasher(): Closure;

    /**
     * @return CacheInterface
     */
    abstract public function getTTCache(): TTCache;

    /**
     * @test
     */
    function cache()
    {
        $v = $this->tt->remember('testkey', null, [], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        // If we use the same key but a different callback that returns something different, we should still get
        // the previously cached value.
        $v = $this->tt->remember('testkey', null, [], fn () => 'hello 2');
        $this->assertEquals('hello 1', $v);
    }

    /**
     * @test
     */
    function clear_by_tags()
    {
        $v = $this->tt->remember('testkey', null, ['tag', 'other:tag'], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        // now it's cached
        $v = $this->tt->remember('testkey', null, [], fn () => 'hello 2');
        $this->assertEquals('hello 1', $v);
        // clear `tag`
        $this->tt->clearTags('tag');
        $v = $this->tt->remember('testkey', null, [], fn () => 'hello 2');
        $this->assertEquals('hello 2', $v);
    }

    /**
     * @test
     */
    function avoids_root_tags_contamination()
    {
        $v = $this->tt->remember('testkey', null, ['tag', 'other:tag'], fn () => 'hello 1');
        $this->assertEquals('hello 1', $v);
        $v = $this->tt->remember('testkey2', null, [], fn () => 'hello 2');
        $this->assertEquals('hello 2', $v);
        // now it's cached
        $v = $this->tt->remember('testkey2', null, [], fn () => 'hello 3');
        $this->assertEquals('hello 2', $v);
        // clear `tag`
        $this->tt->clearTags('tag');
        $v = $this->tt->remember('testkey2', null, [], fn () => 'hello 3');
        $this->assertEquals('hello 2', $v);
    }

    /**
     * @test
     */
    function tree_cache()
    {
        $built = $this->tt->remember('main', null, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', null, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', null, ['sub:2'], function () {
                return "world!";
            });

            return $out;
        });

        $this->assertEquals($built, 'hello dear world!');

        // Now it's cached
        $built = $this->tt->remember('main', null, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello dear world!');

        // clear one of the sub tags
        $this->tt->clearTags('sub:1');
        // sub2 is still in cache
        $sub2 = $this->tt->remember('sub2', null, [], fn () => 'oh no');
        $this->assertEquals('world!', $sub2);

        $built = $this->tt->remember('main', null, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello wholesome world!');
    }

    /**
     * @test
     */
    function handles_exceptions()
    {
        $built = fn () => $this->tt->remember('main', null, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', null, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', null, ['sub:2'], function () {
                throw new \Exception('whoopsie');
            });

            return $out;
        });

        try {
            $built();
        } catch (\Exception $e) {
            // nothing
        }
        $this->assertEquals(' dear ', $this->tt->remember('sub', null, [], fn () => 'failure'));
        $this->assertEquals('failure', $this->tt->remember('main', null, [], fn () => 'failure'));
        $this->assertEquals('failure', $this->tt->remember('sub2', null, [], fn () => 'failure'));
    }

    /**
     * @test
     */
    function if_sub_ttl_expires_then_sup_expires_too()
    {
        $built = $this->tt->remember('main',null, [], function () {
            $out = "hello";
            $out .= $this->tt->remember('sub', 1, ['sub:1'], function () {
                return " dear ";
            });
            $out .= $this->tt->remember('sub2', null, ['sub:2'], function () {
                return "world!";
            });

            return $out;
        });

        $this->assertEquals($built, 'hello dear world!');

        // Now it's cached
        $built = $this->tt->remember('main', null, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello dear world!');

        sleep(1);

        // Now it's been evicted due to ttl
        $built = $this->tt->remember('main', null, [], fn () => 'hello wholesome world!');
        $this->assertEquals($built, 'hello wholesome world!');
    }

    /**
     * @test
     */
    function cache_can_be_bypassed_based_on_result()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', null, [], function () use ($counter) {
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
        $built = fn () => $this->tt->remember('main', null, ['abc', 'def'], function () {
            return new GetTaggedValue('hello');
        });

        $this->assertTrue($built() instanceof TaggedValue);
        $this->assertEquals($built()->value, 'hello');
        $this->assertEquals(
            array_keys($built()->tags),
            [
                't' . $this->keySeparator . $this->getKeyHasher()('abc'),
                't' . $this->keySeparator . $this->getKeyHasher()('def'),
            ],
        );
    }

    /**
     * @test
     */
    function deep_tree_cache()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', null, [], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', null, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', null, ['sub:2'], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', null, ['sub:3'], function () use ($counter) {
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
        $this->assertEquals($counter->get(), 4, 'expected first call to call all 4 levels.');

        // Now it's cached
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0, 'expected not clearing any tags to call 0 levels.'); // no callbacks were called

        // clear one of the sub tags
        $counter->reset();
        $this->tt->clearTags('sub:1');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 2, 'expected clearing tag sub:1 to call only 2 levels.'); // 2 levels of callbacks were called

        // clear deepest sub
        $counter->reset();
        $this->tt->clearTags('sub:3');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4, 'expected clearing of deepest tag to call all 4 levels.'); // 4 levels of callbacks were called
    }

    /**
     * @test
     */
    function tags_can_get_inherited_from_parent_node()
    {
        $counter = $this->counter();
        $built = fn () => $this->tt->remember('main', null, ['main', new HeritableTag('global')], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', null, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', null, ['sub:2', new HeritableTag('subglobal')], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', null, ['sub:3'], function () use ($counter) {
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
        $this->assertEquals($counter->get(), 4, 'expected first call to call all 4 levels.');

        // Now it's cached
        $counter->reset();
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 0, 'expected not clearing any tags to call 0 levels.'); // no callbacks were called

        // clear one of the sub tags
        $counter->reset();
        $this->tt->clearTags('sub:1');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 2, 'expected clearing sub:1 to call 2 levels.'); // 2 levels of callbacks were called

        // clear the heritable tag
        $counter->reset();
        $this->tt->clearTags('global');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4, 'expected clearing heritable tag "global" to call 4 levels.'); // 4 levels of callbacks were called

        // clear the heritable tag
        $counter->reset();
        $this->tt->clearTags('subglobal');
        $this->assertEquals($built(), 'hello dear world!');
        $this->assertEquals($counter->get(), 4, 'expected clarring heritable tag "subglobal" to call 4 levels.'); // 4 levels of callbacks were called
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
            $out .= $this->tt->remember('sub', null, ['sub:1'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->wrap(['sub:2', new HeritableTag('subglobal')], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', null, ['sub:3'], function () use ($counter) {
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
        $built = fn () => $this->tt->remember('main', null, [], function () use ($counter) {
            $counter->increment();
            $out = "hello";
            $out .= $this->tt->remember('sub', null, ['sub:1', 'subs:all'], function () use ($counter) {
                $counter->increment();
                $out = " dear ";
                $out .= $this->tt->remember('sub2', null, ['sub:2'], function () use ($counter) {
                    $counter->increment();
                    $out = "world";
                    $out .= $this->tt->remember('sub3', null, ['sub:3', ...Tags::fromMap(['subs' => 'deep','ocean' => 'verydeep'])], function () use ($counter) {
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
        $built = fn () => $this->tt->remember('main', null, [new ShardingTag('shard', 'abc', 2)], fn () => 'main'.$main->increment());
        $built2 = fn () => $this->tt->remember('sub', null, [new ShardingTag('shard', 'def', 2)], fn () => 'sub'.$sub->increment());

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
