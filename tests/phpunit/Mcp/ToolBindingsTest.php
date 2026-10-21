<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Cli\Command\QueryCommand;
use Knossos\Mcp\ToolArgumentSpec;
use Knossos\Mcp\ToolBindings;
use Knossos\Mcp\ToolCatalog;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The binding table both front ends build their calls from.
 *
 * Every tool is answered one of two ways, from the table or by an adapter
 * of its own in ToolService, and nothing in the table names an argument its
 * tool does not declare. The last two tests pin the two places where the
 * hand-written adapters had moved away from the schema the table now reads.
 */
final class ToolBindingsTest extends KnossosTestCase
{
    use ToolArgumentProbes;

    /** A tool missing from both is a tool no call can reach; one in both has two answers. */
    #[Group('mcp')]
    public function testEveryCatalogToolHasABindingOrADeclaredAdapter(): void
    {
        $tools = array_column(ToolCatalog::definitions(), 'name');
        $bound = ToolBindings::tools();

        assertSame([], array_values(array_intersect($bound, ToolBindings::HAND_WRITTEN)), 'Both bound and hand-written.');
        assertSame([], array_values(array_diff($tools, $bound, ToolBindings::HAND_WRITTEN)), 'Neither bound nor hand-written.');
        assertSame([], array_values(array_diff([...$bound, ...ToolBindings::HAND_WRITTEN], $tools)), 'Bound or hand-written, but no such tool.');
    }

    /** Every query command is a bound tool, so the CLI has no adapter of its own left. */
    #[Group('cli')]
    public function testEveryQueryCommandIsABoundTool(): void
    {
        $commands = (new \ReflectionClassConstant(QueryCommand::class, 'COMMANDS'))->getValue();

        foreach ($commands as $command) {
            assertSame(true, ToolBindings::binds(str_replace('-', '_', $command)), $command);
        }
    }

    /** A binding passes only arguments its tool declares, and every one it reads has a type to read it as. */
    #[Group('mcp')]
    public function testEveryBoundArgumentIsDeclaredByItsTool(): void
    {
        $unknown = [];
        foreach (ToolBindings::tools() as $tool) {
            $declared = ToolArgumentSpec::for($tool)->properties();
            foreach (array_keys(ToolBindings::arguments($tool)) as $argument) {
                if (!in_array($argument, $declared, true)) {
                    $unknown[] = $tool . ':' . $argument;
                }
            }
        }

        assertSame([], $unknown);
    }

    /**
     * The schema advertises as many edge kinds as the graph has, so a client
     * can name every one; the handlers capped the list at 20 and refused it.
     */
    #[Group('mcp')]
    public function testAnEdgeKindsListOfEveryKindIsAccepted(): void
    {
        [$tools, $project] = $this->tools();
        $kinds = AbstractArchitectureQueryService::IMPACT_EDGE_KINDS;
        assertSame(true, count($kinds) > 20, 'The case only means something while there are more kinds than the old cap.');

        assertSame(null, self::errorFrom($tools, 'list_usages', ['project_id' => $project, 'symbol' => 'App\\Checkout', 'edge_kinds' => $kinds]));
        assertSame(
            sprintf('edge_kinds must be a list of at most %d strings.', count($kinds)),
            self::errorFrom($tools, 'list_usages', ['project_id' => $project, 'symbol' => 'App\\Checkout', 'edge_kinds' => [...$kinds, 'calls']]),
        );
    }

    /** review_diff documents `{}` as "no budgets"; its handler refused an empty object as a list. */
    #[Group('mcp')]
    public function testReviewDiffAcceptsAnEmptyBudgetsObject(): void
    {
        [$tools, $project] = $this->tools();

        assertNotSame('budgets must be an object.', self::errorFrom($tools, 'review_diff', ['project_id' => $project, 'files' => ['src/Checkout.php'], 'budgets' => []]));
        assertSame('budgets must be an object.', self::errorFrom($tools, 'review_diff', ['project_id' => $project, 'budgets' => [1]]));
    }
}
