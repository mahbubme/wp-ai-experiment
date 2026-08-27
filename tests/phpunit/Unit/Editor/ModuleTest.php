<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Tests\Unit\Editor;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mahbub\WpAiExperiment\Editor\Module;
use Mahbub\WpAiExperiment\Tests\AbstractTestCase;
use Mockery;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * The module is mostly a chain of guards, and every one of them exists to keep
 * the bundle off a screen it cannot work on. These cover the guards rather than
 * the enqueue itself: a wrongly loaded bundle is the failure that would actually
 * reach a user, as a panel that throws on a screen with no post behind it.
 */
class ModuleTest extends AbstractTestCase
{
    public function testItHooksAdminEnqueueScripts(): void
    {
        Actions\expectAdded('admin_enqueue_scripts')->once();

        $module = new Module();

        $this->assertTrue($module->run(Mockery::mock(ContainerInterface::class)));
    }

    public function testItSkipsScreensThatAreNotTheEditor(): void
    {
        // The list table shares the admin, but has no single post to work on.
        $this->assertNotEnqueuedFor('edit.php', screen: null);
    }

    public function testItSkipsWhenTheScreenIsUnavailable(): void
    {
        // `get_current_screen()` answers null early in some admin requests.
        $this->assertNotEnqueuedFor('post.php', screen: null);
    }

    public function testItSkipsPostTypesWithoutExcerptSupport(): void
    {
        $screen = new stdClass();
        $screen->post_type = 'landing-page';

        Functions\expect('post_type_supports')
            ->once()
            ->with('landing-page', 'excerpt')
            ->andReturn(false);

        $this->assertNotEnqueuedFor('post.php', $screen);
    }

    /**
     * A checkout that has not been built yet should still open the editor.
     * Reaching `wp_enqueue_script()` with no asset file is the regression this
     * pins: it would enqueue a 404 and take the editor down with it.
     */
    public function testItSkipsWhenTheBundleHasNotBeenBuilt(): void
    {
        $screen = new stdClass();
        $screen->post_type = 'post';

        Functions\when('post_type_supports')->justReturn(true);

        // A real path that holds no build output, so the module's own
        // `is_readable()` check answers false without stubbing an internal.
        $properties = Mockery::mock('Inpsyde\Modularity\Properties\Properties');
        $properties->allows('basePath')
            ->andReturn(sys_get_temp_dir() . '/wp-ai-experiment-unbuilt/');

        $this->assertNotEnqueuedFor('post.php', $screen, $properties);
    }

    /**
     * Runs the enqueue callback and asserts it bailed before enqueueing.
     */
    private function assertNotEnqueuedFor(
        string $hookSuffix,
        ?stdClass $screen,
        ?object $properties = null
    ): void {

        Functions\when('get_current_screen')->justReturn($screen);

        // Any of these being reached means a guard let the request through.
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_add_inline_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->andReturn($properties);

        $callback = null;
        Actions\expectAdded('admin_enqueue_scripts')
            ->once()
            ->whenHappen(static function (callable $added) use (&$callback): void {
                $callback = $added;
            });

        (new Module())->run($container);

        $this->assertIsCallable($callback);

        $callback($hookSuffix);
    }
}
