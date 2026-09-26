<?php

declare(strict_types=1);

namespace AIForge\DevTools\Tests\Unit\Campaign;

use AIForge\Campaign\TextTools;
use AIForge\DevTools\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TextToolsTest extends TestCase
{
    public function testFoldLowercasesAndStripsAccents(): void
    {
        $this->assertSame("ete a noel oeuvre l'ecole", TextTools::fold("Été À Noël Œuvre l\u{2019}École"));
    }

    public function testContentWordsKeepsFourLettersAndMore(): void
    {
        $this->assertSame(['reserver', 'bilan'], TextTools::contentWords('Réserver un bilan'));
    }

    public function testContentWordsAreUnique(): void
    {
        $this->assertSame(['bois', 'massif'], TextTools::contentWords('Bois massif, bois massif'));
    }

    public function testOverlapIsTheShareOfWordsFound(): void
    {
        $this->assertSame(0.5, TextTools::overlap(['bilan', 'offert'], ['bilan', 'initial']));
        $this->assertSame(0.0, TextTools::overlap([], ['bilan']));
    }

    public function testShinglesAreFiveWordWindows(): void
    {
        $this->assertSame(['a b c d e', 'b c d e f'], TextTools::shingles('A b, c; d e f'));
        $this->assertSame([], TextTools::shingles('trop court'));
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function numberCases(): array
    {
        return [
            'thousands with a space' => ['15 000 exemplaires', ['15000']],
            'thousands with a no-break space' => ["15\u{00A0}000", ['15000']],
            'thousands with a narrow no-break space' => ["2\u{202F}400 salariés", ['2400']],
            'decimal comma' => ['noté 4,8/5', ['4.8', '5']],
            'year and postcode stay whole' => ['depuis 2010, Paris 75010', ['2010', '75010']],
            'french phone is one token' => ['appelez le 01 23 45 67 89', ['0123456789']],
            'range' => ['2 à 3 carrés', ['2', '3']],
            'percent' => ['100%', ['100']],
            'step number keeps its zero' => ['01', ['01']],
            'no number' => ['Formations régulières', []],
        ];
    }

    /**
     * @param string[] $expected
     */
    #[DataProvider('numberCases')]
    public function testNumbersAreNormalised(string $text, array $expected): void
    {
        $this->assertSame($expected, TextTools::numbers($text));
    }

    public function testWordCount(): void
    {
        $this->assertSame(4, TextTools::wordCount("L'atelier ouvre demain"));
    }
}
