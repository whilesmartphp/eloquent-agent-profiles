<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Definition\RuleCompiler;

class RuleCompilerTest extends TestCase
{
    #[Test]
    public function rules_compile_into_per_event_and_per_tool_lookups(): void
    {
        $schema = new DefinitionSchema;
        $definition = $schema->normalize([
            'permissions' => ['tools' => ['mail.send', 'crm.read']],
            'rules' => [
                ['id' => 'tone', 'evaluator' => 'style', 'when' => ['event' => 'before_response'], 'action' => 'warn'],
                ['id' => 'mail-review', 'evaluator' => 'reviewer', 'when' => ['event' => 'before_tool', 'tools' => ['mail.send']], 'action' => 'require_approval'],
                ['id' => 'audit', 'evaluator' => 'logger', 'when' => ['event' => 'after_tool']],
                ['id' => 'budget', 'evaluator' => 'logger'],
            ],
        ]);

        $compiled = (new RuleCompiler)->compile($definition);

        $this->assertSame(['budget'], $compiled['events']['always']);
        $this->assertSame(['tone'], $compiled['events']['before_response']);
        $this->assertSame(['crm.read' => [], 'mail.send' => ['mail-review']], $compiled['events']['before_tool']);
        $this->assertSame(['crm.read' => ['audit'], 'mail.send' => ['audit']], $compiled['events']['after_tool']);
        $this->assertSame(['logger', 'reviewer', 'style'], $compiled['evaluators']);
        $this->assertSame('block', $compiled['rules']['budget']['action']);
        $this->assertSame(['audit', 'budget', 'mail-review', 'tone'], array_keys($compiled['rules']));
    }

    #[Test]
    public function compilation_does_not_depend_on_input_order(): void
    {
        $schema = new DefinitionSchema;
        $rules = [
            ['id' => 'b', 'evaluator' => 'x'],
            ['id' => 'a', 'evaluator' => 'y'],
        ];

        $forward = (new RuleCompiler)->compile($schema->normalize(['rules' => $rules]));
        $reverse = (new RuleCompiler)->compile($schema->normalize(['rules' => array_reverse($rules)]));

        $this->assertSame($forward, $reverse);
    }
}
