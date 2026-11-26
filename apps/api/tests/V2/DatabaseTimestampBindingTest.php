<?php

namespace Tests\V2;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/** Catch inline application clocks at SQL boundaries, including unexercised feature paths. */
final class DatabaseTimestampBindingTest extends TestCase
{
    public function test_v2_queries_bind_explicit_instants(): void
    {
        $violations = [];
        $files = Finder::create()->files()->in(__DIR__.'/../../app')->name('*.php');
        foreach ($files as $file) {
            if (! str_contains($file->getRelativePathname(), 'V2')) {
                continue;
            }
            foreach ($this->unsafeLines($file->getContents()) as $line) {
                $violations[] = $file->getRelativePathname().':'.$line;
            }
        }
        self::assertSame([], $violations);
    }

    public function test_detects_clock_writes_and_comparisons_and_preserves_typed_paths(): void
    {
        $source = <<<'PHP'
        <?php
        DB::table('items')->update(['expires_at' => now()->addDay()]);
        Item::query()->where('expires_at', '<=', CarbonImmutable::now('UTC'))->get();
        Item::create(['expires_at' => now()]);
        DB::table('items')->update(['expires_at' => V2DatabaseTimestamp::format(now())]);
        DB::table('items')->where('business_date', now()->toDateString());
        DB::table('items')->update(['expires_at' => DB::raw('CURRENT_TIMESTAMP')]);
        PHP;
        self::assertSame([2, 3], $this->unsafeLines($source));
    }

    private function unsafeLines(string $source): array
    {
        if (! preg_match('/\\bnow\\s*\\(/i', $source)) return [];
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        $finder = new NodeFinder();
        $lines = [];
        foreach ($finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            $root = $call->var;
            while ($root instanceof Expr\MethodCall) {
                $root = $root->var;
            }
            if (! $root instanceof Expr\StaticCall || ! $root->name instanceof Node\Identifier
                || ! in_array($root->name->name, ['query', 'table'], true)) {
                continue;
            }

            $args = $call->getArgs();
            if (in_array($call->name->name, ['where', 'orWhere', 'whereBetween'], true)) {
                $column = $args[0]->value ?? null;
                if (! $this->instantColumn($column)) {
                    continue;
                }
                foreach (array_slice($args, 1) as $arg) {
                    $values = $arg->value instanceof Expr\Array_ ? $arg->value->items : [$arg];
                    foreach ($values as $value) {
                        if ($this->applicationClock($value?->value)) {
                            $lines[] = $value->getStartLine();
                        }
                    }
                }
            } elseif (in_array($call->name->name, ['insert', 'insertOrIgnore', 'insertGetId', 'update', 'upsert', 'updateOrInsert'], true)) {
                foreach ($finder->findInstanceOf($args, Node\ArrayItem::class) as $item) {
                    if ($this->instantColumn($item->key) && $this->applicationClock($item->value)) {
                        $lines[] = $item->getStartLine();
                    }
                }
            }
        }
        return $lines;
    }

    private function instantColumn(?Node $node): bool
    {
        return $node instanceof Node\Scalar\String_ && preg_match('/(?:_at|_until|not_before)$/D', $node->value) === 1;
    }

    private function applicationClock(?Node $node): bool
    {
        while ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier
            && preg_match('/^(?:utc|timezone|setTimezone|startOfSecond|(?:add|sub)(?:Seconds?|Minutes?|Hours?|Days?))$/D', $node->name->name)) {
            $node = $node->var;
        }
        return ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toString() === 'now')
            || ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name
                && in_array($node->class->getLast(), ['Carbon', 'CarbonImmutable'], true)
                && $node->name instanceof Node\Identifier && $node->name->name === 'now');
    }
}
