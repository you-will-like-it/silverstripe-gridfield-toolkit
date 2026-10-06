<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Dev\SapphireTest;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\NumericEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\SelectEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\TextEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\ToggleEditor;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

/** Editor strategies in isolation (no HTTP). */
class EditorsTest extends SapphireTest
{
    protected static $extra_dataobjects = [ToolkitRecord::class];

    private function record(array $data = []): ToolkitRecord
    {
        return ToolkitRecord::create($data + ['Title' => 'T', 'Qty' => 1, 'Status' => 'a']);
    }

    public function testToggleCoercion(): void
    {
        $editor = new ToggleEditor('IsActive');
        $r = $this->record();
        foreach ([true, 1, '1', 'true'] as $truthy) {
            $this->assertTrue($editor->coerce($truthy, $r));
        }
        foreach ([false, 0, '0', 'false'] as $falsy) {
            $this->assertFalse($editor->coerce($falsy, $r));
        }
        foreach (['yes', 2, null, [], 'on'] as $bad) {
            try {
                $editor->coerce($bad, $r);
                $this->fail('expected rejection of ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testToggleRefusesNonBooleanColumns(): void
    {
        $this->expectException(LogicException::class);
        (new ToggleEditor('Title'))->assign($this->record(), true);
    }

    public function testTextEditorRules(): void
    {
        $r = $this->record();
        $this->assertSame('a b', (new TextEditor('Title'))->coerce("  a b \t", $r));
        $this->assertSame("  a b ", (new TextEditor('Title', trim: false))->coerce("  a b ", $r));
        $this->assertSame("a\nb", (new TextEditor('Title', multiline: true))->coerce("a\r\nb", $r));
        $this->assertSame('', (new TextEditor('Title'))->coerce(null, $r));
        $this->assertSame('42', (new TextEditor('Title'))->coerce(42, $r));

        $this->expectException(InvalidArgumentException::class);
        (new TextEditor('Title', required: true))->coerce('   ', $r);
    }

    public function testTextEditorRejectsControlCharactersInvalidUtf8AndOversizedInput(): void
    {
        $r = $this->record();
        $editor = new TextEditor('Title', maxLength: 5);
        foreach (["a\x00b", "a\x07b", "\xC3\x28", 'abcdef'] as $bad) {
            try {
                $editor->coerce($bad, $r);
                $this->fail('expected rejection');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('čćžšđ', (new TextEditor('Title', maxLength: 5))->coerce('čćžšđ', $r), 'limit counts characters, not bytes');
    }

    public function testTextEditorDefaultsItsLimitToTheVarcharSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TextEditor('Title'))->coerce(str_repeat('x', 21), $this->record());
    }

    public function testNumericEditorTypesAndRanges(): void
    {
        $r = $this->record();
        $int = new NumericEditor('Qty', min: 0, max: 10);
        $this->assertSame(5, $int->coerce('5', $r));
        $this->assertSame(5, $int->coerce(5.0, $r));
        $this->assertSame(1000, (new NumericEditor('Qty'))->coerce('1e3', $r));

        $decimal = new NumericEditor('Price', allowNull: true);
        $this->assertSame(1.24, $decimal->coerce('1.2399', $r));
        $this->assertNull($decimal->coerce('  ', $r));
        $this->assertSame(0.0, $decimal->coerce('0', $r));

        foreach (['2.5', 'NaN', 'INF', '1,5', '0x1A', [], true] as $bad) {
            try {
                $int->coerce($bad, $r);
                $this->fail('expected rejection of ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNumericReadValueIsTypeStable(): void
    {
        $r = $this->record(['Qty' => '7', 'Price' => '12.50']);
        $this->assertSame(7, (new NumericEditor('Qty'))->readValue($r));
        $this->assertSame(12.5, (new NumericEditor('Price'))->readValue($r));
    }

    public function testSelectEditorMembershipAndEmptyHandling(): void
    {
        $r = $this->record();
        $editor = new SelectEditor('Status', ['a' => 'A', 'b' => 'B'], allowEmpty: true, emptyLabel: '—', emptyValue: null);

        $this->assertSame('b', $editor->coerce('b', $r));
        $this->assertSame('', $editor->coerce('', $r));
        $this->assertSame([['value' => '', 'label' => '—'], ['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']], $editor->getOptions($r));

        $editor->assign($r, '');
        $this->assertNull($r->getField('Status'));
        $this->assertSame('', $editor->readValue($r));

        $this->expectException(InvalidArgumentException::class);
        $editor->coerce('c', $r);
    }

    public function testSelectEditorWithoutEmptyChoiceRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SelectEditor('Status', ['a' => 'A']))->coerce('', $this->record());
    }

    public function testClosureOptionsAreEvaluatedPerRecordAndNotShippedToTheClient(): void
    {
        $editor = new SelectEditor('Status', static fn (ToolkitRecord $r): array => ['x' . $r->Qty => 'dyn']);

        $this->assertFalse($editor->isStatic());
        $this->assertSame(['dynamic' => true], $editor->getClientSchema());
        $this->assertSame('x1', $editor->coerce('x1', $this->record(['Qty' => 1])));
    }
}
