<?php

namespace W3com\BoomBundle\Migration;

use Doctrine\Common\Annotations\DocParser;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use W3com\BoomBundle\Annotation\EntityColumnMeta;
use W3com\BoomBundle\Annotation\EntityMeta;
use W3com\BoomBundle\Annotation\EntitySynchronizedData;
use W3com\BoomBundle\Annotation\SynchronizedData;

/** Converts supported metadata without reformatting the surrounding PHP. */
final class AnnotationConverter
{
    private const TYPES = [EntityMeta::class, EntityColumnMeta::class, EntitySynchronizedData::class, SynchronizedData::class];

    public function convert(string $source): string
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $tree = $parser->parse($source);
        $edits = [];
        $namespaces = array_values(array_filter($tree, fn ($n) => $n instanceof Node\Stmt\Namespace_));
        $scopes = $namespaces ?: [null];
        foreach ($scopes as $scope) {
            $statements = $scope ? $scope->stmts : $tree;
            $namespace = $scope && $scope->name ? $scope->name->toString() : '';
            $imports = [];
            foreach ($statements as $statement) {
                if (!$statement instanceof Node\Stmt\Use_ && !$statement instanceof Node\Stmt\GroupUse) continue;
                foreach ($statement->uses as $use) {
                    $type = $use->type ?: $statement->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) continue;
                    $name = ($statement instanceof Node\Stmt\GroupUse ? $statement->prefix->toString() . '\\' : '') . $use->name->toString();
                    $imports[strtolower($use->getAlias()->toString())] = $name;
                }
            }
            $nodes = (new NodeFinder())->find($statements, fn (Node $node) => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Property);
            foreach ($nodes as $node) {
                $doc = $node->getDocComment();
                if (!$doc) continue;
                $text = $doc->getText();
                $matches = [];
                preg_match_all('~@([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)~', $text, $matches, PREG_OFFSET_CAPTURE);
                $removals = [];
                $attributes = [];
                $seen = [];
                $skipUntil = 0;
                foreach ($matches[0] as $i => [$whole, $offset]) {
                    // Ignore occurrences inside an annotation already being converted.
                    if ($offset < $skipUntil) continue;
                    $name = $matches[1][$i][0];
                    $class = $this->resolve($name, $imports, $namespace);
                    if (!in_array($class, self::TYPES, true)) {
                        $next = $offset + strlen($whole);
                        while (isset($text[$next]) && ctype_space($text[$next])) ++$next;
                        if (($text[$next] ?? '') === '(') {
                            try { $skipUntil = $this->closingParenthesis($text, $next); } catch (\RuntimeException $ignored) {}
                        }
                        continue;
                    }
                    $isClassMetadata = in_array($class, [EntityMeta::class, EntitySynchronizedData::class], true);
                    if ($isClassMetadata !== ($node instanceof Node\Stmt\Class_)) {
                        throw new \RuntimeException("Annotation $class is on an unsupported target.");
                    }
                    if (isset($seen[$class])) throw new \RuntimeException("Duplicate annotation $class.");
                    $seen[$class] = true;
                    $end = $offset + strlen($whole);
                    while (isset($text[$end]) && ctype_space($text[$end])) ++$end;
                    if (($text[$end] ?? '') === '(') $end = $this->closingParenthesis($text, $end);
                    else $end = $offset + strlen($whole);
                    foreach ($node->attrGroups as $group) foreach ($group->attrs as $attribute) {
                        if (strcasecmp($this->resolve($attribute->name->toString(), $imports, $namespace, $attribute->name instanceof Node\Name\FullyQualified), $class) === 0) {
                            throw new \RuntimeException("Both annotation and attribute exist for $class; resolve the conflict manually.");
                        }
                    }
                    $skipUntil = $end;
                    $annotation = substr($text, $offset, $end - $offset);
                    $arguments = substr($annotation, strlen($whole));
                    $docParser = new DocParser();
                    $docParser->setImports(['boomconverted' => $class] + $imports);
                    $objects = $docParser->parse('/** @BoomConverted' . $arguments . ' */');
                    if (count($objects) !== 1 || !$objects[0] instanceof $class) throw new \RuntimeException("Cannot parse $annotation");
                    $values = get_object_vars($objects[0]);
                    foreach ($values as $value) $this->assertConstantValue($value);
                    $args = [];
                    foreach ($values as $key => $value) $args[] = $key . ': ' . var_export($value, true);
                    $attributes[] = '#[\\' . $class . '(' . implode(', ', $args) . ')]';
                    $removals[] = [$offset, $end];
                }
                if (!$attributes) continue;
                // Remove only the annotation text, preserving all other documentation.
                foreach (array_reverse($removals) as [$start, $end]) {
                    $removed = substr($text, $start, $end - $start);
                    $text = substr($text, 0, $start) . preg_replace('/[^\r\n]/', '', $removed) . substr($text, $end);
                }
                $lineStart = strrpos(substr($source, 0, $doc->getStartFilePos()), "\n");
                $indent = substr($source, $lineStart === false ? 0 : $lineStart + 1, $doc->getStartFilePos() - ($lineStart === false ? 0 : $lineStart + 1));
                if (trim($indent) !== '') $indent = '';
                $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
                $replacement = $text . $eol . $indent . implode($eol . $indent, $attributes);
                $edits[] = [$doc->getStartFilePos(), $doc->getEndFilePos() + 1, $replacement];
            }
        }
        usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) $source = substr_replace($source, $replacement, $start, $end - $start);
        $parser->parse($source);
        return $source;
    }

    private function resolve(string $name, array $imports, string $namespace, bool $fullyQualified = false): string
    {
        if ($fullyQualified || str_starts_with($name, '\\')) return ltrim($name, '\\');
        $parts = explode('\\', $name);
        $first = strtolower(array_shift($parts));
        if (isset($imports[$first])) return $imports[$first] . ($parts ? '\\' . implode('\\', $parts) : '');
        return ($namespace !== '' ? $namespace . '\\' : '') . $name;
    }

    private function closingParenthesis(string $text, int $start): int
    {
        $depth = 0; $quote = null; $escaped = false;
        for ($i = $start, $length = strlen($text); $i < $length; ++$i) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($escaped) $escaped = false;
                elseif ($c === '\\') $escaped = true;
                elseif ($c === $quote) $quote = null;
            } elseif ($c === '"' || $c === "'") $quote = $c;
            elseif ($c === '(') ++$depth;
            elseif ($c === ')' && --$depth === 0) return $i + 1;
        }
        throw new \RuntimeException('Unclosed annotation argument list.');
    }

    private function assertConstantValue(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $v) $this->assertConstantValue($v); return; }
        if (!is_scalar($value) && $value !== null) throw new \RuntimeException('Unsupported annotation value.');
    }
}
